<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Etf;
use App\Entity\Notification;
use App\Entity\PriceAlert;
use App\Entity\Stock;
use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Price alerts on stocks and funds: set, cancelled and fired.
 *
 * The ticker never queries alerts. Each instrument with an alert waiting has its nearest targets on either side in
 * one Redis hash, read once a tick; a price through either bound dispatches a message, and the worker fires every
 * alert the price has reached and rewrites the bounds. The same pattern as resting orders (limit_bounds:*), so an
 * alert costs the tick nothing until it fires.
 */
class PriceAlertService
{
    // --- Wire ---
    /** Redis hash: ticker => {"above": nearest target above the price, "below": nearest target below}. */
    public const REDIS_KEY = 'price_alert_bounds';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly \Redis $redis,
        private readonly PlayerNotifier $notifier,
    ) {}

    /** Whether a price has reached either bound of an instrument's waiting alerts. */
    public static function crossed(array $bounds, float $price): bool
    {
        $above = isset($bounds['above']) ? (float) $bounds['above'] : null;
        $below = isset($bounds['below']) ? (float) $bounds['below'] : null;

        return ($above !== null && $price >= $above) || ($below !== null && $price <= $below);
    }

    /**
     * Sets an alert at a target price; the side it waits on is the side of the current price the target is on.
     *
     * @throws \InvalidArgumentException With a message for the player.
     */
    public function create(User $user, string $ticker, float $target): PriceAlert
    {
        if (!is_finite($target) || $target <= 0.0) {
            throw new \InvalidArgumentException('Enter a price above zero.');
        }

        $instrument = $this->instrument($ticker);
        if ($instrument === null) {
            throw new \InvalidArgumentException('Alerts can be set on listed shares and funds.');
        }

        $open = (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM price_alerts WHERE user_id = :u AND triggered_at IS NULL',
            ['u' => $user->getId()],
        );
        if ($open >= PriceAlert::MAX_OPEN_PER_USER) {
            throw new \InvalidArgumentException(sprintf('You can have %d alerts waiting at once.', PriceAlert::MAX_OPEN_PER_USER));
        }

        $price = (float) $instrument->getPrice();
        $alert = new PriceAlert(
            $user,
            $instrument->getTicker(),
            PriceAlert::directionFor($target, $price),
            number_format($target, 4, '.', ''),
            number_format($price, 4, '.', ''),
        );
        $this->entityManager->persist($alert);
        $this->entityManager->flush();

        $this->rebuildBounds($instrument->getTicker());

        return $alert;
    }

    public function cancel(User $user, int $alertId): void
    {
        $alert = $this->entityManager->getRepository(PriceAlert::class)->find($alertId);
        if (!$alert instanceof PriceAlert || $alert->getUser()->getId() !== $user->getId() || $alert->getTriggeredAt() !== null) {
            throw new \InvalidArgumentException('That alert is no longer waiting.');
        }

        $ticker = $alert->getTicker();
        $this->entityManager->remove($alert);
        $this->entityManager->flush();

        $this->rebuildBounds($ticker);
    }

    /** @return list<PriceAlert> The account's waiting alerts, optionally on one instrument. */
    public function waiting(User $user, ?string $ticker = null): array
    {
        $criteria = ['user' => $user, 'triggeredAt' => null];
        if ($ticker !== null) {
            $criteria['ticker'] = $ticker;
        }

        /** @var list<PriceAlert> */
        return $this->entityManager->getRepository(PriceAlert::class)->findBy($criteria, ['ticker' => 'ASC', 'targetPrice' => 'ASC']);
    }

    /**
     * Fires every waiting alert on the instrument that the price has reached, then rewrites its bounds.
     *
     * @return int Alerts fired.
     */
    public function trigger(string $ticker, float $price): int
    {
        $conn = $this->entityManager->getConnection();
        $rows = $conn->fetchAllAssociative(
            "SELECT id, user_id, direction, target_price, price_at_creation FROM price_alerts
             WHERE ticker = :ticker AND triggered_at IS NULL
               AND ((direction = 'ABOVE' AND target_price <= :price) OR (direction = 'BELOW' AND target_price >= :price))",
            ['ticker' => $ticker, 'price' => $price],
        );

        if ($rows !== []) {
            $conn->executeStatement(
                'UPDATE price_alerts SET triggered_at = :now, triggered_price = :price WHERE id IN (:ids) AND triggered_at IS NULL',
                [
                    'now' => (new \DateTime())->format('Y-m-d H:i:s'),
                    'price' => number_format($price, 4, '.', ''),
                    'ids' => array_map(static fn(array $row): int => (int) $row['id'], $rows),
                ],
                ['ids' => ArrayParameterType::INTEGER],
            );

            foreach ($rows as $row) {
                $rose = $row['direction'] === PriceAlert::ABOVE;
                $from = (float) $row['price_at_creation'];
                $this->notifier->queue(
                    (int) $row['user_id'],
                    Notification::KIND_PRICE_ALERT,
                    sprintf('%s %s $%s', $ticker, $rose ? 'rose to' : 'fell to', number_format((float) $row['target_price'], 2)),
                    sprintf(
                        'Now $%s%s.',
                        number_format($price, 2),
                        $from > 0.0 ? sprintf(', %+.1f%% since you set the alert', ($price / $from - 1.0) * 100.0) : '',
                    ),
                    $ticker,
                    '/stock/' . rawurlencode($ticker),
                );
            }
            $this->notifier->publish();
        }

        $this->rebuildBounds($ticker);

        return count($rows);
    }

    /** Rewrites the nearest waiting targets on either side for one instrument, or drops it from the hash. */
    private function rebuildBounds(string $ticker): void
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            "SELECT MIN(CASE WHEN direction = 'ABOVE' THEN target_price END) AS above,
                    MAX(CASE WHEN direction = 'BELOW' THEN target_price END) AS below
             FROM price_alerts WHERE ticker = :ticker AND triggered_at IS NULL",
            ['ticker' => $ticker],
        );

        $above = $row === false || $row['above'] === null ? null : (float) $row['above'];
        $below = $row === false || $row['below'] === null ? null : (float) $row['below'];

        if ($above === null && $below === null) {
            $this->redis->hDel(self::REDIS_KEY, $ticker);

            return;
        }

        $this->redis->hSet(self::REDIS_KEY, $ticker, (string) json_encode(['above' => $above, 'below' => $below]));
    }

    /**
     * Restates every alert on a split instrument, inside the caller's split transaction, so a target keeps its
     * distance from the price instead of firing on the split itself. The factor is bound as an integer, as every
     * split statement binds it (CorporateLedgerService::processStockSplit()).
     *
     * @param bool $pricesMultiply True for a reverse split (prices rise by the factor), false for a forward one.
     */
    public static function restateForSplit(Connection $conn, string $ticker, int $factor, bool $pricesMultiply): void
    {
        $op = $pricesMultiply ? '*' : '/';
        $conn->executeStatement(
            "UPDATE price_alerts
             SET target_price = ROUND(target_price {$op} :factor, 4),
                 price_at_creation = ROUND(price_at_creation {$op} :factor, 4),
                 triggered_price = ROUND(triggered_price {$op} :factor, 4)
             WHERE ticker = :ticker",
            ['factor' => $factor, 'ticker' => $ticker],
            ['factor' => ParameterType::INTEGER],
        );
    }

    /** Restates an instrument's cached bounds by the split's price factor, matching restateForSplit() on the rows. */
    public static function restateBoundsForSplit(\Redis $redis, string $ticker, float $priceFactor): void
    {
        $json = $redis->hGet(self::REDIS_KEY, $ticker);
        $bounds = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($bounds)) {
            return;
        }

        $scale = static fn(mixed $bound): ?float => $bound === null ? null : round((float) $bound * $priceFactor, 4);
        $redis->hSet(self::REDIS_KEY, $ticker, (string) json_encode([
            'above' => $scale($bounds['above'] ?? null),
            'below' => $scale($bounds['below'] ?? null),
        ]));
    }

    /** Rebuilds the whole hash from the table; the ticker calls it at start, so a flushed Redis loses no alert. */
    public function rebuildAll(): void
    {
        $this->redis->del(self::REDIS_KEY);
        $tickers = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT ticker FROM price_alerts WHERE triggered_at IS NULL',
        );
        foreach ($tickers as $ticker) {
            $this->rebuildBounds((string) $ticker);
        }
    }

    private function instrument(string $ticker): Stock|Etf|null
    {
        $stock = $this->entityManager->getRepository(Stock::class)->findOneBy(['ticker' => $ticker]);
        if ($stock instanceof Stock) {
            return $stock->isBankrupt() ? null : $stock;
        }

        $etf = $this->entityManager->getRepository(Etf::class)->findOneBy(['ticker' => $ticker]);

        return $etf instanceof Etf ? $etf : null;
    }
}
