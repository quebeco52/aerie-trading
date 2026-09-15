<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Decides what is listed: which names carry a class, which expiries are open, and which strikes exist.
 *
 * Listing is a market rule rather than a modelling choice, and it is the rule that keeps the chain finite.
 * Exchanges open a class against a float and a trading record, list a handful of near expiries on a shared
 * monthly grid, and add strikes around the money as the underlying moves — they do not list every strike on
 * every name forever. Following that gives roughly a hundred live contracts per optionable name instead of
 * an unbounded surface, which is what makes repricing the whole market inside a tick possible at all.
 *
 * Strikes are never withdrawn once listed. A contract with open interest has to stay tradable so the holder
 * can close it, and the ladder empties on its own when the expiry settles.
 */
final class OptionChainService
{
    // --- Listing Write ---
    /**
     * The columns a newly listed contract is written with, in the order a listing row lays them out.
     *
     * Every column the table requires is named here rather than left to a default, so a listing is one
     * statement whose shape does not depend on the schema's opinion of a missing value. OptionChainListingTest
     * pins the list against the mapping.
     */
    public const LISTING_COLUMNS = [
        'ticker', 'stock_id', 'option_type', 'strike', 'expiry_serial', 'expires_at_time', 'listed_at_time',
        'status', 'price', 'implied_volatility', 'delta', 'gamma', 'vega', 'theta', 'open_interest',
        'structural_open_interest', 'updated_at',
    ];

