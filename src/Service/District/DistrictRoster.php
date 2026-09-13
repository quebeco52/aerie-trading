<?php

declare(strict_types=1);

namespace App\Service\District;

use App\Data\DistrictMap;
use App\Entity\Stock;

/**
 * The street's membership register: WHO holds frontage on Glasswater Row, and in what order, is
 * decided once per reconstitution and frozen in Redis until the next one.
 *
 * Indices reconstitute on a calendar, not on every print, and the street is an index made
 * visible. Freezing the roster is what makes it one: every player sees the same street, a reload
 * never moves a building, a company that goes bankrupt mid-quarter stands as a defunct shell until
 * the next reconstitution evicts it, and a riser below the cut waits its turn. Only the rank
 * plates, windows, heights and masonry stay live between reconstitutions.
 *
 * The cadence is DistrictMap::RECONSTITUTIONS_PER_YEAR, counted in the ticker's own tick count so
 * it lands on the same boundary as the quarterly macro snapshot. MarketTickerCommand calls
 * reconstitute() on that tick and broadcasts the result; DistrictController reads frontageFor()
 * on every render. MarketResetCommand's Redis flush clears the register, so a fresh market takes
 * its first roster on the first render or the first tick, whichever comes first.
 */
class DistrictRoster
{
    // --- Storage ---
    /** Redis key holding the frozen roster: {tick, tickers}. Cleared by the market reset's flush. */
    public const REDIS_KEY = 'district_roster';

    public function __construct(
        private readonly \Redis $redis,
        private readonly DistrictWardComposer $composer,
        private readonly int $ticksPerYear,
        private readonly int $tickIntervalUs,
    ) {}

    /** Ticks between reconstitutions — a simulated quarter at the default cadence. */
    public static function intervalTicks(int $ticksPerYear): int
    {
        return max(1, intdiv($ticksPerYear, DistrictMap::RECONSTITUTIONS_PER_YEAR));
    }

    /** Whether this tick is a reconstitution boundary. Tick 0 is one: a fresh market takes its first roster. */
    public static function isReconstitutionTick(int $tick, int $ticksPerYear): bool
    {
        return $tick % self::intervalTicks($ticksPerYear) === 0;
    }

    /** The first reconstitution boundary strictly after $tick. */
    public static function nextReconstitutionTick(int $tick, int $ticksPerYear): int
    {
        $interval = self::intervalTicks($ticksPerYear);

        return (intdiv($tick, $interval) + 1) * $interval;
    }

    /**
     * Re-ranks the street from live market caps, stores the new order, and reports who moved.
     * Promotion and eviction are read against the stored roster, so the first reconstitution of
     * a fresh market reports nobody.
     *
     * @param  Stock[] $stocks the listed universe
     * @return array{tick: int, tickers: list<string>, promoted: list<string>, evicted: list<string>}
     */
    public function reconstitute(array $stocks, int $tick): array
    {
        $previous = $this->current();
        $tickers = $this->composer->rosterOrder($stocks);

        $this->redis->set(self::REDIS_KEY, json_encode(['tick' => $tick, 'tickers' => $tickers]));

        $before = $previous === null ? $tickers : $previous['tickers'];

        return [
            'tick' => $tick,
            'tickers' => $tickers,
            'promoted' => array_values(array_diff($tickers, $before)),
            'evicted' => array_values(array_diff($before, $tickers)),
        ];
    }

    /**
     * The stored roster, or null when none has been taken since the last reset.
     *
     * @return array{tick: int, tickers: list<string>}|null
     */
    public function current(): ?array
    {
        $raw = $this->redis->get(self::REDIS_KEY);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded) || !isset($decoded['tickers']) || !is_array($decoded['tickers'])) {
            return null;
        }

        return [
            'tick' => (int) ($decoded['tick'] ?? 0),
            'tickers' => array_values(array_map('strval', $decoded['tickers'])),
        ];
    }

    /**
     * Frontage for a page render: the frozen order laid out against today's universe. With no
     * roster on record (fresh market, or a render before the ticker's first tick) one is taken
     * now, so the ticker and the page never disagree about who is on the street.
     *
     * @param  Stock[] $stocks
     * @return array{
     *     slots: list<array{ticker: string, x: int, width: int, row: int, rank: int}>,
     *     viewboxWidth: int,
     *     rowCount: int,
     * }
     */
    public function frontageFor(array $stocks, int $tick): array
    {
        $roster = $this->current() ?? $this->reconstitute($stocks, $tick);

        return $this->composer->composeFrontageForRoster($stocks, $roster['tickers']);
    }

    /**
     * The reconstitution calendar as the page and its countdown tile need it.
     *
     * @return array{
     *     lastTick: int,
     *     nextTick: int,
     *     intervalTicks: int,
     *     ticksPerYear: int,
     *     secondsPerTick: float,
     * }
     */
    public function schedule(int $tick): array
    {
        $roster = $this->current();

        return [
            'lastTick' => $roster['tick'] ?? 0,
            'nextTick' => self::nextReconstitutionTick($tick, $this->ticksPerYear),
            'intervalTicks' => self::intervalTicks($this->ticksPerYear),
            'ticksPerYear' => $this->ticksPerYear,
            'secondsPerTick' => $this->tickIntervalUs / 1_000_000,
        ];
    }
}
