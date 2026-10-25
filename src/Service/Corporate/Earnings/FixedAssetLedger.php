<?php

declare(strict_types=1);

namespace App\Service\Corporate\Earnings;

use App\Data\Macro\CapitalGoodsPrices;
use App\DTO\EarningsSimulationContext;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\EarningsEngine;

/**
 * The plant's book (ASC 360): the depreciation it is charged, the write-downs it takes, the replacement cost of
 * keeping it, and the perpetual-inventory roll-forward of gross PP&E, accumulated depreciation and the price vintage
 * it was bought at. A lender keeps no plant ledger.
 */
final class FixedAssetLedger
{
    // --- Capital-Goods Prices (BEA Fixed Assets, App\Data\Macro\CapitalGoodsPrices) ---
    /**
     * Carried stream-state key: simulated year the firm's capital-goods relative price was first read, where it is 1.
     */
    public const STATE_CAPITAL_GOODS_ANCHOR_TIME = EarningsEngine::CARRIED_STATE_PREFIX . 'capital_goods_anchor_time';
    /**
     * Carried stream-state key: the plant's capex-weighted capital-goods price against the GDP deflator, 1 at the
     * anchor.
     */
    public const STATE_PPE_RELATIVE_PRICE = EarningsEngine::CARRIED_STATE_PREFIX . 'ppe_relative_price';

    public function __construct(
        private readonly CorporateMetrics $corporateMetrics,
    ) {}

    /**
     * The quarter's depreciation on the plant's schedule, which is what it charges at structural output
     * (utilization 1.0). This is the amount carved out of the cash cost base; calculateDepreciation()
     * charges it as it stands, or scales it by output for a units-of-production plant.
     */
    public function resolveStructuralDepreciation(EarningsSimulationContext $ctx): float
    {
        return max(0.0, $ctx->strategy->getDepreciableBase($ctx->stock)) * $this->resolveDepreciationRate($ctx) / 4.0;
    }

    /** Annual declining-balance depreciation rate: the stock's own rate, else its industry's. */
    public function resolveDepreciationRate(EarningsSimulationContext $ctx): float
    {
        $custom = (float) $ctx->stock->getDepreciationRate();

        return $custom > 0.0
            ? $custom
            : $this->corporateMetrics->getIndustryDepreciationRate($ctx->stock->getIndustry() ?: 'General');
    }

    /**
     * Depreciation on the net book value of PP&E (ASC 360). A plant wears out on a schedule, so the charge is the
     * same however hard the quarter runs it: a fixed cost, and a demand swing reaches EBIT in full. A plant used up
     * by extraction is charged by units of production instead, so its charge follows output, and analysts forecast
     * it on the output the quarter would have run at before its own shocks.
     *
     * The base is net PP&E, not invested capital. Goodwill is never depreciated — it is impairment-tested
     * annually, which the engine already does — and working capital does not wear out. Construction in progress is
     * likewise excluded: an asset not yet placed in service earns nothing and depreciates nothing.
     */
    public function calculateDepreciation(EarningsSimulationContext $ctx): void
    {
        $ctx->quarterlyDepreciation = $ctx->structuralDepreciation;
        $ctx->expectedDepreciation = $ctx->structuralDepreciation;
        if ($ctx->strategy->depreciatesByUnitsOfProduction()) {
            $ctx->quarterlyDepreciation *= $ctx->capacityUtilization * $ctx->sectorActivityFactor * $ctx->plantLoad;
            $ctx->expectedDepreciation *= $ctx->capacityUtilizationBeforeShocks * $ctx->sectorActivityFactor * $ctx->plantLoad;
        }
    }

    /**
     * Writes down the long-lived assets the business model's own impairment test found impaired (ASC 360-10): a
     * builder's land the homes on it can no longer pay for. Like the inventory write-down it sits in operating
     * expense, is non-cash and is not in the analyst forecast. It is held in accumulated depreciation and
     * impairment, so the carrying value and the depreciation struck on it both fall.
     */
    public function applyPlantImpairment(EarningsSimulationContext $ctx): void
    {
        $stock = $ctx->stock;
        $charge = $stock->getGrossPpe() === null ? 0.0 : min($stock->getNetPpe(), max(0.0, $ctx->plantImpairment));
        $ctx->plantImpairment = $charge;
        if ($charge <= 0.0) {
            return;
        }

        $stock->setAccumulatedDepreciation((string) ((float) $stock->getAccumulatedDepreciation() + $charge));
        $ctx->ebitda -= $charge;
        $ctx->ebit -= $charge;
        $ctx->impairmentCharges += $charge;
    }

    /**
     * How far a maintenance dollar has to stretch: the current capital-goods price level over the price
     * level the existing plant was bought at. One when prices have not moved since the plant was built.
     *
     * The vintage is seeded to the current level for a firm that has never reported, so nobody inherits a
     * replacement bill for inflation that happened before they existed.
     */
    public function resolveReplacementCostRatio(EarningsSimulationContext $ctx): float
    {
        $stock = $ctx->stock;

        // No plant, no replacement cost. Financial models never open a fixed-asset ledger, so their vintage
        // would never roll forward and the ratio would climb with the price level forever, charging a bank
        // an ever-growing cash premium to replace machinery it does not own.
        if ($stock->getGrossPpe() === null) {
            return 1.0;
        }

        $currentDeflator = max(0.01, $ctx->macroState->gdpDeflator);
        $vintage = $stock->getPpeVintageDeflator();

        if ($vintage === null || (float) $vintage <= 0.0) {
            $stock->setPpeVintageDeflator((string) $currentDeflator);

            return 1.0;
        }

        // Replacement is bought at today's capital-goods prices, which drift against the deflator by industry.
        return max(0.0, ($currentDeflator / (float) $vintage) * ($this->resolveCapitalGoodsRelativePrice($ctx) / $this->resolvePpeRelativePrice($ctx)));
    }

