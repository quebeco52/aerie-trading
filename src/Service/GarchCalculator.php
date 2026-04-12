<?php

namespace App\Service;

class GarchCalculator
{
    /**
     * Estimates GARCH(1,1) parameters and returns the new Long-Term Volatility (Theta).
     * Uses Variance Targeting and Maximum Likelihood Estimation (MLE) via Grid Search.
     *
     * @param float[] $historicalPrices Array of chronological closing prices (oldest to newest)
     * @param int $ticksPerYear Used to annualize the daily variance
     * @return float The new annualized long-term volatility
     */
    public function calculateLongTermVolatility(array $historicalPrices, int $ticksPerYear = 252): float
    {
        if (count($historicalPrices) < 10) {
            return 0.15; // Fallback if not enough data
        }

        // Calculate Logarithmic Returns
        $returns = [];
        for ($i = 1; $i < count($historicalPrices); $i++) {
            $prev = max($historicalPrices[$i - 1], 0.0001);
            $curr = max($historicalPrices[$i], 0.0001);
            $returns[] = log($curr / $prev);
        }

        $n = count($returns);

        // Calculate Unconditional Sample Variance (Variance Targeting)
        $meanReturn = array_sum($returns) / $n;
        $sampleVariance = 0.0;
        foreach ($returns as $r) {
            $sampleVariance += pow($r - $meanReturn, 2);
        }
        $sampleVariance /= ($n - 1);

        // Prevent math errors on flatlined stocks
        $sampleVariance = max($sampleVariance, 0.0000001);

        // Grid Search for MLE (Maximum Likelihood Estimation)
        // test various combinations of alpha (reaction to shocks) and beta (persistence)
        $bestAlpha = 0.05;
        $bestBeta = 0.85;
        $maxLogLikelihood = -INF;

        // Typical market ranges: Alpha [0.01 - 0.20], Beta [0.60 - 0.98]
        // Constraint: Alpha + Beta < 1.0 (Must be mean-reverting)
        for ($alpha = 0.02; $alpha <= 0.20; $alpha += 0.02) {
            for ($beta = 0.60; $beta <= 0.95; $beta += 0.05) {
                if ($alpha + $beta >= 0.99) continue;

                // Omega is derived via Variance Targeting
                $omega = $sampleVariance * (1.0 - $alpha - $beta);
                
                // Calculate Log-Likelihood for this specific alpha/beta pair
                $ll = $this->calculateLogLikelihood($returns, $omega, $alpha, $beta, $sampleVariance);
                
                if ($ll > $maxLogLikelihood) {
                    $maxLogLikelihood = $ll;
                    $bestAlpha = $alpha;
                    $bestBeta = $beta;
                }
            }
        }

        // Derive the new Unconditional Variance from the best fit parameters
        $bestOmega = $sampleVariance * (1.0 - $bestAlpha - $bestBeta);
        $longRunDailyVariance = $bestOmega / (1.0 - $bestAlpha - $bestBeta);

        // Annualize the volatility (Square root of variance * sqrt(Time))
        $annualizedVolatility = sqrt($longRunDailyVariance * $ticksPerYear);

        // Cap and floor the volatility to prevent extreme simulation breakage
        return max(0.05, min(0.80, $annualizedVolatility));
    }

    /**
     * Calculates the Log-Likelihood of a specific GARCH(1,1) parameter set.
     */
    private function calculateLogLikelihood(array $returns, float $omega, float $alpha, float $beta, float $initialVar): float
    {
        $logLikelihood = 0.0;
        $h = $initialVar; // Initial conditional variance

        foreach ($returns as $r) {
            // Update conditional variance (h_t = w + a*r^2 + b*h_t-1)
            $h = $omega + ($alpha * ($r * $r)) + ($beta * $h);
            
            // Log-likelihood function for standard normal errors
            $logLikelihood += -0.5 * (log($h) + (($r * $r) / $h));
        }

        return $logLikelihood;
    }
}