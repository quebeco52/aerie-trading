<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\MacroStateDTO;
use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Market\Flow\OrderFlowStoreInterface;
use App\Service\Math\MathUtility;
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
 * The hedge itself is on its own cadence, running every tick rather than every sweep: a desk re-hedges when
 * the price moves, not when its analytics are refreshed.
 */
final class OptionDeskService
{
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
     * Settles, lists, marks and re-measures the whole option market.
     *
     * @param array<int, Stock> $stocks The ticker's working set.
     * @param float             $dt     Years since the last sweep.
     * @return array{settled: int, listed: int, marked: int, exercised: int}
     */
    public function sweep(array $stocks, MacroStateDTO $macroState, float $dt): array
    {
        $settlements = $this->settlementEngine->settle($macroState->totalTime);

        $listed = 0;
        foreach ($stocks as $stock) {
            $listed += count($this->chainService->listChain($stock, $macroState->totalTime));
        }

        // Newly listed contracts have no identity until they are flushed, and the chain query below reads
        // from the database rather than from the identity map.
        $this->em->flush();

        $marked = 0;
        $curve = $macroState->sovereignCurve();

        foreach ($this->chainsByStock($stocks) as $chain) {
            $stock = $chain['stock'];
            $contracts = $chain['contracts'];

            $quotes = $this->pricingEngine->quoteChain($contracts, $curve, $macroState->totalTime);

            foreach ($contracts as $contract) {
                $quote = $quotes[$contract->getTicker()] ?? null;

                if ($quote === null) {
                    continue;
                }

                $contract->setPrice(MathUtility::formatDecimal($quote->mark, 8))
                    ->setImpliedVolatility(MathUtility::formatDecimal($quote->impliedVolatility, 6))
                    ->setDelta(MathUtility::formatDecimal($quote->delta, 8))
                    ->setGamma(MathUtility::formatDecimal($quote->gamma, 12))
                    ->setVega(MathUtility::formatDecimal($quote->vega, 8))
                    ->setTheta(MathUtility::formatDecimal($quote->theta, 8))
                    ->setUpdatedAt(new \DateTime());

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
     * @param array<int, Stock> $stocks
     * @return float Total absolute shares hedged, for the tick's diagnostics.
     */
    public function hedge(array $stocks): float
    {
        $traded = 0.0;

        foreach ($stocks as $stock) {
            if ($stock->isBankrupt()) {
                continue;
            }

            $shares = $this->gammaEngine->hedgeFlow($stock);

            if ($shares === 0.0) {
                continue;
            }

            $this->orderFlow->record($stock->getTicker(), $shares);
            $traded += abs($shares);
        }

        return $traded;
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
