<?php

namespace App\Service;
use App\Data\EconomicCycle;

/**
 * Service responsible for calculating stock price movements based on various market factors.
 *
 * This engine uses a hybrid model incorporating Geometric Brownian Motion (GBM),
 * Jump Diffusion processes, and Mean Reversion to simulate realistic stock price behavior.
 */
class MarketEngine
{
    public function __construct(
        private ?MathUtility $mathUtility = null
    ) {
        if ($this->mathUtility === null) {
            $this->mathUtility = new MathUtility();
        }
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
     * @param float $targetPE           The target P/E ratio for the stock's sector.
     * @param float $dt                 The time step for the simulation (in years).
     * @param float $drift              The expected return (drift) of the stock.
     * @param float $lambda             The jump intensity (average number of jumps per year).
     * @param float $jumpMean           The mean size of a jump (log-return).
     * @param float $jumpVol            The volatility of the jump size.
     * @param float $beta               The stock's beta (sensitivity to market movements).
     * @param float $marketZ            The systemic market shock Z-score (standard normal).
     * @param float $marketVol          The volatility of the broader market.
     * @param float $reversionSpeed     The speed at which the price reverts to fair value.
     * @param float $kappa              The rate at which volatility reverts to the long-run mean.
     * @param float $volOfVol           The volatility of volatility (how much volatility fluctuates).
     * @param float $rho                The correlation between price and volatility.
     * 
     * @param EconomicCycle|null $economicCycle The current macroeconomic state, which can influence the base drift.
     *
     * @return array{price: float, shock: float|null, next_volatility: float} The calculated next price, shock percentage, and updated volatility.
     */
    public function calculateNextPrice(
        float $currentPrice,
        float $currentVolatility,
        float $longTermVolatility,
        float $earningsPerShare,
        float $targetPE,
        float $dt,
        float $lambda = 2.0,
        float $jumpMean = 0.01,
        float $jumpVol = 0.1,
        float $beta = 1.0,
        float $marketZ = 0.0,
        float $marketVol = 0.15,
        float $drift = 0.08,
        float $reversionSpeed = 0.4,
        float $kappa = 6.0,
        float $volOfVol = 0.2,
        float $rho = -0.7,
        ?EconomicCycle $economicCycle = null,
    ): array {

        // Set the core market baselines
        $riskFreeRate = match ($economicCycle) {
            EconomicCycle::RECESSION => 0.000,
            EconomicCycle::RECOVERY  => 0.010,
            EconomicCycle::EXPANSION => 0.025,
            EconomicCycle::PEAK      => 0.050,
            null                     => 0.020,
        };
        $baseMarketPremium = $drift;

        // Get the current cycle's modifier
        $macroModifier = 0.0;
        if ($economicCycle) {
            $macroModifier = $economicCycle->getDriftModifier();
        }

        // Combine the base premium with the current economic mood
        $totalMarketPremium = $baseMarketPremium + $macroModifier;

        // Calculate the final drift using CAPM
        // The Beta ONLY scales the market risk portion, not the risk-free rate.
        $drift = $riskFreeRate + ($totalMarketPremium * $beta);

        $sqrtDt = sqrt($dt);

        // Generate the random variable for the STOCK PRICE
        $z1 = $this->mathUtility->generateStandardNormal();
        $w1 = $z1; 

        // Let the MathUtility handle the correlation and the Heston math!
        $nextVolatility = $this->mathUtility->calculateHestonVolatility(
            currentVolatility: $currentVolatility,
            longTermVolatility: $longTermVolatility,
            kappa: $kappa,
            volOfVol: $volOfVol,
            rho: $rho,
            dt: $dt,
            z1: $z1
        );

        // Calculate the financial Fair Value
        $valuationEps = max($earningsPerShare, 0.01);
        $fairValue = $valuationEps * $targetPE;

        // Calculate Gravity (Mean Reversion) using the generalized math utility
        $gravityDrift = $this->mathUtility->calculateLogMeanReversion(
            currentValue: $currentPrice,
            targetValue: $fairValue,
            reversionSpeed: $reversionSpeed
        );

        // Calculate Correlation (Rho) to the broad market
        $impliedRho = $beta * ($marketVol / max($currentVolatility, 0.01));

        // Cap correlation so the math doesn't break (max 99% correlated)
        $marketCorrelation = max(-0.99, min(0.99, $impliedRho));

        // Split the volatility
        $systematicDrift = $currentVolatility * $marketCorrelation * $marketZ * $sqrtDt;
        $idiosyncraticDrift = $currentVolatility * sqrt(1 - ($marketCorrelation * $marketCorrelation)) * $w1 * $sqrtDt;

        $currentVariance = $currentVolatility * $currentVolatility;

        // Apply GBM with the properly decoupled components
        $gbmExponent = ($drift + $gravityDrift - 0.5 * $currentVariance) * $dt
            + $systematicDrift
            + $idiosyncraticDrift;

        $gbmPrice = $currentPrice * exp($gbmExponent);

        // Calculate Jump Diffusion (Market Shocks)
        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: $lambda,
            jumpMean: $jumpMean,
            jumpVol: $jumpVol,
            dt: $dt
        );

        // Apply the price multiplier (will just be * 1.0 if no jump occurred)
        $finalPrice = $gbmPrice * $jumpData['multiplier'];
        $shockPct = $jumpData['shock_pct'];

        // If a jump DID occur, spike vol
        if ($jumpData['exponent'] !== null) {
            // Add a multiple of the jump's absolute size to the volatility
            $nextVolatility += abs($jumpData['exponent']) * 1.5;
            $nextVolatility = min($nextVolatility, $longTermVolatility * 3.0);
        }

        return [
            'price' => max(0.01, $finalPrice),
            'shock' => $shockPct,
            'next_volatility' => $nextVolatility
        ];
    }
}
