<?php

namespace App\Service;

class GarchCalculator
{
    public function calculateLongTermVolatility(array $historicalPrices, int $ticksPerYear = 252): float
    {
        if (count($historicalPrices) < 10) {
            return 0.15; 
        }

        // Calculate Logarithmic Returns
        $returns = [];
        for ($i = 1; $i < count($historicalPrices); $i++) {
            $prev = max($historicalPrices[$i - 1], 0.0001);
            $curr = max($historicalPrices[$i], 0.0001);
            $returns[] = log($curr / $prev);
        }
        $n = count($returns);

        // Winsorization: Calculate raw std dev to find the 3-Sigma threshold
        $meanReturn = array_sum($returns) / $n;
        $rawVariance = 0.0;
        foreach ($returns as $r) {
            $rawVariance += pow($r - $meanReturn, 2);
        }
        $rawVariance /= ($n - 1);
        $stdDev = sqrt(max($rawVariance, 0.0000001));
        $clipThreshold = $stdDev * 3.0;
        
        // Create Centered & Clipped Returns (Removes trend bias and SVJJ fat tails)
        $centeredReturns = [];
        $sampleVariance = 0.0;
        foreach ($returns as $r) {
            $clippedR = max($meanReturn - $clipThreshold, min($meanReturn + $clipThreshold, $r));
            $centeredReturns[] = $clippedR - $meanReturn;
            $sampleVariance += pow($clippedR - $meanReturn, 2);
        }
        $sampleVariance /= ($n - 1);
        $sampleVariance = max($sampleVariance, 0.0000001);

        // Grid Search for MLE using the centered returns
        $bestAlpha = 0.05;
        $bestBeta = 0.85;
        $maxLogLikelihood = -INF;

        for ($alpha = 0.02; $alpha <= 0.20; $alpha += 0.02) {
            for ($beta = 0.60; $beta <= 0.95; $beta += 0.05) {
                if ($alpha + $beta >= 0.99) continue;

                $omega = $sampleVariance * (1.0 - $alpha - $beta);
                $ll = $this->calculateLogLikelihood($centeredReturns, $omega, $alpha, $beta, $sampleVariance);
                
                if ($ll > $maxLogLikelihood) {
                    $maxLogLikelihood = $ll;
                    $bestAlpha = $alpha;
                    $bestBeta = $beta;
                }
            }
        }

        // Derive the new Unconditional Variance
        $bestOmega = $sampleVariance * (1.0 - $bestAlpha - $bestBeta);
        $longRunDailyVariance = $bestOmega / (1.0 - $bestAlpha - $bestBeta);

        // Annualize the volatility
        $annualizedVolatility = sqrt($longRunDailyVariance * $ticksPerYear);

        return max(0.05, min(0.80, $annualizedVolatility));
    }

    private function calculateLogLikelihood(array $returns, float $omega, float $alpha, float $beta, float $initialVar): float
    {
        $logLikelihood = 0.0;
        $h = $initialVar;

        foreach ($returns as $r) {
            $h = $omega + ($alpha * pow($r, 2)) + ($beta * $h);
            $logLikelihood += -0.5 * (log($h) + (pow($r, 2) / $h));
        }

        return $logLikelihood;
    }
}