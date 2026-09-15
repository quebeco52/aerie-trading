<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\MacroStateDTO;
use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Market\Flow\OrderFlowStoreInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Runs the option desk for one tick: settlement, listing, marking, the public's book, and the hedge.
 *
 * One entry point because the five jobs are ordered and the order is load-bearing. Expiries settle before
 * anything is listed, so a serial that has just rolled off is gone before its replacement appears. Listing
 * happens before marking, so a strike the underlying has just moved into is quoted the same sweep it opens.
 * Demand and the desk's exposure are both computed from the marks, so both come last — and the exposure has
 * to come after demand, because it is the public's position that the desk is short.
 *
 * The hedge runs on its own, faster cadence: a desk re-hedges when the price moves, not when its analytics
 * are refreshed.
 *
 * COST. A full sweep hydrates and rewrites every listed contract in the market — five thousand of them at
 * the default roster — and it was first wired to the history tick, which at the shipped tick rate fires
 * roughly eight times a second. That is forty-five thousand option UPDATEs a second for a table nothing
 * reads at that resolution, and it made the whole ticker crawl. Two things fix it and neither costs any
 * fidelity:
 *
 *   - The sweep runs on its own slow interval and processes a SLICE of the market each pass, so the whole
 *     market is remarked about once a simulated week and no single pass stalls the tick. Nothing downstream
 *     wants a fresher mark than that: an order is repriced against the live underlying when it is sent, the
 *     public's demand has a time constant of weeks, and an expiry is a month apart.
 *   - Settlement stays on EVERY pass, unsliced. It is one indexed query that returns nothing almost every
 *     time, and a contract that has expired must not wait for its slice to come round before it settles.
 *   - The chain is read as rows and written back as data. Hydrating it made every contract the public
 *     nudged a dirty entity, and the next flush paid one UPDATE for each; see chainsByStock().
 */
final class OptionDeskService
{
    // --- Sweep Cadence ---

    /**
     * Passes the market is spread over. Each pass remarks one slice, so a name is revisited every this many
     * passes and the cost of a sweep is divided by it rather than landing on one tick.
     */
    public const SWEEP_SLICES = 8;

    /** Sweeps of the WHOLE market per simulated year; a name's chain is remarked about once a sim week. */
    public const SWEEPS_PER_YEAR = 52;

    /**
     * Ticks between passes, given a tick rate.
     *
     * @param int $ticksPerYear The simulation's tick rate.
     */
    public static function sweepIntervalTicks(int $ticksPerYear): int
    {
        return max(1, (int) ($ticksPerYear / (self::SWEEPS_PER_YEAR * self::SWEEP_SLICES)));
    }

