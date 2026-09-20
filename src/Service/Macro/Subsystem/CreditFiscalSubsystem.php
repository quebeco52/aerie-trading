<?php

namespace App\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Math\MathUtility;

/**
 * Handles corporate and retail credit spreads, interbank liquidity (TED spread),
 * government spending appropriations, and countercyclical corporate tax policy.
 */
class CreditFiscalSubsystem
{
    // --- INTERBANK LIQUIDITY SPREAD (CIR PROCESS & JUMPS) ---
    /** Cap on the interbank spread (500 bps): the TED spread's all-time high was 457 bps on 10 Oct 2008. */
    public const INTERBANK_MAX_SPREAD = 0.05;
    /** Share of excess IG spread that lifts the interbank spread's mean (~0.4): a 470 bps IG blowout pulls TED toward ~200 bps, a mild recession toward ~90. */
    public const INTERBANK_CREDIT_COUPLING = 0.40;
    /** Mean log-size of a panic jump: median 2.7x (1.65x to 4.5x at one sigma), so a 2008-scale 5x freeze is the tail, not the norm. */
    public const INTERBANK_JUMP_MEAN = 1.00;
    /** Sigma of the jump log-size; at 0.50 the two-sigma low is exactly 1.0x, so a panic jump never shrinks the spread. */
    public const INTERBANK_JUMP_VOL = 0.50;

    // --- Sovereign Debt Dynamics (Greenwood-Vayanos 2014) ---
    /** Bohn (1998, 2008) fiscal reaction: primary surplus response per unit of debt above the neutral threshold (~0.10, the upper end of advanced-economy estimates), which stabilizes debt near 90% against a 2% structural deficit. */
    public const BOHN_FISCAL_REACTION_SENSITIVITY = 0.10;

    // --- Sovereign Risk Premium (Laubach 2009) ---
    /** Debt-to-GDP above which the market prices fiscal risk (Reinhart & Rogoff 2010's 90% line). Deliberately above the 70% the Bohn reaction defends: the engine's own steady state runs 85-90%, and a premium charged for that normal state was measured to lift IG 30 bps and 2s10s 45 bps everywhere. */
    public const SOVEREIGN_RISK_DEBT_THRESHOLD = 0.90;
    /** Long yield per unit of debt-to-GDP above the risk threshold: 3-4 bps per percentage point (Laubach 2009; Engen & Hubbard 2004), so 0.035 per unit. Laubach's deficit coefficient is for PROJECTED structural deficits; the engine's primary deficit is cyclical, so it is published but not priced. */
    public const LAUBACH_DEBT_YIELD_SENSITIVITY = 0.035;
    /** Time constant (years) over which the market reprices the fiscal position: a projection revises over budget rounds, not ticks. */
    public const SOVEREIGN_RISK_REPRICING_YEARS = 0.5;
    /** Cap on the sovereign risk spread (600 bps): the level at which an advanced sovereign lost market access in 2011. */
    public const MAX_SOVEREIGN_RISK_SPREAD = 0.06;
    /** Share of the sovereign spread that passes into the corporate IG base (Durbin & Ng 2005 sovereign ceiling; Almeida et al. 2017 find about half). */
    public const SOVEREIGN_CEILING_PASSTHROUGH = 0.50;

    // --- Barro Tax-Smoothing & Automatic Fiscal Stabilizers (Barro 1979) ---
    /** Countercyclical statutory tax response sensitivity to output gap deviations. */
    public const FISCAL_STABILIZER_SENSITIVITY = 1.0;
    /** Adjustment speed of the effective tax burden toward its cyclical target: automatic stabilisers act within the year (OECD budget semi-elasticity ~0.5; Fatas & Mihov 2001); at 0.2 the bust's tax relief arrived at the next peak. */
    public const FISCAL_ADJUSTMENT_SPEED = 1.0;
    /** Statutory corporate tax rate floor during deep economic recessions. */
    public const MIN_CORPORATE_TAX_RATE = 0.12;
    /** Statutory corporate tax rate ceiling during overheating economic booms. */
    public const MAX_CORPORATE_TAX_RATE = 0.30;

    // --- GOVERNMENT SPENDING & FISCAL APPROPRIATIONS ---
    /** Counter-cyclical appropriation response: a -3% output gap lifts the spending index ~6 points (discretionary stimulus plus stabilizers). */
    public const GOVT_COUNTERCYCLICAL_SENSITIVITY = 200.0;
    /** Speed at which appropriations reach their cyclical target (stabilisers plus a stimulus bill lag of ~2-3 quarters); at 0.4 the spending index was below baseline in the deepest busts. */
    public const GOVT_SPENDING_MEAN_REVERSION = 1.5;
    /** Stochastic volatility of annual budget appropriations, kept below the countercyclical swing so the cycle drives spending. */
    public const GOVT_SPENDING_VOLATILITY = 0.03;
    /** Poisson intensity of major geopolitical events triggering spending surges. */
    public const GEOPOLITICAL_JUMP_PROBABILITY = 0.08;
    /** Mean log-return magnitude of a geopolitical spending surge. */
    public const GEOPOLITICAL_JUMP_MEAN = 0.15;
    /** Volatility of geopolitical jump size. */
    public const GEOPOLITICAL_JUMP_VOL = 0.08;

