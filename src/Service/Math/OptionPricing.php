<?php

declare(strict_types=1);

namespace App\Service\Math;

/**
 * Option pricing: Black-Scholes-Merton prices and greeks, the implied-volatility root, the Gram-Charlier smile
 * (Backus, Foresi & Wu 2004), the smile implied by the simulated jump process, and the short-option margin
 * requirement. Pure functions of their arguments.
 */
final class OptionPricing
{
    // --- Option Pricing (Black-Scholes-Merton) ---
    /** Time to expiry, in years, below which a contract is settled at intrinsic value instead of priced; sigma * sqrt(T) is not invertible at zero. */
    public const MIN_OPTION_TIME_TO_EXPIRY = 1.0e-6;
    /** Floor on the volatility a contract may be quoted at, matching the price process's own volatility floor. */
    public const MIN_OPTION_VOLATILITY = 0.01;
    /** Ceiling on the volatility a contract may be quoted at; past 500% the lognormal call is indistinguishable from the discounted forward. */
    public const MAX_OPTION_VOLATILITY = 5.00;

    // --- Implied Volatility Solver ---
    /** Newton-Raphson iteration cap for the implied-volatility root; a vega-bracketed quote converges in well under ten. */
    public const IMPLIED_VOL_MAX_ITERATIONS = 64;
    /** Price convergence tolerance for the implied-volatility root, in currency units per share. */
    public const IMPLIED_VOL_PRICE_TOLERANCE = 1.0e-8;

    // --- Gram-Charlier Smile (Backus, Foresi & Wu 2004) ---
    /** Widest fractional departure from the at-the-money volatility the skewness and kurtosis terms may produce; the expansion is local and turns negative in the far wings. */
    public const MAX_GRAM_CHARLIER_VOL_DEVIATION = 0.60;

    // --- Short Option Margin (FINRA Rule 4210 / CBOE minimums) ---
    /** Share of the underlying a naked short option is collateralized at, before the out-of-the-money amount is credited back against it. */
    public const SHORT_OPTION_UNDERLYING_REQUIREMENT = 0.20;
    /** Floor on that requirement, struck on the underlying for a call and on the STRIKE for a put, so a far out-of-the-money short is never collateralized at nothing. */
    public const SHORT_OPTION_MINIMUM_REQUIREMENT = 0.10;

    /**
     * The two Black-Scholes moneyness deviates, d1 and d2.
     *
     * Shared by the price, every greek and the smile, all of which are functions of the same pair. Returns
     * null when the contract has no time value left to measure: at that point the quote is intrinsic and
     * sigma * sqrt(T) is zero, which d1 divides by.
     *
     * @param float $spot          Underlying price.
     * @param float $strike        Contract strike.
     * @param float $volatility    Annualised volatility the contract is struck at.
     * @param float $riskFreeRate  Continuously compounded risk-free rate.
     * @param float $dividendYield Continuous dividend yield on the underlying (Merton 1973).
     * @param float $timeToExpiry  Years to expiry.
     * @return array{d1: float, d2: float, sigma_root_t: float}|null
     */
    public static function calculateBlackScholesDeviates(
        float $spot,
        float $strike,
        float $volatility,
        float $riskFreeRate,
        float $dividendYield,
        float $timeToExpiry
    ): ?array {
        if ($spot <= 0.0 || $strike <= 0.0 || $timeToExpiry < self::MIN_OPTION_TIME_TO_EXPIRY) {
            return null;
        }

        $sigma = max(self::MIN_OPTION_VOLATILITY, min(self::MAX_OPTION_VOLATILITY, $volatility));
        $sigmaRootT = $sigma * sqrt($timeToExpiry);

        $d1 = (log($spot / $strike) + (($riskFreeRate - $dividendYield + (0.5 * $sigma * $sigma)) * $timeToExpiry))
            / $sigmaRootT;

        return ['d1' => $d1, 'd2' => $d1 - $sigmaRootT, 'sigma_root_t' => $sigmaRootT];
    }

