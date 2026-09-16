<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Integrated Resorts & Casinos.
 *
 * Financial Physics:
 * - Ultra-Discretionary: Sensitive to consumer sentiment, leisure budgets, and the "Wealth Effect."
 * - High Operating Leverage: Massive physical resort infrastructure creates high fixed overhead.
 * - Table Hold Variance: VIP Baccarat and high-roller gaming revenue is subject to statistical hold volatility.
 * - Promotional Comps: When sentiment drops, operators comp rooms and F&B to defend gaming floor foot traffic.
 * - CRE Tenant Concessions: Weak output gaps force landlords to offer concessions (TI/LCs), compressing NOI margins.
 */
class ResortsCasinosBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Ultra-discretionary leisure spending. */
    public const OPERATING_CYCLICALITY = 1.50;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.90;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.60;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['energy' => 0.10, 'agri' => 0.10, 'labor' => 0.40, 'ppi' => 0.05];

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. A property has to be staffed to be open: hotel, gaming floor and food service payroll is the overhead of the box. */
    public const FIXED_COST_LABOR_SHARE = 0.65;

    // --- FX Exposure ---
    /** Share of revenue whose competitiveness moves with the trade-weighted exchange rate. Inbound tourism is priced in the visitor's currency: a strong home currency prices the destination out. */
    public const FX_REVENUE_EXPOSURE = 0.15;
    /** Yield-managed room and table pricing captures a boom, but the whole spend is discretionary and travel substitutes readily. */
    public const PRICING_POWER_INDEX = 0.55;

    // --- Balance Sheet Realism ---
    /** Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Ground leases and OpCo/PropCo structures. */
    public const LEASE_LIABILITY_INTENSITY = 0.25;

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility from monthly Nevada / Macau gaming control board filings. */
    public const BASE_COVERAGE_VISIBILITY = 0.70;
    /** Base analyst forecasting error given hold variance and tourism seasonality. */
    public const BASE_COVERAGE_ERROR = 0.06;
    /** Floor clamp applied to dynamic visibility. */
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.40;

    // --- Dual-Stream Architecture ---
    /** Baseline fraction of revenue derived from Gross Gaming Revenue (GGR). */
    public const GAMING_REVENUE_WEIGHT = 0.55;
    /** Baseline fraction of revenue derived from Non-Gaming (Rooms, F&B, Entertainment, Conventions). */
    public const NON_GAMING_REVENUE_WEIGHT = 0.45;

    // --- Macro & Sentiment Physics ---
    /** Hard floor on pricing power given the ultra-discretionary nature of leisure travel. */
    public const MIN_BETA_PRICING_POWER_FLOOR = 0.40;
    /** Scalar for how aggressively consumer sentiment shifts drive macro demand. */
    public const SENTIMENT_SENSITIVITY_SCALAR = 0.25;
    /** Scalar for how much variable margins compress via promotional comps when sentiment drops. */
    public const PROMOTIONAL_COMP_DRAG_SCALAR = 0.15;

    // --- Revenue Volatility & Stream Physics ---
    /** Volatility multiplier for top-line revenue shocks reflecting gaming hold and tourism swings. */
    public const REVENUE_VARIANCE_SCALAR = 0.30;
    /** Volatility dampener applied to sticky non-gaming revenue (like convention backlog). */
    public const NON_GAMING_VOLATILITY_SCALAR = 0.50;
    /** The structural intensity of non-gaming variable costs relative to gaming costs. */
    public const NON_GAMING_COST_INTENSITY = 2.0;

    // --- Tail Risk & Shock Events (Symmetric Hold Variance) ---
    /** Negative z-score threshold indicating a severe gaming regulatory crackdown or VIP junket ban. */
    public const GAMING_REGULATION_CRACKDOWN_Z = -2.20;
    /** Revenue haircut applied to gaming operations during regulatory crackdowns. */
    public const GAMING_REGULATION_HAIRCUT = 0.25;
    /** Positive z-score threshold indicating extraordinary house win percentage (House holds big). */
    public const WHALE_LOSS_SURGE_Z = 2.40;
    /** Negative z-score threshold indicating VIP players running hot (House loses). */
    public const WHALE_WIN_CRASH_Z = -2.40;
    /** Absolute multiplier shock applied to gaming revenue during extreme hold variance quarters. */
    public const WHALE_HOLD_SHOCK_MULT = 0.18;

    // --- Property Reinvestment & Asset Decay ---
    /** Quarterly margin decay rate per unit of underinvestment in resort remodels and attractions. */
    public const RESORT_AGING_DECAY_RATE = 0.008;
    /** Quarterly margin gain scalar per unit of mega-resort expansion and modernization. */
    public const RESORT_MODERNIZATION_GAIN_RATE = 0.005;
    /** Structural minimum operating margin floor under severe property aging. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.10;
    /** Structural maximum operating margin ceiling for flagship premier Strip resorts. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.36;

    // --- Commercial Real Estate (CRE) Physics ---
    /** Fraction of excess inflation captured by CRE lease rent escalators. */
    public const CRE_RENT_ESCALATOR_CAPTURE = 0.50;
    /** Sensitivity of CRE leasing demand to the macroeconomic output gap. */
    public const CRE_DEMAND_ELASTICITY = 0.50;
    /** Volatility dampener applied to commercial real estate leases due to long-term lockups. */
    public const CRE_VOLATILITY_SCALAR = 0.20;
    /** Operating margin penalty per Z-score of distress representing vacancy costs and unabsorbed overhead. */
    public const CRE_VACANCY_MARGIN_HIT = 0.05;
    /** Variable margin penalty representing costly Tenant Concessions (TI/LCs) in weak economies. */
    public const CRE_TENANT_CONCESSION_DRAG = 0.10;
    /** Negative Z-score threshold triggering severe tenant distress and vacancy shocks. */
    public const CRE_VACANCY_DISTRESS_Z = -1.50;

        public function getReversionSpeed(): float { return 0.15; }
    public function getMoatSpread(): float { return 0.015; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.02; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.15; }

    public function getSeasonalityFactors(): array
    {
        return [0.85, 1.15, 1.25, 0.75]; // Q2-Q3 summer vacation & holiday travel peaks
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.03;
    }

    public function getCapexCyclicality(): float
    {
        return 2.50;
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        $sentimentShift = $macroState->sentimentDeviation();
        $beta = $this->getOperatingCyclicality($stock);

        $physics['macro_demand_shift'] += ($sentimentShift * $beta * self::SENTIMENT_SENSITIVITY_SCALAR) + $this->resolveFxDemandShift($macroState);

        return $physics;
    }

    protected function calculateSectorPhysics(
        Stock $stock,
        float $expectedRevenue,
        float $realizedVariableMargin,
        float $fixedCosts,
        float $baselineVol,
        MacroStateDTO $macroState,
        MathUtility $mathUtility
    ): SectorPhysicsResult {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::PricingPowerIndex->value          => self::MIN_BETA_PRICING_POWER_FLOOR,
            ModelParam::GamingRevenueWeight->value        => self::GAMING_REVENUE_WEIGHT,
            ModelParam::NonGamingRevenueWeight->value     => self::NON_GAMING_REVENUE_WEIGHT,
            ModelParam::CommercialRealEstateWeight->value => 0.00,
        ]);

        $rawCreWeight = $params[ModelParam::CommercialRealEstateWeight];
        $pricingPower = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        $targetWeights = [
            'gaming'     => $params[ModelParam::GamingRevenueWeight],
            'non_gaming' => $params[ModelParam::NonGamingRevenueWeight],
        ];
        if ($rawCreWeight > 0.0) {
            $targetWeights['cre'] = $rawCreWeight;
        }

        // --- Dynamic Revenue Mix Drift ---
        $activeWeights = $streams->resolveActiveStreamWeights($targetWeights);

        $gamingWeight    = $activeWeights['gaming'];
        $nonGamingWeight = $activeWeights['non_gaming'];
        $creWeight       = $activeWeights['cre'] ?? 0.0;

        $gamingZ    = $streams->generateZ('gaming', 0.15); // Table hold luck (near i.i.d.)
        $nonGamingZ = $streams->generateZ('non_gaming', 0.35); // Hotel occupancy & convention backlog
        $eventZ     = $streams->generateExogenousZ('event', 0.10);

        // --- Tail Risk Events (Symmetric Hold Variance) ---
        $whaleMultiplier = 1.0;
        $gamingHaircut   = 1.0;
        $eventType       = null;

        if ($eventZ < self::GAMING_REGULATION_CRACKDOWN_Z) {
            $gamingHaircut = 1.0 - self::GAMING_REGULATION_HAIRCUT;
            $eventType = ShockEvent::REGULATORY_FINE;
        } elseif ($gamingZ > self::WHALE_LOSS_SURGE_Z) {
            $whaleMultiplier = 1.0 + self::WHALE_HOLD_SHOCK_MULT;
        } elseif ($gamingZ < self::WHALE_WIN_CRASH_Z) {
            $whaleMultiplier = max(0.10, 1.0 - self::WHALE_HOLD_SHOCK_MULT);
        }

        // --- Multi-Stream Revenue Calculation ---
        $gamingRevenue = max(0.0, $expectedRevenue * $gamingWeight)
            * (1.0 + ($gamingZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)))
            * $whaleMultiplier * $gamingHaircut;

        $nonGamingRevenue = max(0.0, $expectedRevenue * $nonGamingWeight)
            * (1.0 + ($nonGamingZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * self::NON_GAMING_VOLATILITY_SCALAR)));

        $streamRevenues = [
            'gaming'     => $gamingRevenue,
            'non_gaming' => $nonGamingRevenue,
        ];

        $creRevenue              = 0.0;
        $creZ                    = 0.0;
        $creDemandShock          = 0.0;
        $rentEscalator           = 0.0;
        $creVacancyShock         = 0.0;
        $creTenantConcessionDrag = 0.0;

        if ($creWeight > 0.0) {
            $creZ = $streams->generateZ('cre', 0.50);

            $creShift = ($macroState->commercialPropertyIndexEma - 100.0) / 100.0;
            $resShift = ($macroState->residentialPropertyIndexEma - 100.0) / 100.0;
            $blendedPropertyShift = ($creShift * 0.70) + ($resShift * 0.30);
            
            $creDemandShock = ($macroState->outputGapEma * $this->getOperatingCyclicality($stock) * self::CRE_DEMAND_ELASTICITY) + ($blendedPropertyShift * 0.50);

            $excessInflation = max(0.0, $macroState->inflationEma - MacroEngine::TARGET_INFLATION);
            $rentEscalator = $excessInflation * self::CRE_RENT_ESCALATOR_CAPTURE;

            $creRevenue = max(0.0, $expectedRevenue * $creWeight * (1.0 + ($creZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::CRE_VOLATILITY_SCALAR) + $creDemandShock + $rentEscalator));
            $streamRevenues['cre'] = $creRevenue;

            if ($macroState->outputGapEma < 0.0) {
                $creTenantConcessionDrag = abs($macroState->outputGapEma) * self::CRE_TENANT_CONCESSION_DRAG;
            }

            if ($creZ < self::CRE_VACANCY_DISTRESS_Z) {
                $creVacancyShock = abs($creZ) * self::CRE_VACANCY_MARGIN_HIT;
            }
        }

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // --- Cost & Margin Physics ---
        // Resort power and HVAC, food and beverage, hospitality payroll and supplies reach the cost base at
        // spot; room and menu pricing recovers part of it. Only the physical resort footprint carries them.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);

        $sentimentShift = $macroState->sentimentDeviation();
        $promotionalDrag = $sentimentShift < 0.0
            ? abs($sentimentShift) * $this->getOperatingCyclicality($stock) * self::PROMOTIONAL_COMP_DRAG_SCALAR
            : 0.0;

        // Structural Margin Blending
        $baseGamingMargin = $realizedVariableMargin / max(0.01, ($gamingWeight + (self::NON_GAMING_COST_INTENSITY * $nonGamingWeight) + $creWeight));

        // Promotional comps isolate entirely to the hotel/F&B ledger
        $gamingVariableMargin    = $baseGamingMargin;
        $nonGamingVariableMargin = ($baseGamingMargin * self::NON_GAMING_COST_INTENSITY) + $promotionalDrag;

        // CRE Landlords operating on NNN leases are immune to casino operational footprint costs
        $creVariableMargin       = $baseGamingMargin + $creVacancyShock + $creTenantConcessionDrag;

        $actualVariableCosts = ($nonGamingRevenue * $nonGamingVariableMargin)
            + ($gamingRevenue * $gamingVariableMargin)
            + ($creRevenue * $creVariableMargin);

        // Energy drag scales strictly against the physical resort operations (gaming + non-gaming)
        $operationalFootprint = $gamingWeight + $nonGamingWeight;
        $effectiveInputCostDrag = $inputCostDrag * $operationalFootprint;

        $rawMargin = ($actualVariableCosts / max(1.0, $actualRevenue)) + $effectiveInputCostDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Shock Determination
        $primaryShockZ = $streams->resolveDominantShockZ([$gamingZ, $nonGamingZ, $creWeight > 0.0 ? $creZ : 0.0], $eventZ);

        $gamingShock = (($gamingZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR) * $whaleMultiplier * $gamingHaircut) + ($whaleMultiplier * $gamingHaircut - 1.0);
        $nonGamingShock = $nonGamingZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::NON_GAMING_VOLATILITY_SCALAR;
        $creShock = $creWeight > 0.0
            ? ($creZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::CRE_VOLATILITY_SCALAR) + $creDemandShock + $rentEscalator
            : 0.0;

        $observableShockZ = ($gamingShock * $gamingWeight) + ($nonGamingShock * $nonGamingWeight) + ($creShock * $creWeight);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues
        );
    }

    /** Under-investment below replacement CapEx erodes operating margin toward the sector floor. */
    public function getDepreciationDecayRate(): float
    {
        return self::RESORT_AGING_DECAY_RATE;
    }

    /** Over-investment above replacement CapEx compounds margin toward the sector ceiling. */
    public function getModernizationGainRate(): float
    {
        return self::RESORT_MODERNIZATION_GAIN_RATE;
    }

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [
            'agricultural_commodity_index_ema',
            'commercial_property_index_ema',
            'consumer_sentiment_index_ema',
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'inflation_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'residential_property_index_ema',
            'tips_breakeven_ema',
            'wage_growth_ema',
        ];
    }
}