    // --- VASICEK ASRF RETAIL DEFAULT RATE ---
    /** Basel II/III consumer asset correlation factor for retail exposures. */
    public const RETAIL_ASRF_RHO = 0.12;
    /** Sensitivity of consumer macro credit Z-score to unemployment rate deviations from natural rate. */
    public const RETAIL_UNEMPLOYMENT_SENSITIVITY = 40.0;
    /** Sensitivity of consumer macro credit Z-score to inflation deviations from target. */
    public const RETAIL_INFLATION_SENSITIVITY = 25.0;
    /** Sensitivity of consumer macro credit Z-score to debt service and corporate borrowing spread stress. */
    public const RETAIL_DEBT_SERVICE_SENSITIVITY = 15.0;
    /** Stochastic volatility of idiosyncratic consumer credit shocks. */
    public const RETAIL_CREDIT_VOLATILITY = 0.35;

    // --- INTERBANK LIQUIDITY SPREAD (CIR PROCESS & JUMPS) ---
    /** Floor on the interbank spread (1 bp) keeping the CIR process strictly positive. */
    public const INTERBANK_MIN_SPREAD = 0.0001;
    /** Poisson intensity of severe interbank credit freeze/panic events. */
    public const INTERBANK_JUMP_PROBABILITY = 0.05;

    // --- Sovereign Debt Dynamics (Greenwood-Vayanos 2014) ---
    /** Baseline structural primary fiscal deficit as a fraction of GDP. */
    public const SOVEREIGN_STRUCTURAL_DEFICIT = 0.020;

    // --- Speculative-Grade Corporate Default Dynamics (Moody's / Altman) ---
    /** Basel II/III corporate asset correlation factor for speculative exposures. */
    public const CORPORATE_DEFAULT_RHO = 0.20;
    /** Sensitivity of corporate credit Z-score to macroeconomic output gap. */
    public const CORPORATE_DEFAULT_GAP_SENSITIVITY = 25.0;
    /** Corporate credit Z per unit of excess HY spread (~5): a 2,000 bps blowout with a -4% gap and 80% SLOOS yields a ~13% default rate. */
    public const CORPORATE_DEFAULT_SPREAD_SENSITIVITY = 5.0;
    /** Corporate credit Z per unit of SLOOS net tightening (~1): a 35% credit crunch adds ~0.35 to the systemic factor. */
    public const CORPORATE_DEFAULT_SLOOS_SENSITIVITY = 1.0;

    // --- Economic Policy Uncertainty (Baker, Bloom & Davis 2016) ---
    /** Length of the fixed electoral term in years; the clock is derived from simulation time, never stored. */
    public const ELECTION_TERM_YEARS = 4.0;
    /** Log lift of the index at the election, ramping in over the final year of the term (Julio & Yook 2012 locate the investment cut in the election year; the BBD index rises a quarter or so into a presidential vote). */
    public const EPU_ELECTION_LIFT = 0.25;
    /** Log lift per unit of recession probability above its unconditional level: the index roughly doubled through 2008-2011 as policy responses were debated. */
    public const EPU_STRESS_LIFT = 1.0;
    /** Unconditional recession probability the stress lift measures from (the probit intercept's ~15%). */
    public const EPU_STRESS_PROBABILITY_FLOOR = 0.15;
    /** Mean reversion of the log index (half-life ~5 months, the ~0.88 monthly autocorrelation of the BBD series). */
    public const EPU_MEAN_REVERSION = 1.5;
    /** Annual log volatility, giving a stationary log spread of ~0.32 around the level the calendar and the cycle set. */
    public const EPU_VOLATILITY = 0.55;
    /** Arrivals per year of unscheduled policy shocks (debt-ceiling standoffs, referendums, trade rulings). */
    public const EPU_JUMP_PROBABILITY = 0.50;
    /** Mean log size of an unscheduled policy shock. */
    public const EPU_JUMP_MEAN = 0.20;
    /** Log volatility of an unscheduled policy shock. */
    public const EPU_JUMP_VOL = 0.10;
    /** Floor of the index: even a quiet mid-term carries a third of average uncertainty. */
    public const MIN_EPU = 30.0;
    /** Ceiling of the index: the BBD US series peaked near four times its mean in 2020. */
    public const MAX_EPU = 400.0;

    // --- Administered Healthcare Prices (CMS market-basket update) ---
    /** Reimbursement update cut per unit of sovereign debt above the risk threshold (90%): the sequester that a fiscal correction imposes on administered prices. */
    public const REIMBURSEMENT_FISCAL_CUT_SENSITIVITY = 0.02;
    /** Floor on the annual update: administered prices are held, not cut, in a deflationary year. */
    public const REIMBURSEMENT_MIN_UPDATE = 0.0;