    /**
     * Black-Scholes-Merton price of a European option on a dividend-paying underlying.
     *
     * Merton's (1973) continuous-yield extension of Black & Scholes (1973): the spot leg is discounted at
     * the dividend yield because the holder of the option does not receive the dividends the holder of the
     * stock does, which is the whole of why an American call on a dividend payer is worth early exercise
     * and a European one is not.
     *
     *     C = S e^(-qT) N(d1) - K e^(-rT) N(d2)
     *     P = K e^(-rT) N(-d2) - S e^(-qT) N(-d1)
     *
     * Past the time floor the contract is settled rather than priced, which is what the exercise path
     * needs and also what keeps a zero-vega quote out of the implied-volatility solver.
     *
     * @param float $spot          Underlying price.
     * @param float $strike        Contract strike.
     * @param float $volatility    Annualised volatility the contract is struck at.
     * @param float $riskFreeRate  Continuously compounded risk-free rate.
     * @param float $dividendYield Continuous dividend yield on the underlying.
     * @param float $timeToExpiry  Years to expiry.
     * @param bool  $isCall        True for a call, false for a put.
     * @return float Premium per share.
     */
    public static function calculateBlackScholesPrice(
        float $spot,
        float $strike,
        float $volatility,
        float $riskFreeRate,
        float $dividendYield,
        float $timeToExpiry,
        bool $isCall
    ): float {
        $deviates = self::calculateBlackScholesDeviates(
            $spot,
            $strike,
            $volatility,
            $riskFreeRate,
            $dividendYield,
            $timeToExpiry
        );

        if ($deviates === null) {
            return $isCall ? max(0.0, $spot - $strike) : max(0.0, $strike - $spot);
        }

        $spotDiscount = $spot * exp(-$dividendYield * $timeToExpiry);
        $strikeDiscount = $strike * exp(-$riskFreeRate * $timeToExpiry);

        if ($isCall) {
            return max(0.0, ($spotDiscount * Distributions::calculateNormalCDF($deviates['d1']))
                - ($strikeDiscount * Distributions::calculateNormalCDF($deviates['d2'])));
        }

        return max(0.0, ($strikeDiscount * Distributions::calculateNormalCDF(-$deviates['d2']))
            - ($spotDiscount * Distributions::calculateNormalCDF(-$deviates['d1'])));
    }

