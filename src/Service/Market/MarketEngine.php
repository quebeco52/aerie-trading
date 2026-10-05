<?php

namespace App\Service\Market;

use App\Service\Macro\MacroEngine;
use App\DTO\MacroStateDTO;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\BusinessModelInterface;

/**
 * Service responsible for calculating stock price movements based on various market factors.
 *
 * This engine uses a hybrid model incorporating Geometric Brownian Motion (GBM),
 * Jump Diffusion processes, and Mean Reversion to simulate realistic stock price behavior.
 */
class MarketEngine
{
    // Volatility Bounds
    private const MIN_VOLATILITY = 0.01; // 1% absolute floor
    private const MAX_VOLATILITY = 1.50; // 150% absolute ceiling

    // Jump Diffusion Constants
    private const SVJJ_P_UP = 0.40;
    private const SVJJ_P_DOWN = 0.60;

    // Analyst Multipliers
    private const VALUE_ANALYST_BOOK_MULT = 0.80;

    // --- Momentum (Hong & Stein 1999) ---
    /** Divisor applied to the reversion rate per unit of accumulated price trend; at the 0.50 trend cap a name reverts at two thirds speed. */
    private const MOMENTUM_REVERSION_RESISTANCE = 1.00;

    // --- Variance Process Calibration ---
    /** Speed the idiosyncratic variance reverts to its long-run level, in reversions per year; a shock is most of the way gone inside two months. */
    public const BASE_VARIANCE_REVERSION_SPEED = 6.0;

    // --- Variance Budget ---
    /** Ceiling on the share of a name's long-run IDIOSYNCRATIC variance the market-wide jump may reclaim, so a quiet name keeps a diffusion that still reads as its configured volatility. */
    private const MAX_SYSTEMIC_VARIANCE_DRAG_SHARE = 0.25;
    /** Ceiling on the share of a name's long-run IDIOSYNCRATIC variance its OWN jump process may supply; the jump component of single-stock return variance is a minority of the total (Huang & Tauchen 2005). */
    private const MAX_IDIOSYNCRATIC_JUMP_VARIANCE_SHARE = 0.25;
    /** Floor on the share of a name's configured variance that stays idiosyncratic, for a configuration whose beta alone already consumes the whole of its stated volatility. */
    private const MIN_IDIOSYNCRATIC_VARIANCE_SHARE = 0.20;

    // --- Volatility Process ---
    /** Mean of the contemporaneous variance jump, as a share of the name's current variance. */
    private const VARIANCE_JUMP_MEAN_SHARE = 0.50;
    /** Sensitivity of the variance reversion speed to jump intensity; a jumpier name pulls back to its long-run level faster rather than having its target clamped. */
    private const JUMP_REGIME_KAPPA_SENSITIVITY = 0.15;
    /** Ratio of the down-jump scale to the up-jump scale, the Kou left-tail skew a single name carries. */
    private const JUMP_DOWNSIDE_SCALE_RATIO = 1.25;
    /** Floor on the jump scale parameter, so a name configured with no jump size cannot divide by it. */
    private const MIN_JUMP_SCALE = 0.01;

    // --- Revenue Floor (Margin-Adjusted Price-to-Sales) ---
    /** Floor on the P/E anchor the price-to-sales multiple is struck from, so a distressed multiple does not erase the revenue floor entirely. */
    private const MIN_PS_ANCHOR_PE = 10.0;
    /** Bounds on the implied net margin the price-to-sales multiple is built on. */
    private const MIN_IMPLIED_NET_MARGIN = 0.01;
    private const MAX_IMPLIED_NET_MARGIN = 0.30;

    // --- Dividend Discount Support ---
    /** Floor on the required yield used to discount the dividend stream. */
    private const MIN_DIVIDEND_REQUIRED_YIELD = 0.02;
    /** Cap on the fundamental growth a retained-earnings calculation may imply for the dividend stream. */
    private const MAX_DIVIDEND_IMPLIED_GROWTH = 0.04;

    public function __construct(
        private MathUtility $mathUtility
    ) {}

    /**
     * The long-run variance a name carries on its own, once its market loading has been paid for.
     *
     * A name's market loading is beta * marketVol by definition, so the diffusion supplies it outright and
     * the stochastic volatility process carries only what is left. Deriving the loading the other way round
     * — from an implied correlation clamped below one — truncated the beta of any name whose volatility fell
     * short of what its beta demanded: measured against the market factor a configured 1.3 realized 1.19 and
     * a 2.8 realized 2.30, and in a crisis, when market volatility triples and the truncation binds on
     * everything at once, a 1.3 realized 0.45. The CAPM drift and the systemic jump were paid on the full
     * beta throughout, so high-beta names were handed the premium without the risk.
     *
     * The target is struck against the market's BASELINE volatility, so the configured figure is the name's
     * total volatility in normal conditions and total volatility then rises with the market's through beta,
     * rather than being inert to it as it was before.
     *
     * A configuration whose beta already consumes the whole of its stated volatility is infeasible; the name
     * keeps a guaranteed idiosyncratic share and ends up more volatile than its configured figure, which is
     * the visible failure mode rather than the silent one.
     *
     * @param float $longTermVolatility The name's configured total volatility.
     * @param float $beta               Its market loading.
     * @return float Annualized variance, excluding the systematic loading.
     */
    public static function longTermIdiosyncraticVariance(float $longTermVolatility, float $beta): float
    {
        $longTermVar = $longTermVolatility * $longTermVolatility;
        $baselineSystematicVar = ($beta * MacroEngine::MACRO_VOL_BASE_ANCHOR) * ($beta * MacroEngine::MACRO_VOL_BASE_ANCHOR);

        return max($longTermVar * self::MIN_IDIOSYNCRATIC_VARIANCE_SHARE, $longTermVar - $baselineSystematicVar);
    }

