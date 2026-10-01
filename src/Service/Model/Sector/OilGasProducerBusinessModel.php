<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\InputOutputExposures;
use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for upstream oil and gas producers (exploration & production).
 *
 * Financial Physics:
 * - Revenue is volume times realized price. The price is the market's: crude off the energy index, gas off the
 *   natural gas index, blended by the firm's production mix. The firm's only price of its own is a small basis
 *   differential (crude quality, location, contract timing).
 * - Lifting, transport and field overhead are paid per barrel, not per dollar, so a price move lands on margin
 *   in full and a price slump leaves the whole cost base standing: that is where a producer's operating
 *   leverage comes from.
 * - Volumes do not follow the domestic cycle: every barrel clears into a global pool at the going price. Decline
 *   is carried by units-of-production depreciation on net plant, which only reinvestment replaces.
 * - A rolling four-quarter swap ladder sells part of the oil forward at the Schwartz (1997) forward curve, so a
 *   spike is only partly captured and a slump partly cushioned, with a lag of up to a year.
 */
class OilGasProducerBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle. A producer sells every barrel it lifts into a global pool at the going price, so the domestic output gap does not move its volume (band floor). */
    public const OPERATING_CYCLICALITY = 0.20;
    /** Own-price elasticity of the firm's demand: a small share of a global market faces residual demand far more elastic than the market's (Landes & Posner 1981), so any premium over the benchmark loses the sale. Inert while the price is pinned to the index. */
    public const PRICE_ELASTICITY_OF_DEMAND = 2.50;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. Peers barely notice a rival barrel in a global market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.15;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain content (labor still the model's own). */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::OIL_GAS_PRODUCER;
    /** Price takers recover none of their own input inflation through pricing: the market sets the quote. */
    public const PRICING_POWER_INDEX = 0.00;

    // --- FX Exposure ---
    /** Share of revenue whose home-currency realization moves with the trade-weighted exchange rate: crude and gas are priced in the world market. */
    public const FX_REVENUE_EXPOSURE = 0.30;

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility: realized crude and gas prices are published daily, so the sell side sees most of the quarter. */
    public const BASE_COVERAGE_VISIBILITY = 0.80;
    /** Base coverage forecasting error given the swings in the benchmark prices. */
    public const BASE_COVERAGE_ERROR = 0.10;

    // --- Production Mix & Hedging ---
    /** Default share of revenue at baseline prices from crude and liquids; the remainder is natural gas. */
    public const LIQUIDS_REVENUE_SHARE = 0.70;
    /** Default share of next-year oil volume sold forward through the swap ladder (a typical E&P programme hedges about half of year-one production). */
    public const OIL_HEDGE_RATIO = 0.50;
    /** Quarterly tranches in the rolling swap ladder: each quarter one quarter of the hedged volume is locked for each of the next four quarters. */
    public const HEDGE_LADDER_TRANCHES = 4;
    /** Persisted state key prefix: sum of the strikes already locked for delivery 0-3 quarters ahead. */
    public const STATE_HEDGE_STRIKE_SUM_PREFIX = 'state:hedge_strike_sum_';

    // --- Revenue Shock Physics ---
    /** Volatility multiplier on the baseline volatility for field production (uptime, well performance) shocks. */
    public const REVENUE_VARIANCE_SCALAR = 0.25;
    /** Firm-specific basis differential noise as a fraction of the production variance scalar (price itself is a market variable). */
    public const BASIS_DIFFERENTIAL_VOL_SCALAR = 0.40;
    /** Quarterly persistence of field production shocks (a field outage or well underperformance takes a few quarters to recover). */
    public const PRODUCTION_SHOCK_PERSISTENCE = 0.35;
    /** Quarterly persistence of the basis differential (pipeline takeaway constraints and quality spreads clear within a few quarters). */
    public const BASIS_SHOCK_PERSISTENCE = 0.15;
    /** Quarterly persistence of the firm's own tail-event draw. */
    public const EVENT_SHOCK_PERSISTENCE = 0.10;

    // --- Tail Risk & Shock Events ---
    /** Negative Z-score threshold indicating sanctions on a jurisdiction the firm produces in, shutting in its output there. */
    public const GEOPOLITICAL_SANCTIONS_Z_SCORE = -2.20;
    /** Production multiplier applied while sanctioned output is shut in. */
    public const SANCTIONS_PRODUCTION_MULT = 0.75;
    /** Negative Z-score threshold indicating a well blowout or platform disaster. */
    public const ENVIRONMENTAL_DISASTER_Z_SCORE = -2.60;
    /** Per-barrel cost ratio penalty funding containment, remediation and repairs. */
    public const ENVIRONMENTAL_DISASTER_PENALTY = 0.08;
    /** Production multiplier applied while the damaged field is shut in. */
    public const DISASTER_PRODUCTION_MULT = 0.85;

    // --- Capital Reinvestment & Asset Depreciation Physics ---
    /** Quarterly efficiency decay rate per unit of underinvestment below replacement CapEx. */
    public const DEPRECIATION_DECAY_RATE = 0.025;
    /** Quarterly efficiency gain scalar per unit of logarithmic overinvestment above replacement CapEx. */
    public const MODERNIZATION_GAIN_RATE = 0.012;
    /** Structural minimum operating margin floor under a depleted, under-invested field base. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.02;
    /** Structural maximum operating margin ceiling at mid-cycle prices, set by the lifting cost of the marginal barrel. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.32;
    /** Upstream CapEx follows the price deck: US oil & gas drilling (FRED IPN213111S) on the real WTI cycle (WTISPLC/CPIAUCSL), HP(1600) quarterly 1972-2024, peak elasticity 0.58 at a 1-2 quarter lag. */
    public const CAPEX_CYCLICALITY = 0.58;
    /** Baseline secular growth rate for a mature producer. */
    public const SECULAR_GROWTH = 0.01;
    /** Share of construction in progress completed each quarter (about two years from sanction to first production). */
    public const CAPEX_COMPLETION_RATE = 0.125;
    /** Annual mean reversion of ROIC toward the cost of capital. */
    public const ROIC_REVERSION_SPEED = 0.3;
    /** Working capital intensity: receivables on a month of sales plus field inventory. */
    public const WORKING_CAPITAL_INTENSITY = 0.15;

    // --- Valuation ---
    /** Book (replacement cost) weight in fair value when normalized EPS is negative: trough producers trade on assets. */
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

    public function getSecularGrowthRate(Stock $stock): float
    {
        return self::SECULAR_GROWTH;
    }

    public function getCapexCyclicality(): float
    {
        return self::CAPEX_CYCLICALITY;
    }

    /**
     * A producer budgets against the oil price, not the domestic economy: the smoothed price's log deviation
     * from its equilibrium, the same smoothed price its revenue is struck at. The EMA supplies the lag the
     * elasticity peaks at.
     */
    public function getCapexCycleSignal(Stock $stock, MacroStateDTO $macroState): float
    {
        if ($macroState->energyPriceIndexEma <= 0.0) {
            return 0.0;
        }

        return log($macroState->energyPriceIndexEma / MacroEngine::ENERGY_BASELINE);
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.30, 'revenue_weight' => 0.70];
    }

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        return self::WORKING_CAPITAL_INTENSITY;
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        // The price lives in the benchmark indices the physics reads, so neither the selling price nor the cost
        // base inflates at the engine level, and volume answers only to the currency it is translated at.
        return [
            'macro_demand_shift' => $this->resolveFxDemandShift($macroState),
            'pricing_power_multiplier' => 1.0,
            'input_cost_multiplier' => 1.0,
        ];
    }

    /**
     * Production mix and hedge ratio for this firm, ticker override first.
     *
     * @return array{liquids_share: float, hedge_ratio: float}
     */
    public function resolveProductionProfile(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::LiquidsRevenueShare->value => self::LIQUIDS_REVENUE_SHARE,
            ModelParam::OilHedgeRatio->value       => self::OIL_HEDGE_RATIO,
        ]);

        return [
            'liquids_share' => max(0.0, min(1.0, $params[ModelParam::LiquidsRevenueShare])),
            'hedge_ratio'   => max(0.0, min(1.0, $params[ModelParam::OilHedgeRatio])),
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
        ['liquids_share' => $liquidsShare, 'hedge_ratio' => $hedgeRatio] = $this->resolveProductionProfile($stock);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        $oilZ   = $streams->generateZ('crude_oil', self::PRODUCTION_SHOCK_PERSISTENCE);
        $gasZ   = $streams->generateZ('natural_gas', self::PRODUCTION_SHOCK_PERSISTENCE);
        $basisZ = $streams->generateExogenousZ('basis', self::BASIS_SHOCK_PERSISTENCE);
        $eventZ = $streams->generateExogenousZ('event', self::EVENT_SHOCK_PERSISTENCE);

        // --- Tail Risk ---
        $productionMultiplier = 1.0;
        $disasterPenalty = 0.0;
        $eventType = null;
        if ($eventZ < self::ENVIRONMENTAL_DISASTER_Z_SCORE) {
            $eventType = ShockEvent::ENVIRONMENTAL_DISASTER;
            $disasterPenalty = self::ENVIRONMENTAL_DISASTER_PENALTY;
            $productionMultiplier = self::DISASTER_PRODUCTION_MULT;
        } elseif ($eventZ < self::GEOPOLITICAL_SANCTIONS_Z_SCORE) {
            $eventType = ShockEvent::GEOPOLITICAL_SANCTIONS;
            $productionMultiplier = self::SANCTIONS_PRODUCTION_MULT;
        }

        // --- Realized Price ---
        // Oil sells partly at the strikes the swap ladder locked in over the last year, the rest at spot.
        $oilSpot = $macroState->energyPriceIndexEma / MacroEngine::ENERGY_BASELINE;
        $gasSpot = $macroState->naturalGasPriceIndexEma / MacroEngine::NATURAL_GAS_BASELINE;
        $hedgedStrike = $this->rollHedgeLadder($streams, $macroState) / MacroEngine::ENERGY_BASELINE;
        $basisShift = $basisZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::BASIS_DIFFERENTIAL_VOL_SCALAR;
        $oilRealized = (($hedgeRatio * $hedgedStrike) + ((1.0 - $hedgeRatio) * $oilSpot)) * (1.0 + $basisShift);
        $gasRealized = $gasSpot * (1.0 + $basisShift);

        // --- Volume x Price ---
        $oilVolume = max(0.0, (1.0 + ($oilZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR)) * $productionMultiplier);
        $gasVolume = max(0.0, (1.0 + ($gasZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR)) * $productionMultiplier);

        $streamRevenues = [
            'crude_oil'   => max(0.0, $expectedRevenue * $liquidsShare * $oilVolume * $oilRealized),
            'natural_gas' => max(0.0, $expectedRevenue * (1.0 - $liquidsShare) * $gasVolume * $gasRealized),
        ];
        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Revenue at the prices the cost base was sized for, so the ratio below is per barrel.
        $volumeRevenue = $expectedRevenue * (($liquidsShare * $oilVolume) + ((1.0 - $liquidsShare) * $gasVolume));
        $priceRelative = $volumeRevenue > 0.0 ? $actualRevenue / $volumeRevenue : 1.0;

        // --- Per-Barrel Cost Base ---
        // Lifting and processing costs (diesel and power, consumables, field payroll) inflate with their input
        // markets and are paid per barrel: re-expressed against the realized price, they rise as a share of a
        // slumping revenue line and fall as a share of a booming one. Stricter rules on extraction cost productivity,
        // and every barrel costs that much more to lift.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);
        $extractionRules = MathUtility::getInstance()->calculateProductivityLossCostFactor(FinancialConstants::ENVIRONMENTAL_REGULATION_TFP_LOSS * $macroState->extractionStringency);
        $perBarrelCostRatio = (($realizedVariableMargin + $inputCostDrag) * $extractionRules) + $disasterPenalty;
        $clampedMargin = $this->clampMargin(MathUtility::getInstance()->calculatePerUnitCostRatio($perBarrelCostRatio, $priceRelative));

        $hedgeGain = $expectedRevenue * $liquidsShare * $oilVolume * $hedgeRatio * ($hedgedStrike - $oilSpot) * (1.0 + $basisShift);

        // The benchmark prices are public; the field's production is not. Dividing the price leg by the base
        // visibility lets a persistent price level converge to an unbiased consensus.
        $productionShock = $expectedRevenue > 0.0 ? ($volumeRevenue / $expectedRevenue) - 1.0 : 0.0;
        $observableShockZ = (($priceRelative - 1.0) / self::BASE_COVERAGE_VISIBILITY) + $productionShock;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $streams->resolveDominantShockZ([$oilZ, $gasZ, $basisZ], $eventZ),
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            kpis: [
                'realized_price_index' => $priceRelative,
                'hedge_gain' => $actualRevenue > 0.0 ? $hedgeGain / $actualRevenue : 0.0,
            ],
        );
    }

    /**
     * Rolls the oil swap ladder one quarter and returns the average strike delivered this quarter.
     *
     * Each quarter the firm sells forward one tranche for each of the next four quarters at the Schwartz (1997)
     * forward for that delivery date, so the volume delivered now carries four tranches struck one to four
     * quarters ago. A firm with no ladder yet opens with one struck on today's curve, so its first quarter
     * books no phantom hedge gain.
     */
    private function rollHedgeLadder(StreamContext $streams, MacroStateDTO $macroState): float
    {
        $tranches = self::HEDGE_LADDER_TRANCHES;
        $spot = $macroState->energyBasePrice > 0.0 ? $macroState->energyBasePrice : $macroState->energyPriceIndexEma;
        $theta = CommodityLogisticsSubsystem::resolveEnergyEquilibriumPrice($macroState->globalDemandGapEma, $macroState->energySupplyEma);
        $math = MathUtility::getInstance();
        $forward = static fn (int $quartersAhead): float => $math->calculateSchwartzForwardPrice(
            $spot,
            CommodityLogisticsSubsystem::ENERGY_MEAN_REVERSION,
            $theta,
            CommodityLogisticsSubsystem::ENERGY_VOLATILITY,
            $quartersAhead / 4.0
        );

        $slots = [];
        for ($d = 0; $d < $tranches; $d++) {
            $slots[$d] = $streams->getPersistedState(self::STATE_HEDGE_STRIKE_SUM_PREFIX . $d, 0.0);
        }
        if ($slots[0] <= 0.0) {
            for ($d = 0; $d < $tranches; $d++) {
                $slots[$d] = ($tranches - $d) * $forward($d);
            }
        }

        $deliveredStrike = $slots[0] / $tranches;

        for ($d = 0; $d < $tranches; $d++) {
            $carried = $d + 1 < $tranches ? $slots[$d + 1] : 0.0;
            $streams->registerState(self::STATE_HEDGE_STRIKE_SUM_PREFIX . $d, $carried + $forward($d + 1));
        }

        return $deliveredStrike;
    }

    /** Producers anchor to book (replacement cost of reserves and plant) at trough earnings and to mid-cycle earnings otherwise. */
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
            'energy_base_price',
            'energy_cost_push_lag',
            'energy_price_index_ema',
            'energy_supply_ema',
            'exchange_rate_index_ema',
            'extraction_stringency',
            'global_demand_gap_ema',
            'industrial_metals_index_ema',
            'natural_gas_price_index_ema',
            'real_wage_gap',
        ];
    }
}
