<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Entity\Stock;
use App\Repository\StockEventRepository;
use App\Repository\StockRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * What moved a company's shares over the past quarter and year: the market, its peers and the company itself
 * (ReturnAttribution), from weekly closes in the price history.
 *
 * The market is every other listed company weighted by its market value today; the peers are its PeerGroup. History is
 * not restated for splits, so a week holding one has the split's factor taken back out. The answer changes once a
 * simulated week and is kept for that long.
 */
final class MoveAttributionBuilder
{
    // --- Windows ---
    /** Weeks in a simulated year: the grid the history is sampled on. */
    private const WEEKS_PER_YEAR = 52;

    /** Weeks of returns the betas are estimated over: two years. */
    private const ESTIMATION_WEEKS = 104;

    /** The periods shown, by their weeks: a quarter and a year. */
    private const PERIODS = ['Past quarter' => 13, 'Past year' => 52];

    // --- Cache ---
    /** Seconds a computed attribution is kept: past any simulated week at the dev clock. */
    private const CACHE_SECONDS = 21600;

    public function __construct(
        private readonly Connection $connection,
        private readonly StockRepository $stocks,
        private readonly StockEventRepository $events,
        private readonly \Redis $redis,
    ) {}

    /**
     * @return array{beta: float, peerLabel: string, peerName: string, periods: list<array{label: string, total: float, market: float, peers: float, company: float}>}|null
     */
    public function build(Stock $stock): ?array
    {
        if ($stock->isBankrupt()) {
            return null;
        }
        $newest = $this->connection->fetchOne('SELECT MAX(sim_time) FROM stock_history WHERE stock_id = :id', ['id' => $stock->getId()]);
        if ($newest === null || $newest === false) {
            return null;
        }
        $key = sprintf('move_attribution:%s:%d', $stock->getTicker(), (int) floor((float) $newest * self::WEEKS_PER_YEAR));
        $cached = $this->redis->get($key);
        if (is_string($cached)) {
            $decoded = unserialize($cached, ['allowed_classes' => false]);
            if ($decoded === null || self::isAttribution($decoded)) {
                return $decoded;
            }
        }

        $result = $this->compute($stock, (float) $newest);
        $this->redis->setex($key, self::CACHE_SECONDS, serialize($result));

        return $result;
    }

