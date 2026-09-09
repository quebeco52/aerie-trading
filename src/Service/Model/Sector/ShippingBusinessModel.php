<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Math\FinancialConstants;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Global Maritime Shipping and Freight Logistics.
 * 
 * Financial Physics:
 * - Hyper-Cyclical Spot-Rate Operational Leverage: Revenues are violently levered to global GDP and the macro output gap.
 * - During economic expansions (+Output Gap), spot freight rates surge exponentially against fixed fleet overhead, producing massive Free Cash Flow explosions.
 * - During economic contractions (-Output Gap), capacity gluts and falling container rates cause severe operating losses.
 * - Heavy Physical Depreciation: Vessels and containers rust and degrade rapidly, requiring consistent, non-discretionary CapEx.
 */
class ShippingBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Global trade volume times a fixed fleet; the freight market is worldwide. */
    public const OPERATING_CYCLICALITY = 1.60;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.30;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.30;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['energy' => 0.35, 'labor' => 0.15, 'ppi' => 0.05];
    /** Bunker adjustment factors on contracts reprice within a quarter; spot voyages carry the fuel themselves. */
    public const INPUT_PASS_THROUGH_LAG_YEARS = 0.25;
    /** Half the book is contracted with bunker clauses, half is spot: bunker moves are half recovered. */
    public const PRICING_POWER_INDEX = 0.50;

    // --- Balance Sheet Realism ---
    /** Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Chartered-in vessels are leases in all but name. */
    public const LEASE_LIABILITY_INTENSITY = 0.35;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Vessel depreciation, bunker fuel and port charges dominate; crew wages are a minority of overhead. */
    public const FIXED_COST_LABOR_SHARE = 0.30;

    // --- FX Exposure ---
    /** Share of revenue whose competitiveness moves with the trade-weighted exchange rate. Charter and spot rates are quoted in the trade currency against globally mobile tonnage. */
    public const FX_REVENUE_EXPOSURE = 0.10;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.75;
    public const BASE_COVERAGE_ERROR = 0.10;
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.50;
        public function getReversionSpeed(): float { return 0.3; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.125; }

    public function getSeasonalityFactors(): array
    {
        return [0.85, 0.95, 1.10, 1.10]; // Pre-holiday maritime trade peak in Q3-Q4
    }
    public function getCapexCyclicality(): float
    {
        return 3.0;
    }
    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.20, 'revenue_weight' => 0.80];
    }

    // --- Dual-Stream Maritime Charter Architecture ---
    /** Baseline fraction of revenue derived from volatile spot market freight and short-term voyage charters. */
    public const SPOT_CHARTER_WEIGHT     = 0.50;
    /** Baseline fraction of revenue derived from long-term contracted time charters and dedicated logistics. */
    public const CONTRACT_CHARTER_WEIGHT = 0.50;

    // --- Hyper-Cyclical Spot Rate Physics ---
    /** Macroeconomic demand shift sensitivity to global trade output gaps. */
    public const MACRO_DEMAND_SCALAR       = 1.80;
    /** Volatility multiplier for top-line revenue shocks driven by maritime freight spot rates. */
    public const REVENUE_VARIANCE_SCALAR   = 0.25;
    /** Positive output gap threshold triggering exponential spot rate boom multipliers. */
    public const SPOT_BOOM_GAP_THRESHOLD   = 0.015;
    /** Output gap multiplier scaling spot freight rate surges during global trade booms. */
    public const SPOT_BOOM_RATE_MULT       = 4.00;
    /** Negative output gap threshold triggering vessel capacity glut penalties. */
    public const SPOT_GLUT_GAP_THRESHOLD   = -0.015;
    /** Output gap multiplier scaling rate collapses during trade slowdowns and capacity gluts. */
    public const SPOT_GLUT_RATE_MULT       = 3.00;
    /** Continuous global trade elasticity scalar scaling spot freight rates smoothly with output gap. */
    public const CONTINUOUS_SPOT_RATE_SCALAR = 3.50;

    // --- Global Trade & Supply Chain Pressure Transmission ---
    /** Sensitivity of maritime container and bulk freight demand to trade balance shifts. */
    public const TRADE_BALANCE_SENSITIVITY = 1.50;
    /** Spot freight rate surge multiplier per unit of NY Fed global supply chain pressure. */
    public const GSCPI_FREIGHT_BOOST_SCALAR = 0.10;

    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Event Lore Thresholds ---
    /** Positive z-score threshold required during trade booms to trigger port congestion lore. */
    public const LORE_CONGESTION_Z_SCORE   = 1.50;
    /** Fraction of the contracted charter backlog delivered each quarter (multi-year time charters, ~2.3 quarters of coverage). */
    public const CONTRACT_BACKLOG_BURN_RATE = 0.30;
    /** Regime key for a port-congestion super-cycle that keeps spot rates elevated until berths clear. */
    public const REGIME_PORT_CONGESTION = 'port_congestion';
    /** Quarterly probability the port backlog clears (~3 quarter expected congestion). */
    public const PORT_CONGESTION_EXIT_HAZARD = 0.33;
    /** Spot rate uplift while congestion ties up effective fleet capacity. */
    public const PORT_CONGESTION_SPOT_RATE_BOOST = 0.15;
    /** Regime key for a newbuild capacity glut that depresses spot rates until tonnage is absorbed. */
    public const REGIME_CAPACITY_GLUT = 'capacity_glut';
    /** Quarterly probability the tonnage overhang is absorbed by scrapping and trade growth (~5 quarters). */
    public const CAPACITY_GLUT_EXIT_HAZARD = 0.20;
    /** Spot rate drag while surplus tonnage chases cargo. */
    public const CAPACITY_GLUT_SPOT_RATE_DRAG = 0.10;
    /** Negative z-score threshold required during trade gluts to trigger operating loss lore. */
    public const LORE_GLUT_Z_SCORE         = -1.50;

    // --- Analyst Visibility & Error ---
    // Moved to getCoverageProfile() — see MarketConsensusEngine.

    // --- Spot Rate Cycle & Output Gap Physics ---
    /** Operating margin mean reversion speed: fast speed reflects rapid shipbuilding order responses. */
    public const SPOT_REVERSION_SPEED      = 4.0;

    // --- Vessel Fleet Aging & Eco-Fleet Reinvestment Physics ---
    /** Quarterly efficiency decay rate per unit of underinvestment below fleet replacement CapEx. */
    public const VESSEL_AGING_DECAY_RATE      = 0.025;
    /** Quarterly efficiency gain scalar per unit of eco-fleet modernization above replacement CapEx. */
    public const ECO_FLEET_MODERNIZATION_RATE = 0.012;
    /** Structural minimum operating margin floor under severe vessel aging and bunker fuel drag. */
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.05;
    /** Structural maximum operating margin ceiling for state-of-the-art eco-fuel vessel fleets. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.38;

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $outputGap = $this->resolveLaggedOutputGap($stock, $macroState);
        $tradeShift = MathUtility::calculateTradeBalanceShift($macroState->tradeBalanceToGdpEma, sensitivity: self::TRADE_BALANCE_SENSITIVITY);
        $beta = $this->getOperatingCyclicality($stock);

        // Extreme sensitivity to global economic momentum and trade volume
        return [
            'macro_demand_shift' => ($outputGap * $beta * self::MACRO_DEMAND_SCALAR) + $this->resolveFxDemandShift($macroState) + $tradeShift,
            ...$this->resolvePricingMultipliers($stock, $macroState),
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::SpotCharterWeight->value     => self::SPOT_CHARTER_WEIGHT,
            ModelParam::ContractCharterWeight->value => self::CONTRACT_CHARTER_WEIGHT,
        ]);

        $spotWeight     = $params[ModelParam::SpotCharterWeight];
        $contractWeight = $params[ModelParam::ContractCharterWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'spot'     => $params[ModelParam::SpotCharterWeight],
            'contract' => $params[ModelParam::ContractCharterWeight],
        ]);

        $spotWeight     = $activeWeights['spot'];
        $contractWeight = $activeWeights['contract'];

        // Independent stream Z-scores with AR(1) persistence
        $spotZ     = $streams->generateZ('spot', 0.35); // Spot ocean freight / Baltic Dry variance
        $contractZ = $streams->generateZ('contract', 0.50); // Multi-year contracted logistics lines

        // Spot Rate Super-Cycle vs. Capacity Glut
        // Crucially, spot rate elasticity applies continuously to spot charter revenue ($spotWeight).
        $outputGap = $macroState->outputGapEma;
        $freightShift = ($macroState->freightRateIndexEma - 100.0) / 100.0;
        $metalsShift = ($macroState->industrialMetalsIndexEma - 100.0) / 100.0;
        $tradeShift = MathUtility::calculateTradeBalanceShift($macroState->tradeBalanceToGdpEma, sensitivity: self::TRADE_BALANCE_SENSITIVITY);
        $gscpiShift = max(0.0, $macroState->supplyChainPressureIndexEma - MacroEngine::GSCPI_BASELINE);
        $spotRateMultiplier = ($outputGap * self::CONTINUOUS_SPOT_RATE_SCALAR) + ($freightShift * 0.50) + ($metalsShift * 0.15) + $tradeShift + ($gscpiShift * self::GSCPI_FREIGHT_BOOST_SCALAR);
        $eventType = null;

        // Congestion and glut are regimes, not blips: berths take quarters to clear and a newbuild
        // overhang takes years of scrapping and trade growth to absorb.
        $congestionElapsed = $streams->evolveRegime(self::REGIME_PORT_CONGESTION, 0.0, self::PORT_CONGESTION_EXIT_HAZARD);
        $glutElapsed       = $streams->evolveRegime(self::REGIME_CAPACITY_GLUT, 0.0, self::CAPACITY_GLUT_EXIT_HAZARD);

        if (($outputGap > self::SPOT_BOOM_GAP_THRESHOLD || $gscpiShift > 1.0) && $spotZ > self::LORE_CONGESTION_Z_SCORE && $congestionElapsed === 0 && $glutElapsed === 0) {
            $congestionElapsed = $streams->startRegime(self::REGIME_PORT_CONGESTION);
            $eventType = ShockEvent::SHIPPING_PORT_CONGESTION;
        } elseif ($outputGap < self::SPOT_GLUT_GAP_THRESHOLD && $spotZ < self::LORE_GLUT_Z_SCORE && $glutElapsed === 0 && $congestionElapsed === 0) {
            $glutElapsed = $streams->startRegime(self::REGIME_CAPACITY_GLUT);
            $eventType = ShockEvent::SHIPPING_CAPACITY_GLUT;
        }

        if ($congestionElapsed > 0) {
            $spotRateMultiplier += self::PORT_CONGESTION_SPOT_RATE_BOOST;
        } elseif ($glutElapsed > 0) {
            $spotRateMultiplier -= self::CAPACITY_GLUT_SPOT_RATE_DRAG;
        }

        $spotRevenue     = max(0.0, $expectedRevenue * $spotWeight * (1.0 + ($spotZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $spotRateMultiplier));
        // Spot freight rates reprice a fixed fleet's voyages: the rate cycle is price, the ships sail either way.
        $priceRevenue    = $expectedRevenue * $spotWeight * $spotRateMultiplier;
        // Time charters are fixed into a multi-quarter contract backlog and recognized as voyages complete.
        $contractBook = $streams->recognizeBacklog('contract', $expectedRevenue * $contractWeight, max(0.0, 1.0 + ($contractZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $this->resolveFxDemandShift($macroState)), self::CONTRACT_BACKLOG_BURN_RATE);
        $contractRevenue = $contractBook['revenue'];

        $streamRevenues = [
            'spot'     => $spotRevenue,
            'contract' => $contractRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // Fuel and Bunker Cost Inflation / Deflation: bunker is bought at spot and only the contracted half of
        // the book carries a bunker adjustment factor, so a crude spike squeezes and a crude slump pays a dividend.
        $bunkerInflationAdjustment = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $bunkerInflationAdjustment);

        $primaryShockZ = $streams->resolveDominantShockZ([$spotZ, $contractZ]);
        // observableShockZ: Baltic Dry Index and Harpex are public daily data (~75% visibility via getCoverageProfile)
        $spotBase = max(1.0, $expectedRevenue * $spotWeight);
        $spotShock = ($spotRevenue - $spotBase) / $spotBase;
        $contractBase = max(1.0, $expectedRevenue * $contractWeight);
        $contractShock = ($contractRevenue - $contractBase) / $contractBase;
        $observableShockZ = ($spotShock * $spotWeight) + ($contractShock * $contractWeight);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
                    kpis: ['contract_book_to_bill' => $contractBook['book_to_bill'], 'contract_backlog_quarters' => $contractBook['backlog_quarters']],
            priceRevenue: $priceRevenue,
        );
    }

    public function getMarginReversionSpeed(): float
    {
        // Spot rate booms attract new shipbuilding orders, causing margins to mean-revert aggressively once new vessels launch
        return self::SPOT_REVERSION_SPEED;
    }

    /** Vessel aging & bunker fuel drag toward floor */
    public function getDepreciationDecayRate(): float
    {
        return self::VESSEL_AGING_DECAY_RATE;
    }

    /** Eco-fleet modernization expands margin ceiling */
    public function getModernizationGainRate(): float
    {
        return self::ECO_FLEET_MODERNIZATION_RATE;
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        // Maritime shipping is deeply asset-heavy. During cyclical freight troughs (negative normalized EPS),
        // valuation shifts heavily toward Tangible Net Asset Value (P/B book replacement value) rather than discounted trough earnings.
        $bookWeight = $normalizedEps <= 0.0 ? 0.65 : 0.30;
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
            'freight_rate_index_ema',
            'industrial_metals_index_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'supply_chain_pressure_index_ema',
            'tips_breakeven_ema',
            'trade_balance_to_gdp_ema',
            'wage_growth_ema',
        ];
    }
}