    /**
     * The Kou jump rates a name actually gaps at, after its jump is held to its variance budget.
     *
     * The jump scale is held to the share of the name's variance a jump process is entitled to. Left at
     * whatever the seed configured, the arrival rate and jump size together supplied a median 29% of a
     * name's variance and up to 148% of it — the jump was not an overlay on the diffusion, it WAS the
     * diffusion for several names, and since none of it was budgeted every name realized more volatility
     * than it was configured with. Scaling the size rather than the arrival rate keeps the seed's statement
     * about how OFTEN a name gaps, which is a property of its business, and only calibrates how FAR, which
     * has to be consistent with how risky the name is overall.
     *
     * Public and shared rather than inline in the price step, because the option desk writes contracts on
     * exactly this jump: the smile it quotes is the skewness and kurtosis these two rates imply, and a desk
     * quoting the seed's uncalibrated figures would be selling a different distribution from the one the
     * price process goes on to realize.
     *
     * @param float $longTermVolatility The name's configured total volatility.
     * @param float $beta               Its market loading.
     * @param float $lambda             Jump intensity, arrivals per year.
     * @param float $jumpVol            The configured jump scale, before calibration.
     * @return array{eta_up: float, eta_down: float, scale: float} Exponential rates and the scale behind them.
     */
    public static function calibratedJumpParameters(
        float $longTermVolatility,
        float $beta,
        float $lambda,
        float $jumpVol
    ): array {
        $jumpScale = max(self::MIN_JUMP_SCALE, $jumpVol);

        if ($lambda > 0.0) {
            // Untruncated second moment of the Kou jump, 2 * scale^2 * (pUp + pDown * ratio^2), which is
            // monotone in the scale and therefore invertible for the budget. Truncation only removes mass,
            // so solving on it and deducting the truncated figure at the budget can never over-reclaim.
            $rawSecondMomentPerUnit = 2.0 * (self::SVJJ_P_UP
                + (self::SVJJ_P_DOWN * self::JUMP_DOWNSIDE_SCALE_RATIO * self::JUMP_DOWNSIDE_SCALE_RATIO));
            $budget = self::longTermIdiosyncraticVariance($longTermVolatility, $beta)
                * self::MAX_IDIOSYNCRATIC_JUMP_VARIANCE_SHARE;
            $configured = $lambda * $rawSecondMomentPerUnit * $jumpScale * $jumpScale;

            if ($configured > $budget && $configured > 0.0) {
                $jumpScale = max(self::MIN_JUMP_SCALE, $jumpScale * sqrt($budget / $configured));
            }
        }

        return [
            'eta_up' => 1.0 / $jumpScale,
            'eta_down' => 1.0 / ($jumpScale * self::JUMP_DOWNSIDE_SCALE_RATIO),
            'scale' => $jumpScale,
        ];
    }

    /** Probability that a single-name jump is upwards; the behavioural skew the price process carries. */
    public static function jumpProbabilityUp(): float
    {
        return self::SVJJ_P_UP;
    }

    /**
     * The speed the variance process actually reverts at, once its jump regime is accounted for.
     *
     * A jumpier name pulls back to its long-run level faster rather than having its target clamped, which is
     * what keeps a jump from permanently raising the volatility it was budgeted out of. The option desk
     * needs the same figure: the volatility it writes a contract at is the variance expected over the
     * contract's life, and that expectation is an integral whose only shape parameter is this speed.
     *
     * @param float $lambda    Jump intensity, arrivals per year.
     * @param float $baseKappa  Reversion speed before the jump regime scales it.
     */
    public static function varianceReversionSpeed(float $lambda, float $baseKappa = self::BASE_VARIANCE_REVERSION_SPEED): float
    {
        return $baseKappa * (1.0 + ($lambda * self::JUMP_REGIME_KAPPA_SENSITIVITY));
    }

