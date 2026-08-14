<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Internet Retail & Digital Marketplace Megacorporations.
 * 
 * Financial Physics:
 * - Tri-Stream Architecture:
 *      1. 1st-Party Retail: Buys and sells physical inventory. Low margin, highly exposed to supply chain inflation and recessions.
 *      2. 3rd-Party Fulfillment (The Tollbooth): Charges independent vendors to use their logistics. High margin, high volume.
 *      3. Digital Advertising / Cloud: Monetizes consumer data. Near-100% margin, zero physical overhead.
 * - Margin Cross-Subsidization: The retail division runs at a near loss to dominate market share, subsidized by Ads and 3P fees.
 * - Tail Risk: Labor unionization strikes in fulfillment centers, or sovereign antitrust breakups.
 */
class InternetRetailBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.40; // 1P sales are visible, but 3P/Ads are a black box
    public const BASE_COVERAGE_ERROR = 0.08;

    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.50, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.5,  'dividend_crisis_icr' => 2.00, 'buyback_min_icr' => 2.50, 'reversion_speed' => 0.15, 'moat_spread' => 0.020, 'nwc_intensity' => -0.05, 'capex_completion_rate' => 0.50];
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.04;
    } // E-commerce secular adoption
    public function getCapexCyclicality(): float
    {
        return 1.5;
    } // Massive warehouse and server farm buildouts
    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.40, 'revenue_weight' => 0.60];
    }

    // --- Tri-Stream Architecture Weights ---
    public const FIRST_PARTY_WEIGHT  = 0.45;
    public const THIRD_PARTY_WEIGHT  = 0.40;
    public const DIGITAL_ADS_WEIGHT  = 0.15;

    // --- Stream Variance Scalars ---
    public const FIRST_PARTY_VARIANCE = 0.35; // Highly cyclical (consumers stop buying TVs in a recession)
    public const THIRD_PARTY_VARIANCE = 0.15; // Sticky (vendors must pay the toll to survive)
    public const DIGITAL_ADS_VARIANCE = 0.25; // Scales aggressively with platform traffic

    // --- Margin Architecture ---
    public const THIRD_PARTY_COST_RATIO = 0.40; // Moderate cost (logistics, server compute)
    public const DIGITAL_ADS_COST_RATIO = 0.10; // Pure profit (algorithmic placement)

    // --- Supply Chain & Labor Physics ---
    public const INFLATION_PENALTY_SCALAR = 1.20; // 1P Retail eats the cost of physical goods inflation
    public const WAGE_INFLATION_SCALAR    = 0.80; // Massive warehouse workforce makes them vulnerable to labor shortages

    // --- Tail Risk Events ---
    public const WAREHOUSE_STRIKE_Z_SCORE = -2.20;
    public const WAREHOUSE_STRIKE_PENALTY = 0.08; // Margin hit from crippled logistics/overtime pay

    public const ANTITRUST_FINE_Z_SCORE   = -2.60;
    public const ANTITRUST_FINE_MULT      = 0.90; // Top-line haircut from forced breakups or regulatory bans

    public const VIRAL_HOLIDAY_SURGE_Z    = 2.40;
    public const VIRAL_HOLIDAY_MULT       = 1.15; // Prime Day / Holiday super-cycle

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify global demand shift. We process output gap cyclically per-stream to prevent double-dipping.
        $physics['macro_demand_shift'] = 0.0;
        // Inflation is absorbed as a cost penalty, not passed on (Internet Retailers compete on lowest price).
        $physics['pricing_power_multiplier'] = 1.0;

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            'first_party_weight' => self::FIRST_PARTY_WEIGHT,
            'third_party_weight' => self::THIRD_PARTY_WEIGHT,
            'digital_ads_weight' => self::DIGITAL_ADS_WEIGHT,
        ]);

        $fpWeight  = $params['first_party_weight'];
        $tpWeight  = $params['third_party_weight'];
        $adsWeight = $params['digital_ads_weight'];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new \App\DTO\StreamContext($momentum, $mathUtility);
        $beta = abs((float) $stock->getBeta());

        // Independent stream Z-scores
        $fpZ  = $streams->generateZ('first_party_retail', 0.25);
        $tpZ  = $streams->generateZ('third_party_seller', 0.40); // High persistence tollbooth
        $adsZ = $streams->generateZ('digital_ads_cloud', 0.20);
        $eventZ = $streams->generateZ('event', 0.10);

        // --- Macro Sensitivities ---
        $outputGap = $macroState->outputGapEma;

        // 1P Retail bears the absolute brunt of consumer recessions
        $fpMacroShift = $outputGap * 2.0 * $beta;

        // 3P and Ads are partially insulated, acting as a structural tollbooth
        $tpMacroShift = $outputGap * 0.5 * $beta;

        // --- Tail Risk Events ---
        $revenueMultiplier = 1.0;
        $eventType = null;
        $strikePenalty = 0.0;

        if ($eventZ < self::ANTITRUST_FINE_Z_SCORE) {
            $revenueMultiplier = self::ANTITRUST_FINE_MULT;
            $eventType = ShockEvent::REGULATORY_FINE;
        } elseif ($eventZ < self::WAREHOUSE_STRIKE_Z_SCORE) {
            $strikePenalty = self::WAREHOUSE_STRIKE_PENALTY;
            $eventType = ShockEvent::LABOR_STRIKE;
        } elseif ($eventZ > self::VIRAL_HOLIDAY_SURGE_Z) {
            $revenueMultiplier = self::VIRAL_HOLIDAY_MULT;
            $eventType = ShockEvent::VIRAL_GROWTH;
        }

        // --- Clamped Tri-Stream Revenue Calculation ---
        $fpRevenue  = max(0.0, $expectedRevenue * $fpWeight * (1.0 + ($fpZ * $baselineVol * self::FIRST_PARTY_VARIANCE) + $fpMacroShift) * $revenueMultiplier);
        $tpRevenue  = max(0.0, $expectedRevenue * $tpWeight * (1.0 + ($tpZ * $baselineVol * self::THIRD_PARTY_VARIANCE) + $tpMacroShift) * $revenueMultiplier);

        // Digital Ads scale exponentially with underlying platform traffic (blending FP and TP Z-scores)
        $platformTrafficBonus = ($fpZ * 0.5) + ($tpZ * 0.5);
        $adsRevenue = max(0.0, $expectedRevenue * $adsWeight * (1.0 + ($adsZ * $baselineVol * self::DIGITAL_ADS_VARIANCE) + ($platformTrafficBonus * 0.10)) * $revenueMultiplier);

        $actualRevenue = $fpRevenue + $tpRevenue + $adsRevenue;

        // --- Structural Margin Blending ---
        // Calculate organic costs for the high-margin divisions
        $tpCosts = $tpRevenue * self::THIRD_PARTY_COST_RATIO;
        $adsCosts = $adsRevenue * self::DIGITAL_ADS_COST_RATIO;

        // Back-calculate the 1st Party Retail margin constraints based on the global expectation
        $targetTotalCosts = $expectedRevenue * $realizedVariableMargin;
        $fpBaselineCosts = max(0.0, $targetTotalCosts - $tpCosts - $adsCosts);
        $fpVariableMargin = $expectedRevenue * $fpWeight > 0 ? $fpBaselineCosts / ($expectedRevenue * $fpWeight) : $realizedVariableMargin;

        // Re-blend actual costs based on shocked revenue
        $actualVariableCosts = $tpCosts + $adsCosts + ($fpRevenue * $fpVariableMargin);

        // --- Inflation & Labor Penalties ---
        $inflation = $macroState->inflationEma;

        // Physical goods inflation crushes 1P retail
        $goodsInflationDrag = $inflation > MacroEngine::TARGET_INFLATION
            ? ($inflation - MacroEngine::TARGET_INFLATION) * $beta * self::INFLATION_PENALTY_SCALAR
            : 0.0;

        // Wage inflation crushes the warehouse network (applies to both 1P and 3P fulfillment)
        $unemployment = $macroState->unemploymentRateEma;
        $wageInflationDrag = $unemployment < 0.04
            ? (0.04 - $unemployment) * self::WAGE_INFLATION_SCALAR // Tight labor market forces wage hikes
            : 0.0;

        $totalMacroCostDrag = ($goodsInflationDrag * $fpWeight) + ($wageInflationDrag * ($fpWeight + $tpWeight));

        $effectiveMargin = $actualRevenue > 0 ? ($actualVariableCosts / $actualRevenue) : $realizedVariableMargin;

        // Apply penalties directly to the baseline margin
        $rawMargin = $effectiveMargin + $totalMacroCostDrag + $strikePenalty;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Primary shock
        $primaryShockZ = max(abs($fpZ), abs($tpZ), abs($adsZ));
        $primaryShockZ = $primaryShockZ === abs($fpZ) ? $fpZ : ($primaryShockZ === abs($tpZ) ? $tpZ : $adsZ);

        if (abs($eventZ) > abs($primaryShockZ)) {
            $primaryShockZ = $eventZ;
        }

        // Visibility: 1P retail is visible via credit card data, 3P and Ads are opaque.
        $observableShockZ = ($fpZ * $fpWeight * self::FIRST_PARTY_VARIANCE * 0.80) +
            ($tpZ * $tpWeight * self::THIRD_PARTY_VARIANCE * 0.20) +
            ($adsZ * $adsWeight * self::DIGITAL_ADS_VARIANCE * 0.10);
        $observableShockZ *= $baselineVol;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: [
                'first_party_retail' => $fpRevenue,
                'third_party_seller' => $tpRevenue,
                'digital_ads_cloud'  => $adsRevenue,
            ],
        );
    }
}
