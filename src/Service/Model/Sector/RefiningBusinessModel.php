<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for merchant oil refiners (refining & marketing).
 *
 * Financial Physics:
 * - A refinery buys crude and sells the barrel it cracks it into. Revenue per barrel is the product slate:
 *   light products (gasoline, distillate, jet) at crude plus the benchmark crack, secondary products (coke,
 *   asphalt, residual fuel, gas liquids) at a fraction of crude. Revenue therefore rises with crude; the margin
 *   is the crack.
 * - Margin per barrel is yield-weighted: light yield x crack, less the discount the secondary barrel sells at
 *   against crude. Expensive crude squeezes a refiner only through that discount (a lower capture rate), never
 *   one for one, because the crack is already quoted over crude.
 * - Feedstock and fuel are paid per barrel; the plant, its crews and turnarounds are fixed. A wider crack is
 *   pure margin on a standing cost base, which is why refiners swing from losses to record profits.
 * - Throughput follows product demand at the income elasticity of oil demand, and the turnaround calendar
 *   (spring maintenance, summer driving season) sets the seasonal run rate.
 */
class RefiningBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of throughput to the output gap: the income elasticity of oil-product demand the energy market itself clears on (Hamilton 2009). */
    public const OPERATING_CYCLICALITY = CommodityLogisticsSubsystem::ENERGY_DEMAND_GAP_SENSITIVITY;
    /** Own-price elasticity of the firm's demand: a small share of a global market faces residual demand far more elastic than the market's (Landes & Posner 1981), so any premium over the benchmark loses the sale. Inert while the price is pinned to the index. */
    public const PRICE_ELASTICITY_OF_DEMAND = 2.50;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.15;

    // --- Input Cost Basket ---
    /** Shares of the non-feedstock variable cost base bought in tracked input markets: purchased natural gas and power for process heat, catalysts and chemicals. */
    public const INPUT_COST_EXPOSURES = ['gas' => 0.70, 'ppi' => 0.20];
    /** The crack is set by the market; a refiner recovers none of its own fuel inflation in price. */
    public const PRICING_POWER_INDEX = 0.00;

    // --- FX Exposure ---
    /** Share of revenue whose home-currency realization moves with the trade-weighted exchange rate: refined products trade across borders. */
    public const FX_REVENUE_EXPOSURE = 0.30;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze: maintenance contracts and turnarounds carry much of the rest. */
    public const FIXED_COST_LABOR_SHARE = 0.30;

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility: crude and crack spreads are published daily, so analysts see most of a refiner's quarter. */
    public const BASE_COVERAGE_VISIBILITY = 0.80;
    /** Base coverage forecasting error on capture rates and crude differentials. */
    public const BASE_COVERAGE_ERROR = 0.10;

    // --- Refinery Yield & Barrel Economics ---
    /** Crude price in $/bbl at the energy index baseline (EIA WTI annual average 2010-2024). */
    public const REFERENCE_CRUDE_PRICE = 72.0;
    /** Light products (gasoline, distillate, jet) per barrel of crude input (EIA U.S. refinery yield 2021-2025). */
    public const LIGHT_PRODUCT_YIELD = 0.866;
    /** Secondary products (coke, asphalt, residual fuel, still gas, gas liquids, lubricants, feedstocks) per barrel of crude input, processing gain included (EIA 2021-2025). */
    public const SECONDARY_PRODUCT_YIELD = 0.195;
    /** Price of the secondary barrel relative to crude (coke and still gas far below it, asphalt and feedstocks near it, lubricants above), set so the margin at a zero crack matches Valero's reported regression, -$0.41/bbl over 2018-2024. */
    public const SECONDARY_PRODUCT_REALIZATION = 0.66;
    /** Share of the benchmark crack the light barrel realizes: Valero's refining margin per barrel moved $0.598 per $1 of Gulf Coast 3-2-1 crack over 2018-2024 (R 0.99), which over the 0.866 light yield is 0.69 (product grades below benchmark, crude bought above WTI). */
    public const REALIZED_CRACK_CAPTURE = 0.69;
    /** Floor on the non-feedstock variable cost share, guarding a seeded row whose margins leave less room than the feedstock takes. */
    public const MIN_VARIABLE_OPEX_SHARE = 0.005;

    // --- Revenue Shock Physics ---
    /** Volatility multiplier on the baseline volatility for throughput (unplanned downtime, run cuts) shocks. */
    public const REVENUE_VARIANCE_SCALAR = 0.25;
    /** Volatility of the capture shock (crude differentials, product slate) relative to the throughput shock. */
    public const CAPTURE_VOL_SCALAR = 1.50;
    /** Quarterly persistence of throughput shocks. */
    public const THROUGHPUT_SHOCK_PERSISTENCE = 0.35;
    /** Quarterly persistence of the capture shock (crude differentials and product cracks move in multi-quarter swings). */
    public const CAPTURE_SHOCK_PERSISTENCE = 0.40;
    /** Quarterly persistence of the firm's own tail-event draw. */
    public const EVENT_SHOCK_PERSISTENCE = 0.10;

    // --- Tail Risk & Shock Events ---
    /** Negative Z-score threshold indicating a refinery fire or explosion. */
    public const REFINERY_OUTAGE_Z_SCORE = -2.60;
    /** Throughput multiplier while the damaged units are down. */
    public const OUTAGE_THROUGHPUT_MULT = 0.85;
    /** Per-barrel cost ratio penalty funding repairs, containment and remediation. */
    public const OUTAGE_COST_PENALTY = 0.08;

    // --- Capital Reinvestment & Asset Depreciation Physics ---
    /** Quarterly efficiency decay rate per unit of underinvestment below replacement CapEx. */
    public const DEPRECIATION_DECAY_RATE = 0.025;
    /** Quarterly efficiency gain scalar per unit of logarithmic overinvestment above replacement CapEx. */
    public const MODERNIZATION_GAIN_RATE = 0.012;
    /** Structural minimum operating margin floor for an aging, low-complexity plant. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.01;
    /** Structural maximum operating margin ceiling at the baseline crack: modernization can only cut opex, and the barrel identity leaves about a point of it to cut. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.085;
    /** Share of construction in progress completed each quarter. */
    public const CAPEX_COMPLETION_RATE = 0.125;
    /** Annual mean reversion of ROIC toward the cost of capital. */
    public const ROIC_REVERSION_SPEED = 0.3;
    /** Working capital intensity: crude and product inventory plus receivables on a fuel sales book. */
    public const WORKING_CAPITAL_INTENSITY = 0.15;

    // --- Valuation ---
    /** Book (replacement cost) weight in fair value when normalized EPS is negative: trough refiners trade on assets. */
    public const TROUGH_BOOK_WEIGHT = 0.70;
    /** Book weight in fair value through the rest of the cycle. */
    public const MID_CYCLE_BOOK_WEIGHT = 0.40;

    public function getReversionSpeed(): float
    {
        return self::ROIC_REVERSION_SPEED;
    }

    public function getCapExCompletionRate(Stock $stock): float
    {
        return self::CAPEX_COMPLETION_RATE;
    }


    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.30, 'revenue_weight' => 0.70];
    }

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        return self::WORKING_CAPITAL_INTENSITY;
    }

    /**
     * Calendar-quarter throughput seasonality [Q1, Q2, Q3, Q4] summing to 4.0: U.S. refinery utilization by quarter
     * (EIA, 2010-2019) over its annual mean. Spring turnarounds, then the summer driving season.
     *
     * @return array<int, float>
     */
    public function getSeasonalityFactors(): array
    {
        return [0.9628, 1.0117, 1.0251, 1.0004];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        // Product prices live in the crude and crack indices the physics reads, so neither the selling price nor
        // the cost base inflates at the engine level. Throughput follows product demand.
        return [
            'macro_demand_shift' => ($this->resolveLaggedOutputGap($macroState) * $this->getOperatingCyclicality($stock))
                + $this->resolveFxDemandShift($macroState),
            'pricing_power_multiplier' => 1.0,
            'input_cost_multiplier' => 1.0,
        ];
    }

    /**
     * Revenue per barrel of crude run: light products at crude plus the share of the benchmark crack the slate
     * realizes, secondary products at their realization against crude.
     */
    public function calculateBarrelRealization(float $crudePrice, float $crackSpread): float
    {
        return (self::LIGHT_PRODUCT_YIELD * ($crudePrice + (self::REALIZED_CRACK_CAPTURE * $crackSpread)))
            + (self::SECONDARY_PRODUCT_YIELD * self::SECONDARY_PRODUCT_REALIZATION * $crudePrice);
    }

    /** Crude in $/bbl implied by the energy index. */
    public function resolveCrudePrice(MacroStateDTO $macroState): float
    {
        return max(0.0, $macroState->energyPriceIndexEma) * self::REFERENCE_CRUDE_PRICE / MacroEngine::ENERGY_BASELINE;
    }

    /**
     * The two market drivers of the refining margin, each as a change in margin per barrel relative to the
     * baseline barrel's revenue: the crack widening over its baseline, and crude moving the secondary barrel's
     * discount.
     *
     * @return array{crack: float, crude: float}
     */
    public function describeMarginDrivers(MacroStateDTO $macroState): array
    {
        $baselineRealization = $this->calculateBarrelRealization(self::REFERENCE_CRUDE_PRICE, MacroEngine::CRACK_SPREAD_BASELINE);
        $secondaryDiscount = 1.0 - self::LIGHT_PRODUCT_YIELD - (self::SECONDARY_PRODUCT_YIELD * self::SECONDARY_PRODUCT_REALIZATION);

        return [
            'crack' => self::LIGHT_PRODUCT_YIELD * self::REALIZED_CRACK_CAPTURE * ($macroState->refiningCrackSpreadEma - MacroEngine::CRACK_SPREAD_BASELINE) / $baselineRealization,
            'crude' => -$secondaryDiscount * ($this->resolveCrudePrice($macroState) - self::REFERENCE_CRUDE_PRICE) / $baselineRealization,
        ];
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
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        $throughputZ = $streams->generateZ('refined_products', self::THROUGHPUT_SHOCK_PERSISTENCE);
        $captureZ    = $streams->generateExogenousZ('capture', self::CAPTURE_SHOCK_PERSISTENCE);
        $eventZ      = $streams->generateExogenousZ('event', self::EVENT_SHOCK_PERSISTENCE);

        $throughputMultiplier = 1.0;
        $outagePenalty = 0.0;
        $eventType = null;
        if ($eventZ < self::REFINERY_OUTAGE_Z_SCORE) {
            $eventType = ShockEvent::ENVIRONMENTAL_DISASTER;
            $throughputMultiplier = self::OUTAGE_THROUGHPUT_MULT;
            $outagePenalty = self::OUTAGE_COST_PENALTY;
        }

        // --- Barrel Economics ---
        $crudePrice = $this->resolveCrudePrice($macroState);
        $captureShift = $captureZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::CAPTURE_VOL_SCALAR;
        $crackSpread = max(0.0, $macroState->refiningCrackSpreadEma * (1.0 + $captureShift));
        $realization = $this->calculateBarrelRealization($crudePrice, $crackSpread);
        $baselineRealization = $this->calculateBarrelRealization(self::REFERENCE_CRUDE_PRICE, MacroEngine::CRACK_SPREAD_BASELINE);

        $throughput = max(0.0, (1.0 + ($throughputZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR)) * $throughputMultiplier);
        $actualRevenue = max(0.0, $expectedRevenue * $throughput * $realization / $baselineRealization);
        $streamRevenues = ['refined_products' => $actualRevenue];
        $streams->recordStreamShares($streamRevenues);

        // --- Per-Barrel Cost Base ---
        // The engine's variable cost ratio was struck on the baseline barrel; crude is its feedstock share and
        // the rest is fuel, catalysts and chemicals, which inflate with their own input markets. Crude is bought
        // barrel for barrel and is never sticky, so the engine's quarterly adjustments to the ratio (sticky costs,
        // overtime) scale the operating costs alone (Anderson, Banker & Janakiraman 2003 measure SG&A, not materials).
        $structuralVariableMargin = $stock->getStructuralVariableMargin() ?? $realizedVariableMargin;
        $engineAdjustment = $structuralVariableMargin > 0.0 ? $realizedVariableMargin / $structuralVariableMargin : 1.0;
        $structuralOpexShare = $structuralVariableMargin - (self::REFERENCE_CRUDE_PRICE / $baselineRealization);
        $opexShare = max(self::MIN_VARIABLE_OPEX_SHARE, $structuralOpexShare * $engineAdjustment);
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $opexShare);
        $opexPerBarrel = ($opexShare + $inputCostDrag + $outagePenalty) * $baselineRealization;
        $clampedMargin = $this->clampMargin($realization > 0.0 ? ($crudePrice + $opexPerBarrel) / $realization : $realizedVariableMargin);

        // Crude and the crack are published daily; the plant's own runs are not. Dividing the price leg by the
        // base visibility lets a persistent price level converge to an unbiased consensus.
        $observableShockZ = ((($realization / $baselineRealization) - 1.0) / self::BASE_COVERAGE_VISIBILITY) + ($throughput - 1.0);
        $benchmarkCrack = $macroState->refiningCrackSpreadEma;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $streams->resolveDominantShockZ([$throughputZ, $captureZ], $eventZ),
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            kpis: [
                'capture_rate' => $benchmarkCrack > 0.0 ? ($realization - $crudePrice) / $benchmarkCrack : 0.0,
                'throughput_index' => $throughput,
            ],
        );
    }

    /** Refiners anchor to book (replacement cost of the plant) at trough earnings and to mid-cycle earnings otherwise. */
    protected function getFairValueBookWeight(float $normalizedEps): float
    {
        return $normalizedEps < 0 ? self::TROUGH_BOOK_WEIGHT : self::MID_CYCLE_BOOK_WEIGHT;
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
            'energy_price_index_ema',
            'exchange_rate_index_ema',
            'natural_gas_price_index_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'refining_crack_spread_ema',
        ];
    }
}
