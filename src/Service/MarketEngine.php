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

    private ?float $spareNormal = null;
    /**
     * Generates a standard normal random variable using the Box-Muller transform.
     *
     * @return float A random number from a standard normal distribution.
     */
    private function generateStandardNormal(): float
    {
        // If we have a spare from the last calculation, use it.
        if ($this->spareNormal !== null) {
            $result = $this->spareNormal;
            $this->spareNormal = null;
            return $result;
        }

        do {
            $x = mt_rand() / mt_getrandmax();
            $y = mt_rand() / mt_getrandmax();
        } while ($x <= 0);

        $radius = sqrt(-2 * log($x));
        $angle = 2 * M_PI * $y;

        // Calculate both! Store the sine one for next time.
        $this->spareNormal = $radius * sin($angle);

        // Return the cosine one now.
        return $radius * cos($angle);
    }
    /**
     * Calculates the next stock price using a hybrid model.
     *
     * This method combines:
     * 1. Geometric Brownian Motion (GBM) for standard drift and volatility.
     * 2. Jump Diffusion for sudden market shocks.
     * 3. Mean Reversion to pull the price towards a fundamental fair value based on PE.
     *
     * @param float $currentPrice     The current price of the stock.
     * @param float $earningsPerShare The current earnings per share (EPS).
     * @param float $targetPE         The target P/E ratio for the stock's sector.
     * @param float $volatility       The stock's volatility (sigma).
     * @param float $dt               The time step for the simulation (in years).
     * @param float $drift            The expected return (drift) of the stock.
     * @param float $lambda           The jump intensity (average number of jumps per year).
     * @param float $jumpMean         The mean size of a jump (log-return).
     * @param float $jumpVol          The volatility of the jump size.
     * @param float $beta             The stock's beta (sensitivity to market movements).
     * @param float $marketNoise      The systemic market noise component.
     * @param float $reversionSpeed   The speed at which the price reverts to fair value.
     *
     * @return array{price: float, shock: float|null} The calculated next price and any shock percentage (if a jump occurred).
     */
    public function calculateNextPrice(
        float $currentPrice, 
        float $earningsPerShare, 
        float $targetPE, 
        float $volatility, 
        float $dt, 
        float $drift = 0.1, 
        float $lambda = 2.0, 
        float $jumpMean = 0.01, 
        float $jumpVol = 0.1, 
        float $beta = 1.0, 
        float $marketNoise = 0.0,
        float $reversionSpeed = 0.3
    ): array {
        $gbmZ = $this->generateStandardNormal();

        // Calculate Fair Value
        $valuationEps = max($earningsPerShare, 0.10);
        $fairValue = $valuationEps * $targetPE;

        // Calculate Fundamental Gravity
        $logFairValue = log(max($fairValue, 0.01));
        
        // Safety check to prevent log(0)
        $logCurrent = log(max($currentPrice, 0.01)); 
        
        // The drift is modified by how far away it is from fair value
        $gravityDrift = $reversionSpeed * ($logFairValue - $logCurrent);

        // Apply GBM with the Gravity Drift included
        $gbmExponent = ($drift + $gravityDrift - 0.5 * pow($volatility, 2)) * $dt
             + $volatility * sqrt($dt) * $gbmZ
             + ($beta * $marketNoise);

        $gbmPrice = $currentPrice * exp($gbmExponent);

        // Jump Diffusion
        $jumpProb = $lambda * $dt;
        $jumpMultiplier = 1.0;
        $shockPct = null;

        if ((mt_rand() / mt_getrandmax()) < $jumpProb) {
            $jumpZ = $this->generateStandardNormal();
            
            $jumpExponent = $jumpMean + ($jumpVol * $jumpZ);
            $jumpMultiplier = exp($jumpExponent);

            $shockPct = ($jumpMultiplier - 1) * 100;
        }

        // Final Price Calculation
        $finalPrice = $gbmPrice * $jumpMultiplier;

        return [
            'price' => max(0.01, $finalPrice), // Ensure the price never goes to absolute zero
            'shock' => $shockPct
        ];
    }
}
