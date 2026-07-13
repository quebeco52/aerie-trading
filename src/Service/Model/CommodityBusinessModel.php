<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Heavy Extractors and Refiners (Oil, Copper, Steel).
 * 
 * Financial Physics:
 * - Ultimate "Price Takers." They have zero ability to set their own prices.
 * - Revenue and margins are violently driven by global supply and demand (The Commodity Supercycle).
 * - INFLATION IS A BLESSING: While standard corporates get crushed by supply chain inflation, 
 *   commodities *are* the supply chain, meaning their margins explode upwards during inflationary spikes.
 */
class CommodityBusinessModel extends StandardCorporateBusinessModel
{
    // --- Dual-Stream Commodity Architecture ---
    /** Baseline fraction of revenue tied to physical extraction and production volume. */
    public const EXTRACTION_REVENUE_WEIGHT = 0.50;
    /** Baseline fraction of revenue tied to global commodity spot pricing and inflation premium. */
    public const SPOT_PRICE_WEIGHT         = 0.50;
    /** Baseline multiplier scaling spot price sensitivity to macro inflation spikes. */
    public const SPOT_PRICE_SENSITIVITY    = 1.00;

    // --- Commodity Spot Price & Inflation Physics ---
    /** Volatility multiplier for top-line revenue shocks driven by global commodity spot prices. */
    public const REVENUE_VARIANCE_SCALAR   = 0.25;
    /** Sensitivity scalar scaling excess inflation with stock beta to determine spot price revenue bonus. */
    public const INFLATION_BONUS_SCALAR    = 1.00;

    // --- Analyst Visibility & Error ---
    /** Base analyst visibility into opaque physical extraction volumes and refinery yields. */
    public const ANALYST_BASE_VISIBILITY   = 0.20;
    /** Standard deviation of analyst estimation error for quarterly extraction volumes. */
    public const ANALYST_ERROR_STD_DEV     = 0.05;

    // --- Capital Reinvestment & Asset Depreciation Physics ---
    /** Quarterly efficiency decay rate per unit of underinvestment below replacement CapEx. */
    public const DEPRECIATION_DECAY_RATE      = 0.025;
    /** Quarterly efficiency gain scalar per unit of logarithmic overinvestment above replacement CapEx. */
    public const MODERNIZATION_GAIN_RATE      = 0.012;
    /** Structural minimum operating margin floor under extreme mining/drilling equipment aging. */
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.02;
    /** Structural maximum operating margin ceiling for state-of-the-art mining/drilling operations. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.32;

    public function getMacroPhysics(Stock $stock, array &$macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        
        // CRITICAL FIX: Commodities are absolute price takers. They have zero traditional pricing power.
        // We set this to 1.0 because their top-line revenue is already dynamically forced up and down 
        // by global spot prices ($inflationBonus) during the Idiosyncratic Shock phase. 
        // Setting this higher would result in massive, compounded double-dipping on inflation.
        $physics['pricing_power_multiplier'] = 1.0;
        
        return $physics;
    }

    /**
     * Commodity revenues are highly volatile due to wild swings in global spot prices.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $params = $this->resolveModelParameters($stock, [
            'extraction_revenue_weight' => self::EXTRACTION_REVENUE_WEIGHT,
            'spot_price_weight'         => self::SPOT_PRICE_WEIGHT,
            'spot_price_sensitivity'    => self::SPOT_PRICE_SENSITIVITY,
        ]);

        $extractionWeight = $params['extraction_revenue_weight'];
        $spotWeight       = $params['spot_price_weight'];
        $spotSensitivity  = $params['spot_price_sensitivity'];

        // Independent stream Z-scores
        $extractionZ = $mathUtility->generateStandardNormal(); // Physical extraction/refining volume variance
        $spotZ       = $mathUtility->generateStandardNormal(); // Global commodity spot price deviations

        // Higher top-line variance compared to standard retail/manufacturing
        $extractionRevenue = $expectedRevenue * $extractionWeight * (1.0 + ($extractionZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));

        // The Inflation Exposure:
        // While standard corporates get crushed by supply chain inflation, commodities *are* the supply chain.
        // Their margins explode upwards during inflationary spikes as spot prices rise, and violently contract during deflation.
        $inflation = $macroState['inflation_ema'] ?? MacroEngine::TARGET_INFLATION;
        $inflationBonus = ($inflation - MacroEngine::TARGET_INFLATION) * abs((float) $stock->getBeta()) * self::INFLATION_BONUS_SCALAR * $spotSensitivity;

        $spotRevenue   = $expectedRevenue * $spotWeight * (1.0 + ($spotZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $inflationBonus);
        $actualRevenue = max(0.0, $extractionRevenue + $spotRevenue);

        // CRITICAL FINANCIAL FIX:
        // Variable extraction costs (labor, diesel, equipment) scale with the physical volume stream produced ($extractionRevenue),
        // NOT the wildly fluctuating global spot price of the refined commodity.
        // However, because $realizedVariableMargin is calculated by the Engine as a percentage of TOTAL structural revenue,
        // we must normalize it against the extraction weight. Otherwise, commodities with a 50% extraction weight
        // would magically see their variable costs cut in half, resulting in 200%+ ROIC explosions (e.g. CASC).
        $extractionVariableMargin = $extractionWeight > 0 ? ($realizedVariableMargin / $extractionWeight) : $realizedVariableMargin;
        $actualVariableCosts = $extractionRevenue * $extractionVariableMargin;
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        // Analyst Visibility
        // Commodity spot prices and inflation are public. Physical extraction volumes ($extractionZ) are ~20% visible.
        $analystError = $mathUtility->generateStandardNormal() * self::ANALYST_ERROR_STD_DEV;
        $dynamicVisibility = min(1.0, max(0.0, self::ANALYST_BASE_VISIBILITY + $analystError));
        $analystExpectedExtraction = $expectedRevenue * $extractionWeight * (1.0 + ($extractionZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR) * $dynamicVisibility));
        $analystExpectedSpot       = $expectedRevenue * $spotWeight * (1.0 + $inflationBonus);
        $analystExpectedRevenue    = max(0.0, $analystExpectedExtraction + $analystExpectedSpot);
        $analystExpectedVariableCosts = $analystExpectedExtraction * $extractionVariableMargin;

        $primaryShockZ = abs($extractionZ) > abs($spotZ) ? $extractionZ : $spotZ;

        return [
            'actual_revenue'                  => $actualRevenue,
            'actual_variable_costs'           => $actualVariableCosts,
            'analyst_expected_revenue'        => $analystExpectedRevenue,
            'analyst_expected_variable_costs' => $analystExpectedVariableCosts,
            'ebit'                            => $ebit,
            'primary_shock_z'                 => $primaryShockZ
        ];
    }

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        return 0.15; // Physical mining inventory holding & bulk refining working capital
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            $decayRate = self::DEPRECIATION_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            $modGain = self::MODERNIZATION_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}