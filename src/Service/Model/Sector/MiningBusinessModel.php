<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\InputOutputExposures;
use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for miners, from single-commodity producers to diversified majors.
 *
 * Financial Physics:
 * - Revenue is payable product times the benchmark it sells against, stream by stream: base and ferrous metals
 *   off the industrial metals complex, precious metals off gold, coal off natural gas (the fuel it is switched
 *   against in power generation), potash off the crop complex. The mine's only price of its own is a small
 *   realization differential (grade and payability, treatment charges, quotational period).
 * - A firm's mix is its asset portfolio: a copper pure-play, a gold producer and a diversified major run the
 *   same physics with different stream weights. Gold moves against the base metals in a downturn, so a
 *   by-product or precious leg cushions a copper bust the way it does for a real major.
 * - Haulage, milling, power and site payroll are paid per tonne of ore, not per dollar of product, so a price
 *   move lands on margin in full and a slump leaves the whole cost base standing.
 * - Output does not follow the domestic cycle: every tonne clears into a global pool at the going price.
 *   Grade decline and depletion are carried by the efficiency decay of an under-invested plant.
 * - The majors sell unhedged, so the realized price is the spot price.
 */
class MiningBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle. A mine sells every tonne into a global pool at the going price, so the domestic output gap does not move its volume (band floor). */
    public const OPERATING_CYCLICALITY = 0.20;
    /** Own-price elasticity of the firm's demand: a small share of a global market faces residual demand far more elastic than the market's (Landes & Posner 1981), so any premium over the benchmark loses the sale. Inert while the price is pinned to the index. */
    public const PRICE_ELASTICITY_OF_DEMAND = 2.50;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. Peers barely notice a rival's tonne in a global market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.15;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain content (labor still the model's own). */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::MINING;
    /** Price takers recover none of their own input inflation through pricing: the market sets the quote. */
    public const PRICING_POWER_INDEX = 0.00;

    // --- FX Exposure ---
    /** Share of revenue whose home-currency realization moves with the trade-weighted exchange rate: metals are priced in the world market. */
    public const FX_REVENUE_EXPOSURE = 0.30;

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility: metals prices are published daily, so the sell side sees most of the quarter. */
    public const BASE_COVERAGE_VISIBILITY = 0.80;
    /** Base coverage forecasting error given the swings in the benchmark price. */
    public const BASE_COVERAGE_ERROR = 0.10;

    // --- Product Mix (default: a base-metals pure-play) ---
    /** Default revenue share at baseline prices from base and ferrous metals. */
    public const BASE_METALS_WEIGHT = 1.00;
    /** Default revenue share at baseline prices from precious metals. */
    public const PRECIOUS_METALS_WEIGHT = 0.00;
    /** Default revenue share at baseline prices from energy minerals. */
    public const ENERGY_MINERALS_WEIGHT = 0.00;
    /** Default revenue share at baseline prices from fertilizer minerals. */
    public const FERTILIZER_MINERALS_WEIGHT = 0.00;

    // --- Revenue Shock Physics ---
    /** Volatility multiplier on the baseline volatility for mine output (grade, recovery, equipment availability) shocks. */
    public const REVENUE_VARIANCE_SCALAR = 0.25;
    /** Realization differential noise as a fraction of the output variance scalar (price itself is a market variable). */
    public const REALIZATION_DIFFERENTIAL_VOL_SCALAR = 0.40;
    /** Quarterly persistence of mine output shocks (a pit wall slip or a mill outage takes a few quarters to recover). */
    public const OUTPUT_SHOCK_PERSISTENCE = 0.35;
    /** Quarterly persistence of the realization differential (treatment charges and grade premia reset with annual contracts). */
    public const REALIZATION_SHOCK_PERSISTENCE = 0.15;
    /** Quarterly persistence of the firm's own tail-event draw. */
    public const EVENT_SHOCK_PERSISTENCE = 0.10;

    // --- Tail Risk & Shock Events ---
    /** Negative Z-score threshold indicating resource nationalism (an export ban or a revoked concession) shutting in part of the output. */
    public const GEOPOLITICAL_SANCTIONS_Z_SCORE = -2.20;
    /** Output multiplier applied while the affected operation is shut in. */
    public const SANCTIONS_OUTPUT_MULT = 0.75;
    /** Negative Z-score threshold indicating a tailings dam failure or pit wall collapse. */
    public const ENVIRONMENTAL_DISASTER_Z_SCORE = -2.60;
    /** Per-tonne cost ratio penalty funding remediation, containment and repairs. */
    public const ENVIRONMENTAL_DISASTER_PENALTY = 0.08;
    /** Output multiplier applied while the damaged operation is shut in. */
    public const DISASTER_OUTPUT_MULT = 0.85;

    // --- Capital Reinvestment & Asset Depreciation Physics ---
    /** Quarterly efficiency decay rate per unit of underinvestment below replacement CapEx (grades decline and haul distances lengthen). */
    public const DEPRECIATION_DECAY_RATE = 0.025;
    /** Quarterly efficiency gain scalar per unit of logarithmic overinvestment above replacement CapEx. */
    public const MODERNIZATION_GAIN_RATE = 0.012;
    /** Structural minimum operating margin floor for a depleted, low-grade operation. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.02;
    /** Structural maximum operating margin ceiling at mid-cycle prices, set by the cost of the marginal tonne. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.32;
    /** Mining CapEx swings hard with the price deck: expansions are sanctioned in booms and deferred in busts. */
    public const CAPEX_CYCLICALITY = 3.0;
    /** Baseline secular growth rate for a mature producer. */
    public const SECULAR_GROWTH = 0.01;
    /** Share of construction in progress completed each quarter (about two years for a brownfield expansion). */
    public const CAPEX_COMPLETION_RATE = 0.125;
    /** Annual mean reversion of ROIC toward the cost of capital. */
    public const ROIC_REVERSION_SPEED = 0.3;
    /** Working capital intensity: concentrate and stockpile inventory plus receivables on provisional pricing. */
    public const WORKING_CAPITAL_INTENSITY = 0.15;

    // --- Valuation ---
    /** Book (replacement cost) weight in fair value when normalized EPS is negative: trough miners trade on assets. */
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
        // The price lives in the metals index the physics reads, so neither the selling price nor the cost base
        // inflates at the engine level, and volume answers only to the currency it is translated at.
        return [
            'macro_demand_shift' => $this->resolveFxDemandShift($macroState),
            'pricing_power_multiplier' => 1.0,
            'input_cost_multiplier' => 1.0,
        ];
    }

    /**
     * Each product stream's benchmark price over its baseline.
     *
     * @return array{base_metals: float, precious_metals: float, energy_minerals: float, fertilizer_minerals: float}
     */
    public function resolveStreamPriceRelatives(MacroStateDTO $macroState): array
    {
        return [
            'base_metals' => max(0.0, $macroState->industrialMetalsIndexEma) / MacroEngine::METALS_BASELINE,
            'precious_metals' => max(0.0, $macroState->goldPriceIndexEma) / MacroEngine::GOLD_BASELINE,
            'energy_minerals' => max(0.0, $macroState->naturalGasPriceIndexEma) / MacroEngine::NATURAL_GAS_BASELINE,
            'fertilizer_minerals' => max(0.0, $macroState->agriculturalCommodityIndexEma) / MacroEngine::AGRI_BASELINE,
        ];
    }

    /**
     * The firm's product mix at baseline prices, ticker override first, normalised to sum to one.
     *
     * @return array<string, float> Stream key => revenue share, zero-weight streams dropped.
     */
    public function resolveProductMix(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::BaseMetalsWeight->value => self::BASE_METALS_WEIGHT,
            ModelParam::PreciousMetalsWeight->value => self::PRECIOUS_METALS_WEIGHT,
            ModelParam::EnergyMineralsWeight->value => self::ENERGY_MINERALS_WEIGHT,
            ModelParam::FertilizerMineralsWeight->value => self::FERTILIZER_MINERALS_WEIGHT,
        ]);
        $weights = array_filter([
            'base_metals' => max(0.0, $params[ModelParam::BaseMetalsWeight]),
            'precious_metals' => max(0.0, $params[ModelParam::PreciousMetalsWeight]),
            'energy_minerals' => max(0.0, $params[ModelParam::EnergyMineralsWeight]),
            'fertilizer_minerals' => max(0.0, $params[ModelParam::FertilizerMineralsWeight]),
        ], static fn (float $weight): bool => $weight > 0.0);
        $total = array_sum($weights);

        return $total > 0.0
            ? array_map(static fn (float $weight): float => $weight / $total, $weights)
            : ['base_metals' => 1.0];
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

        $mix = $this->resolveProductMix($stock);
        $outputZ = [];
        foreach (array_keys($mix) as $stream) {
            $outputZ[$stream] = $streams->generateZ($stream, self::OUTPUT_SHOCK_PERSISTENCE);
        }
        $realizationZ = $streams->generateExogenousZ('realization', self::REALIZATION_SHOCK_PERSISTENCE);
        $eventZ       = $streams->generateExogenousZ('event', self::EVENT_SHOCK_PERSISTENCE);

        // --- Tail Risk ---
        $outputMultiplier = 1.0;
        $disasterPenalty = 0.0;
        $eventType = null;
        if ($eventZ < self::ENVIRONMENTAL_DISASTER_Z_SCORE) {
            $eventType = ShockEvent::ENVIRONMENTAL_DISASTER;
            $disasterPenalty = self::ENVIRONMENTAL_DISASTER_PENALTY;
            $outputMultiplier = self::DISASTER_OUTPUT_MULT;
        } elseif ($eventZ < self::GEOPOLITICAL_SANCTIONS_Z_SCORE) {
            $eventType = ShockEvent::GEOPOLITICAL_SANCTIONS;
            $outputMultiplier = self::SANCTIONS_OUTPUT_MULT;
        }

        // --- Tonnes x Price, stream by stream ---
        $realizationShift = $realizationZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::REALIZATION_DIFFERENTIAL_VOL_SCALAR;
        $benchmarks = $this->resolveStreamPriceRelatives($macroState);
        $streamRevenues = [];
        $volumeRevenue = 0.0;
        foreach ($mix as $stream => $weight) {
            $output = max(0.0, (1.0 + ($outputZ[$stream] * $baselineVol * self::REVENUE_VARIANCE_SCALAR)) * $outputMultiplier);
            $streamRevenues[$stream] = max(0.0, $expectedRevenue * $weight * $output * $benchmarks[$stream] * (1.0 + $realizationShift));
            $volumeRevenue += $expectedRevenue * $weight * $output;
        }
        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);
        // Realized price across the mix, over the prices the cost base was sized for.
        $priceRelative = $volumeRevenue > 0.0 ? $actualRevenue / $volumeRevenue : 1.0;

        // --- Per-Tonne Cost Base ---
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);
        $perTonneCostRatio = $realizedVariableMargin + $inputCostDrag + $disasterPenalty;
        $clampedMargin = $this->clampMargin(MathUtility::getInstance()->calculatePerUnitCostRatio($perTonneCostRatio, $priceRelative));

        // The benchmark is public; the mine's own output is not. Dividing the price leg by the base visibility
        // lets a persistent price level converge to an unbiased consensus.
        $outputShock = $expectedRevenue > 0.0 ? ($volumeRevenue / $expectedRevenue) - 1.0 : 0.0;
        $observableShockZ = (($priceRelative - 1.0) / self::BASE_COVERAGE_VISIBILITY) + $outputShock;

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $streams->resolveDominantShockZ([...array_values($outputZ), $realizationZ], $eventZ),
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            kpis: [
                'realized_price_index' => $priceRelative,
            ],
        );
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        // Miners anchor to book (replacement cost of the mine and mill) at trough earnings and to mid-cycle earnings otherwise.
        $bookWeight = $normalizedEps < 0 ? self::TROUGH_BOOK_WEIGHT : self::MID_CYCLE_BOOK_WEIGHT;
        $earningsWeight = 1.0 - $bookWeight;

        $baseConsensus = ($earningsValue * $earningsWeight) + ($pbFairValue * $bookWeight);
        return $dividendSupportValue > 0.0
            ? ($baseConsensus * (1.0 - FinancialConstants::FAIR_VALUE_DDM_WEIGHT)) + ($dividendSupportValue * FinancialConstants::FAIR_VALUE_DDM_WEIGHT)
            : $baseConsensus;
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
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'gold_price_index_ema',
            'industrial_metals_index_ema',
            'natural_gas_price_index_ema',
            'real_wage_gap',
        ];
    }
}
