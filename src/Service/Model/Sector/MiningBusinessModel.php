<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for metals miners (copper, aluminium, gold).
 *
 * Financial Physics:
 * - Revenue is payable metal times the benchmark price. The price is the market's, the industrial metals
 *   complex cleared on global demand; the mine's only price of its own is a small realization differential
 *   (concentrate grade and payability, treatment charges, quotational period).
 * - Haulage, milling, power and site payroll are paid per tonne of ore, not per dollar of metal, so a price
 *   move lands on margin in full and a slump leaves the whole cost base standing.
 * - Output does not follow the domestic cycle: every tonne clears into a global pool at the going price.
 *   Grade decline and depletion are carried by the efficiency decay of an under-invested plant.
 * - The majors sell unhedged, so the realized price is the spot price.
 * - A gold miner has no precious-metals index to price off and reads the industrial complex here, which moves
 *   against gold in a downturn; a gold firm needs its own benchmark before it is seeded.
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
    /** Shares of the per-tonne cost base bought in tracked input markets: haul-truck diesel and mill power, grinding media, explosives and reagents, site payroll. */
    public const INPUT_COST_EXPOSURES = ['energy' => 0.15, 'metals' => 0.05, 'ppi' => 0.10, 'labor' => 0.20];
    /** Price takers recover none of their own input inflation through pricing: the market sets the quote. */
    public const PRICING_POWER_INDEX = 0.00;

    // --- FX Exposure ---
    /** Share of revenue whose home-currency realization moves with the trade-weighted exchange rate: metals are priced in the world market. */
    public const FX_REVENUE_EXPOSURE = 0.30;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Site overhead is dominated by the fleet, the mill and royalties, not payroll. */
    public const FIXED_COST_LABOR_SHARE = 0.30;

    // --- Analyst Visibility & Error ---
    /** Base coverage visibility: metals prices are published daily, so the sell side sees most of the quarter. */
    public const BASE_COVERAGE_VISIBILITY = 0.80;
    /** Base coverage forecasting error given the swings in the benchmark price. */
    public const BASE_COVERAGE_ERROR = 0.10;

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

    /** The benchmark metals price over its baseline. */
    public function resolveBenchmarkPriceRelative(MacroStateDTO $macroState): float
    {
        return max(0.0, $macroState->industrialMetalsIndexEma) / MacroEngine::METALS_BASELINE;
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

        $outputZ      = $streams->generateZ('metal_sales', self::OUTPUT_SHOCK_PERSISTENCE);
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

        // --- Tonnes x Price ---
        $realizationShift = $realizationZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::REALIZATION_DIFFERENTIAL_VOL_SCALAR;
        $priceRelative = $this->resolveBenchmarkPriceRelative($macroState) * (1.0 + $realizationShift);
        $output = max(0.0, (1.0 + ($outputZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR)) * $outputMultiplier);

        $actualRevenue = max(0.0, $expectedRevenue * $output * $priceRelative);
        $streamRevenues = ['metal_sales' => $actualRevenue];
        $streams->recordStreamShares($streamRevenues);

        // --- Per-Tonne Cost Base ---
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);
        $perTonneCostRatio = $realizedVariableMargin + $inputCostDrag + $disasterPenalty;
        $clampedMargin = $this->clampMargin(MathUtility::getInstance()->calculatePerUnitCostRatio($perTonneCostRatio, $priceRelative));

        // The benchmark is public; the mine's own output is not. Dividing the price leg by the base visibility
        // lets a persistent price level converge to an unbiased consensus.
        $observableShockZ = (($priceRelative - 1.0) / self::BASE_COVERAGE_VISIBILITY) + ($output - 1.0);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $streams->resolveDominantShockZ([$outputZ, $realizationZ], $eventZ),
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
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'industrial_metals_index_ema',
            'producer_price_inflation_ema',
            'wage_growth_ema',
        ];
    }
}