    /**
     * Calculates the next stock price using a hybrid model.
     *
     * This method combines:
     * 1. Geometric Brownian Motion (GBM) for standard drift and volatility.
     * 2. Jump Diffusion for sudden market shocks (Merton Model).
     * 3. Mean Reversion to pull the price towards a fundamental fair value based on PE.
     * 4. Heston Stochastic Volatility Model for dynamic volatility.
     *
     * @param float $currentPrice       The current price of the stock.
     * @param float $currentVolatility  The current instantaneous volatility.
     * @param float $longTermVolatility The long-run mean volatility.
     * @param float $earningsPerShare   The current earnings per share (EPS).
     * @param float $dt                 The time step for the simulation (in years).
     * @param float $lambda             The jump intensity (average number of jumps per year).
     * @param float $jumpVol            The volatility of the jump size.
     * @param float $beta               The stock's beta (sensitivity to market movements).
     * @param float $marketZ            The systemic market shock Z-score (standard normal).
     * @param float $marketVol          The volatility of the broader market.
     * @param float $reversionSpeed     The speed at which the price reverts to fair value.
     * @param float $kappa              The rate at which volatility reverts to the long-run mean.
     * @param float $volOfVol           The volatility of volatility (how much volatility fluctuates).
     * @param MacroStateDTO|null $macroState The current macroeconomic state, which can influence the base drift.
     * @param float $bookValuePerShare  The physical equity value per share.
     * @param float $totalDebt          The total debt on the balance sheet.
     * @param float $totalEquity        The total equity on the balance sheet.
     * @param float $creditSpread       The company's baseline credit spread (borrowing premium).
     * @param float $dividendPerShare   The absolute quarterly dividend per share.
     *
     * @return array{price: float, shock: float|null, next_volatility: float, analyst_targets: array<string, float>, perceived_fair_value: float, dynamic_reversion: float} The calculated next price, shock percentage, updated TOTAL volatility, analyst targets, fair value and the reversion speed that was applied.
     */
    public function calculateNextPrice(\App\DTO\MarketPricingContext $ctx): array {
        $currentPrice = $ctx->currentPrice;
        $currentVolatility = $ctx->currentVolatility;
        $longTermVolatility = $ctx->longTermVolatility;
        $earningsPerShare = $ctx->earningsPerShare;
        $dt = $ctx->dt;
        $lambda = $ctx->lambda;
        $jump_vol = $ctx->jumpVol;
        $beta = $ctx->beta;
        $marketZ = $ctx->marketZ;
        $sectorZ = $ctx->sectorZ;
        $marketJumpMultiplier = $ctx->marketJumpMultiplier;
        $marketVol = $ctx->marketVol;
        $reversionSpeed = $ctx->reversionSpeed;
        $kappa = $ctx->kappa;
        $volOfVol = $ctx->volOfVol;
        $macroState = $ctx->macroState;
        $bookValuePerShare = $ctx->bookValuePerShare;
        $investedCapitalPerShare = $ctx->investedCapitalPerShare;
        $maShock = $ctx->maShock;
        $currentRoic = $ctx->currentRoic;
        $roicTtm = $ctx->roicTtm;
        $dividendPerShare = $ctx->dividendPerShare;
        $liveWacc = $ctx->liveWacc;
        $baselineIndustryPE = $ctx->baselineIndustryPE;
        $revenuePerShare = $ctx->revenuePerShare;
        $businessModel = $ctx->businessModel;
        $liveCostOfEquity = $ctx->liveCostOfEquity;
        $netDebtPerShare = $ctx->netDebtPerShare;
        $recentPriceTrend = $ctx->recentPriceTrend;
        $secularGrowth = $ctx->secularGrowth;
        $baselineRoic = $ctx->baselineRoic;
        $baselineMargin = $ctx->baselineMargin;
        $accrualsRatio = $ctx->accrualsRatio;
        $orderFlowVariance = $ctx->orderFlowVariance;

        // CAPM & MACRO TRANSMISSION MECHANISM

        $riskFreeRate = $macroState->policyRate ?? 0.04;
        $outputGap = $macroState->outputGap ?? 0.0;
        $inflation = $macroState->inflation ?? 0.02;
        $erp = $macroState->equityRiskPremium ?? 0.045;

        $capUp = FinancialConstants::MAX_JUMP_LOG_RETURN;
        $capDown = abs(FinancialConstants::MIN_JUMP_LOG_RETURN);

        // State Variables
        $currentVar = $currentVolatility * $currentVolatility;
        $longTermVar = $longTermVolatility * $longTermVolatility;

        // SYSTEMATIC / IDIOSYNCRATIC SPLIT
        // See longTermIdiosyncraticVariance() for why the loading is delivered outright rather than implied.
        $systematicVar = ($beta * $marketVol) * ($beta * $marketVol);
        $longTermIdiosyncraticVar = self::longTermIdiosyncraticVariance($longTermVolatility, $beta);

        // The floor the same split guarantees, which the variance state is held to when it is stripped back
        // to its idiosyncratic part below.
        $minIdiosyncraticVar = $longTermVar * self::MIN_IDIOSYNCRATIC_VARIANCE_SHARE;

        // IDIOSYNCRATIC JUMP CALIBRATION
        $jumpParameters = self::calibratedJumpParameters($longTermVolatility, $beta, $lambda, $jump_vol);
        $dynamicEtaUp = $jumpParameters['eta_up'];
        $dynamicEtaDown = $jumpParameters['eta_down'];

        $dynamicMuV = max(0.0, $currentVar) * self::VARIANCE_JUMP_MEAN_SHARE;

        // The SVJJ Jump Process (Kou Distribution)
        $jumpData = $this->mathUtility->calculateSVJJJumps(
            lambda: $lambda,
            pUp: self::SVJJ_P_UP,  // Maintain the 30/70 behavioral skew
            etaUp: $dynamicEtaUp,  // Calibrated upside jump
            etaDown: $dynamicEtaDown, // Calibrated downside crash
            muV: $dynamicMuV,      // Calibrated volatility explosion
            dt: $dt
        );

        // Merton jump compensation: adjust CAPM drift by the jump arrival compensator to preserve expected return.
        $jumpCompensator = $lambda * $this->mathUtility->kouTruncatedCompensator(
            self::SVJJ_P_UP,
            $dynamicEtaUp,
            $dynamicEtaDown,
            $capUp,
            $capDown
        );

        // The market-wide jump reaches the stock as beta * J, so its compensator and its variance are those
        // of the SCALED jump, not beta times the unscaled one: scaling an exponential divides its rate, and
        // a negative beta swaps the two tails outright — an inverse name gains on the market's crashes.
        $systemicExposure = abs($beta);
        $systemicCompensator = 0.0;
        $systemicJumpVariance = 0.0;

        if ($systemicExposure > 0.0) {
            $scaledEtaUp = MacroEngine::SYSTEMIC_JUMP_ETA_UP / $systemicExposure;
            $scaledEtaDown = MacroEngine::SYSTEMIC_JUMP_ETA_DOWN / $systemicExposure;

            $exposedPUp = $beta > 0.0
                ? MacroEngine::SYSTEMIC_JUMP_PROBABILITY_UP
                : 1.0 - MacroEngine::SYSTEMIC_JUMP_PROBABILITY_UP;
            $exposedEtaUp = $beta > 0.0 ? $scaledEtaUp : $scaledEtaDown;
            $exposedEtaDown = $beta > 0.0 ? $scaledEtaDown : $scaledEtaUp;

            $systemicCompensator = MacroEngine::SYSTEMIC_JUMP_INTENSITY * $this->mathUtility->kouTruncatedCompensator(
                $exposedPUp,
                $exposedEtaUp,
                $exposedEtaDown,
                $capUp,
                $capDown
            );

            $systemicJumpVariance = MacroEngine::SYSTEMIC_JUMP_INTENSITY * $this->mathUtility->kouTruncatedSecondMoment(
                $exposedPUp,
                $exposedEtaUp,
                $exposedEtaDown,
                $capUp,
                $capDown
            );
        }

        $finalDrift = $this->mathUtility->calculateCAPM($riskFreeRate, $beta, $erp)
            - $jumpCompensator
            - $systemicCompensator;

        $cycleVolModifier = 1.0;
        if ($macroState !== null) {
            // Positive output gap (boom) reduces vol slightly, negative gap (bust) increases vol
            $cycleVolModifier = 1.0 - $macroState->outputGap;
        }

        // Dynamically scale variance reversion speed (kappa) during jump diffusion regimes
        // instead of linearly clamping theta, preventing artificial volatility suppression
        $dynamicKappa = self::varianceReversionSpeed($lambda, $kappa);

        // Market-wide jump variance budget: deduct systemic jump variance from total diffusion variance.
        $systemicJumpVariance = min(
            $systemicJumpVariance,
            $longTermIdiosyncraticVar * self::MAX_SYSTEMIC_VARIANCE_DRAG_SHARE
        );

        // Idiosyncratic jump variance budget: deduct stock jump variance at truncated second moment.
        $idiosyncraticJumpVariance = $lambda > 0.0
            ? min(
                $lambda * $this->mathUtility->kouTruncatedSecondMoment(
                    self::SVJJ_P_UP,
                    $dynamicEtaUp,
                    $dynamicEtaDown,
                    $capUp,
                    $capDown
                ),
                $longTermIdiosyncraticVar * self::MAX_IDIOSYNCRATIC_JUMP_VARIANCE_SHARE
            )
            : 0.0;

        // Order-flow variance budget: deduct measured impact variance from diffusion variance.
        $impactVariance = min(
            max(0.0, $orderFlowVariance),
            $longTermIdiosyncraticVar * FinancialConstants::MAX_IMPACT_VARIANCE_DRAG_SHARE
        );

        // What the idiosyncratic diffusion is left with once every other source of variance has been paid
        // for. Every jump the name is exposed to is charged here, which is the only bucket that can flex:
        // the systematic loading is pinned at beta * marketVol and is not the engine's to spend.
        $adjustedTheta = max(
            0.0001,
            ($longTermIdiosyncraticVar * $cycleVolModifier) - $systemicJumpVariance - $idiosyncraticJumpVariance - $impactVariance
        );

        // The state arrives as the name's TOTAL variance, which is what every other part of the system
        // reads, so every source the engine adds back below is stripped out before the process steps
        // forward. Stripping exactly what is added keeps the round trip lossless at any tick rate.
        $currentIdiosyncraticVar = max(
            $minIdiosyncraticVar,
            $currentVar - $systematicVar - $systemicJumpVariance - $idiosyncraticJumpVariance
        );

        // Variance Process via Quadratic-Exponential (QE) Scheme, run on the idiosyncratic variance.
        $nextIdiosyncraticVar = $this->mathUtility->calculateQEVarianceStep(
            currentVar: $currentIdiosyncraticVar,
            theta: $adjustedTheta,
            kappa: $dynamicKappa,
            sigma: $volOfVol,
            dt: $dt
        );

        // Add the contemporaneous volatility jump from the SVJJ model
        $nextIdiosyncraticVar += $jumpData['var_jump'];

        // Back to a TOTAL variance for everything downstream: the liquidity engine's spread and turnover,
        // the agents' risk charge, and the UI all read this as the name's volatility, and all of them want
        // what the name actually realizes. That includes the variance its jumps deliver — reporting the
        // diffusion alone would understate a jumpy name by exactly the amount the budget just deducted, and
        // the spread and turnover models would be calibrated against a number the tape never prints.
        $nextVar = $nextIdiosyncraticVar + $systematicVar + $systemicJumpVariance + $idiosyncraticJumpVariance;

        // Convert back to volatility for the return payload.
        $nextVolatility = sqrt(max(0.0, $nextVar));

        // Enforce bounds to prevent flatlining in extreme bull markets and runaway chaos in crashes
        $nextVolatility = max(self::MIN_VOLATILITY, min(self::MAX_VOLATILITY, $nextVolatility));

        $fundamentalState = $this->evaluateFundamentalState(
            $currentPrice,
            $outputGap,
            $inflation,
            $beta,
            $liveWacc,
            $reversionSpeed,
            $earningsPerShare,
            $currentRoic,
            $roicTtm,
            $riskFreeRate,
            $bookValuePerShare,
            $dividendPerShare,
            $baselineIndustryPE,
            $revenuePerShare,
            $businessModel,
            $liveCostOfEquity,
            $currentVolatility,
            $netDebtPerShare,
            $secularGrowth,
            $baselineRoic,
            $baselineMargin,
            $accrualsRatio,
            $investedCapitalPerShare,
            $macroState,
            $ctx->tangibleBookValuePerShare,
            $ctx->targetPayoutRatio,
            $ctx->dividendAdjustmentSpeed,
            $ctx->policyBasesPerShare
        );

        $perceivedFairValue = $fundamentalState['perceived_fair_value'];
        $dynamicReversion = $fundamentalState['dynamic_reversion'];

        // Ornstein-Uhlenbeck mean reversion in log-space, damped by price momentum magnitude.
        $momentumResistance = 1.0 + (abs($recentPriceTrend) * self::MOMENTUM_REVERSION_RESISTANCE);
        $reversionWeight = max(0.0, min(1.0, exp(-($dynamicReversion / $momentumResistance) * $dt)));

        // Pure Geometric Brownian Motion (GBM) Step
        $idiosyncraticShock = $this->mathUtility->generateStandardNormal();

        // Calculate pure continuous price diffusion WITHOUT the linear gravity drift
        // The sector factor is the second common driver: without it two banks co-move only through their
        // betas, so a sector rotation is invisible in prices between reporting dates. The loading is a
        // share of the NON-market residual, so total step variance is unchanged either way.
        $gbmPrice = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: $currentPrice,
            idiosyncraticVolatility: sqrt(max(0.0, $currentIdiosyncraticVar)),
            drift: $finalDrift,
            gravityDrift: 0.0, // Set to zero, handled below
            dt: $dt,
            beta: $beta,
            marketVol: $marketVol,
            marketZ: $marketZ,
            w1: $idiosyncraticShock,
            sectorZ: $sectorZ,
            sectorVarianceShare: MacroEngine::SECTOR_FACTOR_VARIANCE_SHARE
        );

