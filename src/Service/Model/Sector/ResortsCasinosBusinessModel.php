<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\InputOutputExposures;
use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Integrated Resorts & Casinos.
 *
 * Financial Physics:
 * - Visitor demand: one driver (output gap, exchange rate, confidence beyond the gap, the foreign bloc's cycle)
 *   reaches the gaming floor, the hotel and the tenants' tills alike.
 * - Hold luck: the high-limit tables win or lose by chance alone, independent of demand and gone next quarter.
 * - Regulatory regimes: a crackdown on high-limit play takes a share of gaming revenue for years, not a quarter.
 * - Resort landlord: base rent on a staggered lease roll, priced off tenant sales, plus a percentage of tenants'
 *   gross receipts, less the rent lost to tenant failures.
 * - Expected revenue carries what is visible as the quarter opens (visitor demand, a crackdown in force, the
 *   rent roll); surprises are volume noise, hold luck and regime switches.
 * - Promotional comps: when sentiment drops, operators comp rooms and F&B to defend gaming floor foot traffic.
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
    /** Rooms, food and beverage, entertainment and shop rents are services: selling prices track supercore, not goods breakevens. */
    public const PRICING_INFLATION_BASIS = 'supercore_inflation_ema';

    // --- Input Cost Basket ---
    /** Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain content (labor still the model's own). */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::RESORTS_CASINOS;

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

    // --- Visitor Demand ---
    /** Scalar for how aggressively confidence beyond the output gap drives visitor demand. */
    public const SENTIMENT_SENSITIVITY_SCALAR = 0.25;
    /** Visitor volume per unit of the foreign bloc's output gap: inbound tourism and convention traffic. */
    public const FOREIGN_DEMAND_SENSITIVITY = 1.00;
    /** AR(1) persistence of the firm's own visitor volume shocks (hotel occupancy, convention backlog, gaming drop). */
    public const VISITOR_DEMAND_PERSISTENCE = 0.35;
    /** Scalar for how much variable margins compress via promotional comps when sentiment drops. */
    public const PROMOTIONAL_COMP_DRAG_SCALAR = 0.15;

    // --- Revenue Volatility & Stream Physics ---
    /** Volatility multiplier for top-line revenue shocks reflecting gaming volume and tourism swings. */
    public const REVENUE_VARIANCE_SCALAR = 0.30;
    /** Volatility dampener applied to sticky non-gaming revenue (like convention backlog). */
    public const NON_GAMING_VOLATILITY_SCALAR = 0.50;
    /** The structural intensity of non-gaming variable costs relative to gaming costs. */
    public const NON_GAMING_COST_INTENSITY = 2.0;

    // --- Hold Luck ---
    /** Share of gaming win from high-limit baccarat, the only play whose hold varies by luck (Las Vegas Strip ~20%, UNLV Center for Gaming Research). */
    public const HIGH_LIMIT_SHARE_OF_GAMING = 0.20;
    /** Quarterly s.d. of high-limit win from hold luck: Macau rolling-chip win runs 2.5-3.5% around a 2.85% theoretical, read as +/-2 s.d. */
    public const HIGH_LIMIT_HOLD_VOLATILITY = 0.088;

    // --- Regulatory Regimes (Markov switching) ---
    /** Annual arrival intensity of a crackdown on high-limit play (junket bans, licence conditions): about one in eighteen years. */
    public const GAMING_CRACKDOWN_INTENSITY = 0.056;
    /** Share of gaming revenue lost while a crackdown regime lasts. */
    public const GAMING_REGULATION_HAIRCUT = 0.25;
    /** Mean years a crackdown regime lasts (Macau GGR 2013 MOP 360bn, back to 303bn by 2018 after the 2014 campaign). */
    public const GAMING_CRACKDOWN_MEAN_YEARS = 3.0;
    /** Regime key for an active crackdown. */
    public const CRACKDOWN_REGIME = 'gaming_crackdown';

    // --- Resort Landlord (CRE) ---
    /** Percentage rent on tenants' gross receipts as a share of rent (Simon Property Group 2018: overage $162m on minimum rent $3,489m). */
    public const OVERAGE_RENT_SHARE = 0.044;
    /** Weighted average lease term of the shop and restaurant roll, as the REIT model's. */
    public const CRE_LEASE_WALT_YEARS = ReitBusinessModel::LEASE_WALT_YEARS;
    /** Bound on the gap between in-place and market rents, as the REIT model's. */
    public const CRE_MAX_RELEASING_SPREAD = ReitBusinessModel::MAX_RELEASING_SPREAD;
    /** Rent lost per unit of relative excess retail default rate, as the REIT model's retail tenant channel. */
    public const RETAIL_TENANT_DEFAULT_RENT_LOSS = ReitBusinessModel::RETAIL_DEFAULT_VACANCY_SCALAR;
    /** Volatility dampener applied to commercial real estate leases due to long-term lockups. */
    public const CRE_VOLATILITY_SCALAR = 0.20;
    /** AR(1) persistence of leasing noise on the rent roll. */
    public const CRE_LEASING_PERSISTENCE = 0.50;
    /** Persisted state key: in-place rent relative to trend, carried across quarters as the roll turns over. */
    public const STATE_IN_PLACE_RENT = 'state:in_place_rent';

    // --- Property Reinvestment & Asset Decay ---
    /** Quarterly margin decay rate per unit of underinvestment in resort remodels and attractions. */
    public const RESORT_AGING_DECAY_RATE = 0.008;
    /** Quarterly margin gain scalar per unit of mega-resort expansion and modernization. */
    public const RESORT_MODERNIZATION_GAIN_RATE = 0.005;
    /** Structural minimum operating margin floor under severe property aging. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.10;
    /** Structural maximum operating margin ceiling for flagship premier Strip resorts. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.36;

    // --- Structural Rates ---
    /** Speed at which operating margin reverts to its structural level. */
    public const MARGIN_REVERSION_SPEED = 0.15;
    /** Return spread over WACC a destination resort's location and licence defend. */
    public const MOAT_SPREAD = 0.015;
    /** Working capital per unit of revenue: cage cash and receivables net of payables and advance deposits. */
    public const WORKING_CAPITAL_INTENSITY = 0.02;
    /** Share of construction in progress completed per quarter (multi-year resort builds). */
    public const CAPEX_COMPLETION_RATE = 0.15;
    /** Nominal secular growth of destination leisure spending. */
    public const SECULAR_GROWTH_RATE = 0.03;
    /** Capex response to the cycle: resort expansions are deferred in slumps and launched in booms. */
    public const CAPEX_CYCLICALITY = 2.50;
    /** Calendar-quarter seasonality of a coastal resort: Atlantic City land-based casino win 2015 (NJ DGE) by quarter over its mean. */
    public const SEASONALITY_FACTORS = [0.90, 0.99, 1.18, 0.93];

    public function getReversionSpeed(): float { return self::MARGIN_REVERSION_SPEED; }
    public function getMoatSpread(): float { return self::MOAT_SPREAD; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return self::WORKING_CAPITAL_INTENSITY; }
    public function getCapExCompletionRate(Stock $stock): float { return self::CAPEX_COMPLETION_RATE; }

    public function getSeasonalityFactors(): array
    {
        return self::SEASONALITY_FACTORS;
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return self::SECULAR_GROWTH_RATE;
    }

    public function getCapexCyclicality(): float
    {
        return self::CAPEX_CYCLICALITY;
    }

    /**
     * The root shift is the revenue-weighted shift of every stream as the quarter opens: what analysts can see in
     * visitor demand, a crackdown in force and the rent roll. Each stream takes its own back in calculateSectorPhysics().
     */
    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        $physics['macro_demand_shift'] = $this->resolveRootShift($stock, $macroState, $this->resolveVisitorDemandShift($stock, $macroState));

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
        $pricingPower = $this->resolvePricingPower($stock);
        $targetWeights = $this->resolveTargetWeights($stock);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        $activeWeights   = $streams->resolveActiveStreamWeights($targetWeights);
        $gamingWeight    = $activeWeights['gaming'];
        $nonGamingWeight = $activeWeights['non_gaming'];
        $creWeight       = $activeWeights['cre'] ?? 0.0;

        // Expected revenue already carries the root shift; each stream adds its own shift less it.
        $visitorShift = $this->resolveVisitorDemandShift($stock, $macroState);
        $rootShift = $this->resolveRootShift($stock, $macroState, $visitorShift);
        $tenantDefaultLoss = $this->resolveTenantDefaultLoss($macroState);

        $gamingZ    = $streams->generateZ('gaming', self::VISITOR_DEMAND_PERSISTENCE);
        $nonGamingZ = $streams->generateZ('non_gaming', self::VISITOR_DEMAND_PERSISTENCE);
        $holdZ      = $streams->generateExogenousZ('hold', 0.0);
        $eventZ     = $streams->generateExogenousZ('event', 0.0);

        // --- Regulatory Regime (Hamilton 1989 Markov switching) ---
        $quarter = EarningsEngine::QUARTERLY_TIME_STEP;
        $streams->evolveRegime(self::CRACKDOWN_REGIME, 0.0, 1.0 - exp(-$quarter / self::GAMING_CRACKDOWN_MEAN_YEARS));
        $eventType = null;
        if ($mathUtility->calculateNormalCDF($eventZ) < 1.0 - exp(-self::GAMING_CRACKDOWN_INTENSITY * $quarter)
            && $streams->getRegimeElapsed(self::CRACKDOWN_REGIME) === 0) {
            $streams->startRegime(self::CRACKDOWN_REGIME);
            $eventType = ShockEvent::GAMING_CRACKDOWN;
        }

        // --- Rent roll: only the slice expiring this quarter reprices to market ---
        $inPlaceRent = $streams->getPersistedState(self::STATE_IN_PLACE_RENT, $visitorShift);
        [$rolledInPlaceRent, $releasingSpread] = MathUtility::rollLeaseLadder(
            $inPlaceRent,
            $visitorShift,
            self::CRE_LEASE_WALT_YEARS,
            self::CRE_MAX_RELEASING_SPREAD,
            $quarter
        );

        $shifts = $this->resolveStreamShifts($visitorShift, $streams->getRegimeElapsed(self::CRACKDOWN_REGIME) > 0, $rolledInPlaceRent, $tenantDefaultLoss);
        // Each stream's level over the one expected revenue was built at.
        $relative = static fn (float $shift): float => ((1.0 + $shift) / max(0.01, 1.0 + $rootShift)) - 1.0;

        // --- Gaming: volume on the visitor cycle, hold luck on the high-limit tables ---
        $gamingVolumeRevenue = max(0.0, $expectedRevenue * $gamingWeight
            * (1.0 + $relative($shifts['gaming']) + ($gamingZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR)));
        $holdLuck = $holdZ * self::HIGH_LIMIT_SHARE_OF_GAMING * self::HIGH_LIMIT_HOLD_VOLATILITY;
        $gamingRevenue = max(0.0, $gamingVolumeRevenue * (1.0 + $holdLuck));

        $nonGamingRevenue = max(0.0, $expectedRevenue * $nonGamingWeight
            * (1.0 + $relative($shifts['non_gaming']) + ($nonGamingZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::NON_GAMING_VOLATILITY_SCALAR)));

        $streamRevenues = [
            'gaming'     => $gamingRevenue,
            'non_gaming' => $nonGamingRevenue,
        ];

        // --- Resort Landlord ---
        $creZ = 0.0;
        $creLeasedRevenue = 0.0;
        $kpis = [];
        if ($creWeight > 0.0) {
            $creZ = $streams->generateZ('cre', self::CRE_LEASING_PERSISTENCE);
            $leasingShock = $creZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::CRE_VOLATILITY_SCALAR;
            $streams->registerState(self::STATE_IN_PLACE_RENT, $rolledInPlaceRent);

            // The leased footprint carries the operating cost; the rent it earns moves with the roll and tenant sales.
            $creLeasedRevenue = max(0.0, $expectedRevenue * $creWeight * (1.0 + $leasingShock + $relative(0.0)));
            $streamRevenues['cre'] = max(0.0, $expectedRevenue * $creWeight * (1.0 + $leasingShock + $relative($shifts['cre'])));

            $kpis = [
                'walt_years' => self::CRE_LEASE_WALT_YEARS,
                'releasing_spread' => $releasingSpread,
                'in_place_rent_index' => $rolledInPlaceRent,
            ];
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

        $baseGamingMargin = $realizedVariableMargin / max(0.01, ($gamingWeight + (self::NON_GAMING_COST_INTENSITY * $nonGamingWeight) + $creWeight));
        // Promotional comps isolate entirely to the hotel/F&B ledger.
        $nonGamingVariableMargin = ($baseGamingMargin * self::NON_GAMING_COST_INTENSITY) + $promotionalDrag;

        // Costs follow volume: hold luck, rent repricing, percentage rent and lost rent move revenue alone.
        $actualVariableCosts = ($gamingVolumeRevenue * $baseGamingMargin)
            + ($nonGamingRevenue * $nonGamingVariableMargin)
            + ($creLeasedRevenue * $baseGamingMargin);

        $effectiveInputCostDrag = $inputCostDrag * ($gamingWeight + $nonGamingWeight);

        $rawMargin = ($actualVariableCosts / max(1.0, $actualRevenue)) + $effectiveInputCostDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        $primaryShockZ = $streams->resolveDominantShockZ([$gamingZ, $nonGamingZ, $holdZ, $creZ], $eventZ);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            // Monthly gaming filings and the rent roll reveal the quarter's revenue against expectations.
            observableShockZ: ($actualRevenue / max(1.0, $expectedRevenue)) - 1.0,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            kpis: $kpis,
        );
    }

    /**
     * Visitor demand: the lagged output gap and exchange rate, confidence beyond the gap (Lemmon & Portniaguina
     * 2006) and the foreign bloc's cycle, which fills the rooms the district's own does not.
     */
    private function resolveVisitorDemandShift(Stock $stock, MacroStateDTO $macroState): float
    {
        return $this->resolveCycleDemandShift($stock, $macroState)
            + ($macroState->sentimentResidual() * $this->getOperatingCyclicality($stock) * self::SENTIMENT_SENSITIVITY_SCALAR)
            + MathUtility::calculateForeignDemandShift($macroState->foreignOutputGapEma, sensitivity: self::FOREIGN_DEMAND_SENSITIVITY);
    }

    /**
     * Each stream's shift from trend: visitor demand on the floor and in the hotel, less the crackdown haircut on
     * gaming; on the rent roll, in-place base rent plus a percentage of tenants' gross receipts, less rent lost to
     * tenant failures.
     *
     * @return array{gaming: float, non_gaming: float, cre: float}
     */
    private function resolveStreamShifts(float $visitorShift, bool $crackdown, float $inPlaceRent, float $tenantDefaultLoss): array
    {
        return [
            'gaming'     => ((1.0 + $visitorShift) * ($crackdown ? 1.0 - self::GAMING_REGULATION_HAIRCUT : 1.0)) - 1.0,
            'non_gaming' => $visitorShift,
            'cre'        => ((1.0 - self::OVERAGE_RENT_SHARE) * $inPlaceRent) + (self::OVERAGE_RENT_SHARE * $visitorShift) - $tenantDefaultLoss,
        ];
    }

    /**
     * Revenue-weighted stream shift over the target mix, from the state the quarter opens with (last quarter's
     * regime and rent roll): the part of the quarter analysts can already see, carried in expected revenue.
     */
    private function resolveRootShift(Stock $stock, MacroStateDTO $macroState, float $visitorShift): float
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $shifts = $this->resolveStreamShifts(
            $visitorShift,
            (float) ($momentum[StreamContext::REGIME_STATE_PREFIX . self::CRACKDOWN_REGIME] ?? 0.0) > 0.0,
            (float) ($momentum[self::STATE_IN_PLACE_RENT] ?? $visitorShift),
            $this->resolveTenantDefaultLoss($macroState)
        );

        $weights = $this->resolveTargetWeights($stock);
        $rootShift = 0.0;
        foreach ($weights as $key => $weight) {
            $rootShift += $weight * $shifts[$key];
        }

        return $rootShift / max(1e-9, array_sum($weights));
    }

    /** Rent lost to retail tenant failures, from the excess retail default rate. */
    private function resolveTenantDefaultLoss(MacroStateDTO $macroState): float
    {
        return MathUtility::excessOverBaseline($macroState->retailDefaultRateEma, MacroEngine::RETAIL_DEFAULT_BASELINE)
            * self::RETAIL_TENANT_DEFAULT_RENT_LOSS;
    }

    /** @return array<string, float> */
    private function resolveTargetWeights(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::GamingRevenueWeight->value        => self::GAMING_REVENUE_WEIGHT,
            ModelParam::NonGamingRevenueWeight->value     => self::NON_GAMING_REVENUE_WEIGHT,
            ModelParam::CommercialRealEstateWeight->value => 0.00,
        ]);

        $weights = [
            'gaming'     => (float) $params[ModelParam::GamingRevenueWeight],
            'non_gaming' => (float) $params[ModelParam::NonGamingRevenueWeight],
        ];
        $creWeight = (float) $params[ModelParam::CommercialRealEstateWeight];
        if ($creWeight > 0.0) {
            $weights['cre'] = $creWeight;
        }

        return $weights;
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
            'consumer_sentiment_index_ema',
            'exchange_rate_index_ema',
            'foreign_output_gap_ema',
            'output_gap_ema',
            'retail_default_rate_ema',
            'supercore_inflation_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
