<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Data\District\DistrictCalendar;
use App\Entity\Notification;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes messages to accounts and pushes them to the account's open pages.
 *
 * Messages are queued, never written where they arise: a fill, a forced sale or an expiry happens inside a
 * transaction that can still roll back, and a message about a trade that did not happen is worse than none. The
 * process that owns the transaction calls publish() once it commits (the ticker after each tick, the worker and the
 * web after their own) and discard() when it rolls back. News on watched names is queued by instrument and resolved
 * to watchers at publish, so a tick costs one watchlist query however many stories it ran.
 */
class PlayerNotifier
{
    // --- Wire ---
    /** Redis channel the websocket server routes to each account's connections. */
    public const CHANNEL = 'player_notifications';

    // --- Limits ---
    /** Most messages held between publishes; a fast-forward that never publishes stops queueing here. */
    private const MAX_PENDING = 5000;
    /** Rows per INSERT statement. */
    private const INSERT_CHUNK = 500;

    /** @var list<array{user: int, kind: string, title: string, body: ?string, ticker: ?string, link: ?string}> */
    private array $pending = [];

    /** @var list<array{ticker: string, title: string, body: ?string, link: string}> */
    private array $pendingWatched = [];

    private ?float $simTime = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
    ) {}

    /** The page an instrument's messages open. */
    public static function assetLink(string $assetType, string $ticker): string
    {
        return ($assetType === 'BOND' ? '/bond/' : '/stock/') . rawurlencode($ticker);
    }

    /** "Bought 50 GULL at $12.34", the line a fill is announced with. */
    public static function fillLine(string $action, int|string $quantity, string $ticker, float $price): string
    {
        $verb = match ($action) {
            'BUY' => 'Bought',
            'SELL' => 'Sold',
            'SHORT' => 'Sold short',
            'COVER' => 'Bought back',
            'WRITE' => 'Wrote',
            default => $action,
        };

        return sprintf('%s %s %s at $%s', $verb, number_format((float) $quantity), $ticker, number_format($price, 2));
    }

    /** Dates messages published from now on; the ticker calls it each tick. Otherwise the committed clock is read. */
    public function stampSimTime(float $simTime): void
    {
        $this->simTime = $simTime;
    }

    public function queue(int $userId, string $kind, string $title, ?string $body = null, ?string $ticker = null, ?string $link = null): void
    {
        if (count($this->pending) >= self::MAX_PENDING) {
            return;
        }

        $this->pending[] = ['user' => $userId, 'kind' => $kind, 'title' => $title, 'body' => $body, 'ticker' => $ticker, 'link' => $link];
    }

    /** Queues a story for every account watching the instrument. */
    public function queueForWatchers(string $ticker, string $title, ?string $body, string $link): void
    {
        if (count($this->pendingWatched) >= self::MAX_PENDING) {
            return;
        }

        $this->pendingWatched[] = ['ticker' => $ticker, 'title' => $title, 'body' => $body, 'link' => $link];
    }

    /** Messages waiting for publish(). */
    public function pendingCount(): int
    {
        return count($this->pending) + count($this->pendingWatched);
    }

    /** Drops everything queued since the last publish: the transaction that raised it rolled back. */
    public function discard(): void
    {
        $this->pending = [];
        $this->pendingWatched = [];
    }

    /**
     * Writes and pushes everything queued. Failures are logged, never thrown: a message is never worth a tick.
     *
     * @return int Messages written.
     */
    public function publish(): int
    {
        if ($this->pending === [] && $this->pendingWatched === []) {
            return 0;
        }

        $messages = $this->pending;
        $watched = $this->pendingWatched;
        $this->discard();

        try {
            $messages = array_merge($messages, $this->resolveWatchers($watched));
            if ($messages === []) {
                return 0;
            }

            $simTime = $this->simTime ?? $this->committedSimTime();
            $this->insert($messages, $simTime);
            $this->push($messages, $simTime);

            return count($messages);
        } catch (\Throwable $e) {
            $this->logger->error('Player notifications not written', ['count' => count($messages), 'exception' => $e]);

            return 0;
        }
    }

    /**
     * @param list<array{ticker: string, title: string, body: ?string, link: string}> $watched
     * @return list<array{user: int, kind: string, title: string, body: ?string, ticker: ?string, link: ?string}>
     */
    private function resolveWatchers(array $watched): array
    {
        if ($watched === []) {
            return [];
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT user_id, ticker FROM watchlist_items WHERE ticker IN (:tickers)',
            ['tickers' => array_values(array_unique(array_column($watched, 'ticker')))],
            ['tickers' => ArrayParameterType::STRING]
        );

        $watchers = [];
        foreach ($rows as $row) {
            $watchers[(string) $row['ticker']][] = (int) $row['user_id'];
        }

        $messages = [];
        foreach ($watched as $story) {
            foreach ($watchers[$story['ticker']] ?? [] as $userId) {
                $messages[] = [
                    'user' => $userId,
                    'kind' => Notification::KIND_NEWS,
                    'title' => $story['title'],
                    'body' => $story['body'],
                    'ticker' => $story['ticker'],
                    'link' => $story['link'],
                ];
            }
        }

        return $messages;
    }

    /** @param list<array{user: int, kind: string, title: string, body: ?string, ticker: ?string, link: ?string}> $messages */
    private function insert(array $messages, ?float $simTime): void
    {
        $conn = $this->entityManager->getConnection();
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        foreach (array_chunk($messages, self::INSERT_CHUNK) as $chunk) {
            $values = [];
            $params = [];
            foreach ($chunk as $message) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $params,
                    $message['user'],
                    $message['kind'],
                    mb_substr($message['title'], 0, 200),
                    $message['body'],
                    $message['ticker'],
                    $message['link'],
                    $simTime,
                    $now
                );
            }

            $conn->executeStatement(
                'INSERT INTO notifications (user_id, kind, title, body, ticker, link, sim_time, created_at) VALUES ' . implode(', ', $values),
                $params
            );
        }
    }

    /** @param list<array{user: int, kind: string, title: string, body: ?string, ticker: ?string, link: ?string}> $messages */
    private function push(array $messages, ?float $simTime): void
    {
        $dateline = $simTime === null ? null : DistrictCalendar::dateline($simTime);
        $pipeline = $this->redis->multi(\Redis::PIPELINE);

        foreach ($messages as $message) {
            $presentation = Notification::presentation($message['kind']);
            $pipeline->publish(self::CHANNEL, (string) json_encode([
                'type' => 'notification',
                'uid' => (string) $message['user'],
                'kind' => $message['kind'],
                'label' => $presentation['label'],
                'tone' => $presentation['tone'],
                'title' => $message['title'],
                'body' => $message['body'],
                'ticker' => $message['ticker'],
                'link' => $message['link'],
                'dateline' => $dateline,
            ]));
        }

        $pipeline->exec();
    }

    private function committedSimTime(): ?float
    {
        $value = $this->entityManager->getConnection()->fetchOne('SELECT total_time FROM simulation_clock WHERE id = 1');

        return $value === false || $value === null ? null : (float) $value;
    }
}
