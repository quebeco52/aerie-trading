<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\MacroStateDTO;
use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Market\Flow\OrderFlowStoreInterface;
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

        // Newly listed contracts have no identity until they are flushed, and the chain query below reads
        // from the database rather than from the identity map.
        $this->em->flush();

        $marked = 0;
        $curve = $macroState->sovereignCurve();
        $held = $this->heldContractIds();

        foreach ($this->chainsByStock($stocks) as $chain) {
            $stock = $chain['stock'];
            $contracts = $chain['contracts'];

            $quotes = $this->pricingEngine->quoteChain($contracts, $curve, $macroState->totalTime);

            foreach ($contracts as $contract) {
                $quote = $quotes[$contract->getTicker()] ?? null;

                if ($quote === null || !isset($held[$contract->getId()])) {
                    continue;
                }

                $this->pricingEngine->applyMark($contract, $quote);
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

            $this->gammaEngine->refresh($stock, $contracts, $quotes);
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
     * The contracts somebody actually holds, as a lookup.
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
     * OptionDeskMarkingTest guards it.
     *
     * @return array<int, true>
     */
    private function heldContractIds(): array
    {
        return array_fill_keys(
            $this->em->getConnection()->fetchFirstColumn(
                'SELECT DISTINCT option_contract_id FROM user_options WHERE quantity <> 0'
            ),
            true
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
     * One query for the whole market rather than one per name: a sweep touches every listed contract, and
     * fifty round trips to assemble what a single indexed read returns is the difference between a sweep
     * that fits inside a tick and one that does not.
     *
     * @param array<int, Stock> $stocks
     * @return array<int, array{stock: Stock, contracts: array<int, OptionContract>}>
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

        $contracts = $this->em->getRepository(OptionContract::class)->createQueryBuilder('o')
            ->andWhere('o.status = :status')
            ->andWhere('o.stock IN (:stocks)')
            ->setParameter('status', OptionContract::STATUS_ACTIVE)
            ->setParameter('stocks', array_values($byId))
            ->getQuery()
            ->getResult();

        $chains = [];

        foreach ($contracts as $contract) {
            $stockId = $contract->getStock()->getId();

            if ($stockId === null || !isset($byId[$stockId])) {
                continue;
            }

            if (!isset($chains[$stockId])) {
                $chains[$stockId] = ['stock' => $byId[$stockId], 'contracts' => []];
            }

            $chains[$stockId]['contracts'][] = $contract;
        }

        return array_values($chains);
    }
}