    /** Which slice of the market a pass belongs to. */
    public static function sweepSlice(int $tickCount, int $intervalTicks): int
    {
        return (int) (($tickCount / max(1, $intervalTicks)) % self::SWEEP_SLICES);
    }

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OptionChainService $chainService,
        private readonly OptionPricingEngine $pricingEngine,
        private readonly OptionSettlementEngine $settlementEngine,
        private readonly OptionDemandEngine $demandEngine,
        private readonly DealerGammaEngine $gammaEngine,
        private readonly OrderFlowStoreInterface $orderFlow,
    ) {}

    /**
     * Settles the market, then lists, marks and re-measures one slice of it.
     *
     * @param array<int, Stock> $stocks The ticker's working set.
     * @param float             $dt     Years since THIS SLICE was last swept, not since the last pass.
     * @param int               $slice  Which slice of the market to process.
     * @return array{settled: int, listed: int, marked: int, exercised: int}
     */
    public function sweep(array $stocks, MacroStateDTO $macroState, float $dt, int $slice = 0): array
    {
        $settlements = $this->settlementEngine->settle($macroState->totalTime);

        $stocks = $this->slice($stocks, $slice);

        $listed = 0;
        foreach ($stocks as $stock) {
            $listed += count($this->chainService->listChain($stock, $macroState->totalTime));
        }

        // Newly listed contracts have no row until they are flushed, and the chain below is read from the
        // table rather than from the identity map.
        $this->em->flush();

        $marked = 0;
        $curve = $macroState->sovereignCurve();
        $held = $this->heldContractTickers();
        $connection = $this->em->getConnection();

        /** @var array<int, array<int, mixed>> $bookRows Contract id => [structural open interest], where it moved. */
        $bookRows = [];

        foreach ($this->chainsByStock($stocks) as $chain) {
            $stock = $chain['stock'];
            $contracts = $chain['contracts'];
            $ids = $chain['ids'];

            $quotes = $this->pricingEngine->quoteChain($contracts, $curve, $macroState->totalTime);

            $bookBefore = [];

            foreach ($contracts as $contract) {
                $ticker = $contract->getTicker();
                $bookBefore[$ticker] = (int) $contract->getStructuralOpenInterest();
                $quote = $quotes[$ticker] ?? null;

                if ($quote === null || !isset($held[$ticker])) {
                    continue;
                }

                $this->pricingEngine->applyMark($contract, $quote);
                $this->writeMark($connection, $ids[$ticker], $contract);
                $marked++;
            }

            $this->demandEngine->evolve(
                $stock,
                $contracts,
                $quotes,
                $macroState->marketVolatility,
                MacroEngine::MACRO_VOL_BASE_ANCHOR,
                $dt
            );

            // Only a book that actually moved is written. Most of a chain sits still on any one pass, and
            // rewriting an unchanged row is the whole cost this pass is trying not to pay.
            foreach ($contracts as $contract) {
                $ticker = $contract->getTicker();
                $after = (int) $contract->getStructuralOpenInterest();

                if ($after !== $bookBefore[$ticker]) {
                    $bookRows[$ids[$ticker]] = [$after];
                }
            }

            $this->gammaEngine->refresh($stock, $contracts, $quotes);
        }

        if ($bookRows !== []) {
            BulkRowUpdate::apply($connection, 'option_contracts', ['structural_open_interest'], $bookRows);
        }

        return [
            'settled' => count($settlements),
            'listed' => $listed,
            'marked' => $marked,
            'exercised' => count(array_filter($settlements, static fn (array $s): bool => $s['exercised'])),
        ];
    }

    /**
     * Places the desk's hedge for the move since it last hedged, in every name that carries a chain.
     *
     * The flow joins the tick's order flow rather than moving the price itself, so it is charged the same
     * impact as a player's order and the diffusion gives back the variance it supplies.
     *
     * Need not run on every tick. The hedge is gamma times the move since the last one, which TELESCOPES:
     * hedging once across six ticks trades exactly what six hedges across the same six ticks would have,
     * because the intermediate reference prices cancel. Only the granularity of when the flow arrives
     * changes, and it arrives inside the same bar either way.
     *
     * @param array<int, Stock> $stocks
     * @return float Total absolute shares hedged, for the tick's diagnostics.
     */
    public function hedge(array $stocks): float
    {
        $traded = 0.0;

        foreach ($this->gammaEngine->hedgeMarket($stocks) as $ticker => $shares) {
            $this->orderFlow->record($ticker, $shares);
            $traded += abs($shares);
        }

        return $traded;
    }

    /**
     * The contracts somebody actually holds, as a lookup by symbol.
     *
     * THE INVARIANT THIS ESTABLISHES: a contract with a position carries a stored mark; one without carries
     * a stale one, and nothing may read it. Every SQL reader of option_contracts.price in this codebase —
     * net worth in its five places, the Rule 4210 requirement, the liquidation ordering, the dashboard —
     * reaches the row by joining THROUGH user_options, so each of them is asking only about contracts that
     * are held by construction. The chain page is the one surface that looks at the rest, and it prices
     * them on read rather than reading a column.
     *
     * Break that invariant and the failure is silent: a new query that reads price for an unheld contract
     * gets whatever the mark was when somebody last had a position in it, which could be years stale.
     * OptionMarkInvariantTest guards it.
     *
     * @return array<string, true>
     */
    private function heldContractTickers(): array
    {
        return array_fill_keys(
            $this->em->getConnection()->fetchFirstColumn(
                'SELECT DISTINCT o.ticker
                 FROM user_options uo
                 JOIN option_contracts o ON o.id = uo.option_contract_id
                 WHERE uo.quantity <> 0'
            ),
            true
        );
    }

    /**
     * Writes one held contract's mark. Few contracts are held, so a row each is fine here; it is the
     * thousands that are not held that must never cost a statement.
     */
    private function writeMark(Connection $connection, int $id, OptionContract $contract): void
    {
        $connection->executeStatement(
            'UPDATE option_contracts
             SET price = ?, implied_volatility = ?, delta = ?, gamma = ?, vega = ?, theta = ?, updated_at = ?
             WHERE id = ?',
            [
                $contract->getPrice(),
                $contract->getImpliedVolatility(),
                $contract->getDelta(),
                $contract->getGamma(),
                $contract->getVega(),
                $contract->getTheta(),
                $contract->getUpdatedAt()->format('Y-m-d H:i:s'),
                $id,
            ]
        );
    }

    /**
     * The names belonging to one pass.
     *
     * Sliced on position in the working set, which the ticker reloads in a stable order, so a name lands in
     * the same slice from one pass to the next and is therefore revisited on a fixed period rather than
     * drifting or being skipped.
     *
     * @param array<int, Stock> $stocks
     * @return array<int, Stock>
     */
    private function slice(array $stocks, int $slice): array
    {
        $stocks = array_values($stocks);
        $selected = [];

        foreach ($stocks as $index => $stock) {
            if ($index % self::SWEEP_SLICES === $slice) {
                $selected[] = $stock;
            }
        }

        return $selected;
    }

    /**
     * The live chain of every name in the working set, grouped by underlying.
     *
     * One query for the whole slice rather than one per name, and rows rather than entities. A sweep touches
     * every listed contract, and hydrating them through the ORM meant two things the tick could not afford:
     * the hydration itself, and — worse — that every contract the public's book had nudged became a dirty
     * entity, and the next flush sent an UPDATE for each one. The contracts here are plain objects built
     * from the rows, so the engines see exactly what they always saw and the unit of work sees nothing.
     * What changed is written back as data, in bulk, by the caller; the row's identity rides alongside so
     * it can be.
     *
     * @param array<int, Stock> $stocks
     * @return array<int, array{stock: Stock, contracts: array<int, OptionContract>, ids: array<string, int>}>
     */
    private function chainsByStock(array $stocks): array
    {
        $byId = [];
        foreach ($stocks as $stock) {
            if ($stock->getId() !== null) {
                $byId[$stock->getId()] = $stock;
            }
        }

        if ($byId === []) {
            return [];
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, ticker, stock_id, option_type, strike, expiry_serial, expires_at_time, listed_at_time,
                    open_interest, structural_open_interest
             FROM option_contracts
             WHERE status = :status AND stock_id IN (:stocks)',
            ['status' => OptionContract::STATUS_ACTIVE, 'stocks' => array_keys($byId)],
            ['stocks' => ArrayParameterType::INTEGER]
        );

        $chains = [];

        foreach ($rows as $row) {
            $stockId = (int) $row['stock_id'];

            if (!isset($byId[$stockId])) {
                continue;
            }

            if (!isset($chains[$stockId])) {
                $chains[$stockId] = ['stock' => $byId[$stockId], 'contracts' => [], 'ids' => []];
            }

            $contract = (new OptionContract())
                ->setTicker((string) $row['ticker'])
                ->setStock($byId[$stockId])
                ->setOptionType((string) $row['option_type'])
                ->setStrike((string) $row['strike'])
                ->setExpirySerial((int) $row['expiry_serial'])
                ->setExpiresAtTime((float) $row['expires_at_time'])
                ->setListedAtTime((float) $row['listed_at_time'])
                ->setStatus(OptionContract::STATUS_ACTIVE)
                ->setOpenInterest((int) $row['open_interest'])
                ->setStructuralOpenInterest((int) $row['structural_open_interest']);

            $chains[$stockId]['contracts'][] = $contract;
            $chains[$stockId]['ids'][(string) $row['ticker']] = (int) $row['id'];
        }

        return array_values($chains);
    }
}
