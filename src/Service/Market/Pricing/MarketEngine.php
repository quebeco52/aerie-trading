<?php

declare(strict_types=1);

namespace App\Service\Market\Pricing;

use App\DTO\MarketPricingContext;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;

/**
 * Stock price step: a Kou double-exponential jump process with stochastic variance (SVJJ), a systematic loading on
 * the market factor, and a pull toward a multi-leg fair value.
 *
 * The step draws, in order, the jumps, the variance step and the diffusion; what each source of variance may supply
 * is VarianceBudget's, and the fair value the price is pulled toward is FairValue's.
 */
class MarketEngine
{
    // --- Fair-Value Pull ---
    /**
     * Half-life of a mispricing, in years: log P = log F + a stationary gap (Summers 1986; Poterba & Summers 1988).
     * Balvers, Wu & Gilliland (2000) estimate ~3y for national indices; a shorter, deliberate in-world choice keeps
     * gaps readable while leaving a slow enough pull for momentum.
     */
    public const FAIR_VALUE_PULL_HALF_LIFE_YEARS = 1.0;
    /**
     * The pull's base speed per year, ln 2 over the half-life; funding stress slows it (Brunnermeier & Pedersen 2009).
     */
    public const FAIR_VALUE_PULL_SPEED = M_LN2 / self::FAIR_VALUE_PULL_HALF_LIFE_YEARS;

    // --- Sector Factor ---
    /**
     * Fraction of a stock's non-market variance loaded onto its sector factor, setting within- vs cross-sector
     * correlation.
     */
    public const SECTOR_FACTOR_VARIANCE_SHARE = 0.20;

    // --- Institutional & Market Architecture ---
    /**
     * Circuit breaker on the continuous diffusion, as the largest move of a single trading DAY; scaled by the square
     * root of the step actually taken.
     */
    public const MAX_DAILY_PRICE_CIRCUIT_BREAKER = 0.40;

    public function __construct(
        private MathUtility $mathUtility,
    ) {}

