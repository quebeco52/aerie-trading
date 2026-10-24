<?php

declare(strict_types=1);

namespace App\Service\Market\Pricing;

use App\DTO\MarketPricingContext;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\OptionPricing;
use App\Service\Math\StochasticProcesses;

/**
 * A name's price variance budget, every source in one place: the systematic loading (beta times market vol, and the
 * market-wide jump through the same beta), its own Kou jumps held to their share, the order-flow and announcement
 * variance the diffusion gives back, the Merton compensators that keep the jumps out of the expected return, and the
 * idiosyncratic stochastic-variance state they are stripped from and the reported total volatility is rebuilt from.
 *
 * An instance is the budget of one price step (forStep()); the static formulas are the ones the option desk and the
 * debt desk read the same jump and variance process through. No random draw.
 */
final readonly class VarianceBudget
{
    // --- Volatility Bounds ---
    /** Absolute floor on a name's total volatility (1%). */
    private const MIN_VOLATILITY = 0.01;
    /** Absolute ceiling on a name's total volatility (150%). */
    private const MAX_VOLATILITY = 1.50;

    // --- Jump Diffusion ---
    /** Probability that a single-name jump is upwards; the behavioural skew the price process carries. */
    private const SVJJ_P_UP = 0.40;

    // --- Variance Process Calibration ---
    /**
     * Speed the idiosyncratic variance reverts to its long-run level, in reversions per year; a shock is most of the
     * way gone inside two months.
     */
    public const BASE_VARIANCE_REVERSION_SPEED = 6.0;

    // --- Variance Budget ---
    /**
     * Ceiling on the share of a name's long-run IDIOSYNCRATIC variance its OWN jump process may supply; the jump
     * component of single-stock return variance is a minority of the total (Huang & Tauchen 2005).
     */
    private const MAX_IDIOSYNCRATIC_JUMP_VARIANCE_SHARE = 0.25;

    // --- Common Idiosyncratic Volatility (Herskovic, Kelly, Lustig & Van Nieuwerburgh 2016) ---
    /**
     * Loading of a firm's residual variance on the common idiosyncratic factor: 0.71-1.54 by size quintile, about one
     * (HKLV, calibration table). Market variance stands in for the factor, which tracks it (correlation 0.64, annual
     * levels 1926-2010).
     */
    public const IDIOSYNCRATIC_COMMON_FACTOR_LOADING = 1.0;
    /**
     * Years the market variance trend averages over: one postwar business cycle, 58.4 months of expansion plus 11.1 of
     * contraction (NBER, 1945-2009).
     */
    public const MARKET_VARIANCE_TREND_YEARS = (58.4 + 11.1) / 12.0;
    /**
     * Floor on the share of a name's configured variance that stays idiosyncratic, for a configuration whose beta alone
     * already consumes the whole of its stated volatility.
     */
    private const MIN_IDIOSYNCRATIC_VARIANCE_SHARE = 0.20;

    // --- Volatility Process ---
    /** Mean of the contemporaneous variance jump, as a share of the name's current variance. */
    private const VARIANCE_JUMP_MEAN_SHARE = 0.50;
    /**
     * Sensitivity of the variance reversion speed to jump intensity; a jumpier name pulls back to its long-run level
     * faster rather than having its target clamped.
     */
    private const JUMP_REGIME_KAPPA_SENSITIVITY = 0.15;
    /** Ratio of the down-jump scale to the up-jump scale, the Kou left-tail skew a single name carries. */
    private const JUMP_DOWNSIDE_SCALE_RATIO = 1.25;
    /** Floor on the jump scale parameter, so a name configured with no jump size cannot divide by it. */
    private const MIN_JUMP_SCALE = 0.01;

    // --- Market Microstructure: Order Flow Variance Budget ---
    /** Ceiling on the share of long-run variance order flow may reclaim from the diffusion. */
    private const MAX_IMPACT_VARIANCE_DRAG_SHARE = 0.25;

    /**
     * @param float $beta                   The name's market loading.
     * @param float $systematicVar          The market factor's loading at this tick's market vol, (beta * marketVol)^2.
     * @param float $diffusionMarketVol     Market vol the market factor diffuses at once the fund's impact is paid for.
     * @param float $etaUp                  Up-jump rate of the name's own Kou jump, held to its budget.
     * @param float $etaDown                Down-jump rate of the same jump.
     * @param float $jumpCompensator        Annual Merton compensator of the name's own jump.
     * @param float $idiosyncraticJumpMean  Annual log mean of the name's own jump.
     * @param float $systemicCompensator    Annual Merton compensator of the market-wide jump through the beta.
     * @param float $systemicJumpMean       Annual log mean of the market-wide jump through the beta.
     * @param float $systemicJumpVariance   Annual variance the market-wide jump supplies through the beta.
     * @param float $ownSourceVariance      The name's own jump, order-flow and announcement variance, summed.
     * @param float $kappa                  Speed the idiosyncratic variance reverts at in its jump regime.
     * @param float $theta                  Long-run idiosyncratic variance the process is struck to settle on.
     * @param float $idiosyncraticVar       This step's idiosyncratic variance state, every other source stripped.
     */
    private function __construct(
        public float $beta,
        public float $systematicVar,
        public float $diffusionMarketVol,
        public float $etaUp,
        public float $etaDown,
        public float $jumpCompensator,
        public float $idiosyncraticJumpMean,
        public float $systemicCompensator,
        public float $systemicJumpMean,
        public float $systemicJumpVariance,
        public float $ownSourceVariance,
        public float $kappa,
        public float $theta,
        public float $idiosyncraticVar,
    ) {}

    /**
     * The budget of one price step: the variance each source supplies, and the idiosyncratic state the stored total
     * variance leaves once every one of them is stripped from it.
     */
    public static function forStep(MarketPricingContext $ctx): self
    {
        $currentVolatility = $ctx->currentVolatility;
        $longTermVolatility = $ctx->longTermVolatility;
        $dt = $ctx->dt;
        $lambda = $ctx->lambda;
        $jumpVol = $ctx->jumpVol;
        $beta = $ctx->beta;
        $marketVol = $ctx->marketVol;
        $kappa = $ctx->kappa;
        $orderFlowVariance = $ctx->orderFlowVariance;

        $capUp = FinancialConstants::MAX_JUMP_LOG_RETURN;
        $capDown = abs(FinancialConstants::MIN_JUMP_LOG_RETURN);

        // State Variables
        $currentVar = $currentVolatility * $currentVolatility;

        // SYSTEMATIC / IDIOSYNCRATIC SPLIT
        // See longTermIdiosyncraticVariance() for why the loading is delivered outright rather than implied.
        $systematicVar = ($beta * $marketVol) * ($beta * $marketVol);

        // The sovereign fund trades every name in proportion to its float, so the impact it measurably supplies is
        // systematic variance: the market factor's diffusion gives it back, floored at zero, as the idiosyncratic
        // diffusion gives back the name's own flow.
        // The shock is loaded at the market vol known before it arrives: MacroEngine updates marketVolatility with this
        // tick's own innovation (the leverage effect), so the updated vol is correlated with marketZ and E[vol * z] < 0
        // would drift every name down by beta times that. An Ito integrand is non-anticipating (Kloeden & Platen 1992).
        $shockMarketVol = $ctx->priorMarketVol ?? $marketVol;
        $shockSystematicVar = ($beta * $shockMarketVol) * ($beta * $shockMarketVol);
        $fundImpactVariance = min(max(0.0, $ctx->fundImpactVariance), $shockSystematicVar);
        $diffusionMarketVol = $shockSystematicVar > 0.0 ? $shockMarketVol * sqrt(1.0 - ($fundImpactVariance / $shockSystematicVar)) : $shockMarketVol;
        $longTermIdiosyncraticVar = self::longTermIdiosyncraticVariance($longTermVolatility, $beta);

        // IDIOSYNCRATIC JUMP CALIBRATION
        $jumpParameters = self::calibratedJumpParameters($longTermVolatility, $beta, $lambda, $jumpVol);
        $dynamicEtaUp = $jumpParameters['eta_up'];
        $dynamicEtaDown = $jumpParameters['eta_down'];

        // Merton jump compensation: adjust CAPM drift by the jump arrival compensator to preserve expected return.
        $jumpCompensator = $lambda * StochasticProcesses::kouTruncatedCompensator(
            self::SVJJ_P_UP,
            $dynamicEtaUp,
            $dynamicEtaDown,
            $capUp,
            $capDown,
        );

        // The market-wide jump as this name takes it, through its beta (systemicJumpMoments()).
        $systemicJump = self::systemicJumpMoments($beta);
        $systemicCompensator = $systemicJump['compensator'];
        $systemicJumpMean = $systemicJump['mean'];
        $systemicJumpVariance = $systemicJump['variance'];

        $commonIdiosyncraticScale = self::commonIdiosyncraticVarianceScale($marketVol, $ctx->marketVarianceTrend);

        // Dynamically scale variance reversion speed (kappa) during jump diffusion regimes
        // instead of linearly clamping theta, preventing artificial volatility suppression
        $dynamicKappa = self::varianceReversionSpeed($lambda, $kappa);

        // Idiosyncratic jump variance budget: deduct stock jump variance at truncated second moment.
        $idiosyncraticJumpVariance = $lambda > 0.0
            ? min(
                $lambda * StochasticProcesses::kouTruncatedSecondMoment(
                    self::SVJJ_P_UP,
                    $dynamicEtaUp,
                    $dynamicEtaDown,
                    $capUp,
                    $capDown,
                ),
                $longTermIdiosyncraticVar * self::MAX_IDIOSYNCRATIC_JUMP_VARIANCE_SHARE,
            )
            : 0.0;

        // Order-flow variance budget: deduct measured impact variance from diffusion variance.
        $impactVariance = min(
            max(0.0, $orderFlowVariance),
            $longTermIdiosyncraticVar * self::MAX_IMPACT_VARIANCE_DRAG_SHARE,
        );

        // Announcement variance budget: a report or a warning is a scheduled jump (Dubinsky, Johannes, Kaeck &
        // Seeger 2019), so the diffusion gives back what the name's own announcements measurably supplied,
        // within the same minority bound as its unscheduled jumps.
        $announcementVariance = min(
            max(0.0, $ctx->announcementVariance),
            $longTermIdiosyncraticVar * self::MAX_IDIOSYNCRATIC_JUMP_VARIANCE_SHARE,
        );

        // What the idiosyncratic diffusion is left with once the name's own other sources of variance have been paid
        // for, which is the only bucket that can flex: the market's diffusion and its jump reach the name through beta
        // and were paid for before the idiosyncratic budget was struck (longTermIdiosyncraticVariance()).
        // The variance jumps below add to the stationary mean, so the target is struck low enough that the
        // process still settles on it: see varianceJumpLoad().
        // The name's own sources besides its diffusion, deducted here, stripped from the state and added back to the
        // reported total below: one sum, so the three can never list different sources.
        $ownSourceVariance = $idiosyncraticJumpVariance + $impactVariance + $announcementVariance;
        $adjustedTheta = max(
            0.0001,
            (($longTermIdiosyncraticVar * $commonIdiosyncraticScale) - $ownSourceVariance)
                * (1.0 - self::varianceJumpLoad($lambda, $dynamicKappa, $dt)),
        );

        // The state arrives as the name's TOTAL variance, which is what every other part of the system
        // reads, so every source the engine adds back below is stripped out before the process steps
        // forward. The systematic part is stripped at the market vol it was added at, last tick's, so a
        // move in market vol does not leak into the idiosyncratic state. The state is held at zero, not at any
        // floor above it: clamped up to a floor its target sits below, the process would settle above its target.
        $priorMarketVol = $ctx->priorMarketVol ?? $marketVol;
        $priorSystematicVar = ($beta * $priorMarketVol) * ($beta * $priorMarketVol);
        $currentIdiosyncraticVar = max(
            0.0,
            $currentVar - $priorSystematicVar - $systemicJumpVariance - $ownSourceVariance,
        );

        // The log mean of the name's own jump, which the price step's median-unbiased pull gives back.
        $idiosyncraticJumpMean = $lambda > 0.0
            ? $lambda * StochasticProcesses::kouTruncatedMean(self::SVJJ_P_UP, $dynamicEtaUp, $dynamicEtaDown, $capUp, $capDown)
            : 0.0;

        return new self(
            beta: $beta,
            systematicVar: $systematicVar,
            diffusionMarketVol: $diffusionMarketVol,
            etaUp: $dynamicEtaUp,
            etaDown: $dynamicEtaDown,
            jumpCompensator: $jumpCompensator,
            idiosyncraticJumpMean: $idiosyncraticJumpMean,
            systemicCompensator: $systemicCompensator,
            systemicJumpMean: $systemicJumpMean,
            systemicJumpVariance: $systemicJumpVariance,
            ownSourceVariance: $ownSourceVariance,
            kappa: $dynamicKappa,
            theta: $adjustedTheta,
            idiosyncraticVar: $currentIdiosyncraticVar,
        );
    }

    /** The expected total return less both jumps' Merton compensators, so the jumps leave the mean return unchanged. */
    public function jumpCompensatedDrift(float $expectedReturn): float
    {
        return $expectedReturn
            - $this->jumpCompensator
            - $this->systemicCompensator;
    }

    /**
     * The log losses the step's own processes create, given back in the drift: Ito on the diffusion and the convexity
     * of the Merton-compensated jumps, E[e^J - 1 - J].
     */
    public function logLossCompensation(): float
    {
        return 0.5 * ((($this->beta * $this->diffusionMarketVol) ** 2) + max(0.0, $this->idiosyncraticVar))
            + ($this->jumpCompensator - $this->idiosyncraticJumpMean)
            + ($this->systemicCompensator - $this->systemicJumpMean);
    }

    /**
     * Mean of the contemporaneous variance jump: it scales with the name's own idiosyncratic variance, since the
     * systematic loading is pinned at beta * marketVol and a market-wide spike must not inflate a single name's jumps.
     */
    public function varianceJumpMean(): float
    {
        return max(0.0, $this->idiosyncraticVar) * self::VARIANCE_JUMP_MEAN_SHARE;
    }

    /** Volatility of the idiosyncratic diffusion at this step's state. */
    public function idiosyncraticVolatility(): float
    {
        return sqrt(max(0.0, $this->idiosyncraticVar));
    }

    /**
     * The name's TOTAL volatility once the idiosyncratic variance has stepped, bounded.
     *
     * Back to a TOTAL variance for everything downstream: the liquidity engine's spread and turnover, the agents' risk
     * charge, and the UI all read this as the name's volatility, and all of them want what the name actually
     * realizes. That includes the variance its jumps, its order flow and its announcements deliver — reporting the
     * diffusion alone would understate the name by exactly what the budget just deducted, and the spread and turnover
     * models would be calibrated against a number the tape never prints.
     */
    public function totalVolatility(float $nextIdiosyncraticVar): float
    {
        $nextVar = $nextIdiosyncraticVar + $this->systematicVar + $this->systemicJumpVariance + $this->ownSourceVariance;

        // Convert back to volatility for the return payload.
        $nextVolatility = sqrt(max(0.0, $nextVar));

        // Enforce bounds to prevent flatlining in extreme bull markets and runaway chaos in crashes
        return max(self::MIN_VOLATILITY, min(self::MAX_VOLATILITY, $nextVolatility));
    }

    /**
     * The long-run variance a name carries on its own, once its market loading has been paid for.
     *
     * A name's market loading is beta * marketVol by definition, so the diffusion supplies it outright and
     * the stochastic volatility process carries only what is left. The market-wide jump reaches the name through
     * the same beta, so it is systematic variance too and is paid for here, not out of the name's own budget.
     *
     * The target is struck against the market's BASELINE volatility, so the configured figure is the name's
     * total volatility in normal conditions and total volatility then rises with the market's through beta.
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
        $baselineSystematicVar = (($beta * MacroConstants::MACRO_VOL_BASE_ANCHOR) ** 2) + self::systemicJumpMoments($beta)['variance'];

        return max($longTermVar * self::MIN_IDIOSYNCRATIC_VARIANCE_SHARE, $longTermVar - $baselineSystematicVar);
    }

    /**
     * The annual compensator, mean and second moment of the market-wide Kou jump as a name of this beta takes it.
     *
     * The jump reaches the stock as beta * J, so these are the moments of the SCALED jump, not beta times the unscaled
     * ones: scaling an exponential divides its rate, and a negative beta swaps the two tails outright, so an inverse
     * name gains on the market's crashes.
     *
     * @return array{compensator: float, mean: float, variance: float}
     */
    public static function systemicJumpMoments(float $beta): array
    {
        $jump = self::systemicJumpParameters($beta);
        if ($jump['lambda'] <= 0.0) {
            return ['compensator' => 0.0, 'mean' => 0.0, 'variance' => 0.0];
        }

        $capUp = FinancialConstants::MAX_JUMP_LOG_RETURN;
        $capDown = abs(FinancialConstants::MIN_JUMP_LOG_RETURN);
        [$pUp, $etaUp, $etaDown] = [$jump['p_up'], $jump['eta_up'], $jump['eta_down']];

        return [
            'compensator' => $jump['lambda'] * StochasticProcesses::kouTruncatedCompensator($pUp, $etaUp, $etaDown, $capUp, $capDown),
            'mean' => $jump['lambda'] * StochasticProcesses::kouTruncatedMean($pUp, $etaUp, $etaDown, $capUp, $capDown),
            'variance' => $jump['lambda'] * StochasticProcesses::kouTruncatedSecondMoment($pUp, $etaUp, $etaDown, $capUp, $capDown),
        ];
    }

    /**
     * The market-wide Kou jump as a name of this beta takes it: the market's arrival rate, and the rates of the jump
     * scaled by the beta (a negative beta swaps the tails). The option desk quotes a name's smile on these.
     *
     * @return array{lambda: float, p_up: float, eta_up: float, eta_down: float}
     */
    public static function systemicJumpParameters(float $beta): array
    {
        $exposure = abs($beta);
        if ($exposure <= 0.0) {
            return ['lambda' => 0.0, 'p_up' => MacroConstants::SYSTEMIC_JUMP_PROBABILITY_UP, 'eta_up' => 0.0, 'eta_down' => 0.0];
        }

        $scaledEtaUp = MacroConstants::SYSTEMIC_JUMP_ETA_UP / $exposure;
        $scaledEtaDown = MacroConstants::SYSTEMIC_JUMP_ETA_DOWN / $exposure;

        return [
            'lambda' => MacroConstants::SYSTEMIC_JUMP_INTENSITY,
            'p_up' => $beta > 0.0 ? MacroConstants::SYSTEMIC_JUMP_PROBABILITY_UP : 1.0 - MacroConstants::SYSTEMIC_JUMP_PROBABILITY_UP,
            'eta_up' => $beta > 0.0 ? $scaledEtaUp : $scaledEtaDown,
            'eta_down' => $beta > 0.0 ? $scaledEtaDown : $scaledEtaUp,
        ];
    }

    /**
     * The Kou jump rates a name actually gaps at, after its jump is held to its variance budget.
     *
     * The jump scale is held to the share of the name's variance a jump process is entitled to, so the jump is an
     * overlay on the diffusion and not the whole of it. Scaling the size rather than the arrival rate keeps the
     * seed's statement about how OFTEN a name gaps, which is a property of its business.
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
        float $jumpVol,
    ): array {
        $jumpScale = max(self::MIN_JUMP_SCALE, $jumpVol);

        if ($lambda > 0.0) {
            // Untruncated second moment of the Kou jump, 2 * scale^2 * (pUp + pDown * ratio^2), which is
            // monotone in the scale and therefore invertible for the budget. Truncation only removes mass,
            // so solving on it and deducting the truncated figure at the budget can never over-reclaim.
            $rawSecondMomentPerUnit = OptionPricing::calculateKouJumpMoment(
                2,
                self::SVJJ_P_UP,
                1.0,
                1.0 / self::JUMP_DOWNSIDE_SCALE_RATIO,
            );
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

    /**
     * The scale on a name's long-run residual variance from the common idiosyncratic factor: market variance over its
     * measured trend, at the firm loading, so residual volatility rises in a turbulent market and falls in a calm one
     * and is unchanged on average. Without a trend it reads the level the market settles at. Floored at zero.
     *
     * @param float|null $marketVarianceTrend Measured trend of market variance (StockTracker).
     */
    public static function commonIdiosyncraticVarianceScale(float $marketVol, ?float $marketVarianceTrend = null): float
    {
        $trend = $marketVarianceTrend ?? (MacroConstants::MACRO_VOL_BASE_ANCHOR * MacroConstants::MACRO_VOL_BASE_ANCHOR);
        $marketVarianceRatio = $trend > 0.0 ? ($marketVol * $marketVol) / $trend : 1.0;

        return max(0.0, 1.0 + (self::IDIOSYNCRATIC_COMMON_FACTOR_LOADING * ($marketVarianceRatio - 1.0)));
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
     * Share of the idiosyncratic variance target given back to the variance jumps (Duffie, Pan & Singleton 2000).
     *
     * The jump mean is proportional to the state (muV = c v), so one step of the QE scheme plus a jump has
     * stationary mean theta_a / (1 - L), L = lambda dt c m / (1 - e^(-kappa dt)), with m the mean jump per
     * unit muV. Striking theta_a = theta (1 - L) lands the process on theta; L tends to lambda c m / kappa as
     * dt goes to zero.
     */
    public static function varianceJumpLoad(float $lambda, float $kappa, float $dt): float
    {
        if ($lambda <= 0.0 || $kappa <= 0.0 || $dt <= 0.0) {
            return 0.0;
        }

        $meanJumpShare = StochasticProcesses::meanVarianceJump(self::SVJJ_P_UP, self::VARIANCE_JUMP_MEAN_SHARE);

        return ($lambda * $dt * $meanJumpShare) / (1.0 - exp(-$kappa * $dt));
    }
}