        // Geometrically blend the GBM price with the fundamental Fair Value
        $diffusedPrice = exp(
            $reversionWeight * log(max(0.01, $gbmPrice)) +
                (1.0 - $reversionWeight) * log(max(0.01, $perceivedFairValue))
        );

        // Calculate Analyst Targets for UI and Sentiment Display
        $analystTargets = $fundamentalState['analyst_targets'];

        // Circuit breaker: clamp single-step move symmetrically in log space scaled by sqrt(dt).
        $stepDays = max(0.0, $dt) * FinancialConstants::TRADING_DAYS_PER_YEAR;
        $maxLogMove = log(1.0 + FinancialConstants::MAX_DAILY_PRICE_CIRCUIT_BREAKER) * sqrt($stepDays);

        $minPriceFloor = max(0.01, $currentPrice * exp(-$maxLogMove));
        $maxPriceCeiling = $currentPrice * exp($maxLogMove);
        $boundedPrice = max($minPriceFloor, min($maxPriceCeiling, $diffusedPrice));

        // Market-Wide Jump Exposure:
        // The district-wide Kou jump arrives as a common log return, so a stock's share of it is its beta
        // (the CAPM exposure), applied in log space. An inverse-beta hedge therefore gains on a crash
        // rather than merely falling less. The result is clamped to the same per-jump bounds every other
        // jump in the system obeys, so a high-beta name gaps hardest but never without limit.
        $systemicJumpMultiplier = 1.0;
        if ($marketJumpMultiplier > 0.0 && $marketJumpMultiplier !== 1.0) {
            $systemicJumpLogReturn = max(
                FinancialConstants::MIN_JUMP_LOG_RETURN,
                min(FinancialConstants::MAX_JUMP_LOG_RETURN, log($marketJumpMultiplier) * $beta)
            );
            $systemicJumpMultiplier = exp($systemicJumpLogReturn);
        }