    /**
     * Calculates the next stock price: diffusion on the market and sector factors, Kou jumps held to the variance
     * budget, stochastic variance, and a pull toward the multi-leg fair value.
     *
     * @return array{price: float, shock: float|null, next_volatility: float, analyst_targets: array<string, float>, perceived_fair_value: float, dynamic_reversion: float, policy_repricing: float} The calculated next price, shock percentage, updated TOTAL volatility, analyst targets, fair value, the reversion speed that was applied and the policy news multiplier the price took.
     */
    public function calculateNextPrice(MarketPricingContext $ctx): array
    {
        $currentPrice = $ctx->currentPrice;
        $dt = $ctx->dt;
        $lambda = $ctx->lambda;
        $beta = $ctx->beta;
        $marketZ = $ctx->marketZ;
        $sectorZ = $ctx->sectorZ;
        $marketJumpMultiplier = $ctx->marketJumpMultiplier;
        $volOfVol = $ctx->volOfVol;
        $maShock = $ctx->maShock;
        $dividendPerShare = $ctx->dividendPerShare;

        // What each source of variance supplies this step, and the idiosyncratic state left to the diffusion.
        $budget = VarianceBudget::forStep($ctx);

        // Expected total return is the cost of equity the fair value is discounted at (CAPM on the 10Y,
        // DebtEngine::calculateHealth()), so a fairly priced name drifts at the rate it is valued at; the
        // dividend leaves the price on the ex-date.
        $finalDrift = $budget->jumpCompensatedDrift($ctx->liveCostOfEquity);

        // The SVJJ Jump Process (Kou Distribution). The variance jump scales with the name's own
        // idiosyncratic variance (VarianceBudget::varianceJumpMean()).
        $jumpData = $this->mathUtility->calculateSVJJJumps(
            lambda: $lambda,
            pUp: VarianceBudget::jumpProbabilityUp(),
            etaUp: $budget->etaUp,
            etaDown: $budget->etaDown,
            muV: $budget->varianceJumpMean(),
            dt: $dt,
        );

        // Variance Process via Quadratic-Exponential (QE) Scheme, run on the idiosyncratic variance.
        $nextIdiosyncraticVar = $this->mathUtility->calculateQEVarianceStep(
            currentVar: $budget->idiosyncraticVar,
            theta: $budget->theta,
            kappa: $budget->kappa,
            sigma: $volOfVol,
            dt: $dt,
        );

        // Add the contemporaneous volatility jump from the SVJJ model
        $nextIdiosyncraticVar += $jumpData['var_jump'];

        $nextVolatility = $budget->totalVolatility($nextIdiosyncraticVar);

        $fundamentalState = FairValue::fundamentalStateFor($ctx);

        $perceivedFairValue = $fundamentalState['perceived_fair_value'];
        $dynamicReversion = $fundamentalState['dynamic_reversion'];

        // Gordon (1962) at the market price: expected total return is fair-value growth plus the yield the
        // price actually offers (Fama & French 2002, r = D/P + g). The drift above is the return at fair value;
        // below it the same cash dividend is a fatter yield, above it a thinner one. Without this the fixed-dollar
        // ex-dividend drop compounded a discount instead of supporting it.
        $annualDividend = max(0.0, $dividendPerShare) * FinancialConstants::QUARTERS_PER_YEAR;
        $finalDrift += ($annualDividend / max(0.01, $currentPrice)) - ($annualDividend / max(0.01, $perceivedFairValue));

        // Ornstein-Uhlenbeck mean reversion in log-space at a constant speed: the exact discretization, so the pull
        // per year is the same at any tick rate.
        $reversionWeight = max(0.0, min(1.0, exp(-$dynamicReversion * $dt)));

        // Median-unbiased pull: the drift gives back the log losses the engine's own processes create, so a fairly
        // priced name's log price grows with log fair value and the log pull settles at zero (Summers 1986 log noise),
        // not -var/(2 kappa).
        $finalDrift += $budget->logLossCompensation();

        // Pure Geometric Brownian Motion (GBM) Step
        $idiosyncraticShock = $this->mathUtility->generateStandardNormal();

        // Calculate pure continuous price diffusion WITHOUT the linear gravity drift
        // The sector factor is the second common driver: without it two banks co-move only through their
        // betas, so a sector rotation is invisible in prices between reporting dates. The loading is a
        // share of the NON-market residual, so total step variance is unchanged either way.
        $gbmPrice = StochasticProcesses::calculateCorrelatedGBM(
            currentPrice: $currentPrice,
            idiosyncraticVolatility: $budget->idiosyncraticVolatility(),
            drift: $finalDrift,
            gravityDrift: 0.0, // Set to zero, handled below
            dt: $dt,
            beta: $beta,
            marketVol: $budget->diffusionMarketVol,
            marketZ: $marketZ,
            w1: $idiosyncraticShock,
            sectorZ: $sectorZ,
            sectorVarianceShare: self::SECTOR_FACTOR_VARIANCE_SHARE,
        );

        // Geometrically blend the GBM price with the fundamental Fair Value
        $diffusedPrice = exp(
            $reversionWeight * log(max(0.01, $gbmPrice))
                + (1.0 - $reversionWeight) * log(max(0.01, $perceivedFairValue)),
        );

        // Calculate Analyst Targets for UI and Sentiment Display
        $analystTargets = $fundamentalState['analyst_targets'];

        // Circuit breaker: clamp single-step move symmetrically in log space scaled by sqrt(dt).
        $stepDays = max(0.0, $dt) * FinancialConstants::TRADING_DAYS_PER_YEAR;
        $maxLogMove = log(1.0 + self::MAX_DAILY_PRICE_CIRCUIT_BREAKER) * sqrt($stepDays);

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
                min(FinancialConstants::MAX_JUMP_LOG_RETURN, log($marketJumpMultiplier) * $beta),
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
            'policy_repricing' => $fundamentalState['policy_repricing'],
        ];
    }

    /**
     * Fair value and analyst targets struck on a context, with no price step and no random draw.
     *
     * The ticker re-strikes on a report tick, so the agents and analysts who trade after the filing value the
     * books it filed rather than the ones the tick's price step saw.
     *
     * @return array{perceived_fair_value: float, analyst_targets: array<string, float>}
     */
    public function strikeFairValue(MarketPricingContext $ctx): array
    {
        $state = FairValue::fundamentalStateFor($ctx);

        return ['perceived_fair_value' => $state['perceived_fair_value'], 'analyst_targets' => $state['analyst_targets']];
    }
}
