<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
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

        public function getMinIcr(): float { return 2.5; }
    public function getWholesaleLeverageLimit(): float { return 1.5; }
    public function getDividendCrisisIcr(): float { return 2.0; }
    public function getBuybackMinIcr(): float { return 2.5; }
    public function getReversionSpeed(): float { return 0.15; }
    public function getMoatSpread(): float { return 0.02; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return -0.05; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.5; }

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
            ModelParam::FirstPartyWeight->value => self::FIRST_PARTY_WEIGHT,
            ModelParam::ThirdPartyWeight->value => self::THIRD_PARTY_WEIGHT,
            ModelParam::DigitalAdsWeight->value => self::DIGITAL_ADS_WEIGHT,
        ]);

        $fpWeight  = $params[ModelParam::FirstPartyWeight];
        $tpWeight  = $params[ModelParam::ThirdPartyWeight];
        $adsWeight = $params[ModelParam::DigitalAdsWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new \App\DTO\StreamContext($momentum, $mathUtility);
        $beta = abs((float) $stock->getBeta());

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'first_party_retail' => $params[ModelParam::FirstPartyWeight],
            'third_party_seller' => $params[ModelParam::ThirdPartyWeight],
            'digital_ads_cloud'  => $params[ModelParam::DigitalAdsWeight],
        ]);

        $fpWeight  = $activeWeights['first_party_retail'];
        $tpWeight  = $activeWeights['third_party_seller'];
        $adsWeight = $activeWeights['digital_ads_cloud'];

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

        $streamRevenues = [
            'first_party_retail' => $fpRevenue,
            'third_party_seller' => $tpRevenue,
            'digital_ads_cloud'  => $adsRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Structural Margin Blending ---
        // Calculate organic costs for the high-margin divisions
        $tpCosts = $tpRevenue * self::THIRD_PARTY_COST_RATIO;
        $adsCosts = $adsRevenue * self::DIGITAL_ADS_COST_RATIO;

        // Back-calculate the 1st Party Retail margin constraints based on the global expectation
        $targetTotalCosts = $expectedRevenue * $realizedVariableMargin;
        $fpBaselineCosts = max(0.0, $targetTotalCosts - $tpCosts - $adsCosts);
        $fpVariableMargin = $expectedRevenue * $fpWeight > 0 ? $fpBaselineCosts / ($expectedRevenue * $fpWeight) : $realizedVariableMargin;
        
        $fxShift = ($macroState->exchangeRateIndexEma - 100.0) / 100.0;
        $fpCostSavings = $fpRevenue * $fxShift * 0.15;

        // Re-blend actual costs based on shocked revenue
        $actualVariableCosts = $tpCosts + $adsCosts + ($fpRevenue * $fpVariableMargin) - $fpCostSavings;

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

        // Global ocean & logistics freight spikes increase 1P import and 3P fulfillment delivery costs
        $freightShift = max(0.0, ($macroState->freightRateIndexEma - 100.0) / 100.0);
        $freightCostDrag = $freightShift * 0.05 * ($fpWeight + $tpWeight);

        $totalMacroCostDrag = ($goodsInflationDrag * $fpWeight) + ($wageInflationDrag * ($fpWeight + $tpWeight)) + $freightCostDrag;

        $effectiveMargin = $actualRevenue > 0 ? ($actualVariableCosts / $actualRevenue) : $realizedVariableMargin;

        // Apply penalties directly to the baseline margin
        $rawMargin = $effectiveMargin + $totalMacroCostDrag + $strikePenalty;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Primary shock
        $primaryShockZ = $streams->resolveDominantShockZ([$fpZ, $tpZ, $adsZ], $eventZ);

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
            streamRevenue: $streamRevenues,
        );
    }
}