    /**
     * Whether a cached value has the shape build() returns; a value written by an older shape is computed afresh.
     *
     * @phpstan-assert-if-true array{beta: float, peerLabel: string, peerName: string, periods: list<array{label: string, total: float, market: float, peers: float, company: float}>} $value
     */
    private static function isAttribution(mixed $value): bool
    {
        if (!is_array($value) || !is_float($value['beta'] ?? null) || !is_string($value['peerLabel'] ?? null) || !is_string($value['peerName'] ?? null) || !is_array($value['periods'] ?? null) || !array_is_list($value['periods'])) {
            return false;
        }
        foreach ($value['periods'] as $period) {
            if (!is_array($period) || !is_string($period['label'] ?? null)) {
                return false;
            }
            foreach (['total', 'market', 'peers', 'company'] as $part) {
                if (!is_float($period[$part] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array{beta: float, peerLabel: string, peerName: string, periods: list<array{label: string, total: float, market: float, peers: float, company: float}>}|null
     */
    private function compute(Stock $stock, float $newest): ?array
    {
        $listed = array_values(array_filter($this->stocks->findAll(), static fn(Stock $s): bool => !$s->isBankrupt()));
        $others = array_values(array_filter($listed, static fn(Stock $s): bool => $s->getId() !== $stock->getId()));
        ['label' => $peerLabel, 'name' => $peerName, 'members' => $peers] = PeerGroup::of($stock, $listed);

        $from = $newest - (self::ESTIMATION_WEEKS + 1) / self::WEEKS_PER_YEAR;
        $returns = $this->weeklyReturns($listed, $from);
        $own = $returns[(int) $stock->getId()] ?? [];
        $weight = static fn(Stock $s): float => max(0.0, (float) $s->getPrice() * (float) $s->getSharesOutstanding());

        $ownSeries = [];
        $marketSeries = [];
        $peerSeries = [];
        foreach ($own as $week => $r) {
            $market = self::weighted($others, $returns, $week, $weight);
            if ($market === null) {
                continue;
            }
            $ownSeries[] = $r;
            $marketSeries[] = $market;
            $peerSeries[] = self::weighted($peers, $returns, $week, $weight) ?? $market;
        }

        $periods = [];
        $beta = null;
        foreach (self::PERIODS as $label => $weeks) {
            $split = ReturnAttribution::attribute($ownSeries, $marketSeries, $peerSeries, $weeks);
            if ($split === null) {
                return null;
            }
            $beta = $split['beta'];
            $periods[] = ['label' => $label, 'total' => $split['total'], 'market' => $split['market'], 'peers' => $split['peers'], 'company' => $split['company']];
        }

        return $beta === null ? null : ['beta' => $beta, 'peerLabel' => $peerLabel, 'peerName' => $peerName, 'periods' => $periods];
    }

    /**
     * Each company's weekly log returns since $from, keyed by stock id then week, from the last close of each week. A
     * week holding a split or a reverse split has its factor taken back out.
     *
     * @param  list<Stock>                   $stocks
     * @return array<int, array<int, float>>
     */
    private function weeklyReturns(array $stocks, float $from): array
    {
        $ids = array_map(static fn(Stock $s): int => (int) $s->getId(), $stocks);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT h.stock_id, h.sim_time, h.price FROM stock_history h JOIN ('
            . 'SELECT MAX(id) AS id FROM stock_history WHERE stock_id IN (:ids) AND sim_time >= :from GROUP BY stock_id, FLOOR(sim_time * :weeks)'
            . ') w ON w.id = h.id ORDER BY h.stock_id, h.sim_time',
            ['ids' => $ids, 'from' => $from, 'weeks' => self::WEEKS_PER_YEAR],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $splits = [];
        foreach ($this->events->findLatestAmong($stocks, ['SPLIT', 'REVERSE_SPLIT'], 1000) as $event) {
            $time = $event->getSimTime();
            if ($time !== null && $time >= $from && preg_match('/(\d+)-for-(\d+)/', $event->getDescription(), $m) === 1 && (int) $m[1] > 0 && (int) $m[2] > 0) {
                $splits[(int) $event->getStock()->getId()][] = [$time, log((float) $m[1] / (float) $m[2])];
            }
        }

        return self::weeklyLogReturns($rows, $splits);
    }

    /**
     * Weekly log returns from each company's last close of each week, oldest first, keyed by stock id then week. A
     * split's log factor (log 2 for a 2-for-1, log 1/10 for a 1-for-10) is added back in the week that holds it.
     *
     * @param  iterable<array<string, mixed>>                                                    $rows stock_id, sim_time, price
     * @param  array<int, list<array{0: float, 1: float}>>                                       $splits by stock id: [time, log factor]
     * @return array<int, array<int, float>>
     */
    public static function weeklyLogReturns(iterable $rows, array $splits): array
    {
        $returns = [];
        $last = [];
        foreach ($rows as $row) {
            if (!is_numeric($row['stock_id'] ?? null) || !is_numeric($row['sim_time'] ?? null) || !is_numeric($row['price'] ?? null)) {
                continue;
            }
            $id = (int) $row['stock_id'];
            $time = (float) $row['sim_time'];
            $price = (float) $row['price'];
            if ($price <= 0.0) {
                continue;
            }
            if (isset($last[$id])) {
                [$prevTime, $prevPrice] = $last[$id];
                $r = log($price / $prevPrice);
                foreach ($splits[$id] ?? [] as [$splitTime, $logFactor]) {
                    if ($splitTime > $prevTime && $splitTime <= $time) {
                        $r += $logFactor;
                    }
                }
                $returns[$id][(int) floor($time * self::WEEKS_PER_YEAR)] = $r;
            }
            $last[$id] = [$time, $price];
        }

        return $returns;
    }

    /**
     * A group's return in one week, weighted by market value, over the members that traded that week; null for none.
     *
     * @param list<Stock>                   $group
     * @param array<int, array<int, float>> $returns
     * @param \Closure(Stock): float        $weight
     */
    private static function weighted(array $group, array $returns, int $week, \Closure $weight): ?float
    {
        $sum = 0.0;
        $weights = 0.0;
        foreach ($group as $member) {
            $r = $returns[(int) $member->getId()][$week] ?? null;
            if ($r !== null) {
                $w = $weight($member);
                $sum += $w * $r;
                $weights += $w;
            }
        }

        return $weights > 0.0 ? $sum / $weights : null;
    }
}
