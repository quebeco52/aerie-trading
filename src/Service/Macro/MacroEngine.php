<?php

namespace App\Service\Macro;

use Psr\Log\LoggerInterface;
use App\Service\Math\MathUtility;


class MacroEngine
{
    public const REDIS_MACRO_STATE = 'macroeconomic_state';

    // --- Central Bank & Structural Constraints ---
    /** The Federal Reserve's long-term annual inflation target (2%). */
    public const TARGET_INFLATION = 0.02;
    /** The natural real rate of interest (r*) representing neutral monetary policy. */
    public const NATURAL_RATE = 0.015;
    /** The baseline corporate tax rate for standard physical companies. */
    public const BASE_CORPORATE_TAX_RATE = 0.21;
    /** The baseline historical equity risk premium expected over risk-free assets. */
    public const BASE_EQUITY_RISK_PREMIUM = 0.045;
    /** Campbell-Cochrane (1999) habit formation risk aversion sensitivity. */
    public const HABIT_RISK_AVERSION_COEFF = 4.0;
    /** Structural floor: equities must logically yield more than risk-free T-bills. */
    public const MIN_EQUITY_RISK_PREMIUM = 0.02;
    /** The discount to the policy rate representing the yield on corporate treasury cash. */
    public const CASH_YIELD_SPREAD = 0.0025;

    // --- KALDOR-KALECKI 2D LIMIT CYCLE ---
    /** Linear momentum of aggregate demand feedback loop. */
    public const KALDOR_MOMENTUM = 0.15;
    /** Cubic stabilization factor bounding extreme boom/bust expansions. */
    public const KALDOR_CAPACITY = 180.0;
    /** Sensitivity of aggregate demand to real interest rate deviations from natural rate. */
    public const KALDOR_MONETARY_DRAG = 0.75;
    /** Countercyclical fiscal stimulus multiplier from corporate tax rate cuts. */
    public const KALDOR_FISCAL_MULTIPLIER = 0.50;
    /** Sensitivity of the output gap to physical capital stock overhang (excess capacity drags down growth). */
    public const KALDOR_CAPITAL_DRAG = 0.25;
    /** The rate at which business investment (output gap) accumulates into the physical capital stock. */
    public const CAPITAL_ACCUMULATION_RATE = 0.50;
    /** The rate at which physical capital depreciates, organically clearing overhangs and creating pent-up demand. */
    public const CAPITAL_DECAY_RATE = 0.15;
    /** Stochastic diffusion volatility of the macroeconomic output gap. */
    public const OUTPUT_GAP_DIFFUSION_SIGMA = 0.010;

    // OKUN'S LAW (LABOR MARKET)
    public const NATURAL_UNEMPLOYMENT = 0.04;
    public const OKUNS_COEFFICIENT = 0.4;
    public const OKUNS_HIRING_SPEED = 1.5;
    public const OKUNS_FIRING_SPEED = 3.0;



    // ENERGY SHOCK JUMP DIFFUSION
    /** Baseline index value for energy prices. */
    public const ENERGY_BASELINE = 100.0;
    public const ENERGY_JUMP_PROBABILITY = 0.05; // 5% chance of severe shock per year
    public const ENERGY_MEAN_REVERSION = 0.8;    // Speed of reversion to 100 baseline
    public const ENERGY_VOLATILITY = 0.25;       // Log-price volatility (Schwartz 1-factor sigma)
    public const ENERGY_JUMP_MEAN = 0.20;        // Mean log-return of energy shock (20% avg spike)
    public const ENERGY_JUMP_VOL = 0.10;         // Volatility of the jump size
    public const ENERGY_COST_PUSH_TRANSMISSION = 0.015;

    // --- GARCH-MIDAS Macroeconomic Volatility Constants (Engle, Ghysels, & Sohn 2013 Eq. 5) ---
    /** Long-run equilibrium baseline volatility (~15% VIX) during neutral economic conditions. */
    public const MACRO_VOL_BASE_ANCHOR            = 0.15;
    /** Sensitivity of exponential baseline volatility to output gap fluctuations (countercyclical). */
    public const MACRO_VOL_OUTPUT_GAP_SENSITIVITY = 10.0;
    /** Sensitivity of exponential baseline volatility to corporate credit spread deviations from baseline. */
    public const MACRO_VOL_CREDIT_SENSITIVITY     = 10.0;
    /** Sensitivity of exponential baseline volatility to yield curve slope (flattening/inversion increases vol). */
    public const MACRO_VOL_SLOPE_SENSITIVITY      = 8.0;
    /** Lower clamp for baseline volatility during extreme Goldilocks expansions (~10% VIX floor). */
    public const MACRO_VOL_MIN_BASELINE           = 0.10;
    /** Upper clamp for macro-driven baseline volatility to prevent infinite variance explosion. */
    public const MACRO_VOL_MAX_BASELINE           = 0.45;
    public const MACRO_VOL_KAPPA                  = 2.0;
    public const MACRO_VOL_SIGMA                  = 0.30;

    // SVJJ JUMP DIFFUSION CONSTANTS
    public const SVJJ_LAMBDA = 0.80;
    public const SVJJ_P_UP = 0.35;
    public const SVJJ_ETA_UP = 10.0;
    public const SVJJ_ETA_DOWN = 5.0;
    public const SVJJ_MU_V = 0.05;



    // YIELD WEIGHTS
    public const BORROWING_POLICY_WEIGHT = 0.70;
    public const BORROWING_YIELD5Y_WEIGHT = 0.30;

    // --- TAYLOR RULE & THE EVANS RULE (FORWARD GUIDANCE) ---
    /** Weight on inflation deviations from the 2% target in the Taylor Rule. */
    public const TAYLOR_INFLATION_WEIGHT = 0.50;
    /** Weight on positive output gap during economic expansions. */
    public const TAYLOR_BOOM_WEIGHT = 0.50;
    /** Non-linear scaling factor amplifying rate cuts during deep recessions. */
    public const TAYLOR_RECESSION_SCALE = 5.0;
    /** Evans Rule forward guidance: Unemployment threshold (5.0% = natural rate + 1.0%) required before lifting off from ZLB. */
    public const EVANS_RULE_UNEMPLOYMENT = 0.050;
    /** Evans Rule forward guidance: Maximum inflation ceiling (2.5%) tolerated while holding rates at ZLB. */
    public const EVANS_RULE_INFLATION_CAP = 0.025;
    /** Central bank baseline interest rate smoothing speed per year. */
    public const CB_SMOOTHING_SPEED = 1.0;
    /** Inflation panic reaction multiplier accelerating rate hikes during inflation spikes. */
    public const CB_INFLATION_PANIC_SCALE = 50.0;
    /** Recession panic reaction multiplier accelerating emergency cuts during downturns. */
    public const CB_RECESSION_PANIC_SCALE = 100.0;
    /** Maximum annual rate hike velocity cap (Volcker-style panic speed cap). */
    public const CB_MAX_HIKE_PANIC_SPEED = 3.0;
    /** Maximum annual rate cut velocity cap during financial crises. */
    public const CB_MAX_CUT_PANIC_SPEED = 10.0;
    /** Policy rate threshold determining proximity to the Zero Lower Bound. */
    public const ZLB_PROXIMITY_THRESHOLD = 0.015;

    // NELSON-SIEGEL TERM PREMIUM CONSTANTS
    public const NS_BASE_TERM_PREMIUM = 0.0125;
    public const NS_GAP_TERM_PREMIUM_SCALE = -0.15;

