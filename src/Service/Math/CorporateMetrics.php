<?php

namespace App\Service\Math;

use App\Entity\Stock;

/**
 * Extracts domain-specific metric calculations away from the pure stochastic MathUtility.
 */
class CorporateMetrics
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function getIndustryDepreciationRate(string $industry): float
    {
        return \App\Data\Sectors::INDUSTRY_METRICS[$industry]['depreciation'] ?? 0.05;
    }

    public function calculateMarketShare(float $investedCapital, float $nominalGdpIndex, float $samRatio, float $baselineSectorTam = FinancialConstants::BASELINE_SECTOR_TAM): float
    {
        $dynamicSam = $baselineSectorTam * $nominalGdpIndex * $samRatio;
        return $investedCapital / max(1.0, $dynamicSam);
    }

    public function calculateOperatingBase(float $revenue, float $equity, float $floor = 10000000.0): float
    {
        return max($revenue, $equity, $floor);
    }

    public function calculateLiveInvestedCapital(float $equity, float $debt, float $treasury): float
    {
        return max(1.0, max($equity * 0.50, ($equity + $debt - $treasury)));
    }

    /**
     * Calculates variable margin friction from market saturation, following the Penrose (1959) Limit to Growth
     * and Hayashi (1982) Q-Theory with Quadratic Adjustment Costs.
     *
     * As a company pushes its capital footprint ($investedCapital) beyond optimal market share ($optimalThreshold),
     * administrative bloat, coordination friction, and SG&A costs increase quadratically above threshold ($excessRatio^2).
     */
    public function calculateMarketSaturationPenalty(Stock $stock, float $investedCapital, \App\DTO\MacroStateDTO $macroState): float
    {
        $nominalGdpIndex = $macroState->nominalGdpIndex;
        $samRatio = (float) $stock->getSamRatio();
        $marketShare = $this->calculateMarketShare($investedCapital, $nominalGdpIndex, $samRatio);

        $moatFactor = FinancialConstants::SYSTEMIC_MOAT_FACTORS[$stock->getSystemicImportance()]
            ?? FinancialConstants::SYSTEMIC_MOAT_FACTORS['default'];

        // Penrose Effect / Hayashi Quadratic Adjustment Costs: C(I) ∝ I^2
        $optimalThreshold = FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD;
        $excessRatio = max(0.0, ($marketShare - $optimalThreshold) / max(0.01, 1.0 - $optimalThreshold));
        $convexBloat = ($excessRatio * $excessRatio) * FinancialConstants::DISECONOMY_FRICTION_COEFF;

        $saturationPenalty = $convexBloat * $moatFactor;

        return $saturationPenalty;
    }

    /**
     * Calculates diminishing marginal return on capital above optimal market scale, following the
     * Cobb-Douglas Marginal Productivity Model: ROIC_marginal = ROIC_base * (K / K_optimal)^(-α).
     *
     * In neoclassical investment theory (Tobin's Q / Hayashi 1982), marginal productivity of capital (MP_K)
     * decays power-law asymptotically as capital accumulation (K) outpaces serviceable demand (K_optimal).
     * The elasticity parameter α is scaled by the firm's economic moat ($moatFactor) to reflect resistance to saturation.
     */
    public function calculateMarginalReturn(Stock $stock, float $trueReturn, float $saturationPenalty, float $investedCapital, \App\DTO\MacroStateDTO $macroState): float
    {
        $baseReturn = max(0.0, $trueReturn - $saturationPenalty);

        $nominalGdpIndex = $macroState->nominalGdpIndex;
        $samRatio = (float) $stock->getSamRatio();
        $marketShare = $this->calculateMarketShare($investedCapital, $nominalGdpIndex, $samRatio);

        $optimalThreshold = FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD;
        if ($marketShare <= $optimalThreshold) {
            return $baseReturn;
        }

        $moatFactor = FinancialConstants::SYSTEMIC_MOAT_FACTORS[$stock->getSystemicImportance()]
            ?? FinancialConstants::SYSTEMIC_MOAT_FACTORS['default'];

        // Cobb-Douglas diminishing marginal productivity decay above optimal scale: ROIC_marginal = ROIC_base * (K / K_optimal)^(-α)
        $capitalScale = $marketShare / max(0.01, $optimalThreshold);
        $effectiveElasticity = FinancialConstants::CAPITAL_MARGINAL_ELASTICITY * $moatFactor;

        return max(0.0, $baseReturn * pow($capitalScale, -$effectiveElasticity));
    }

    /**
     * Capitalized operating lease liability under IFRS 16 / ASC 842, approximated as a sector-specific
     * multiple of annual revenue. Debt-like for leverage and solvency; the rent itself stays in fixed costs.
     */
    public function calculateLeaseLiability(float $annualRevenue, float $leaseIntensity): float
    {
        return max(0.0, $annualRevenue) * max(0.0, $leaseIntensity);
    }

    /**
     * Seeds the fixed-asset ledger for a firm that has never reported, so depreciation has a real asset
     * account to run against from the first quarter.
     *
     * Net PP&E is invested capital less the other things invested capital is made of (working capital,
     * goodwill and construction in progress), which is the accounting identity read backwards; the floor
     * keeps an asset-light firm from seeding a zero base. Gross cost is then grossed up by the assumed
     * age of the plant, because a firm mid-life carries assets whose historical cost exceeds their book
     * value. Seeding gross and accumulated separately (rather than starting a brand-new plant) matters:
     * a zero-age base would under-depreciate for years and overstate early free cash flow.
     */
    public function seedFixedAssetLedger(
        Stock $stock,
        float $investedCapital,
        float $netWorkingCapital,
        float $goodwill,
        float $constructionInProgress,
        float $assetAgeRatio = FinancialConstants::SEED_ASSET_AGE_RATIO
    ): void {
        $capital = abs($investedCapital);
        $netPpe = max(
            $capital * FinancialConstants::MIN_PPE_SHARE_OF_CAPITAL,
            $capital - max(0.0, $netWorkingCapital) - max(0.0, $goodwill) - max(0.0, $constructionInProgress)
        );

        $age = min(0.90, max(0.0, $assetAgeRatio));
        $grossPpe = $netPpe / (1.0 - $age);

        $stock->setGrossPpe((string) $grossPpe);
        $stock->setAccumulatedDepreciation((string) ($grossPpe - $netPpe));
    }

    public function calculateInterestCoverageRatio(float $ebit, float $interestExpense): float
    {
        if ($interestExpense <= 0.0) {
            return 999.0;
        }
        return $ebit / $interestExpense;
    }

    /**
     * Calculates the severity of market saturation [0.0 to 1.0] by comparing the saturation penalty
     * to the theoretical baseline economic return.
     *
     * In corporate life-cycle theory (DeAngelo, DeAngelo & Stulz 2006; Jensen 1986), as saturation penalty
     * approaches or exceeds the baseline economic return, internal growth opportunities vanish,
     * signaling a structural transition from growth capital retention to mature cash-cow distribution.
     */
    public function calculateSaturationSeverity(float $saturationPenalty, float $trueReturn): float
    {
        if ($saturationPenalty <= 0.0) {
            return 0.0;
        }
        $baselineReturn = max(0.01, $trueReturn + $saturationPenalty);
        return min(1.0, max(0.0, $saturationPenalty / $baselineReturn));
    }

    /**
     * Calculates the effective life-cycle target dividend payout ratio according to the
     * DeAngelo-DeAngelo (2006) Life-Cycle Theory of Dividends.
     *
     * As market saturation severity increases (diminishing marginal returns on reinvestment),
     * the optimal retention ratio collapses and the target payout ratio dynamically expands
     * from baseline towards the mature cash-cow ceiling (LIFE_CYCLE_MAX_PAYOUT_RATIO).
     */
    public function calculateLifeCyclePayoutRatio(float $baselinePayoutRatio, float $saturationSeverity): float
    {
        $maxPayout = FinancialConstants::LIFE_CYCLE_MAX_PAYOUT_RATIO;
        return min($maxPayout, max($baselinePayoutRatio, $baselinePayoutRatio + (($maxPayout - $baselinePayoutRatio) * $saturationSeverity)));
    }
}