    // --- Household Credit Cycle (Mian & Sufi 2018; BIS DSR; Basel III CCyB) ---
    /** Share of household debt that is mortgage debt (~70% in the US), priced off the mortgage rate; the rest is consumer credit priced off the policy rate. */
    public const HOUSEHOLD_MORTGAGE_DEBT_SHARE = 0.70;
    /** Spread of consumer credit (cards, auto, personal) over the policy rate. */
    public const CONSUMER_CREDIT_SPREAD = 0.08;
    /** Average remaining maturity of the household debt stock (years) in the BIS debt-service ratio annuity (Drehmann, Illes, Juselius & Santos 2015 use 18). */
    public const DSR_AVERAGE_MATURITY_YEARS = 18.0;
    /** Time constant (years) of the ratio's long-run average: Drehmann & Juselius (2012, 2014) read the DSR as its deviation from a 15-year moving average. */
    public const DSR_TREND_HORIZON_YEARS = 15.0;
    /** Annual credit growth per unit of house-price deviation from baseline: collateral values drive borrowing (Mian & Sufi 2011 home-equity channel). */
    public const CREDIT_GROWTH_HOUSE_PRICE = 0.10;
    /** Annual credit growth lost per unit of the SLOOS tightening index: credit supply gates the boom. */
    public const CREDIT_GROWTH_SLOOS = 0.10;
    /** Annual credit growth lost per unit of the effective household rate above its neutral level. */
    public const CREDIT_GROWTH_RATE = 1.00;
    /** Annual credit growth per unit of output gap: incomes and confidence borrow. */
    public const CREDIT_GROWTH_GAP = 0.50;
    /** Annual reversion of leverage toward its baseline per unit of relative excess: amortisation outrunning new borrowing once the boom fades. */
    public const CREDIT_MEAN_REVERSION = 0.05;
    /** Annual log volatility of the leverage ratio. */
    public const CREDIT_GROWTH_SIGMA = 0.01;
    /** Extra annual credit contraction per unit of debt-service gap above the warning line: households repay when the service bites (Mian & Sufi 2018). */
    public const DELEVERAGING_SPEED = 0.50;
    /** Bounds on household debt to income. */
    public const MIN_HOUSEHOLD_DEBT_TO_INCOME = 0.40;
    /** Upper bound on household debt to income. */
    public const MAX_HOUSEHOLD_DEBT_TO_INCOME = 2.50;
    /** Time constant (years) of the one-sided credit trend: the stand-in for the Basel one-sided HP filter (lambda 400,000), whose trend has a multi-decade half-life. */
    public const CREDIT_TREND_HORIZON_YEARS = 10.0;
    /** Credit-to-GDP gap at which the countercyclical buffer starts to build (Basel III: 2 percentage points). */
    public const CCYB_GAP_FLOOR = 0.02;
    /** Credit-to-GDP gap at which the buffer reaches its maximum (Basel III: 10 percentage points). */
    public const CCYB_GAP_CEILING = 0.10;
    /** Maximum countercyclical capital buffer (Basel III: 2.5% of risk-weighted assets). */
    public const MAX_CCYB = 0.025;
    /** Phase-in time (years) of a buffer decision: Basel gives banks twelve months. */
    public const CCYB_PHASE_IN_YEARS = 1.0;
    /** Retail default z-score per unit of debt-service gap (ratio over its long-run average): a point of income more in debt service is ~0.3 z of stress. */
    public const RETAIL_DSR_SENSITIVITY = 30.0;
    /** How the buffer reads to lending standards: a unit of buffer is worth this much excess credit spread in the SLOOS response. */
    public const SLOOS_CCYB_SPREAD_EQUIVALENT = 0.50;

    // --- Credit Crisis Hazard (Schularick & Taylor 2012; Jorda, Schularick & Taylor 2013; Drehmann & Juselius 2014) ---
    /** Logit intercept: ~1% a year with no boom, the post-war advanced-economy crisis frequency in Schularick & Taylor's panel. */
    public const CREDIT_CRISIS_LOGIT_INTERCEPT = -4.6;
    /** Logit per unit of credit gap: a 10-point gap lifts the hazard to ~12% a year (BIS: a third of such gaps end in a crisis within three years). */
    public const CREDIT_CRISIS_LOGIT_GAP = 26.0;
    /** Logit per unit of debt-service gap: two points of income over the average add a logit point, the near-term trigger in Drehmann & Juselius. */
    public const CREDIT_CRISIS_LOGIT_DSR = 50.0;
    /** Years after a crisis during which the hazard is off: the bust resets the credit stock, and the panel's crises are decades apart. */
    public const CREDIT_CRISIS_REFRACTORY_YEARS = 5.0;
    /** Demand drag (pp/yr) a crisis books on impact without any boom behind it: a financial recession runs ~1pp a year deeper than a normal one (JST 2013). */
    public const CREDIT_CRISIS_DRAG_BASE = 0.010;
    /** Extra drag per unit of credit gap at the crisis: "credit bites back", each ten points of boom cost another ~1.5pp a year. */
    public const CREDIT_CRISIS_DRAG_PER_GAP = 0.15;
    /** Decay of the crisis drag (two-year time constant): financial recessions bottom in the second or third year. */
    public const CREDIT_CRISIS_DRAG_DECAY = 0.5;
    /** How a crisis reads to lending standards: a unit of crisis drag is worth this much excess spread, so a boom-fed crisis (2.5pp/yr) is 400bp and standards reach the ~80% net tightening of 2008 (Bassett, Chosak, Driscoll & Zakrajsek 2014). */
    public const SLOOS_CRISIS_SPREAD_EQUIVALENT = 1.6;

