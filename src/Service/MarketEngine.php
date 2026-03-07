<?php

namespace App\Service;


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
     * 2. Jump Diffusion for sudden market shocks.
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
     * @param float $marketNoise        The systemic market noise component.
     * @param float $reversionSpeed     The speed at which the price reverts to fair value.
     * @param float $kappa              The rate at which volatility reverts to the long-run mean.
     * @param float $volOfVol           The volatility of volatility (how much volatility fluctuates).
     * @param float $rho                The correlation between price and volatility (usually negative).
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
        float $drift = 0.1, 
        float $lambda = 2.0, 
        float $jumpMean = 0.01, 
        float $jumpVol = 0.1, 
        float $beta = 1.0, 
        float $marketNoise = 0.0,
        float $reversionSpeed = 0.3,
        float $kappa = 2.0,
        float $volOfVol = 0.2,
        float $rho = -0.7
    ): array {
        
        //Generate Correlated Random Variables
        $z1 = $this->mathUtility->generateStandardNormal();
        $z2 = $this->mathUtility->generateStandardNormal();

        // w1 drives the stock price, w2 drives the volatility
        $w1 = $z1;
        $w2 = ($rho * $z1) + (sqrt(1 - pow($rho, 2)) * $z2);

        //The Heston Variance Process
        $currentVariance = pow($currentVolatility, 2);
        $longTermVariance = pow($longTermVolatility, 2);

        // Calculate how much the variance changes this tick
        $dv = $kappa * ($longTermVariance - $currentVariance) * $dt 
            + $volOfVol * $currentVolatility * sqrt($dt) * $w2;

        // Ensure variance never goes negative (Full Truncation method)
        $nextVariance = max(0.000001, $currentVariance + $dv);
        $nextVolatility = sqrt($nextVariance);

        //Calculate Fair Value & Gravity
        $valuationEps = max($earningsPerShare, 0.10);
        $fairValue = $valuationEps * $targetPE;

        $logFairValue = log(max($fairValue, 0.01));
        $logCurrent = log(max($currentPrice, 0.01)); 
        $gravityDrift = $reversionSpeed * ($logFairValue - $logCurrent);

        // Apply GBM with the LIVE Volatility
        $gbmExponent = ($drift + $gravityDrift - 0.5 * $currentVariance) * $dt
             + $currentVolatility * sqrt($dt) * $w1
             + ($beta * $marketNoise);

        $gbmPrice = $currentPrice * exp($gbmExponent);

        // Jump Diffusion (Market Shocks)
        $jumpProb = $lambda * $dt;
        $jumpMultiplier = 1.0;
        $shockPct = null;

        if ((mt_rand() / mt_getrandmax()) < $jumpProb) {
            $jumpZ = $this->mathUtility->generateStandardNormal();
            
            $jumpExponent = $jumpMean + ($jumpVol * $jumpZ);
            $jumpMultiplier = exp($jumpExponent);
            $shockPct = ($jumpMultiplier - 1) * 100;
        }

        $finalPrice = $gbmPrice * $jumpMultiplier;

        return [
            'price' => max(0.01, $finalPrice),
            'shock' => $shockPct,
            'next_volatility' => $nextVolatility
        ];
    }
}
