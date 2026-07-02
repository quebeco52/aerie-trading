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

    public function calculateMarketShare(float $investedCapital, float $nominalGdpIndex, float $samRatio, float $baselineSectorTam = 2000000000000.0): float
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

        $moat = match ($stock->getSystemicImportance()) {
            'titan'    => 0.30,
            'systemic' => 0.75,
            'base'     => 0.90,
            default    => 1.00,
        };

        // COURNOT & HHI MARKET SATURATION
        // In a Cournot oligopoly, industry margins are proportional to HHI / Demand Elasticity.
        // However, this engine penalizes firms that attempt to artificially inflate their market share 
        // beyond natural Cournot equilibrium via sheer capital bloat (oversupply).
        // We calculate the firm's isolated HHI contribution (s_i^2).
        $firmHhiContribution = $marketShare * $marketShare;
        
        // As the firm pushes its isolated HHI towards a pure monopoly (1.0) or oversupplies the TAM (>1.0),
        // the marginal cost to steal the remaining fractional market share approaches infinity.
        // We use a Cournot deadweight loss derivation to model this exponential margin compression.
        $demandElasticity = 1.25;
        $cournotDeadweightLoss = ($firmHhiContribution * $firmHhiContribution) / $demandElasticity;
        
        $saturationPenalty = $cournotDeadweightLoss * $moat;
        
        return min(1.25, $saturationPenalty);
    }
}
