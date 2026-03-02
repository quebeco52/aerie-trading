<?php

namespace App\Service;

class MarketEngine
{

    private function generateStandardNormal(): float
    {
        do {
            $x = mt_rand() / mt_getrandmax();
            $y = mt_rand() / mt_getrandmax();
        } while ($x <= 0);

        return sqrt(-2 * log($x)) * cos(2 * M_PI * $y);
    }
    /**
     * Calculates the next stock price using a hybrid model.
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

        // GBM (Standard Volatility) 
        $gbmExponent = ($drift - 0.5 * pow($volatility, 2)) * $dt
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

        // Fundamental Gravity
        $intermediatePrice = $gbmPrice * $jumpMultiplier;
        $valuationEps = max($earningsPerShare, 0.10);
        $fairValue = $valuationEps * $targetPE;

        $gravity = ($fairValue - $intermediatePrice) * $reversionSpeed * $dt;

        return [
            'price' => max(0.01, $intermediatePrice + $gravity),
            'shock' => $shockPct
        ];
    }
}