    /**
     * Analytic Black-Scholes-Merton sensitivities.
     *
     * Delta and gamma are what a hedging desk trades on, vega is what an option desk quotes a spread on,
     * and theta is what a holder pays for both. All are reported in natural units per SHARE and per YEAR:
     * vega is the premium change for a 1.00 (not one point) move in volatility and theta the change over a
     * full year, so a caller that wants the conventional per-point or per-day figures divides here rather
     * than having the convention baked in where it cannot be seen.
     *
     * @param float $spot          Underlying price.
     * @param float $strike        Contract strike.
     * @param float $volatility    Annualised volatility the contract is struck at.
     * @param float $riskFreeRate  Continuously compounded risk-free rate.
     * @param float $dividendYield Continuous dividend yield on the underlying.
     * @param float $timeToExpiry  Years to expiry.
     * @param bool  $isCall        True for a call, false for a put.
     * @return array{delta: float, gamma: float, vega: float, theta: float, rho: float}
     */
    public static function calculateBlackScholesGreeks(
        float $spot,
        float $strike,
        float $volatility,
        float $riskFreeRate,
        float $dividendYield,
        float $timeToExpiry,
        bool $isCall
    ): array {
        $deviates = self::calculateBlackScholesDeviates(
            $spot,
            $strike,
            $volatility,
            $riskFreeRate,
            $dividendYield,
            $timeToExpiry
        );

        if ($deviates === null) {
            // Settled. The position is either stock or nothing, so delta is a step and every second-order
            // sensitivity has already collapsed.
            $inTheMoney = $isCall ? ($spot > $strike) : ($spot < $strike);
            $delta = $inTheMoney ? ($isCall ? 1.0 : -1.0) : 0.0;

            return ['delta' => $delta, 'gamma' => 0.0, 'vega' => 0.0, 'theta' => 0.0, 'rho' => 0.0];
        }

        $d1 = $deviates['d1'];
        $d2 = $deviates['d2'];
        $rootT = sqrt($timeToExpiry);
        $sigma = $deviates['sigma_root_t'] / $rootT;

        $carryDiscount = exp(-$dividendYield * $timeToExpiry);
        $rateDiscount = exp(-$riskFreeRate * $timeToExpiry);
        $density = Distributions::calculateNormalPDF($d1);

        $gamma = ($carryDiscount * $density) / ($spot * $deviates['sigma_root_t']);
        $vega = $spot * $carryDiscount * $density * $rootT;

        // The decay term both sides share, before the financing legs that differ by side.
        $decay = -($spot * $carryDiscount * $density * $sigma) / (2.0 * $rootT);

        if ($isCall) {
            $delta = $carryDiscount * Distributions::calculateNormalCDF($d1);
            $theta = $decay
                - ($riskFreeRate * $strike * $rateDiscount * Distributions::calculateNormalCDF($d2))
                + ($dividendYield * $spot * $carryDiscount * Distributions::calculateNormalCDF($d1));
            $rho = $strike * $timeToExpiry * $rateDiscount * Distributions::calculateNormalCDF($d2);
        } else {
            $delta = $carryDiscount * (Distributions::calculateNormalCDF($d1) - 1.0);
            $theta = $decay
                + ($riskFreeRate * $strike * $rateDiscount * Distributions::calculateNormalCDF(-$d2))
                - ($dividendYield * $spot * $carryDiscount * Distributions::calculateNormalCDF(-$d1));
            $rho = -$strike * $timeToExpiry * $rateDiscount * Distributions::calculateNormalCDF(-$d2);
        }

        return ['delta' => $delta, 'gamma' => $gamma, 'vega' => $vega, 'theta' => $theta, 'rho' => $rho];
    }

    /**
     * The volatility that reproduces an observed option premium.
     *
     * Newton-Raphson on vega, which is the exact derivative of the price with respect to volatility and is
     * strictly positive wherever the contract has time value, so the root is unique. Falls back to
     * bisection on the bracket whenever a step leaves it, which a near-intrinsic quote will do from any
     * starting guess because vega there is numerically zero. Same structure as
     * calculateYieldToMaturity(), for the same reason.
     *
     * @param float $optionPrice   Observed premium per share.
     * @param float $spot          Underlying price.
     * @param float $strike        Contract strike.
     * @param float $riskFreeRate  Continuously compounded risk-free rate.
     * @param float $dividendYield Continuous dividend yield on the underlying.
     * @param float $timeToExpiry  Years to expiry.
     * @param bool  $isCall        True for a call, false for a put.
     * @param float $guess         Starting volatility.
     * @return float The implied volatility, bounded by the quotable range.
     */
    public static function calculateImpliedVolatility(
        float $optionPrice,
        float $spot,
        float $strike,
        float $riskFreeRate,
        float $dividendYield,
        float $timeToExpiry,
        bool $isCall,
        float $guess = 0.25
    ): float {
        if ($timeToExpiry < self::MIN_OPTION_TIME_TO_EXPIRY || $spot <= 0.0 || $strike <= 0.0) {
            return self::MIN_OPTION_VOLATILITY;
        }

        $lowerBound = self::MIN_OPTION_VOLATILITY;
        $upperBound = self::MAX_OPTION_VOLATILITY;

        // A quote outside the no-arbitrage band has no root inside the bracket; return the bound it is
        // pressed against rather than iterating toward an answer that does not exist.
        if ($optionPrice <= self::calculateBlackScholesPrice($spot, $strike, $lowerBound, $riskFreeRate, $dividendYield, $timeToExpiry, $isCall)) {
            return $lowerBound;
        }
        if ($optionPrice >= self::calculateBlackScholesPrice($spot, $strike, $upperBound, $riskFreeRate, $dividendYield, $timeToExpiry, $isCall)) {
            return $upperBound;
        }

        $sigma = max($lowerBound, min($upperBound, $guess));

        for ($iteration = 0; $iteration < self::IMPLIED_VOL_MAX_ITERATIONS; $iteration++) {
            $modelPrice = self::calculateBlackScholesPrice($spot, $strike, $sigma, $riskFreeRate, $dividendYield, $timeToExpiry, $isCall);
            $error = $modelPrice - $optionPrice;

            if (abs($error) < self::IMPLIED_VOL_PRICE_TOLERANCE) {
                return $sigma;
            }

            if ($error > 0.0) {
                $upperBound = $sigma;
            } else {
                $lowerBound = $sigma;
            }

            $vega = self::calculateBlackScholesGreeks($spot, $strike, $sigma, $riskFreeRate, $dividendYield, $timeToExpiry, $isCall)['vega'];

            $next = $vega > 0.0 ? $sigma - ($error / $vega) : ($lowerBound + $upperBound) / 2.0;

            if ($next <= $lowerBound || $next >= $upperBound || !is_finite($next)) {
                $next = ($lowerBound + $upperBound) / 2.0;
            }

            $sigma = $next;
        }

        return $sigma;
    }