    /** Contracts written per INSERT. A slice's first pass opens its whole chain, so the batch is sized for that rather than for the quiet case. */
    public const LISTINGS_PER_STATEMENT = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LiquidityEngine $liquidityEngine,
    ) {}

    /**
     * The monthly listing serial a moment in simulation time falls in.
     *
     * Expiries sit on a grid shared by every name, so an expiry is a market-wide event — one day when the
     * whole market's hedges roll — rather than 52 unrelated ones.
     */
    public static function expirySerial(float $currentTime): int
    {
        return (int) floor($currentTime * 12.0);
    }

    /** Simulation time, in years, at which a serial expires. */
    public static function expiryTime(int $serial): float
    {
        return $serial / 12.0;
    }

    /**
     * The earliest simulation time at which a serial could have been listed.
     *
     * Listing only ever opens serials AHEAD of the clock, the furthest of them OPTION_EXPIRY_MONTHS out, so a
     * serial sitting in the table is proof that the clock once stood at least that far back from its expiry.
     * That makes the chain a witness to how far the simulation has actually run — which is the one thing a
     * clock restored from a lossy cache cannot vouch for about itself.
     */
    public static function earliestTimeFor(int $serial): float
    {
        return self::expiryTime($serial - max(FinancialConstants::OPTION_EXPIRY_MONTHS));
    }

    /** The furthest serial ever listed, or null if no chain has been opened yet. */
    public function furthestListedSerial(): ?int
    {
        $serial = $this->em->getConnection()->fetchOne('SELECT MAX(expiry_serial) FROM option_contracts');

        return $serial === null || $serial === false ? null : (int) $serial;
    }

    /**
     * The serials open for listing as of now.
     *
     * @return array<int, int> Ascending.
     */
    public static function listedSerials(float $currentTime): array
    {
        $current = self::expirySerial($currentTime);

        return array_map(
            static fn (int $months): int => $current + $months,
            FinancialConstants::OPTION_EXPIRY_MONTHS
        );
    }

    /**
     * The round increment a name's ladder is struck on.
     *
     * A ladder spaced at a flat fraction of spot would put strikes on numbers nobody quotes. Real ladders
     * snap to round figures, and which round figure depends on the price level, so the increment is the
     * smallest listed one that is at least the target spacing.
     */
    public static function strikeIncrement(float $spot): float
    {
        $target = $spot * FinancialConstants::OPTION_STRIKE_SPACING_FRACTION;
        $increments = FinancialConstants::OPTION_STRIKE_INCREMENTS;

        foreach ($increments as $increment) {
            if ($increment >= $target) {
                return $increment;
            }
        }

        return (float) end($increments);
    }

    /**
     * The strikes listed around a spot price.
     *
     * @return array<int, float> Ascending, on the round increment, inside the ladder width.
     */
    public static function strikeLadder(float $spot): array
    {
        if ($spot <= 0.0) {
            return [];
        }

        $increment = self::strikeIncrement($spot);
        $width = FinancialConstants::OPTION_STRIKE_LADDER_WIDTH;

        $lowest = max($increment, ceil(($spot * (1.0 - $width)) / $increment) * $increment);
        $highest = floor(($spot * (1.0 + $width)) / $increment) * $increment;

        $strikes = [];
        for ($strike = $lowest; $strike <= $highest + ($increment * 1.0e-9); $strike += $increment) {
            $strikes[] = round($strike, 4);
        }

        return $strikes;
    }

    /**
     * Whether a name meets the listing standard.
     *
     * A bankrupt shell, a sub-dollar name whose ladder would be one strike wide, and a name nobody trades
     * all fail for the same reason: there is no market to write contracts against.
     */
    public function isListable(Stock $stock): bool
    {
        if ($stock->isBankrupt()) {
            return false;
        }

        if ((float) $stock->getPrice() < FinancialConstants::OPTION_LISTING_MIN_PRICE) {
            return false;
        }

        return $this->liquidityEngine->structuralDailyVolume($stock) >= FinancialConstants::OPTION_LISTING_MIN_ADV;
    }

    /**
     * The symbol one contract trades under: underlying, expiry serial, side, strike.
     *
     * Readable rather than the OCC's fixed-width encoding, because a player types it.
     */
    public static function contractTicker(string $underlying, int $serial, string $optionType, float $strike): string
    {
        $strikeText = rtrim(rtrim(number_format($strike, 2, '.', ''), '0'), '.');

        return sprintf(
            '%s-%d%s%s',
            $underlying,
            $serial,
            $optionType === OptionContract::TYPE_CALL ? 'C' : 'P',
            $strikeText
        );
    }

    /**
     * Opens whatever is missing from the chains of a whole slice of the market.
     *
     * Idempotent: called each listing sweep, it adds the strikes the underlyings have moved into and leaves
     * everything already open untouched.
     *
     * WRITTEN AS DATA, and for the whole slice at once, because listing is bursty. The expiry grid is monthly
     * and shared, so when a serial rolls, every name in the market wants a fresh expiry on the same pass —
     * a couple of hundred contracts for one slice. Persisting those through the unit of work sent one INSERT
     * each and put the sweep thirty milliseconds over a twenty-millisecond tick every simulated month. One
     * query to see what exists and one INSERT per hundred contracts costs the same whether the slice is
     * quiet or a serial has just rolled.
     *
     * @param array<int, Stock> $stocks The slice being swept.
     * @return int Contracts newly listed.
     */
    public function listChains(array $stocks, float $currentTime): int
    {
        $listable = [];

        foreach ($stocks as $stock) {
            $id = $stock->getId();

            if ($id !== null && $this->isListable($stock)) {
                $listable[$id] = $stock;
            }
        }

        if ($listable === []) {
            return 0;
        }

        $existing = $this->existingTickers(array_keys($listable));
        $serials = self::listedSerials($currentTime);
        $stamp = (new \DateTime())->format('Y-m-d H:i:s');
        $rows = [];

        foreach ($listable as $stockId => $stock) {
            $strikes = self::strikeLadder((float) $stock->getPrice());

            foreach ($serials as $serial) {
                $expiresAt = self::expiryTime($serial);

                foreach ($strikes as $strike) {
                    foreach ([OptionContract::TYPE_CALL, OptionContract::TYPE_PUT] as $optionType) {
                        $ticker = self::contractTicker($stock->getTicker(), $serial, $optionType, $strike);

                        if (isset($existing[$ticker])) {
                            continue;
                        }

                        $existing[$ticker] = true;

                        $rows[] = [
                            $ticker,
                            $stockId,
                            $optionType,
                            (string) $strike,
                            $serial,
                            $expiresAt,
                            $currentTime,
                            OptionContract::STATUS_ACTIVE,
                            '0.00000000',
                            '0.000000',
                            '0.00000000',
                            '0.000000000000',
                            '0.00000000',
                            '0.00000000',
                            0,
                            0,
                            $stamp,
                        ];
                    }
                }
            }
        }

        if ($rows === []) {
            return 0;
        }

        $this->insert($rows);

        return count($rows);
    }

    /**
     * The symbols already taken on a set of names, whatever became of them.
     *
     * Symbols only, and one query for the slice rather than one per name. Listing needs to know which
     * contracts exist, not what they are worth, and hydrating a hundred entities to read one string off each
     * of them meant every sweep loaded the whole chain twice — once here and once to mark it.
     *
     * EVERY status, not just the live ones. A symbol is unique across the whole table, so a settled contract
     * still owns its name and re-listing it is not a duplicate chain but a failed INSERT that takes the whole
     * tick down with it. It is tempting to argue that cannot happen — the expiry grid only ever looks
     * forward, so a serial that has settled is behind us forever — but that argument rests on the clock never
     * going backwards, and the clock does not live here. Simulation time is accumulated in REDIS while the
     * chain is in the database, so anything that loses or rewinds the Redis state (a flush, a restore, a
     * ticker restarted against a half-old stack) puts the grid back over serials whose contracts are still
     * sitting in the table, settled. Asking whether the name is taken costs the same query and does not care.
     *
     * @param array<int, int> $stockIds
     * @return array<string, true>
     */
    private function existingTickers(array $stockIds): array
    {
        return array_fill_keys(
            $this->em->getConnection()->fetchFirstColumn(
                'SELECT ticker FROM option_contracts WHERE stock_id IN (:stocks)',
                ['stocks' => $stockIds],
                ['stocks' => ArrayParameterType::INTEGER]
            ),
            true
        );
    }

    /**
     * Writes listing rows, LISTINGS_PER_STATEMENT at a time.
     *
     * @param array<int, array<int, mixed>> $rows
     */
    private function insert(array $rows): void
    {
        $connection = $this->em->getConnection();
        $columns = implode(', ', self::LISTING_COLUMNS);
        $placeholders = '(' . implode(', ', array_fill(0, count(self::LISTING_COLUMNS), '?')) . ')';

        foreach (array_chunk($rows, self::LISTINGS_PER_STATEMENT) as $chunk) {
            $connection->executeStatement(
                'INSERT INTO option_contracts (' . $columns . ') VALUES '
                . implode(', ', array_fill(0, count($chunk), $placeholders)),
                array_merge(...$chunk)
            );
        }
    }
}