        // Apply Simultaneous Price Jumps AND M&A Shocks outside the GBM exponent.
        // The systemic jump is deliberately absent from the returned 'shock' field: it hits every stock at
        // once, so publishing it per ticker would bury the feed. The district reports it as one macro event.
        $totalShockMultiplier = $jumpData['price_multiplier'] * $systemicJumpMultiplier * (1.0 + $maShock) * $fundamentalState['policy_repricing'];
        $finalPrice = $boundedPrice * $totalShockMultiplier;

        return [
            'price'             => max(0.01, $finalPrice),
            'shock'             => $jumpData['shock_pct'],
            'next_volatility'   => $nextVolatility,
            'analyst_targets'   => $analystTargets,
            'perceived_fair_value' => $perceivedFairValue,
            'dynamic_reversion' => $dynamicReversion,
        ];
    }

    /**
     * Evaluates the fundamental fair value and dynamic reversion speed of a company
     * by combining structural ROIC, cost of capital, Kalman-smoothed EPS run-rates,
     * and ESTAR non-linear arbitrage dynamics.
     * 
     * @param float $currentPrice        The current market price of the stock.
     * @param float $outputGap           The macroeconomic output gap (boom vs bust).
     * @param float $inflation           The current inflation rate.
     * @param float $beta                The stock's sensitivity to systemic market moves.
     * @param float $liveWacc            The true dynamic Weighted Average Cost of Capital.
     * @param float $reversionSpeed      The baseline speed at which the stock reverts to fair value.
     * @param float $earningsPerShare    The current EPS (Earnings Per Share).
     * @param float $currentRoic         The current Return on Invested Capital (or ROE for banks).
     * @param float $roicTtm             The Trailing Twelve Month ROIC (or ROE).
     * @param float $riskFreeRate        The central bank's policy rate.
     * @param float $bookValuePerShare   The equity value per share.
     * @param float $dividendPerShare    The absolute quarterly dividend per share.
     * @param float $baselineIndustryPE  The standard P/E multiple for this industry.
     * @param float $revenuePerShare     The total revenue per share.
     * @param string $businessModel      The type of business (e.g., 'tech', 'bank', 'retail').
     * @param float $liveCostOfEquity    The Cost of Equity (CAPM) for financial institutions.
     * @param float $currentVolatility   The current asset volatility.
     * @param float $netDebtPerShare     The net debt per share.
     * @param float $secularGrowth       The long-term growth rate of the sector/economy.
     * @param float $baselineRoic        The historical average ROIC.
     * @param float $baselineMargin      The historical average net margin.
     * @param float $accrualsRatio       The accruals-to-assets ratio.
     * @param float|null $tangibleBookValuePerShare Book equity less goodwill per share; a financial's P/B leg is struck on it.
     * @param float $targetPayoutRatio   The payout ratio the firm's dividend policy steers to.
     * @param float $dividendAdjustmentSpeed Share of the gap to its target dividend the firm closes each quarter.
     * @param array<string, float> $policyBasesPerShare What each law the firm's accounts answer to is charged on or moves, per share, keyed by lever.
     * @return array{perceived_fair_value: float, dynamic_reversion: float, policy_repricing: float, analyst_targets: array}
     */
    private function evaluateFundamentalState(
        float $currentPrice,
        float $outputGap,
        float $inflation,
        float $beta,
        float $liveWacc,
        float $reversionSpeed,
        float $earningsPerShare,
        float $currentRoic,
        float $roicTtm,
        float $riskFreeRate,
        float $bookValuePerShare,
        float $dividendPerShare,
        float $baselineIndustryPE = 20.0,
        float $revenuePerShare = 0.0,
        string $businessModel = 'none',
        float $liveCostOfEquity = 0.10,
        float $currentVolatility = 0.20,
        float $netDebtPerShare = 0.0,
        float $secularGrowth = 0.02,
        float $baselineRoic = 0.10,
        float $baselineMargin = 0.20,
        float $accrualsRatio = 0.0,
        float $investedCapitalPerShare = 0.0,
        ?MacroStateDTO $macroState = null,
        ?float $tangibleBookValuePerShare = null,
        float $targetPayoutRatio = 0.0,
        float $dividendAdjustmentSpeed = 1.0,
        array $policyBasesPerShare = []
    ): array {

        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        $hurdleRate = $strategy->isFinancial() ? $liveCostOfEquity : $liveWacc;

        // Structural ROIC is determined by the business model strategy, allowing sectors like Insurance
        // to smooth out extreme catastrophic volatility and price based on through-the-cycle baseline capacity.
        $structuralRoic = $strategy->calculateStructuralRoic(
            $roicTtm,
            $baselineRoic,
            $revenuePerShare,
            $bookValuePerShare,
            $baselineMargin
        );

        // MACROECONOMIC STRESS INDEX (MSI)
        $recessionStress = max(0.0, -$outputGap);
        $inflationStress = abs($inflation - 0.02);
        $systemicStressIndex = $recessionStress + $inflationStress;

        // 1. MACRO FORWARD GUIDANCE & FUNDAMENTAL P/E
        // The growth transmission and the Sloan accruals discount live in MathUtility because the corporate
        // engines strike the same multiple when management decides on a buyback, an offering or a deal.
        $expectedGrowth = $this->mathUtility->calculateFundableGrowth(
            $this->mathUtility->calculateExpectedNominalGrowth(
                $secularGrowth,
                $outputGap,
                $beta,
                $inflation,
                $strategy->getMoatSpread()
            ),
            $structuralRoic,
            $targetPayoutRatio
        );

        $fairValuePE = $this->mathUtility->calculateQualityAdjustedFairValuePE(
            $hurdleRate,
            $structuralRoic,
            $expectedGrowth,
            $baselineIndustryPE,
            $accrualsRatio
        );

        $trueStructuralEps = $strategy->calculateStructuralEps(
            $bookValuePerShare,
            $structuralRoic,
            $revenuePerShare,
            $riskFreeRate,
            // Real capital employed when the caller supplied a balance sheet; the model's structural
            // approximation from revenue and book value otherwise.
            $investedCapitalPerShare > 0.0 ? $investedCapitalPerShare : null
        );

        // 2. STRUCTURAL EPS SMOOTHING (Past Performance via Kalman Filter)
        // Real analysts value a company based on its established structural run-rate.
        // We use a 1D Kalman Filter to optimally estimate the true EPS by weighing the structural prior 
        // against the noisy quarterly measurement.
        $safeStructuralEps = max(0.01, $trueStructuralEps);

        $normalizedEps = $this->mathUtility->calculateKalmanSmoothedEps(
            $safeStructuralEps,
            $earningsPerShare,
            $currentVolatility,
            $systemicStressIndex
        );

        $peFairValue = max(0.00, $normalizedEps * $fairValuePE);

        // THE ZOMBIE FIX: Revenue Floor (Margin-Adjusted Price-to-Sales)
        // High-turnover physical corporations (retail, grocers) have thin net margins and cannot support software-like P/S multiples.
        $trueMargin = $trueStructuralEps > 0 
            ? $trueStructuralEps / max(0.01, $revenuePerShare) 
            : self::MIN_IMPLIED_NET_MARGIN;
        $impliedMargin = max(self::MIN_IMPLIED_NET_MARGIN, min(self::MAX_IMPLIED_NET_MARGIN, $trueMargin));
        
        $psMultiple = max(
            FinancialConstants::MIN_PS_FALLBACK_MULT,
            min(FinancialConstants::MAX_PS_FALLBACK_MULT, max(self::MIN_PS_ANCHOR_PE, $fairValuePE) * $impliedMargin)
        );
        $revenueFloorValue = $revenuePerShare * $psMultiple;
        $revenueFloorEquityValue = max(0.01, $revenueFloorValue - $netDebtPerShare);

        // The earnings leg: the value-driver P/E value, never below what the model's revenue floor is worth.
        $earningsValue = $strategy->calculateEarningsValue($revenueFloorEquityValue, $peFairValue);

        // Dividend Discount Model (Gordon 1962) on the dividends the firm's own policy will pay: its target payout of
        // normalized earnings, growing at the rate its retained earnings compound at (Higgins 1977), plus the present
        // value of today's gap to that target while partial adjustment closes it (Lintner 1956). Struck on the policy
        // rather than the last cheque, a cut costs what is missed while the dividend recovers, and there is no step at
        // zero. Only the models that price on income read it; an operating company's value does not turn on its payout.
        $dividendSupportValue = 0.0;
        if ($targetPayoutRatio > 0.0 && $normalizedEps > 0.0) {
            $requiredYield = max(self::MIN_DIVIDEND_REQUIRED_YIELD, $liveCostOfEquity);
            $payoutRatio = min(1.0, $targetPayoutRatio);
            $returnOnEquity = $bookValuePerShare > 0.0 ? $normalizedEps / $bookValuePerShare : 0.0;
            $sustainableGrowth = max(0.0, min(self::MAX_DIVIDEND_IMPLIED_GROWTH, $returnOnEquity * (1.0 - $payoutRatio)));
            $targetDividend = $normalizedEps * $payoutRatio;

            $dividendSupportValue = max(
                0.0,
                $this->mathUtility->calculateDividendDiscountModel($targetDividend, $requiredYield, $sustainableGrowth)
                    + $this->mathUtility->calculateDividendAdjustmentValue(($dividendPerShare * 4.0) - $targetDividend, $requiredYield, $dividendAdjustmentSpeed)
            );
        }

        // Intrinsic Price-to-Book (P/B) Valuation
        // The multiple belongs to the business model: plant earning above its hurdle is worth more than the
        // plant, while a portfolio of marketable stakes is worth the portfolio, so a trust declares 1.0 and
        // this term hands its model net asset value per share rather than a multiple of it.
        $pbFairValue = $bookValuePerShare * $strategy->getIntrinsicPbMultiple($structuralRoic, $hurdleRate);

        // A financial is valued on tangible book, since its regulator deducts goodwill from capital. The same
        // earnings over the smaller base give the same value wherever the multiple is interior; the change is
        // that the floor and ceiling then bound price to TANGIBLE book, so goodwill cannot prop up the floor.
        if ($strategy->isFinancial() && $tangibleBookValuePerShare !== null && $bookValuePerShare > 0.0) {
            $tangibleBook = max(0.0, $tangibleBookValuePerShare);
            $returnOnTangible = $tangibleBook > 0.0 ? $structuralRoic * $bookValuePerShare / $tangibleBook : 0.0;
            $pbFairValue = $tangibleBook * $strategy->getIntrinsicPbMultiple($returnOnTangible, $hurdleRate);
        }

        // PERFECTED WEIGHTED CONSENSUS MODEL
        $fairValue = $strategy->calculateFairValue($earningsValue, $pbFairValue, $normalizedEps, $dividendSupportValue);

        // Closed-end structures trade below the assets they hold, by a gap that moves with the cycle. Applied
        // here rather than inside the consensus so it reaches the reversion target: the price is pulled toward
        // the discounted value, which is what makes a widening discount a fall rather than a mispricing.
        $fairValue *= (1.0 - $strategy->getStructuralValuationDiscount($macroState ?? new MacroStateDTO()));

        // The laws the market expects rather than the ones the trailing earnings carry; a forecast revised this tick
        // moves the price with the target, as news does, rather than leaving it to drift there.
        $policyRepricing = 1.0;
        if ($macroState !== null) {
            $capRate = max(PolicyCapitalization::MIN_CAP_RATE, $hurdleRate - $expectedGrowth);
            $repriced = $this->repriceForPolicy($fairValue, $macroState, $strategy, $policyBasesPerShare, $capRate, false);
            $policyRepricing = $repriced / $this->repriceForPolicy($fairValue, $macroState, $strategy, $policyBasesPerShare, $capRate, true);
            $fairValue = $repriced;
        }

        $perceivedFairValue = max(0.01, $fairValue);

        // 1. ESTAR (Exponential Smooth Transition Autoregressive) Mean Reversion
        // Explains non-linear institutional arbitrage around a fundamental target (Taylor, Peel, & Sarno, 2001).
        // Within narrow valuation bands, transaction costs and noise-trader risk keep institutional arbitrage near zero.
        // As mispricing spreads widen, institutions enter aggressively, scaling reversion speed smoothly toward an upper asymptotic limit.
        $logValuationGap = abs(log(max(0.01, $currentPrice) / max(0.01, $perceivedFairValue)));
        $arbitrageElasticity = FinancialConstants::ESTAR_ARBITRAGE_ELASTICITY;
        $maxReversionCap = FinancialConstants::MAX_REVERSION_FORCE_CAP;

        $estarTransition = 1.0 - exp(-$arbitrageElasticity * ($logValuationGap * $logValuationGap));
        $effectiveReversion = $reversionSpeed + (($maxReversionCap - $reversionSpeed) * $estarTransition);

        // 2. Brunnermeier-Pedersen Funding Liquidity Dampener (2009)
        // During macroeconomic stress and systemic crises, funding liquidity dries up and capital-constrained 
        // arbitrageurs pull back, slowing down market efficiency and price correction speed.
        $liquidityDampener = 1.0 / (1.0 + ($systemicStressIndex * FinancialConstants::FUNDING_LIQUIDITY_STRESS_FACTOR));
        $dynamicReversion = $effectiveReversion * $liquidityDampener;

        return [
            'perceived_fair_value' => $perceivedFairValue,
            'dynamic_reversion'    => $dynamicReversion,
            'policy_repricing'     => $policyRepricing,
            'analyst_targets'      => [
                'growth_analyst' => max(0.01, $earningsValue),
                'income_analyst' => max(0.01, $dividendSupportValue),
                'value_analyst'  => max(0.01, $pbFairValue * self::VALUE_ANALYST_BOOK_MULT),
            ]
        ];
    }

    /**
     * A fair value repriced for the laws the market expects (PolicyCapitalization), on the forecast as it stands this
     * tick or as it stood the tick before.
     *
     * @param array<string, float> $policyBasesPerShare What each law the firm's accounts answer to is charged on or moves, per share, keyed by lever.
     */
    private function repriceForPolicy(float $fairValue, MacroStateDTO $macroState, BusinessModelInterface $strategy, array $policyBasesPerShare, float $capRate, bool $previous): float
    {
        $taxExpected = $strategy->getEffectiveTaxRate($macroState->corporateTaxRate + PolicyCapitalization::corporateTaxShiftGap($macroState, $capRate, $previous));

        return PolicyCapitalization::reprice(
            $fairValue,
            $strategy->getEffectiveTaxRate($macroState->corporateTaxRate),
            $taxExpected,
            PolicyCapitalization::earningsGap($macroState, $policyBasesPerShare, $taxExpected, $capRate, $previous),
            $capRate
        );
    }
}