    /**
     * Raw moment E[J^n] of Kou's (2002) double-exponential jump size.
     *
     * The jump is +X with probability p where X ~ Exp(etaUp), and -Y with probability 1 - p where
     * Y ~ Exp(etaDown), so E[X^n] = n! / eta^n and the down branch carries the sign of (-1)^n:
     *
     *     E[J^n] = p * n! / etaUp^n + (1 - p) * (-1)^n * n! / etaDown^n
     *
     * This is the bridge between the process the market engine actually simulates and the smile the option
     * desk quotes: the odd moments are the skew and the even ones the fat tails, and both are properties
     * of the jump parameters already carried on every stock rather than a surface fitted by hand.
     *
     * @param int   $order   Moment order, 1 or higher.
     * @param float $pUp     Probability the jump is upwards.
     * @param float $etaUp   Exponential rate of the up jump.
     * @param float $etaDown Exponential rate of the down jump.
     * @return float The raw moment.
     */
    public static function calculateKouJumpMoment(int $order, float $pUp, float $etaUp, float $etaDown): float
    {
        if ($order < 1 || $etaUp <= 0.0 || $etaDown <= 0.0) {
            return 0.0;
        }

        $factorial = 1.0;
        for ($i = 2; $i <= $order; $i++) {
            $factorial *= $i;
        }

        $upMoment = $factorial / ($etaUp ** $order);
        $downMoment = $factorial / ($etaDown ** $order);
        $downSign = ($order % 2 === 0) ? 1.0 : -1.0;

        return ($pUp * $upMoment) + ((1.0 - $pUp) * $downSign * $downMoment);
    }

    /**
     * Skewness and excess kurtosis of the horizon log return of a Kou jump-diffusion.
     *
     * Cumulants are additive over independent components and, for a compound Poisson, the n-th cumulant is
     * lambda * T * E[J^n]. The diffusion contributes only to the second:
     *
     *     k2 = sigma^2 T + lambda T E[J^2],  k3 = lambda T E[J^3],  k4 = lambda T E[J^4]
     *
     * so skewness is k3 / k2^(3/2) and excess kurtosis k4 / k2^2. Both shrink as T grows at the rates the
     * central limit theorem requires (1/sqrt(T) and 1/T), which is why a long-dated smile flattens out of
     * the same parameters that make a weekly one steep.
     *
     * @param float $diffusionVariance Annualised variance of the diffusion leg, excluding jumps.
     * @param float $lambda            Jump intensity, arrivals per year.
     * @param float $pUp               Probability the jump is upwards.
     * @param float $etaUp             Exponential rate of the up jump.
     * @param float $etaDown           Exponential rate of the down jump.
     * @param float $horizonYears      Years over which the return is measured.
     * @return array{variance: float, skewness: float, excess_kurtosis: float} Horizon variance and shape.
     */
    public static function calculateJumpDiffusionShape(
        float $diffusionVariance,
        float $lambda,
        float $pUp,
        float $etaUp,
        float $etaDown,
        float $horizonYears
    ): array {
        if ($horizonYears <= 0.0) {
            return ['variance' => 0.0, 'skewness' => 0.0, 'excess_kurtosis' => 0.0];
        }

        $jumpArrivals = max(0.0, $lambda) * $horizonYears;

        $secondCumulant = (max(0.0, $diffusionVariance) * $horizonYears)
            + ($jumpArrivals * self::calculateKouJumpMoment(2, $pUp, $etaUp, $etaDown));

        if ($secondCumulant <= 0.0) {
            return ['variance' => 0.0, 'skewness' => 0.0, 'excess_kurtosis' => 0.0];
        }

        $thirdCumulant = $jumpArrivals * self::calculateKouJumpMoment(3, $pUp, $etaUp, $etaDown);
        $fourthCumulant = $jumpArrivals * self::calculateKouJumpMoment(4, $pUp, $etaUp, $etaDown);

        return [
            'variance' => $secondCumulant,
            'skewness' => $thirdCumulant / ($secondCumulant ** 1.5),
            'excess_kurtosis' => $fourthCumulant / ($secondCumulant * $secondCumulant),
        ];
    }

