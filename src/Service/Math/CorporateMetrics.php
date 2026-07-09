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

    public function calculateMarketSaturationPenalty(Stock $stock, float $investedCapital, array $macroState): float
    {
        $nominalGdpIndex = $macroState['nominal_gdp_index'] ?? 1.0;
        $samRatio = (float) $stock->getSamRatio();
        $marketShare = $this->calculateMarketShare($investedCapital, $nominalGdpIndex, $samRatio);

        $moatFactor = FinancialConstants::SYSTEMIC_MOAT_FACTORS[$stock->getSystemicImportance()]
            ?? FinancialConstants::SYSTEMIC_MOAT_FACTORS['default'];

        // DISECONOMIES OF SCALE / PENROSE EFFECT
        // As a company pushes its capital footprint beyond its optimal Serviceable Addressable Market (SAM),
        // coordination friction and administrative bloat increase quadratically (convex curve) above threshold.
        $optimalThreshold = FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD;
        $excessRatio = max(0.0, ($marketShare - $optimalThreshold) / max(0.01, 1.0 - $optimalThreshold));
        $convexBloat = ($excessRatio * $excessRatio) * FinancialConstants::DISECONOMY_FRICTION_COEFF;

        $saturationPenalty = $convexBloat * $moatFactor;

        // Safety limit
        return min(0.25, $saturationPenalty);
    }
}
