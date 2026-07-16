<?php

namespace App\Service\Math;

use App\Entity\Stock;

/**
 * Extracts domain-specific metric calculations away from the pure stochastic MathUtility.
 */
class CorporateMetrics
{
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
    public function calculateMarketSaturationPenalty(Stock $stock, float $investedCapital, \App\DTO\MacroStateDTO|array $macroState): float
    {
        $dto = $macroState instanceof \App\DTO\MacroStateDTO ? $macroState : \App\DTO\MacroStateDTO::fromArray($macroState);
        $nominalGdpIndex = $dto->nominalGdpIndex;
        $samRatio = (float) $stock->getSamRatio();
        $marketShare = $this->calculateMarketShare($investedCapital, $nominalGdpIndex, $samRatio);

        $moatFactor = FinancialConstants::SYSTEMIC_MOAT_FACTORS[$stock->getSystemicImportance()]
            ?? FinancialConstants::SYSTEMIC_MOAT_FACTORS['default'];

        // Penrose Effect / Hayashi Quadratic Adjustment Costs: C(I) ∝ I^2
        $optimalThreshold = FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD;
        $excessRatio = max(0.0, ($marketShare - $optimalThreshold) / max(0.01, 1.0 - $optimalThreshold));
        $convexBloat = ($excessRatio * $excessRatio) * FinancialConstants::DISECONOMY_FRICTION_COEFF;

        $saturationPenalty = $convexBloat * $moatFactor;

        // Safety limit: cap variable cost penalty at 0.15 to reflect realistic SG&A friction without self-cannibalization
        return min(0.15, $saturationPenalty);
    }

    /**
     * Calculates diminishing marginal return on capital above optimal market scale, following the
     * Cobb-Douglas Marginal Productivity Model: ROIC_marginal = ROIC_base * (K / K_optimal)^(-α).
     *
     * In neoclassical investment theory (Tobin's Q / Hayashi 1982), marginal productivity of capital (MP_K)
     * decays power-law asymptotically as capital accumulation (K) outpaces serviceable demand (K_optimal).
     * The elasticity parameter α is scaled by the firm's economic moat ($moatFactor) to reflect resistance to saturation.
     */
    public function calculateMarginalReturn(Stock $stock, float $trueReturn, float $saturationPenalty, float $investedCapital, \App\DTO\MacroStateDTO|array $macroState): float
    {
        $baseReturn = max(0.0, $trueReturn - $saturationPenalty);

        $dto = $macroState instanceof \App\DTO\MacroStateDTO ? $macroState : \App\DTO\MacroStateDTO::fromArray($macroState);
        $nominalGdpIndex = $dto->nominalGdpIndex;
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
}