    /**
     * Implied volatility at a strike, from the at-the-money level and the distribution's shape.
     *
     * Backus, Foresi & Wu (2004): expanding the risk-neutral density in a Gram-Charlier series and solving
     * the resulting price for its Black-Scholes implied volatility gives, to the order of the fourth
     * moment,
     *
     *     sigma(d1) = sigma_atm * [1 - (skew / 6) d1 - (excess kurtosis / 24) (1 - d1^2)]
     *
     * The two terms are the two things a flat Black-Scholes surface gets wrong. Negative skewness tilts the
     * line: low strikes sit at positive d1 and are marked UP, which is the equity smirk and is the same
     * asymmetry the Kou down-jump puts into the simulated returns. Positive excess kurtosis bends it:
     * at-the-money volatility is marked down and both wings up, because fat tails are worth more to a wing
     * than to a strike the spot is already sitting on.
     *
     * The expansion is local and its quartic term turns the quote negative in the far wings, so the
     * multiplier is bounded. That bound is a statement about where the approximation stops being usable,
     * not a tuning parameter: past it the desk is quoting the boundary rather than the model.
     *
     * @param float $atmVolatility  At-the-money volatility the expansion is struck around.
     * @param float $d1             Black-Scholes d1 at the strike, evaluated AT the at-the-money volatility.
     * @param float $skewness       Skewness of the horizon log return.
     * @param float $excessKurtosis Excess kurtosis of the horizon log return.
     * @return float The strike's implied volatility.
     */
    public static function calculateGramCharlierImpliedVolatility(
        float $atmVolatility,
        float $d1,
        float $skewness,
        float $excessKurtosis
    ): float {
        $multiplier = 1.0
            - (($skewness / 6.0) * $d1)
            - (($excessKurtosis / 24.0) * (1.0 - ($d1 * $d1)));

        $multiplier = max(
            1.0 - self::MAX_GRAM_CHARLIER_VOL_DEVIATION,
            min(1.0 + self::MAX_GRAM_CHARLIER_VOL_DEVIATION, $multiplier)
        );

        return max(self::MIN_OPTION_VOLATILITY, min(self::MAX_OPTION_VOLATILITY, $atmVolatility * $multiplier));
    }