    // NEW KEYNESIAN PHILLIPS CURVE CONSTANTS
    public const PHILLIPS_SLOPE = 0.15;
    public const INFLATION_MEAN_REVERSION = 0.50;

    // MERTON STRUCTURAL CREDIT SPREAD CONSTANTS (Merton 1974)
    public const BASE_CREDIT_SPREAD = 0.020;        // 200 bps normal corporate spread
    public const MERTON_LEVERAGE_SENSITIVITY = 4.0; // Sensitivity of default risk to GDP contractions
    public const MERTON_VOL_SENSITIVITY = 0.15;     // Sensitivity of default spreads to excess market volatility
    public const MAX_CREDIT_SPREAD = 0.10;          // 1000 bps crisis spread cap
    public const CREDIT_SPREAD_EXCESS_VOL_THRESHOLD = 0.20;

    // BARRO TAX-SMOOTHING & FISCAL STABILIZER CONSTANTS (Barro 1979)
    public const TARGET_CORPORATE_TAX_RATE = 0.21;     // 21% structural baseline corporate tax rate
    public const FISCAL_STABILIZER_SENSITIVITY = 1.0;  // Countercyclical tax response to output gap
    public const FISCAL_ADJUSTMENT_SPEED = 0.20;        // Institutional speed of tax legislation
    public const MIN_CORPORATE_TAX_RATE = 0.12;        // 12% statutory tax floor during deep recessions
    public const MAX_CORPORATE_TAX_RATE = 0.30;        // 30% statutory tax cap during overheating booms

    // --- CONSUMER SENTIMENT INDEX CONSTANTS ---
    public const SENTIMENT_BASELINE = 100.0;
    public const SENTIMENT_MISERY_MULTIPLIER = 200.0;
    public const SENTIMENT_VOLATILITY_MULTIPLIER = 50.0;
    public const SENTIMENT_MOMENTUM_MULTIPLIER = 250.0;
    public const SENTIMENT_RATE_MULTIPLIER = 250.0;
    public const ANIMAL_SPIRITS_MEAN_REVERSION = 2.0; // Theta (Speed of return to reality)
    public const ANIMAL_SPIRITS_VOLATILITY = 2.5;     // Sigma (How irrational people get)

    // --- QE & Yield Curve Constants ---
    public const QE_ACTIVATION_ZLB_THRESHOLD = 0.60;
    public const QE_ACTIVATION_GAP_THRESHOLD = -0.01;
    public const QE_MAX_SUPPRESSION = 0.02;
    public const QE_SEVERITY_MULTIPLIER = 0.5;
    public const QE_RAMP_SPEED = 1.0;
    public const INFLATION_LEVEL_WEIGHT = 0.5;

    // --- MUNDELL-FLEMING OPEN ECONOMY (IS-LM-BOP) ---
    /** G7 average policy rate proxy for Uncovered Interest Parity (UIP) baseline. */
    public const GLOBAL_BASELINE_RATE = 0.025;
    /** Baseline exchange rate index (100 = neutral purchasing power). */
    public const EXCHANGE_RATE_BASELINE = 100.0;
    /** UIP sensitivity: exchange rate response to domestic-foreign interest rate differential. */
    public const UIP_SENSITIVITY = 3.0;
    /** Mean-reversion speed of exchange rate toward purchasing power parity equilibrium. */
    public const EXCHANGE_RATE_MEAN_REVERSION = 0.60;
    /** Stochastic volatility of exchange rate fluctuations (FX market noise). */
    public const EXCHANGE_RATE_VOLATILITY = 0.08;

    // --- 2-FACTOR CORRELATED OU INDUSTRIAL COMMODITIES ---
    /** Baseline industrial metals index value (100 = neutral equilibrium). */
    public const METALS_BASELINE = 100.0;
    /** Mean-reversion speed of short-term supply disruptions (strikes, logistics). */
    public const METALS_SHORT_TERM_KAPPA = 1.50;
    /** Mean-reversion speed of long-term industrial metals supercycle equilibrium shifts. */
    public const METALS_LONG_TERM_KAPPA = 0.20;
    /** Volatility of short-term supply disruption shocks. */
    public const METALS_SHORT_TERM_SIGMA = 0.30;
    /** Volatility of long-term supercycle equilibrium shifts. */
    public const METALS_LONG_TERM_SIGMA = 0.08;
    /** Correlation between short-term disruptions and long-term shifts. */
    public const METALS_RHO = -0.30;
    /** Sensitivity of long-term metals equilibrium target to the macroeconomic output gap. */
    public const METALS_OUTPUT_GAP_SENSITIVITY = 0.50;

    // --- GOVERNMENT SPENDING & FISCAL APPROPRIATIONS ---
    /** Baseline government spending index (100 = normal peacetime budget). */
    public const GOVT_SPENDING_BASELINE = 100.0;
    /** Counter-cyclical fiscal multiplier: spending rises when output gap contracts. */
    public const GOVT_COUNTERCYCLICAL_SENSITIVITY = 80.0;
    /** Mean-reversion speed of government spending toward structural baseline. */
    public const GOVT_SPENDING_MEAN_REVERSION = 0.30;
    /** Stochastic volatility of annual budget appropriation fluctuations. */
    public const GOVT_SPENDING_VOLATILITY = 0.06;
    /** Poisson intensity of major geopolitical events triggering spending surges (lambda per year). */
    public const GEOPOLITICAL_JUMP_PROBABILITY = 0.08;
    /** Mean log-return magnitude of a geopolitical spending surge. */
    public const GEOPOLITICAL_JUMP_MEAN = 0.15;
    /** Volatility of geopolitical jump size. */
    public const GEOPOLITICAL_JUMP_VOL = 0.08;

    // --- DIPASQUALE-WHEATON COMMERCIAL REAL ESTATE (2-QUADRANT) ---
    /** Baseline commercial property index (100 = neutral valuation). */
    public const CRE_BASELINE = 100.0;
    /** Sensitivity of occupancy/rent demand factor to excess unemployment (DiPasquale-Wheaton spatial market). */
    public const CRE_OCCUPANCY_UNEMPLOYMENT_SENSITIVITY = 3.0;
    /** Structural risk premium spread above 10Y yield for CRE cap rate derivation. */
    public const CRE_CAP_RATE_RISK_PREMIUM = 0.02;
    /** Pre-calibrated neutral cap rate at macro equilibrium: yield10y(5.04%) + creditSpread(2.0%) + riskPremium(2.0%). */
    public const CRE_NEUTRAL_CAP_RATE = 0.0904;
    /** Mean-reversion speed of commercial property values toward fundamental equilibrium. */
    public const CRE_MEAN_REVERSION = 0.25;
    /** Stochastic volatility of commercial property valuations. */
    public const CRE_VOLATILITY = 0.10;
    /** Minimum cap rate floor to prevent division instability in extreme rate environments. */
    public const CRE_MIN_CAP_RATE = 0.03;

    // --- VASICEK ASRF RETAIL DEFAULT RATE ---
    /** Baseline long-run average through-the-cycle retail consumer probability of default (2.5%). */
    public const RETAIL_DEFAULT_BASELINE = 0.025;
    /** Basel II/III consumer asset correlation factor for retail exposures. */
    public const RETAIL_ASRF_RHO = 0.12;
    /** Sensitivity of consumer macro credit Z-score to unemployment rate deviations from natural rate. */
    public const RETAIL_UNEMPLOYMENT_SENSITIVITY = 40.0;
    /** Sensitivity of consumer macro credit Z-score to inflation deviations from target. */
    public const RETAIL_INFLATION_SENSITIVITY = 25.0;
    /** Stochastic volatility of idiosyncratic consumer credit shocks. */
    public const RETAIL_CREDIT_VOLATILITY = 0.35;