    /**
     * The firm's capital goods priced against the GDP deflator: its industry's measured drift (BEA Fixed Assets,
     * CapitalGoodsPrices) compounded from the year the firm was first read, where it is 1. Measuring from the firm's
     * own anchor lets a running game take the drift on without restating the plant it already owns.
     */
    private function resolveCapitalGoodsRelativePrice(EarningsSimulationContext $ctx): float
    {
        $state = $ctx->stock->getEarningsMomentumZ() ?? [];
        $anchor = (float) ($state[self::STATE_CAPITAL_GOODS_ANCHOR_TIME] ?? $ctx->macroState->totalTime);

        return exp(CapitalGoodsPrices::driftFor($ctx->strategy) * ($ctx->macroState->totalTime - $anchor));
    }

    /** The relative price the plant on the books was bought at, capex-weighted; today's, for a firm not yet tracked. */
    private function resolvePpeRelativePrice(EarningsSimulationContext $ctx): float
    {
        $state = $ctx->stock->getEarningsMomentumZ() ?? [];

        return max(0.01, (float) ($state[self::STATE_PPE_RELATIVE_PRICE] ?? $this->resolveCapitalGoodsRelativePrice($ctx)));
    }

    /**
     * Perpetual-inventory roll-forward of the fixed-asset ledger.
     *
     * Gross cost rises by the capital actually placed in service this quarter: CapEx that buys an asset
     * outright, plus construction finally completed. Accumulated depreciation rises by the quarter's charge.
     *
     * Fully depreciated cost is then retired from both sides at the useful-life rate. Without retirement the
     * gross balance would grow forever while net book value plateaued, so the asset-age ratio would march to
     * 1.0 and never come back — a firm that had replaced its entire plant would still look ancient. Retiring
     * a cohort each quarter is what a real fixed-asset register does when equipment reaches the end of its
     * life and leaves the books.
     */
    public function rollForwardFixedAssetLedger(EarningsSimulationContext $ctx, float $directCapex): void
    {
        $stock = $ctx->stock;
        if ($stock->getGrossPpe() === null) {
            return; // Financial balance sheets never open a plant ledger.
        }

        $openingNetPpe = $stock->getNetPpe();
        $additions = max(0.0, $directCapex) + max(0.0, $ctx->completedCip);

        $grossPpe = (float) $stock->getGrossPpe() + $additions;
        $accumulated = (float) $stock->getAccumulatedDepreciation() + max(0.0, $ctx->quarterlyDepreciation);

        // The plant's vintage is the CapEx-weighted price level it was bought at: this quarter's additions
        // come in at today's prices and pull the average forward, while the assets still on the books keep
        // theirs. A firm replacing its plant steadily converges on the current price level; one that has
        // stopped investing keeps an old vintage, and its eventual replacement bill grows accordingly.
        $currentDeflator = max(0.01, $ctx->macroState->gdpDeflator);
        $vintage = (float) ($stock->getPpeVintageDeflator() ?? $currentDeflator);
        $vintageBase = max(0.0, $openingNetPpe) + $additions;
        if ($vintageBase > 0.0) {
            $vintage = ((max(0.0, $openingNetPpe) * $vintage) + ($additions * $currentDeflator)) / $vintageBase;
        }
        $stock->setPpeVintageDeflator((string) max(0.01, $vintage));

        // The same weighting on the capital-goods price against the deflator, kept on the stream map.
        $relativePrice = $this->resolveCapitalGoodsRelativePrice($ctx);
        $ppeRelativePrice = $this->resolvePpeRelativePrice($ctx);
        if ($vintageBase > 0.0) {
            $ppeRelativePrice = ((max(0.0, $openingNetPpe) * $ppeRelativePrice) + ($additions * $relativePrice)) / $vintageBase;
        }
        $state = $stock->getEarningsMomentumZ() ?? [];
        $state[self::STATE_CAPITAL_GOODS_ANCHOR_TIME] = (float) ($state[self::STATE_CAPITAL_GOODS_ANCHOR_TIME] ?? $ctx->macroState->totalTime);
        $state[self::STATE_PPE_RELATIVE_PRICE] = max(0.01, $ppeRelativePrice);
        $stock->setEarningsMomentumZ($state);

        $retired = min($accumulated, $grossPpe * $this->resolveDepreciationRate($ctx) / 4.0);
        $grossPpe -= $retired;
        $accumulated -= $retired;

        $stock->setGrossPpe((string) max(0.0, $grossPpe));
        $stock->setAccumulatedDepreciation((string) max(0.0, min($accumulated, $grossPpe)));

        // New assets enter the tax basis at cost, exactly as they enter the book ledger, and then start
        // depreciating on their own accelerated schedule from next quarter.
        if ($additions > 0.0 && $stock->getPpeTaxBasis() !== null) {
            $stock->setPpeTaxBasis((string) ((float) $stock->getPpeTaxBasis() + $additions));
        }
    }
}