    /**
     * Margin a naked short option must be collateralized with, per SHARE.
     *
     * The FINRA Rule 4210 / CBOE minimum: the premium the writer would have to pay to buy the position
     * back, plus a charge on the underlying that is reduced by however far out of the money the contract
     * sits, and floored so that a far out-of-the-money short is never free:
     *
     *     requirement = premium + max(0.20 * underlying - out-of-the-money amount, 0.10 * base)
     *
     * where the base is the underlying for a call and the STRIKE for a put. The asymmetry is not an
     * oversight in the rule: a call's loss is unbounded above and scales with the underlying, while a put's
     * worst case is the strike going to zero, so each floor is struck against the quantity that actually
     * bounds the writer's loss.
     *
     * Marking the premium into the requirement is what makes a short option position self-correcting. As
     * the contract moves against the writer the premium rises and the out-of-the-money credit shrinks, so
     * the requirement climbs on both terms at once and the account is called before the loss is realized —
     * which is the whole reason writing options is a margin activity rather than a cash one.
     *
     * @param float $underlyingPrice Live price of the underlying.
     * @param float $strike          Contract strike.
     * @param float $premium         Current premium per share; what buying the position back costs.
     * @param bool  $isCall          True for a short call, false for a short put.
     * @return float Requirement per share of underlying.
     */
    public static function calculateShortOptionRequirement(
        float $underlyingPrice,
        float $strike,
        float $premium,
        bool $isCall
    ): float {
        $outOfTheMoney = $isCall
            ? max(0.0, $strike - $underlyingPrice)
            : max(0.0, $underlyingPrice - $strike);

        $floorBase = $isCall ? $underlyingPrice : $strike;

        $charge = max(
            (self::SHORT_OPTION_UNDERLYING_REQUIREMENT * $underlyingPrice) - $outOfTheMoney,
            self::SHORT_OPTION_MINIMUM_REQUIREMENT * $floorBase
        );

        return max(0.0, $premium) + max(0.0, $charge);
    }

    /**
     * Model-free implied variance of an expiry, from its strip of out-of-the-money quotes.
     *
     * The CBOE VIX construction (Demeterfi, Derman, Kamal & Zou 1999): the fair strike of a variance swap
     * is a portfolio of options weighted by the inverse square of their strikes,
     *
     *     sigma^2 = (2 e^(rT) / T) * sum_i (dK_i / K_i^2) Q(K_i) - (1/T) (F / K0 - 1)^2
     *
     * where Q(K_i) is the out-of-the-money quote at K_i and K0 the last strike at or below the forward.
     * It reads the whole surface rather than one strike, so it measures the market's priced variance
     * including the tails a single at-the-money volatility throws away. The correction term removes the
     * error from striking the log contract at K0 instead of exactly at F.
     *
     * @param array<int, array{strike: float, price: float}> $strip Quotes in strictly increasing strike order.
     * @param float                                          $forward Forward price of the underlying at expiry.
     * @param float                                          $atmStrike Highest strike at or below the forward.
     * @param float                                          $riskFreeRate Continuously compounded risk-free rate.
     * @param float                                          $timeToExpiry Years to expiry.
     * @return float Annualised implied variance; take the square root for a volatility index.
     */
    public static function calculateModelFreeImpliedVariance(
        array $strip,
        float $forward,
        float $atmStrike,
        float $riskFreeRate,
        float $timeToExpiry
    ): float {
        $count = count($strip);

        if ($count < 2 || $timeToExpiry < self::MIN_OPTION_TIME_TO_EXPIRY || $atmStrike <= 0.0) {
            return 0.0;
        }

        $strip = array_values($strip);
        $contribution = 0.0;

        foreach ($strip as $index => $quote) {
            $strike = (float) $quote['strike'];

            if ($strike <= 0.0) {
                continue;
            }

            // The strike interval each quote stands for: half the distance to each neighbour, and the
            // one-sided gap at the ends of the strip where there is no outer neighbour to split with.
            if ($index === 0) {
                $interval = (float) $strip[1]['strike'] - $strike;
            } elseif ($index === $count - 1) {
                $interval = $strike - (float) $strip[$count - 2]['strike'];
            } else {
                $interval = ((float) $strip[$index + 1]['strike'] - (float) $strip[$index - 1]['strike']) / 2.0;
            }

            $contribution += ($interval / ($strike * $strike)) * max(0.0, (float) $quote['price']);
        }

        $forwardError = ($forward / $atmStrike) - 1.0;

        $variance = ((2.0 * exp($riskFreeRate * $timeToExpiry) * $contribution) / $timeToExpiry)
            - (($forwardError * $forwardError) / $timeToExpiry);

        return max(0.0, $variance);
    }
}