    // --- 2-FACTOR CORRELATED OU AGRICULTURAL COMMODITIES & WEATHER JUMPS ---
    /** Baseline agricultural commodity index value (100 = neutral crop harvest). */
    public const AGRI_BASELINE = 100.0;
    /** Mean-reversion speed of short-term agricultural supply disruptions (frost, harvest delays). */
    public const AGRI_SHORT_TERM_KAPPA = 1.80;
    /** Mean-reversion speed of long-term agricultural equilibrium supercycles toward baseline. */
    public const AGRI_LONG_TERM_KAPPA = 0.25;
    /** Volatility of short-term agricultural supply shocks. */
    public const AGRI_SHORT_TERM_SIGMA = 0.25;
    /** Volatility of long-term agricultural equilibrium shifts. */
    public const AGRI_LONG_TERM_SIGMA = 0.06;
    /** Correlation between short-term disruptions and long-term agricultural shifts. */
    public const AGRI_RHO = -0.20;
    /** Amplitude of annual seasonal harvest cycle price oscillation (percentage of index). */
    public const AGRI_SEASONALITY_AMPLITUDE = 0.06;
    /** Poisson intensity of major climate/weather shocks such as droughts or El Niño events (lambda per year). */
    public const AGRI_WEATHER_JUMP_PROBABILITY = 0.10;
    /** Mean log-return price jump magnitude resulting from an extreme weather shock. */
    public const AGRI_WEATHER_JUMP_MEAN = 0.18;
    /** Volatility of climate jump shock magnitude. */
    public const AGRI_WEATHER_JUMP_VOL = 0.08;

    // --- COBWEB THEOREM FREIGHT RATE INDEX (BALTIC DRY) ---
    /** Baseline ocean freight index value (100 = balanced fleet capacity and trade volume). */
    public const FREIGHT_BASELINE = 100.0;
    /** Elasticity of instantaneous shipping demand to macroeconomic output gap. */
    public const FREIGHT_DEMAND_GAP_SENSITIVITY = 3.5;
    /** Sensitivity of bulk shipping demand to industrial metals production and raw material flows. */
    public const FREIGHT_DEMAND_METALS_SENSITIVITY = 0.30;
    /** Elasticity of desired fleet capacity orders to prevailing freight charter profitability. */
    public const FREIGHT_SUPPLY_ORDER_ELASTICITY = 0.80;
    /** Time constant in years for multi-year shipyard shipbuilding capacity adjustments (3-year lag). */
    public const FREIGHT_SUPPLY_LAG_YEARS = 3.0;
    /** Inelasticity exponent amplifying freight spot rates when capacity utilization exceeds 1.0. */
    public const FREIGHT_CAPACITY_INELASTICITY = 2.0;
    /** Mean-reversion speed of spot charter rates toward capacity-clearing equilibrium. */
    public const FREIGHT_MEAN_REVERSION = 1.50;
    /** Stochastic volatility of spot charter market fluctuations. */
    public const FREIGHT_VOLATILITY = 0.25;

    // --- JORGENSON USER COST RESIDENTIAL REAL ESTATE ---
    /** Baseline residential property index value (100 = neutral home affordability). */
    public const RESIDENTIAL_BASELINE = 100.0;
    /** Structural mortgage spread above 30Y Treasury yield for prime residential mortgages. */
    public const RESIDENTIAL_MORTGAGE_SPREAD = 0.018;
    /** Structural property tax, insurance, and maintenance depreciation rate. */
    public const RESIDENTIAL_DEPRECIATION_TAX_RATE = 0.025;
    /** Baseline equilibrium user cost of housing capital: yield30y(5.48%) + spread(1.8%) + deprec(2.5%) - inflation(2%). */
    public const RESIDENTIAL_NEUTRAL_USER_COST = 0.0778;
    /** Sensitivity of housing demand to unemployment rate shocks (foreclosure and affordability drag). */
    public const RESIDENTIAL_UNEMPLOYMENT_SENSITIVITY = 5.0;
    /** Mean-reversion speed of residential property valuations toward fundamental user-cost equilibrium. */
    public const RESIDENTIAL_MEAN_REVERSION = 0.08;
    /** Stochastic volatility of residential home prices. */
    public const RESIDENTIAL_VOLATILITY = 0.06;

    public function __construct(
        private MathUtility $mathUtility,
        private LoggerInterface $logger,
        private \Redis $redis
    ) {}

    public function getLiveState(): MacroState
    {
        $rawState = $this->redis->get(self::REDIS_MACRO_STATE);
        return $rawState ? MacroState::fromArray(json_decode($rawState, true)) : new MacroState();
    }

    /**
     * Advances the macroeconomic state by one tick.
     * Calculates Inflation, Output Gap, Taylor Rule (Short Rate), and the Yield Curve.
     */
    public function updateMacroState(float $dt): \App\DTO\MacroStateDTO
    {
        $rawState = $this->redis->get(self::REDIS_MACRO_STATE);
        $state = $rawState ? MacroState::fromArray(json_decode($rawState, true)) : new MacroState();

        // Advance physical simulation time in years
        $state->totalTime += $dt;

        $state->targetRate = $this->calculateTargetRate($state, self::TARGET_INFLATION, self::NATURAL_RATE);
        $state->policyRate = $this->updatePolicyRate($state, $state->targetRate, $dt);

        $yieldData = $this->calculateYieldCurveAndQE($state, self::TARGET_INFLATION, self::NATURAL_RATE, $dt);

        $state->qeIntensity = $yieldData['new_qe_intensity'];
        $state->qeActive = $state->qeIntensity > 0.001;

        $state->yield2y = $yieldData['yield_2y'];
        $state->yield5y = $yieldData['yield_5y'];
        $state->yield10y = $yieldData['yield_10y'];
        $state->yield30y = $yieldData['yield_30y'];
        $state->nsLevel = $yieldData['level'];
        $state->nsCurvature = $yieldData['curvature'];

        $state->nsSlope = $state->yield10y - $state->policyRate;
        $state->structuralSlope = $yieldData['structural_10y'] - $state->policyRate;

        if ($state->structuralSlope < 0.0) {
            $state->inversionDuration += $dt;
        } else {
            $state->inversionDuration = 0.0;
        }

        $state->marketZ = $this->mathUtility->generateStandardNormal();

        // 2D Kaldor Phase Space: Capital Stock tracking
        // Booms build excess capacity (+k); Recessions cause physical depreciation and pent-up demand (-k).
        $state->capitalStockOverhang += (($state->outputGap * self::CAPITAL_ACCUMULATION_RATE) - (self::CAPITAL_DECAY_RATE * $state->capitalStockOverhang)) * $dt;
        $state->capitalStockOverhang = max(-0.15, min(0.15, $state->capitalStockOverhang));

        $stressMultiplier = 1.0 + (abs($state->outputGap) * 10.0);
        $state->outputGap = $this->calculateOutputGap($state, $state->yield5y, self::NATURAL_RATE, $dt, $stressMultiplier);

        $this->calculateUnemployment($state, $dt);
        $this->calculateEnergyShock($state, $dt);
        $this->calculateExchangeRate($state, $dt);
        $this->calculateIndustrialMetalsIndex($state, $dt);
        $this->calculateGovernmentSpending($state, $dt);
        $this->calculateCommercialPropertyIndex($state, $dt);
        $this->calculateRetailDefaultRate($state, $dt);
        $this->calculateAgriculturalCommodityIndex($state, $dt);
        $this->calculateFreightRateIndex($state, $dt);
        $this->calculateResidentialPropertyIndex($state, $dt);

        $state->inflation = $this->calculateInflation($state, self::TARGET_INFLATION, $stressMultiplier, $dt);
        $state->marketVolatility = $this->calculateMarketVolatility($state, $dt);

        $this->updateExponentialMovingAverages($state, $dt);
        $this->calculateMacroCreditSpread($state);

        $this->calculatePotentialAndNominalGdp($state, self::NATURAL_RATE, $dt);
        $this->calculateDynamicFiscalPolicy($state, $dt);
        $this->calculateEquityRiskPremium($state);
        $this->calculateConsumerSentiment($state, $dt);


        $payload = $state->toArray();
        $this->redis->set(self::REDIS_MACRO_STATE, json_encode($payload));
        return \App\DTO\MacroStateDTO::fromMacroState($state);
    }