    // --- Federal Reserve Senior Loan Officer Opinion Survey (SLOOS) ---
    /** Mean-reversion speed (kappa) of bank lending standards toward fundamental target. */
    public const SLOOS_KAPPA = 1.80;
    /** Sensitivity of net tightening percentage to wholesale corporate credit spread widening. */
    public const SLOOS_CREDIT_SENSITIVITY = 15.0;
    /** Sensitivity of net tightening percentage to output gap contraction. */
    public const SLOOS_GAP_SENSITIVITY = 3.0;
    /** Stochastic diffusion volatility of commercial bank underwriting standards. */
    public const SLOOS_SIGMA = 0.08;

    public function __construct(
        private readonly MathUtility $mathUtility
    ) {}

    /**
     * Merton (1974) Structural Distance-to-Default Corporate Credit Spread Model
     * with Jarrow, Lando & Turnbull (1997) Dual-Tranche (IG vs HY) Rating Migration Cliff.
     *
     * Models investment-grade (IG) and speculative high-yield (HY) corporate credit spreads
     * over risk-free Treasuries driven by leverage decay, equity volatility, wholesale interbank contagion,
     * and the non-linear "fallen angel" rating migration cliff during contractions.
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public function calculateMacroCreditSpread(MacroState $state): void
    {
        $interbankStress = max(0.0, $state->interbankLiquiditySpreadEma - MacroEngine::INTERBANK_BASELINE_SPREAD);

        // Sovereign ceiling: a corporate is rarely priced inside its own sovereign, so part of the fiscal premium
        // lifts the whole investment-grade base before the cycle scales it.
        $baseIgSpread = MacroEngine::BASE_CREDIT_SPREAD + (self::SOVEREIGN_CEILING_PASSTHROUGH * $state->sovereignRiskSpreadEma);

        $trancheSpreads = $this->mathUtility->calculateDualTrancheCreditSpreads(
            baseIgSpread: $baseIgSpread,
            outputGapEma: $state->outputGapEma,
            marketVolEma: $state->marketVolatilityEma,
            interbankStress: $interbankStress,
            hyBaseMultiplier: MacroEngine::HY_BASE_SPREAD_MULTIPLIER,
            fallenAngelSens: MacroEngine::FALLEN_ANGEL_CLIFF_SENSITIVITY
        );

        // Both tranches are already floored and capped inside the formula (MIN/MAX_CREDIT_SPREAD, MAX_HY_CREDIT_SPREAD).
        $state->macroCreditSpread = $trancheSpreads['ig'];
        $state->highYieldCreditSpread = $trancheSpreads['hy'];
    }

    /**
     * Cox-Ingersoll-Ross (CIR 1985) Square-Root Diffusion with Systemic TED Freeze Jumps (Kou 2002).
     *
     * Models wholesale interbank lending liquidity spreads (TED / Libor-OIS spread) using a strictly
     * positive mean-reverting CIR square-root process compounded with volatility-sensitive Poisson panic jumps.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateInterbankLiquiditySpread(MacroState $state, float $dt): void
    {
        $currentSpread = $state->interbankLiquiditySpread ?? MacroEngine::INTERBANK_BASELINE_SPREAD;

        // Systemic credit risk coupling (Brunnermeier 2009, Gorton & Metrick 2012):
        // Interbank lending risk premium rises with wholesale corporate credit spreads
        $excessCreditSpread = max(0.0, $state->macroCreditSpread - MacroEngine::BASE_CREDIT_SPREAD);
        $creditCoupledTheta = MacroEngine::INTERBANK_BASELINE_SPREAD + ($excessCreditSpread * self::INTERBANK_CREDIT_COUPLING);

        $dW = $this->mathUtility->generateStandardNormal();
        $baseProcess = $this->mathUtility->calculateCIR(
            currentValue: $currentSpread,
            kappa: MacroEngine::INTERBANK_SPREAD_KAPPA,
            theta: $creditCoupledTheta,
            sigma: MacroEngine::INTERBANK_SPREAD_SIGMA,
            dt: $dt,
            dW: $dW
        );

        $volatilityRatio = max(1.0, $state->marketVolatilityEma / MacroEngine::MACRO_VOL_BASE_ANCHOR);
        $creditRatio = max(1.0, $state->macroCreditSpreadEma / MacroEngine::BASE_CREDIT_SPREAD);
        $jumpProbability = min(0.20, self::INTERBANK_JUMP_PROBABILITY * $volatilityRatio * (1.0 + 0.5 * ($creditRatio - 1.0)));

        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: $jumpProbability,
            jumpMean: self::INTERBANK_JUMP_MEAN,
            jumpVol: self::INTERBANK_JUMP_VOL,
            dt: $dt
        );

        $jumpAmount = 0.0;
        if ($jumpData['multiplier'] !== 1.0) {
            $jumpAmount = $baseProcess * ($jumpData['multiplier'] - 1.0);
        }
        // A credit crisis is a run on wholesale funding (Gorton & Metrick 2012): the panic jump lands with certainty on the day.
        if ($state->lastCreditCrisisAt === $state->totalTime) {
            $jumpAmount = max($jumpAmount, $baseProcess * (exp(self::INTERBANK_JUMP_MEAN) - 1.0));
        }

        $state->interbankLiquiditySpread = max(
            self::INTERBANK_MIN_SPREAD,
            min(self::INTERBANK_MAX_SPREAD, $baseProcess + $jumpAmount)
        );
    }

    /**
     * Basel II / III Vasicek Asymptotic Single Risk Factor (ASRF) Consumer Credit Portfolio Model.
     *
     * Derives conditional retail loan default probability (PD) driven by a macroeconomic systemic factor
     * reflecting unemployment shocks (Okun's Law) and inflation-induced disposable income erosion.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateRetailDefaultRate(MacroState $state, float $dt): void
    {
        $unemploymentShock = ($state->unemploymentRateEma - $state->nairu) * self::RETAIL_UNEMPLOYMENT_SENSITIVITY;
        $inflationShock = ($state->inflationEma - MacroEngine::TARGET_INFLATION) * self::RETAIL_INFLATION_SENSITIVITY;

        $borrowingSpreadStress = max(0.0, $state->macroCreditSpreadEma - MacroEngine::BASE_CREDIT_SPREAD);
        $interbankStress = max(0.0, $state->interbankLiquiditySpreadEma - MacroEngine::INTERBANK_BASELINE_SPREAD);
        // Debt service is what a household actually pays: the spreads on new borrowing plus the burden on the stock.
        $debtServiceShock = (($borrowingSpreadStress + $interbankStress) * self::RETAIL_DEBT_SERVICE_SENSITIVITY)
            + ($state->householdDebtServiceGap * self::RETAIL_DSR_SENSITIVITY);

        $dW = $this->mathUtility->generateStandardNormal();
        $macroZ = - ($unemploymentShock + $inflationShock + $debtServiceShock) + ($dW * self::RETAIL_CREDIT_VOLATILITY);

        $conditionalPd = $this->mathUtility->calculateVasicekExpectedLoss(
            macroZ: $macroZ,
            pdLra: MacroEngine::RETAIL_DEFAULT_BASELINE,
            rho: self::RETAIL_ASRF_RHO,
            lgd: 1.0
        );

        $state->retailDefaultRate = max(0.005, min(0.20, $conditionalPd));
    }

    /**
     * Keynesian Countercyclical Fiscal Spending Rule with Geopolitical Defense Jumps.
     *
     * Adjusts sovereign government spending countercyclically against GDP output gap deviations,
     * combined with Schwartz (1997) mean reversion and Poisson geopolitical conflict appropriation jumps.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateGovernmentSpending(MacroState $state, float $dt): void
    {
        $cyclicalTarget = MacroEngine::GOVT_SPENDING_BASELINE - ($state->outputGapEma * self::GOVT_COUNTERCYCLICAL_SENSITIVITY);
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

    /**
     * Barro (1979) Tax-Smoothing Hypothesis & Automatic Fiscal Stabilizers.
     *
     * Adjusts the corporate tax rate continuously via an Ornstein-Uhlenbeck institutional process:
     * raises effective tax burden during economic booms to cool demand, and cuts taxes during recessions.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateDynamicFiscalPolicy(MacroState $state, float $dt): void
    {
        $targetTaxRate = MacroEngine::TARGET_CORPORATE_TAX_RATE + (self::FISCAL_STABILIZER_SENSITIVITY * $state->outputGapEma);
        $targetTaxRate = max(self::MIN_CORPORATE_TAX_RATE, min(self::MAX_CORPORATE_TAX_RATE, $targetTaxRate));

        $state->corporateTaxRate += self::FISCAL_ADJUSTMENT_SPEED * ($targetTaxRate - $state->corporateTaxRate) * $dt;
    }

    /**
     * Sovereign Debt-to-GDP Stock Accumulation (Blanchard 2019, Greenwood-Vayanos 2014).
     *
     * Accumulates sovereign debt-to-GDP ratio from primary deficit flow, net interest expenses,
     * and nominal GDP growth erosion:
     *   d(Debt/GDP) = [ (G - T)/GDP + (r_10y - g_nominal) * (Debt/GDP) ] * dt
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateSovereignDebt(MacroState $state, float $dt): void
    {
        $taxRevenue = $state->corporateTaxRate * $state->nominalGdpIndex * (1.0 + $state->outputGap);
        $govtSpendingFlow = ($state->governmentSpendingIndex / MacroEngine::GOVT_SPENDING_BASELINE)
            * MacroEngine::TARGET_CORPORATE_TAX_RATE * $state->nominalGdpIndex;

        // Bohn (1998) Fiscal Reaction Function: primary budget surpluses emerge when debt/GDP exceeds neutral threshold
        $excessDebt = max(0.0, $state->sovereignDebtToGdp - MacroEngine::SOVEREIGN_DEBT_NEUTRAL_THRESHOLD);
        $bohnFiscalAdjustment = self::BOHN_FISCAL_REACTION_SENSITIVITY * $excessDebt * $state->nominalGdpIndex;

        $primaryDeficit = ($govtSpendingFlow - $taxRevenue) + (self::SOVEREIGN_STRUCTURAL_DEFICIT * $state->nominalGdpIndex) - $bohnFiscalAdjustment;
        $state->primaryDeficitToGdp = $primaryDeficit / max(0.1, $state->nominalGdpIndex);
        $interestCost = $state->yield10yEma * $state->sovereignDebtToGdp;

        // Blanchard (2019): Nominal GDP growth includes real potential growth trend (labor + TFP) + cyclical gap + inflation
        $realPotentialGrowth = MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + MacroEngine::TFP_DRIFT;
        $nominalGrowthRate = $realPotentialGrowth + $state->outputGap + $state->inflationEma;
        $growthErosion = $nominalGrowthRate * $state->sovereignDebtToGdp;

        $dDebt = ($primaryDeficit / max(0.1, $state->nominalGdpIndex)) + $interestCost - $growthErosion;
        $state->sovereignDebtToGdp += $dDebt * $dt;
        $state->sovereignDebtToGdp = max(0.20, min(2.50, $state->sovereignDebtToGdp));
    }

    /**
     * The household credit cycle (Mian & Sufi 2018) with the BIS debt-service ratio and the Basel III buffer.
     *
     * Leverage builds on collateral values, easy standards, cheap money and incomes, and unwinds through
     * amortisation and, past the warning line, deleveraging. The debt-service ratio is the BIS annuity
     * (Drehmann, Illes, Juselius & Santos 2015): the stock times the instalment its effective rate implies
     * over the average remaining maturity, read against its own 15-year average (Drehmann & Juselius 2012),
     * so a cold start and a slow drift in leverage are silent. The credit-to-GDP gap is the stock against a slow one-sided trend
     * (the Basel filter's stand-in), and the countercyclical buffer maps that gap onto the 0-2.5% Basel
     * schedule with a year's phase-in. The ratio reaches the retail default rate, the IS curve and, through
     * the buffer, lending standards; the gap reaches the lenders' order books.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateHouseholdCredit(MacroState $state, float $dt): void
    {
        $mortgageRate = $state->yield10yEma + MacroEngine::RESIDENTIAL_MORTGAGE_SPREAD;
        $revolvingRate = max(0.0, $state->policyRateEma) + self::CONSUMER_CREDIT_SPREAD;
        $effectiveRate = (self::HOUSEHOLD_MORTGAGE_DEBT_SHARE * $mortgageRate) + ((1.0 - self::HOUSEHOLD_MORTGAGE_DEBT_SHARE) * $revolvingRate);
        $neutralRate = (self::HOUSEHOLD_MORTGAGE_DEBT_SHARE * (MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION + MacroEngine::NS_BASE_TERM_PREMIUM + MacroEngine::RESIDENTIAL_MORTGAGE_SPREAD))
            + ((1.0 - self::HOUSEHOLD_MORTGAGE_DEBT_SHARE) * (MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION + self::CONSUMER_CREDIT_SPREAD));

        $housePriceLift = ($state->residentialPropertyIndexEma / MacroEngine::RESIDENTIAL_BASELINE) - 1.0;
        $relativeExcess = ($state->householdDebtToIncome - MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE) / MacroEngine::HOUSEHOLD_DEBT_TO_INCOME_BASELINE;
        $excessDsr = max(0.0, $state->householdDebtServiceGap - MacroEngine::HOUSEHOLD_DSR_STRESS_MARGIN);

        $growth = (self::CREDIT_GROWTH_HOUSE_PRICE * $housePriceLift)
            - (self::CREDIT_GROWTH_SLOOS * $state->sloosTighteningIndexEma)
            - (self::CREDIT_GROWTH_RATE * ($effectiveRate - $neutralRate))
            + (self::CREDIT_GROWTH_GAP * $state->outputGapEma)
            - (self::CREDIT_MEAN_REVERSION * $relativeExcess)
            - (self::DELEVERAGING_SPEED * $excessDsr);
        $noise = self::CREDIT_GROWTH_SIGMA * sqrt($dt) * $this->mathUtility->generateStandardNormal();

        $state->householdDebtToIncome = max(self::MIN_HOUSEHOLD_DEBT_TO_INCOME, min(self::MAX_HOUSEHOLD_DEBT_TO_INCOME, $state->householdDebtToIncome * exp(($growth * $dt) + $noise)));

        // BIS debt-service ratio: the instalment on the stock over its average remaining maturity.
        $annuityFactor = $effectiveRate > 0.0
            ? $effectiveRate / (1.0 - ((1.0 + $effectiveRate) ** (-self::DSR_AVERAGE_MATURITY_YEARS)))
            : 1.0 / self::DSR_AVERAGE_MATURITY_YEARS;
        $state->householdDebtServiceRatio = $state->householdDebtToIncome * $annuityFactor;

        // The service gap: the ratio against its own long-run average, started at the first observation.
        if ($state->householdDebtServiceTrend <= 0.0) {
            $state->householdDebtServiceTrend = $state->householdDebtServiceRatio;
        }
        $state->householdDebtServiceTrend = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $state->householdDebtServiceTrend,
            targetValue: $state->householdDebtServiceRatio,
            dt: $dt,
            lagTimeConstant: self::DSR_TREND_HORIZON_YEARS
        );
        $state->householdDebtServiceGap = $state->householdDebtServiceRatio - $state->householdDebtServiceTrend;

        // Credit-to-GDP gap against a slow one-sided trend, and the Basel buffer it maps to.
        $state->creditToGdpTrend = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $state->creditToGdpTrend,
            targetValue: $state->householdDebtToIncome,
            dt: $dt,
            lagTimeConstant: self::CREDIT_TREND_HORIZON_YEARS
        );
        $state->creditToGdpGap = $state->householdDebtToIncome - $state->creditToGdpTrend;

        $bufferPosition = max(0.0, min(1.0, ($state->creditToGdpGapEma - self::CCYB_GAP_FLOOR) / (self::CCYB_GAP_CEILING - self::CCYB_GAP_FLOOR)));
        $state->countercyclicalBufferRate = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $state->countercyclicalBufferRate,
            targetValue: self::MAX_CCYB * $bufferPosition,
            dt: $dt,
            lagTimeConstant: self::CCYB_PHASE_IN_YEARS
        );
    }

    /**
     * The crisis channel of the credit cycle (Schularick & Taylor 2012; Jorda, Schularick & Taylor 2013).
     *
     * A boom carries state the output gap does not: the debt stock. Its hazard is a logit on the credit gap
     * (the medium-term signal) and the debt-service gap (the near-term trigger, Drehmann & Juselius 2014),
     * evaluated as a Poisson arrival per tick. When a crisis lands it books a deleveraging drag on demand
     * that scales with the boom behind it ("credit bites back") and decays over the following years, and
     * the same tick forces the wholesale funding run in the interbank spread. The hazard is off for a
     * refractory window afterwards: the bust resets the stock the hazard reads.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateCreditCrisisHazard(MacroState $state, float $dt): void
    {
        $state->creditCrisisDrag *= exp(-self::CREDIT_CRISIS_DRAG_DECAY * $dt);

        $yearsSinceLast = $state->lastCreditCrisisAt < 0.0 ? INF : $state->totalTime - $state->lastCreditCrisisAt;
        if ($yearsSinceLast < self::CREDIT_CRISIS_REFRACTORY_YEARS) {
            $state->creditCrisisHazard = 0.0;
            return;
        }

        $state->creditCrisisHazard = $this->mathUtility->calculateSchularickTaylorCrisisHazard(
            creditGap: $state->creditToGdpGapEma,
            debtServiceGap: $state->householdDebtServiceGap,
            beta0: self::CREDIT_CRISIS_LOGIT_INTERCEPT,
            betaGap: self::CREDIT_CRISIS_LOGIT_GAP,
            betaDsr: self::CREDIT_CRISIS_LOGIT_DSR
        );

        if (!$this->mathUtility->checkProbability($state->creditCrisisHazard * $dt)) {
            return;
        }

        $state->lastCreditCrisisAt = $state->totalTime;
        $state->creditCrisisDrag += self::CREDIT_CRISIS_DRAG_BASE + (self::CREDIT_CRISIS_DRAG_PER_GAP * max(0.0, $state->creditToGdpGapEma));
    }

    /**
     * Sovereign risk premium (Laubach 2009): the fiscal position priced into the long end.
     *
     * One-sided on the debt stock above the level at which an advanced sovereign is re-rated. The premium is
     * a level the whole curve carries (it enters the term premium, the corporate IG base through the
     * sovereign ceiling, and the currency), repriced over budget rounds rather than ticks. At the seeded
     * 60% debt it is zero, and it stays zero through the 85-90% the fiscal reaction settles at.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateSovereignRiskSpread(MacroState $state, float $dt): void
    {
        $excessDebt = max(0.0, $state->sovereignDebtToGdpEma - self::SOVEREIGN_RISK_DEBT_THRESHOLD);
        $target = min(self::MAX_SOVEREIGN_RISK_SPREAD, self::LAUBACH_DEBT_YIELD_SENSITIVITY * $excessDebt);

        $state->sovereignRiskSpread = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $state->sovereignRiskSpread,
            targetValue: $target,
            dt: $dt,
            lagTimeConstant: self::SOVEREIGN_RISK_REPRICING_YEARS
        );
    }

    /**
     * Economic policy uncertainty (Baker, Bloom & Davis 2016) on a fixed-term election calendar.
     *
     * A log mean-reverting index whose level is set by two things the record ties it to: the calendar, since
     * uncertainty about the policy regime builds into a scheduled election and resolves after it (Julio &
     * Yook 2012), and the cycle, since a downturn brings the policy response itself into question. Unscheduled
     * shocks arrive as jumps. The election is derived from simulation time -- a term of ELECTION_TERM_YEARS --
     * so nothing about the calendar is stored, only the tick the last vote fell on, for the event pulse.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculatePolicyUncertainty(MacroState $state, float $dt): void
    {
        $term = self::ELECTION_TERM_YEARS;
        $yearsToElection = $term - fmod($state->totalTime, $term);
        $electionProximity = max(0.0, 1.0 - $yearsToElection);
        $recessionExcess = max(0.0, $state->recessionProbabilityEma - self::EPU_STRESS_PROBABILITY_FLOOR);

        // The index is normalised to average its baseline, as the published series is, so the calendar lift is
        // taken relative to its term average (a one-year ramp averages 0.5 / term) and the jumps are compensated
        // by their stationary log contribution (lambda x mean size / kappa). Only the cycle lifts the mean.
        $averageElectionProximity = 0.5 / $term;
        $jumpLogCompensator = self::EPU_JUMP_PROBABILITY * self::EPU_JUMP_MEAN / self::EPU_MEAN_REVERSION;

        $target = MacroEngine::EPU_BASELINE * exp(
            (self::EPU_ELECTION_LIFT * ($electionProximity - $averageElectionProximity))
            + (self::EPU_STRESS_LIFT * $recessionExcess)
            - $jumpLogCompensator
        );

        $baseProcess = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: max(self::MIN_EPU, $state->policyUncertaintyIndex),
            kappa: self::EPU_MEAN_REVERSION,
            theta: $target,
            sigma: self::EPU_VOLATILITY,
            dt: $dt,
            dW: $this->mathUtility->generateStandardNormal()
        );

        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: self::EPU_JUMP_PROBABILITY,
            jumpMean: self::EPU_JUMP_MEAN,
            jumpVol: self::EPU_JUMP_VOL,
            dt: $dt
        );

        $state->policyUncertaintyIndex = max(self::MIN_EPU, min(self::MAX_EPU, $baseProcess * $jumpData['multiplier']));

        if (floor($state->totalTime / $term) > floor(($state->totalTime - $dt) / $term)) {
            $state->lastElectionAt = $state->totalTime;
        }
    }

    /**
     * Administered healthcare price update (the CMS market-basket rule).
     *
     * Hospital reimbursement is an administered price: reset once a year to the inflation the payer observes
     * at the update, less a statutory productivity offset (ACA s.3401), less a sequester that scales with the
     * sovereign's excess debt. It is a step function, not a diffusion -- between updates the rate is flat
     * whatever inflation does, which is why a hospital's margin is squeezed through an inflation spike and
     * repaired a year later.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateReimbursementRate(MacroState $state, float $dt): void
    {
        $crossedYearEnd = floor($state->totalTime) > floor($state->totalTime - $dt);
        if (!$crossedYearEnd) {
            return;
        }

        $excessDebt = max(0.0, $state->sovereignDebtToGdpEma - self::SOVEREIGN_RISK_DEBT_THRESHOLD);
        $update = $state->inflationEma
            - MacroEngine::REIMBURSEMENT_PRODUCTIVITY_OFFSET
            - (self::REIMBURSEMENT_FISCAL_CUT_SENSITIVITY * $excessDebt);

        $state->reimbursementRateGrowth = max(self::REIMBURSEMENT_MIN_UPDATE, $update);
        $state->reimbursementRateIndex *= 1.0 + $state->reimbursementRateGrowth;
    }

    /**
     * Federal Reserve Senior Loan Officer Opinion Survey (SLOOS) Credit Standards Index.
     *
     * Evaluates net percentage of commercial banks tightening C&I loan standards
     * based on wholesale credit spreads and macroeconomic output gap.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateSloosCreditStandards(MacroState $state, float $dt): void
    {
        $excessCreditSpread = max(0.0, $state->macroCreditSpread - MacroEngine::BASE_CREDIT_SPREAD);
        // A countercyclical buffer is capital banks must hold against the loans they write: it tightens standards like a spread would.
        $bufferTightening = self::SLOOS_CCYB_SPREAD_EQUIVALENT * $state->countercyclicalBufferRateEma;
        // A crisis destroys the capital behind the loan book: the exogenous credit-supply cut of Bassett et al. (2014).
        $crisisTightening = self::SLOOS_CRISIS_SPREAD_EQUIVALENT * $state->creditCrisisDrag;
        $dW = $this->mathUtility->generateStandardNormal();

        $state->sloosTighteningIndex = $this->mathUtility->calculateSloosCreditStandards(
            currentSloos: $state->sloosTighteningIndex,
            outputGap: $state->outputGapEma,
            excessCreditSpread: $excessCreditSpread + $bufferTightening + $crisisTightening,
            dt: $dt,
            dW: $dW,
            kappa: self::SLOOS_KAPPA,
            creditSensitivity: self::SLOOS_CREDIT_SENSITIVITY,
            gapSensitivity: self::SLOOS_GAP_SENSITIVITY,
            sigma: self::SLOOS_SIGMA
        );
    }

    /**
     * Moody's / S&P Speculative-Grade Corporate Default Rate Model.
     *
     * Derives realized corporate probability of default (CDR) driven by a structural
     * macroeconomic credit factor combining output gap, speculative high-yield credit spreads,
     * and bank lending standards (SLOOS).
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateCorporateDefaultRate(MacroState $state, float $dt): void
    {
        $baseHySpread = MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER;
        $excessHySpread = max(0.0, $state->highYieldCreditSpread - $baseHySpread);

        $macroZ = ($state->outputGapEma * self::CORPORATE_DEFAULT_GAP_SENSITIVITY)
            - ($excessHySpread * self::CORPORATE_DEFAULT_SPREAD_SENSITIVITY)
            - ($state->sloosTighteningIndexEma * self::CORPORATE_DEFAULT_SLOOS_SENSITIVITY);

        $state->corporateDefaultRate = $this->mathUtility->calculateCorporateDefaultRate(
            macroZ: $macroZ,
            baseDefaultRate: MacroEngine::CORPORATE_DEFAULT_BASELINE,
            rho: self::CORPORATE_DEFAULT_RHO
        );
    }
}