    public function recordMacroSnapshot(\App\DTO\MacroStateDTO $macroState, \Doctrine\DBAL\Connection $conn): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $conn->executeStatement(
            "INSERT INTO macro_report (recorded_at, inflation, inflation_ema, output_gap, output_gap_ema, policy_rate, policy_rate_ema, yield2y, yield2y_ema, yield5y, yield5y_ema, yield10y, yield10y_ema, yield30y, yield30y_ema, corporate_tax_rate, equity_risk_premium, nominal_gdp_index, market_volatility, macro_credit_spread, macro_credit_spread_ema, unemployment_rate, unemployment_rate_ema, energy_price_index, energy_price_index_ema, consumer_sentiment_index, consumer_sentiment_index_ema, exchange_rate_index, exchange_rate_index_ema, industrial_metals_index, industrial_metals_index_ema, government_spending_index, government_spending_index_ema, commercial_property_index, commercial_property_index_ema, residential_property_index, residential_property_index_ema, retail_default_rate, retail_default_rate_ema, agricultural_commodity_index, agricultural_commodity_index_ema, freight_rate_index, freight_rate_index_ema, capital_stock_overhang, capital_stock_overhang_ema) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $now,
                $macroState->inflation,
                $macroState->inflationEma,
                $macroState->outputGap,
                $macroState->outputGapEma,
                $macroState->policyRate,
                $macroState->policyRateEma,
                $macroState->yield2y,
                $macroState->yield2yEma,
                $macroState->yield5y,
                $macroState->yield5yEma,
                $macroState->yield10y,
                $macroState->yield10yEma,
                $macroState->yield30y,
                $macroState->yield30yEma,
                $macroState->corporateTaxRate,
                $macroState->equityRiskPremium,
                $macroState->nominalGdpIndex,
                $macroState->marketVolatility,
                $macroState->macroCreditSpread,
                $macroState->macroCreditSpreadEma,
                $macroState->unemploymentRate,
                $macroState->unemploymentRateEma,
                $macroState->energyPriceIndex,
                $macroState->energyPriceIndexEma,
                $macroState->consumerSentimentIndex,
                $macroState->consumerSentimentIndexEma,
                $macroState->exchangeRateIndex,
                $macroState->exchangeRateIndexEma,
                $macroState->industrialMetalsIndex,
                $macroState->industrialMetalsIndexEma,
                $macroState->governmentSpendingIndex,
                $macroState->governmentSpendingIndexEma,
                $macroState->commercialPropertyIndex,
                $macroState->commercialPropertyIndexEma,
                $macroState->residentialPropertyIndex,
                $macroState->residentialPropertyIndexEma,
                $macroState->retailDefaultRate,
                $macroState->retailDefaultRateEma,
                $macroState->agriculturalCommodityIndex,
                $macroState->agriculturalCommodityIndexEma,
                $macroState->freightRateIndex,
                $macroState->freightRateIndexEma,
                $macroState->capitalStockOverhang,
                $macroState->capitalStockOverhangEma,
            ]
        );
    }

    private function calculateTargetRate(MacroState $state, float $targetInflation, float $naturalRate): float
    {
        $trendInflation = $state->inflationEma;

        // The Evans Rule (2012): Institutional Forward Guidance.
        // If unemployment is high and inflation is contained, the central bank 
        // explicitly overrides the Taylor Rule and locks the target rate at the ZLB.
        if ($state->unemploymentRate > self::EVANS_RULE_UNEMPLOYMENT && $trendInflation < self::EVANS_RULE_INFLATION_CAP) {
            return 0.00;
        }

        if ($state->outputGap < 0.0) {
            $gapWeight = self::TAYLOR_INFLATION_WEIGHT + min(self::TAYLOR_INFLATION_WEIGHT, abs($state->outputGap) * self::TAYLOR_RECESSION_SCALE);
        } else {
            $gapWeight = self::TAYLOR_BOOM_WEIGHT; // Benign neglect during a boom
        }

        $targetRate = $naturalRate + $trendInflation
            + self::TAYLOR_INFLATION_WEIGHT * ($trendInflation - $targetInflation)
            + $gapWeight * ($state->outputGap);

        return max(0.00, min(0.20, $targetRate));
    }

    private function updatePolicyRate(MacroState $state, float $targetRate, float $dt): float
    {
        $currentPolicyRate = $state->policyRate;
        $cbSpeed = self::CB_SMOOTHING_SPEED;

        if ($targetRate > $currentPolicyRate) {
            $inflationExcess = max(0.0, $state->inflation - self::TARGET_INFLATION);
            $cbSpeed += min(self::CB_MAX_HIKE_PANIC_SPEED, $inflationExcess * self::CB_INFLATION_PANIC_SCALE);
        } else {
            $deflationPanic = max(0.0, self::TARGET_INFLATION - $state->inflation) * self::CB_INFLATION_PANIC_SCALE;
            $recessionPanic = max(0.0, -$state->outputGap) * self::CB_RECESSION_PANIC_SCALE;
            $cbSpeed += min(self::CB_MAX_CUT_PANIC_SPEED, $deflationPanic + $recessionPanic);
        }

        $rawMove = $cbSpeed * ($targetRate - $currentPolicyRate);
        $clampedMove = max(-0.08, min(0.05, $rawMove)); // Tightened max annual velocity to -800 bps to +500 bps/year

        $newRate = $currentPolicyRate + $clampedMove * $dt;
        $newRate = max(0.00, min(0.20, $newRate)); // Explicit bounds

        if ($targetRate > $currentPolicyRate) {
            return min($targetRate, $newRate);
        } else {
            return max($targetRate, $newRate);
        }
    }

    private function calculateYieldCurveAndQE(MacroState $state, float $targetInflation, float $naturalRate, float $dt): array
    {
        $zlbProximity = min(1.0, max(0.0, (self::ZLB_PROXIMITY_THRESHOLD - $state->policyRate) / self::ZLB_PROXIMITY_THRESHOLD));
        $recessionSeverity = max(0.0, -$state->outputGap);

        // Determine target QE intensity based on zero-lower-bound proximity and recession severity
        if ($zlbProximity > self::QE_ACTIVATION_ZLB_THRESHOLD && $state->outputGap < self::QE_ACTIVATION_GAP_THRESHOLD) {
            $qeYieldSuppressionTarget = min(self::QE_MAX_SUPPRESSION, $zlbProximity * $recessionSeverity * self::QE_SEVERITY_MULTIPLIER);
        } else {
            $qeYieldSuppressionTarget = 0.0;
        }

        // Exact exponential decay to prevent Euler integration overshoot. 
        // We calculate the new value but DO NOT mutate $state->qeIntensity here to preserve CQS.
        $newQeIntensity = $qeYieldSuppressionTarget + ($state->qeIntensity - $qeYieldSuppressionTarget) * exp(-self::QE_RAMP_SPEED * $dt);

        $expectedInflation = $state->inflationEma;

        $level = $naturalRate + (self::INFLATION_LEVEL_WEIGHT * $targetInflation) + (self::INFLATION_LEVEL_WEIGHT * $expectedInflation);
        $nsBeta1 = $state->policyRate - $level;
        $nsBeta2 = max(-0.01, 0.015 + ($state->outputGap * 0.25));

        $yield2y  = $this->calculateNelsonSiegelTenor(2.0, $level, $nsBeta1, $nsBeta2, $state, $newQeIntensity);
        $yield5y  = $this->calculateNelsonSiegelTenor(5.0, $level, $nsBeta1, $nsBeta2, $state, $newQeIntensity);
        $yield10y = $this->calculateNelsonSiegelTenor(10.0, $level, $nsBeta1, $nsBeta2, $state, $newQeIntensity);
        $yield30y = $this->calculateNelsonSiegelTenor(30.0, $level, $nsBeta1, $nsBeta2, $state, $newQeIntensity);

        return [
            'level' => $level,
            'curvature' => $nsBeta2,
            'new_qe_intensity' => $newQeIntensity, // Passed back to the caller to mutate state
            'structural_10y' => $yield10y + $newQeIntensity, // Unclamped
            'yield_2y'  => $yield2y,  // Unclamped, allowing negative yields
            'yield_5y'  => $yield5y,
            'yield_10y' => $yield10y,
            'yield_30y' => $yield30y
        ];
    }

    private function calculateNelsonSiegelTenor(float $t, float $level, float $nsBeta1, float $nsBeta2, MacroState $state, float $qeYieldSuppression): float
    {
        // Concave duration scaling: anchors 10-year at ~1.0, and 30-year asymptotically flattens out around ~1.58
        // This mirrors real-world term premium flattening at the long end and prevents linear explosion.
        $durationScale = (1.0 - exp(-$t / 10.0)) / (1.0 - exp(-1.0));

        $termPremium = (self::NS_BASE_TERM_PREMIUM * $durationScale)
            + ($state->outputGap * self::NS_GAP_TERM_PREMIUM_SCALE * $durationScale);

        // Apply the same concave scaling to QE to suppress the entire long end properly
        $qeTargetedSuppression = $qeYieldSuppression * $durationScale;

        $pureYield = $this->mathUtility->calculateNelsonSiegelYield($level, $nsBeta1, $nsBeta2, $t);

        return $pureYield + $termPremium - $qeTargetedSuppression;
    }

    private function calculateOutputGap(MacroState $state, float $yield5y, float $naturalRate, float $dt, float $stressMultiplier): float
    {
        $y = $state->outputGap;
        $outZ = $this->mathUtility->generateStandardNormal();

        $borrowingCost = (self::BORROWING_POLICY_WEIGHT * $state->policyRate) + (self::BORROWING_YIELD5Y_WEIGHT * $yield5y);
        $realRate = $borrowingCost - $state->inflation;

        $momentum = self::KALDOR_MOMENTUM * $y;
        $cubicConstraint = self::KALDOR_CAPACITY * pow($y, 3);
        $monetaryDrag = self::KALDOR_MONETARY_DRAG * ($realRate - $naturalRate);

        // Fiscal stimulus: Tax cuts below the target rate boost aggregate demand.
        $fiscalStimulus = self::KALDOR_FISCAL_MULTIPLIER * (self::TARGET_CORPORATE_TAX_RATE - $state->corporateTaxRate);

        // The 2D Kaldor Force: Overcapacity drags the economy down; Pent-up depreciation forces a recovery.
        $capitalDrag = self::KALDOR_CAPITAL_DRAG * $state->capitalStockOverhang;

        // QE automatically lowers monetary drag through the reduced $yield5y in the borrowing cost calculation.
        $drift = ($momentum - $cubicConstraint - $monetaryDrag + $fiscalStimulus - $capitalDrag) * $dt;
        $volatility = self::OUTPUT_GAP_DIFFUSION_SIGMA * $stressMultiplier * sqrt($dt) * $outZ;

        $newGap = $y + $drift + $volatility;

        return max(-0.12, min(0.10, $newGap));
    }

    private function calculateInflation(MacroState $state, float $targetInflation, float $stressMultiplier, float $dt): float
    {
        $infZ = $this->mathUtility->generateStandardNormal();
        // Inflation expectations are fully anchored. Revert structurally toward the target rate.
        $inflationDrift = self::INFLATION_MEAN_REVERSION * ($targetInflation - $state->inflation) * $dt;

        $phillipsSlope = $state->outputGap * self::PHILLIPS_SLOPE;

        // Add energy cost-push inflation
        $energyCostPush = ($state->energyPriceShock / 100.0) * self::ENERGY_COST_PUSH_TRANSMISSION; // Moderated transmission of energy shock

        $phillipsEffect = ($phillipsSlope + $energyCostPush) * $dt;

        $newInflation = $state->inflation + $inflationDrift + $phillipsEffect + (0.005 * $stressMultiplier * sqrt($dt) * $infZ);
        return max(-0.02, min(0.25, $newInflation));
    }

    private function calculateMarketVolatility(MacroState $state, float $dt): float
    {
        $currentMarketVol = $state->marketVolatility;

        // Continuous exponential macroeconomic link (Engle, Ghysels, & Sohn 2013 Eq. 5):
        // Long-run volatility smoothly scales across all economic states without piecewise kinks.
        $spreadDeviation = max(0.0, $state->macroCreditSpread - self::BASE_CREDIT_SPREAD);
        $macroDriver = (-$state->outputGap * self::MACRO_VOL_OUTPUT_GAP_SENSITIVITY)
            + ($spreadDeviation * self::MACRO_VOL_CREDIT_SENSITIVITY)
            + (-min(0.0, $state->structuralSlope) * self::MACRO_VOL_SLOPE_SENSITIVITY); // Only penalize true inversions

        $longTermVol = min(
            self::MACRO_VOL_MAX_BASELINE,
            max(self::MACRO_VOL_MIN_BASELINE, self::MACRO_VOL_BASE_ANCHOR * exp($macroDriver))
        );

        $currentVar = $currentMarketVol * $currentMarketVol;
        $longTermVar = $longTermVol * $longTermVol;

        $jumpData = $this->mathUtility->calculateSVJJJumps(
            lambda: self::SVJJ_LAMBDA,
            pUp: self::SVJJ_P_UP,
            etaUp: self::SVJJ_ETA_UP,
            etaDown: self::SVJJ_ETA_DOWN,
            muV: self::SVJJ_MU_V,
            dt: $dt
        );

        $expectedVarJump = (self::SVJJ_P_UP * self::SVJJ_MU_V * 0.5) + ((1.0 - self::SVJJ_P_UP) * self::SVJJ_MU_V);
        $jumpVarianceDrag = (self::SVJJ_LAMBDA * $expectedVarJump) / 3.0;
        $adjustedTheta = max(0.0001, $longTermVar - $jumpVarianceDrag);

        $nextVar = $this->mathUtility->calculateQEVarianceStep($currentVar, $adjustedTheta, self::MACRO_VOL_KAPPA, self::MACRO_VOL_SIGMA, $dt);
        $nextVar += $jumpData['var_jump'];

        return max(0.08, min(0.80, sqrt($nextVar)));
    }

    private function calculatePotentialAndNominalGdp(MacroState $state, float $naturalRate, float $dt): void
    {
        $nominalPotentialGrowth = $naturalRate + $state->inflationEma;
        $state->potentialGdpIndex = max(0.10, $state->potentialGdpIndex * exp($nominalPotentialGrowth * $dt));
        $state->nominalGdpIndex = $state->potentialGdpIndex * (1.0 + $state->outputGap);
    }

    private function updateExponentialMovingAverages(MacroState $state, float $dt): void
    {
        $emaWeight = min(1.0, $dt / 0.25);

        $state->outputGapEma += $emaWeight * ($state->outputGap - $state->outputGapEma);
        $state->policyRateEma += $emaWeight * ($state->policyRate - $state->policyRateEma);
        $state->inflationEma += $emaWeight * ($state->inflation - $state->inflationEma);
        $state->nsSlopeEma += $emaWeight * ($state->nsSlope - $state->nsSlopeEma);

        $state->yield2yEma += $emaWeight * ($state->yield2y - $state->yield2yEma);
        $state->yield5yEma += $emaWeight * ($state->yield5y - $state->yield5yEma);
        $state->yield10yEma += $emaWeight * ($state->yield10y - $state->yield10yEma);
        $state->yield30yEma += $emaWeight * ($state->yield30y - $state->yield30yEma);

        $state->marketVolatilityEma += $emaWeight * ($state->marketVolatility - $state->marketVolatilityEma);
        $state->macroCreditSpreadEma += $emaWeight * ($state->macroCreditSpread - $state->macroCreditSpreadEma);
        $state->unemploymentRateEma += $emaWeight * ($state->unemploymentRate - $state->unemploymentRateEma);
        $state->energyPriceIndexEma += $emaWeight * ($state->energyPriceIndex - $state->energyPriceIndexEma);
        $state->consumerSentimentIndexEma += $emaWeight * ($state->consumerSentimentIndex - $state->consumerSentimentIndexEma);
        $state->exchangeRateIndexEma += $emaWeight * ($state->exchangeRateIndex - $state->exchangeRateIndexEma);
        $state->industrialMetalsIndexEma += $emaWeight * ($state->industrialMetalsIndex - $state->industrialMetalsIndexEma);
        $state->governmentSpendingIndexEma += $emaWeight * ($state->governmentSpendingIndex - $state->governmentSpendingIndexEma);
        $state->commercialPropertyIndexEma += $emaWeight * ($state->commercialPropertyIndex - $state->commercialPropertyIndexEma);
        $state->residentialPropertyIndexEma += $emaWeight * ($state->residentialPropertyIndex - $state->residentialPropertyIndexEma);
        $state->retailDefaultRateEma += $emaWeight * ($state->retailDefaultRate - $state->retailDefaultRateEma);
        $state->agriculturalCommodityIndexEma += $emaWeight * ($state->agriculturalCommodityIndex - $state->agriculturalCommodityIndexEma);
        $state->freightRateIndexEma += $emaWeight * ($state->freightRateIndex - $state->freightRateIndexEma);
        $state->capitalStockOverhangEma += $emaWeight * ($state->capitalStockOverhang - $state->capitalStockOverhangEma);
    }

    private function calculateDynamicFiscalPolicy(MacroState $state, float $dt): void
    {
        // Barro's Countercyclical Fiscal Policy Rule (Barro, 1979):
        // Replaces arbitrary dice rolls and step hikes with a smooth continuous institutional feedback loop.
        // As the output gap expands (boom), automatic stabilizers and tax legislation increase the effective 
        // tax burden to cool aggregate demand. In recessions, fiscal stimulus smoothly reduces corporate tax burden.
        $targetTaxRate = self::TARGET_CORPORATE_TAX_RATE + (self::FISCAL_STABILIZER_SENSITIVITY * $state->outputGapEma);
        $targetTaxRate = max(self::MIN_CORPORATE_TAX_RATE, min(self::MAX_CORPORATE_TAX_RATE, $targetTaxRate));

        // Smooth Ornstein-Uhlenbeck institutional adjustment toward the fiscal target
        $state->corporateTaxRate += self::FISCAL_ADJUSTMENT_SPEED * ($targetTaxRate - $state->corporateTaxRate) * $dt;
    }

    private function calculateEquityRiskPremium(MacroState $state): void
    {
        // Campbell-Cochrane (1999) Habit Formation Model:
        // As the output gap contracts below potential, consumer surplus shrinks and aggregate risk aversion
        // scales exponentially, widening the required equity risk premium without ad-hoc piecewise branches.
        $habitErp = self::BASE_EQUITY_RISK_PREMIUM * exp(-self::HABIT_RISK_AVERSION_COEFF * $state->outputGapEma);

        $state->equityRiskPremium = max(self::MIN_EQUITY_RISK_PREMIUM, min(0.12, $habitErp));
    }

    private function calculateMacroCreditSpread(MacroState $state): void
    {
        // Merton (1974) Structural Credit Spread Model:
        // Corporate debt default probability scales exponentially with economic downturns (leverage effect)
        // and linearly with excess macroeconomic volatility (option volatility effect).
        $cycleSpread = self::BASE_CREDIT_SPREAD * exp(-self::MERTON_LEVERAGE_SENSITIVITY * $state->outputGapEma);
        $excessVol = max(0.0, $state->marketVolatilityEma - 0.20);
        $volSpread = self::MERTON_VOL_SENSITIVITY * $excessVol;

        $state->macroCreditSpread = max(0.008, min(self::MAX_CREDIT_SPREAD, $cycleSpread + $volSpread));
    }

    private function calculateUnemployment(MacroState $state, float $dt): void
    {
        // Dynamic Okun's Law with Asymmetric Hysteresis
        // Recessions cause rapid spikes in unemployment (firing is fast), expansions cause slow decay (hiring is slow/frictional)
        $targetUnemployment = max(0.01, self::NATURAL_UNEMPLOYMENT - (self::OKUNS_COEFFICIENT * $state->outputGap));

        $unemploymentGap = $targetUnemployment - $state->unemploymentRate;

        // Asymmetric speed of adjustment
        $adjustmentSpeed = $unemploymentGap > 0 ? self::OKUNS_FIRING_SPEED : self::OKUNS_HIRING_SPEED;

        $state->unemploymentRate += $adjustmentSpeed * $unemploymentGap * $dt;
    }



    private function calculateEnergyShock(MacroState $state, float $dt): void
    {
        // Schwartz 1-Factor Model (1997) for Commodity Pricing
        // Uses Ornstein-Uhlenbeck on the log-price for mathematically sound log-normal distribution
        $dW = $this->mathUtility->generateStandardNormal();
        $baseProcess = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->energyPriceIndex,
            kappa: self::ENERGY_MEAN_REVERSION,
            theta: 100.0,
            sigma: self::ENERGY_VOLATILITY,
            dt: $dt,
            dW: $dW
        );

        // Exogenous Poisson Jump Shocks (e.g., Geopolitics, Supply Cuts)
        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: self::ENERGY_JUMP_PROBABILITY,
            jumpMean: self::ENERGY_JUMP_MEAN,
            jumpVol: self::ENERGY_JUMP_VOL,
            dt: $dt
        );

        $jumpAmount = 0.0;
        if ($jumpData['multiplier'] !== 1.0) {
            $jumpAmount = $baseProcess * ($jumpData['multiplier'] - 1.0);
        }

        $state->energyPriceIndex = $baseProcess + $jumpAmount;
        $state->energyPriceIndex = max(10.0, min(500.0, $state->energyPriceIndex)); // Clamp extremes
        $state->energyPriceShock = $state->energyPriceIndex - 100.0;
    }

    private function calculateConsumerSentiment(MacroState $state, float $dt): void
    {
        // 1. Calculate the "Rational" Fundamental Sentiment (The math we just tuned)
        $excessInflation = max(0.0, $state->inflation - self::TARGET_INFLATION);
        $excessUnemployment = max(0.0, $state->unemploymentRate - self::NATURAL_UNEMPLOYMENT);
        $miseryPenalty = ($excessInflation + $excessUnemployment) * self::SENTIMENT_MISERY_MULTIPLIER;

        $inflationMomentum = max(0.0, $state->inflation - $state->inflationEma);
        $unemploymentMomentum = max(0.0, $state->unemploymentRate - $state->unemploymentRateEma);
        $momentumPenalty = ($inflationMomentum + $unemploymentMomentum) * self::SENTIMENT_MOMENTUM_MULTIPLIER;

        $excessVolatility = max(0.0, $state->marketVolatility - self::MACRO_VOL_BASE_ANCHOR);
        $fearPenalty = $excessVolatility * self::SENTIMENT_VOLATILITY_MULTIPLIER;

        $excessYield = max(0.0, $state->yield10y - (self::NATURAL_RATE + self::TARGET_INFLATION));
        $ratePenalty = $excessYield * self::SENTIMENT_RATE_MULTIPLIER;

        $gasPanic = max(0.0, $state->energyPriceShock) * 0.15;

        // The "Rational" Target (Mu)
        $fundamentalSentiment = self::SENTIMENT_BASELINE - $miseryPenalty - $momentumPenalty - $fearPenalty - $ratePenalty - $gasPanic;
        if ($state->outputGap > 0.0) {
            $fundamentalSentiment += ($state->outputGap * 300.0);
        }

        // 2. Apply Ornstein-Uhlenbeck (OU) Stochastic Process for "Animal Spirits"
        $currentSentiment = $state->consumerSentimentIndex ?? 100.0;
        $dW = $this->mathUtility->generateStandardNormal(); // Wiener process increment

        // OU Equation: dX = Theta * (Mu - X) * dt + Sigma * sqrt(dt) * dW
        $drift = self::ANIMAL_SPIRITS_MEAN_REVERSION * ($fundamentalSentiment - $currentSentiment) * $dt;
        $diffusion = self::ANIMAL_SPIRITS_VOLATILITY * sqrt($dt) * $dW;

        $newSentiment = $currentSentiment + $drift + $diffusion;

        // 3. Apply final bounds
        $state->consumerSentimentIndex = max(40.0, min(120.0, $newSentiment));
    }

    private function calculateExchangeRate(MacroState $state, float $dt): void
    {
        // Mundell-Fleming Open Economy Model (IS-LM-BOP) via Uncovered Interest Parity (UIP)
        // Interest rate differential relative to global baseline drives the equilibrium FX target
        $rateDiff = $state->policyRate - self::GLOBAL_BASELINE_RATE;
        $targetFx = self::EXCHANGE_RATE_BASELINE * exp(self::UIP_SENSITIVITY * $rateDiff);

        $dW = $this->mathUtility->generateStandardNormal();
        $newFx = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->exchangeRateIndex,
            kappa: self::EXCHANGE_RATE_MEAN_REVERSION,
            theta: $targetFx,
            sigma: self::EXCHANGE_RATE_VOLATILITY,
            dt: $dt,
            dW: $dW
        );

        $state->exchangeRateIndex = max(60.0, min(160.0, $newFx));
    }

    private function calculateIndustrialMetalsIndex(MacroState $state, float $dt): void
    {
        // Output gap dynamically shifts the long-term structural target (theta), not raw drift
        $baselineLog = log(self::METALS_BASELINE);
        $shiftedThetaXi = $baselineLog + ($state->outputGapEma * self::METALS_OUTPUT_GAP_SENSITIVITY);

        $result = $this->mathUtility->calculateTwoFactorOU(
            chi: $state->metalsChi,
            xi: $state->metalsXi,
            kappaChi: self::METALS_SHORT_TERM_KAPPA,
            kappaXi: self::METALS_LONG_TERM_KAPPA,
            thetaChi: 0.0,
            thetaXi: $shiftedThetaXi,
            sigChi: self::METALS_SHORT_TERM_SIGMA,
            sigXi: self::METALS_LONG_TERM_SIGMA,
            rho: self::METALS_RHO,
            dt: $dt
        );

        $state->metalsChi = $result['chi'];
        $state->metalsXi = $result['xi'];
        $state->industrialMetalsIndex = max(20.0, min(400.0, $result['spot']));
    }

    private function calculateGovernmentSpending(MacroState $state, float $dt): void
    {
        // Counter-cyclical Fiscal Spending Rule with Exogenous Geopolitical Poisson Jumps
        // Recessions trigger automatic stabilizers; booms prompt fiscal restraint
        $cyclicalTarget = self::GOVT_SPENDING_BASELINE - ($state->outputGapEma * self::GOVT_COUNTERCYCLICAL_SENSITIVITY);
        $targetSpending = max(60.0, min(160.0, $cyclicalTarget));

        $dW = $this->mathUtility->generateStandardNormal();
        $baseProcess = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->governmentSpendingIndex,
            kappa: self::GOVT_SPENDING_MEAN_REVERSION,
            theta: $targetSpending,
            sigma: self::GOVT_SPENDING_VOLATILITY,
            dt: $dt,
            dW: $dW
        );

        // Exogenous Poisson Jump Shocks (e.g. Geopolitical conflict, defense appropriations)
        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: self::GEOPOLITICAL_JUMP_PROBABILITY,
            jumpMean: self::GEOPOLITICAL_JUMP_MEAN,
            jumpVol: self::GEOPOLITICAL_JUMP_VOL,
            dt: $dt
        );

        $jumpAmount = 0.0;
        if ($jumpData['multiplier'] !== 1.0) {
            $jumpAmount = $baseProcess * ($jumpData['multiplier'] - 1.0);
        }

        $newSpending = $baseProcess + $jumpAmount;
        $state->governmentSpendingIndex = max(60.0, min(200.0, $newSpending));
    }

    private function calculateCommercialPropertyIndex(MacroState $state, float $dt): void
    {
        // DiPasquale-Wheaton (1996) 2-Quadrant Commercial Real Estate Model
        // Quadrant 1 (Spatial Market): Occupancy factor contracts with excess unemployment
        $excessUnemployment = $state->unemploymentRateEma - self::NATURAL_UNEMPLOYMENT;
        $occupancyFactor = 1.0 - ($excessUnemployment * self::CRE_OCCUPANCY_UNEMPLOYMENT_SENSITIVITY);
        $occupancyFactor = max(0.30, min(1.80, $occupancyFactor));

        // Quadrant 2 (Asset Market): Cap rate driven by 10Y yield + macro credit spread + CRE risk premium
        $capRate = max(self::CRE_MIN_CAP_RATE, $state->yield10y + $state->macroCreditSpread + self::CRE_CAP_RATE_RISK_PREMIUM);
        $fundamentalValue = self::CRE_BASELINE * $occupancyFactor * (self::CRE_NEUTRAL_CAP_RATE / $capRate);

        // Valuation adjusts toward fundamental value with physical market delay
        $dW = $this->mathUtility->generateStandardNormal();
        $newIndex = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->commercialPropertyIndex,
            kappa: self::CRE_MEAN_REVERSION,
            theta: $fundamentalValue,
            sigma: self::CRE_VOLATILITY,
            dt: $dt,
            dW: $dW
        );

        $state->commercialPropertyIndex = max(30.0, min(250.0, $newIndex));
    }

    private function calculateRetailDefaultRate(MacroState $state, float $dt): void
    {
        // Basel II/III Vasicek Asymptotic Single Risk Factor (ASRF) Consumer Credit Model
        // Macroeconomic shock Z is driven by Okun's Law unemployment and real wage inflation destruction
        $unemploymentShock = ($state->unemploymentRateEma - self::NATURAL_UNEMPLOYMENT) * self::RETAIL_UNEMPLOYMENT_SENSITIVITY;
        $inflationShock = ($state->inflationEma - self::TARGET_INFLATION) * self::RETAIL_INFLATION_SENSITIVITY;

        $dW = $this->mathUtility->generateStandardNormal();
        $macroZ = - ($unemploymentShock + $inflationShock) + ($dW * self::RETAIL_CREDIT_VOLATILITY);

        // Expected retail default rate: conditional PD derived via Vasicek ASRF with LGD = 1.0
        $conditionalPd = $this->mathUtility->calculateVasicekExpectedLoss(
            macroZ: $macroZ,
            pdLra: self::RETAIL_DEFAULT_BASELINE,
            rho: self::RETAIL_ASRF_RHO,
            lgd: 1.0
        );

        $state->retailDefaultRate = max(0.005, min(0.20, $conditionalPd));
    }

    private function calculateAgriculturalCommodityIndex(MacroState $state, float $dt): void
    {
        // Two-Factor Correlated Ornstein-Uhlenbeck (OU) Model with Harvest Seasonality and Poisson Weather Jumps
        $result = $this->mathUtility->calculateTwoFactorOU(
            chi: $state->agriChi,
            xi: $state->agriXi,
            kappaChi: self::AGRI_SHORT_TERM_KAPPA,
            kappaXi: self::AGRI_LONG_TERM_KAPPA,
            thetaChi: 0.0,
            thetaXi: log(self::AGRI_BASELINE),
            sigChi: self::AGRI_SHORT_TERM_SIGMA,
            sigXi: self::AGRI_LONG_TERM_SIGMA,
            rho: self::AGRI_RHO,
            dt: $dt
        );

        // Exogenous Poisson Weather Jumps (Droughts, Frost, El Niño)
        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: self::AGRI_WEATHER_JUMP_PROBABILITY,
            jumpMean: self::AGRI_WEATHER_JUMP_MEAN,
            jumpVol: self::AGRI_WEATHER_JUMP_VOL,
            dt: $dt
        );

        $chi = $result['chi'];
        if ($jumpData['multiplier'] !== 1.0) {
            $chi += log($jumpData['multiplier']);
        }

        $state->agriChi = $chi;
        $state->agriXi = $result['xi'];

        // Deterministic Harvest Seasonality: annual sine wave oscillation representing autumn harvest supply peaks vs spring planting troughs
        $timeOfYear = fmod($state->totalTime, 1.0);
        $seasonalMultiplier = 1.0 + (self::AGRI_SEASONALITY_AMPLITUDE * sin(2.0 * M_PI * $timeOfYear));

        $spot = exp($state->agriChi + $state->agriXi) * $seasonalMultiplier;
        $state->agriculturalCommodityIndex = max(20.0, min(400.0, $spot));
    }

    private function calculateFreightRateIndex(MacroState $state, float $dt): void
    {
        // Cobweb Theorem / Stopford Maritime Shipping Model (Stopford 2009)
        // 1. Current Instantaneous Demand for Global Ocean Freight (Ton-Miles)
        $metalsShift = ($state->industrialMetalsIndexEma - self::METALS_BASELINE) / 100.0;
        $demandFactor = 1.0 + ($state->outputGapEma * self::FREIGHT_DEMAND_GAP_SENSITIVITY) + ($metalsShift * self::FREIGHT_DEMAND_METALS_SENSITIVITY);
        $demand = self::FREIGHT_BASELINE * max(0.20, $demandFactor);

        // 2. Cobweb Fleet Capacity (Supply): Shipowners order new vessels when charter rates are profitable
        // Multi-year shipyard construction lag (~3 years) creates delayed fleet deliveries
        $profitabilityRatio = max(0.10, $state->freightRateIndexEma / self::FREIGHT_BASELINE);
        $targetSupply = self::FREIGHT_BASELINE * pow($profitabilityRatio, self::FREIGHT_SUPPLY_ORDER_ELASTICITY);

        $slowEmaWeight = min(1.0, $dt / self::FREIGHT_SUPPLY_LAG_YEARS);
        $state->freightSupplyEma += $slowEmaWeight * ($targetSupply - $state->freightSupplyEma);
        $supply = max(20.0, $state->freightSupplyEma);

        // 3. Market Clearing Rate: Inelastic capacity creates convex supercycles
        $utilization = $demand / $supply;
        $equilibriumRate = self::FREIGHT_BASELINE * pow($utilization, self::FREIGHT_CAPACITY_INELASTICITY);

        // 4. Spot Rate Mean Reversion with Stochastic Volatility (Schwartz 1-Factor)
        $dW = $this->mathUtility->generateStandardNormal();
        $newFreight = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->freightRateIndex,
            kappa: self::FREIGHT_MEAN_REVERSION,
            theta: $equilibriumRate,
            sigma: self::FREIGHT_VOLATILITY,
            dt: $dt,
            dW: $dW
        );

        $state->freightRateIndex = max(20.0, min(500.0, $newFreight));
    }

    private function calculateResidentialPropertyIndex(MacroState $state, float $dt): void
    {
        // Jorgenson User Cost of Capital Model (1963) for Residential Housing
        // User cost of housing capital: U = Mortgage Rate + Property Tax/Maintenance - Expected Inflation
        $mortgageRate = $state->yield30yEma + self::RESIDENTIAL_MORTGAGE_SPREAD;
        $userCost = max(0.015, $mortgageRate + self::RESIDENTIAL_DEPRECIATION_TAX_RATE - $state->inflationEma);

        // Housing Affordability & Spatial Demand Equilibrium
        $excessUnemployment = max(0.0, $state->unemploymentRateEma - self::NATURAL_UNEMPLOYMENT);
        $affordabilityFactor = (self::RESIDENTIAL_NEUTRAL_USER_COST / $userCost) * (1.0 - ($excessUnemployment * self::RESIDENTIAL_UNEMPLOYMENT_SENSITIVITY));
        $fundamentalPrice = self::RESIDENTIAL_BASELINE * max(0.30, min(2.50, $affordabilityFactor));

        // Sticky physical housing price mean-reversion toward user cost equilibrium
        $dW = $this->mathUtility->generateStandardNormal();
        $newIndex = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->residentialPropertyIndex,
            kappa: self::RESIDENTIAL_MEAN_REVERSION,
            theta: $fundamentalPrice,
            sigma: self::RESIDENTIAL_VOLATILITY,
            dt: $dt,
            dW: $dW
        );

        $state->residentialPropertyIndex = max(30.0, min(300.0, $newIndex));
    }
}
