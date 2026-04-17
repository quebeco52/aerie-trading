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
     * @param array $macroState         The current macroeconomic state, which can influence the base drift.
     * @param float $bookValuePerShare  The physical equity value per share.
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
        float $beta = 1.0,
        float $marketZ = 0.0,
        float $marketVol = 0.15,
        float $drift = 0.08,
        float $reversionSpeed = 0.4,
        float $kappa = 2.5,
        float $volOfVol = 0.3,
        array $macroState = [],
        ?float $fcfPerShare = null,
        float $bookValuePerShare = 0.0,
        float $maShock = 0.0
    ): array {
        
        // =====================================================================
        // CAPM & MACRO TRANSMISSION MECHANISM
        // =====================================================================
        $riskFreeRate = $macroState['policy_rate'] ?? 0.04;
        $outputGap = $macroState['output_gap'] ?? 0.0;
        $inflation = $macroState['inflation'] ?? 0.02;

        // 1. Asymmetric Sentiment (Fear > Greed)
        // A boom (+5%) gives a gentle +2.5% tailwind. A recession (-5%) gives a brutal -10% headwind.
        $outputGapModifier = $outputGap > 0 ? ($outputGap * 0.5) : ($outputGap * 2.0);

        // 2. The Stagflation Tax
        // High inflation destroys the purchasing power of corporate earnings. 
        // If inflation breaks above 4%, investors demand a massive risk premium, crushing stock prices.
        $inflationPenalty = $inflation > 0.04 ? -($inflation - 0.04) * 1.5 : 0.0;

        // 3. The Liquidity Drain (Yield Curve Inversion)
        // An inverted yield curve suffocates bank lending and chokes off corporate liquidity.
        $yieldCurveInversionPenalty = min(0.0, $macroState['ns_slope'] ?? 0.0) * 3.0;

        $totalMarketPremium = $drift + $yieldCurveInversionPenalty + $outputGapModifier + $inflationPenalty;
        $finalDrift = $riskFreeRate + ($totalMarketPremium * $beta);

        // State Variables
        $currentVar = $currentVolatility * $currentVolatility;
        $longTermVar = $longTermVolatility * $longTermVolatility;

        // The SVJJ Jump Process (Kou Distribution)
        $jumpData = $this->mathUtility->calculateSVJJJumps(
            lambda: $lambda,
            pUp: 0.30,     // Asymmetric tails: 30% chance of upside jump, 70% chance of downside crash
            etaUp: 10.0,   // ~10% avg up-jump
            etaDown: 8.0,  // ~12.5% avg down-jump (fatter left tail)
            muV: 0.04,     // Base variance jump size
            dt: $dt
        );

        // Compensate for the expected variance added by SVJJ jumps to prevent a positive feedback loop
        $expectedVarJump = (0.30 * 0.04 * 0.5) + (0.70 * 0.04);
        $jumpVarianceDrag = ($lambda * $expectedVarJump) / $kappa;
        
        // This stops the QE scheme from dragging the continuous volatility into a black hole.
        $absoluteFloorVar = 0.05 * 0.05; 
        $adjustedTheta = max($absoluteFloorVar, $longTermVar - $jumpVarianceDrag);

        // Variance Process via Quadratic-Exponential (QE) Scheme
        $nextVar = $this->mathUtility->calculateQEVarianceStep(
            currentVar: $currentVar,
            theta: $adjustedTheta,
            kappa: $kappa,
            sigma: $volOfVol,
            dt: $dt
        );

        // Add the contemporaneous volatility jump from the SVJJ model
        $nextVar += $jumpData['var_jump'];
        
        // Convert back to volatility for the return payload
        $nextVolatility = sqrt($nextVar);

        // Remove the 2.00 hard cap! If a crash demands 300% volatility, let it happen.
        // We only enforce a 5.0 (500%) ceiling to prevent integer overflows in the database.
        $nextVolatility = min(5.00, $nextVolatility);

        // 2. Mean Reversion to Fundamental Value (Gravity Drift)
        // Pull the price towards its fair value derived from Earnings and Sector Target P/E
        $peFairValue = $earningsPerShare * $targetPE;

        // If Free Cash Flow is available, blend the P/E valuation with a DCF valuation
        if ($fcfPerShare !== null && $fcfPerShare > 0.0) {
            $dcfFairValue = $this->calculateIntrinsicValueDCF(
                fcfPerShare: $fcfPerShare,
                policyRate: $riskFreeRate,
                beta: $beta
            );
            $earningsValue = ($peFairValue + $dcfFairValue) / 2.0;
        } else {
            $earningsValue = $peFairValue;
        }

        // Graham-style Value Investing Failsafe:
        // Value investors will step in if the stock drops below 80% of its physical Book Value.
        $fairValue = max($earningsValue, $bookValuePerShare * 0.80);
        $fairValue = max(0.01, $fairValue);

        // 4. Panic Gravity (Flight to Safety)
        $macroStress = abs($outputGap) + abs($inflation - 0.02);
        $dynamicReversion = $reversionSpeed + ($macroStress * 2.5);

        // 5. Pure Geometric Brownian Motion (GBM) Step
        $idiosyncraticShock = $this->mathUtility->generateStandardNormal();

        // Calculate pure continuous price diffusion WITHOUT the linear gravity drift
        $gbmPrice = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: $currentPrice,
            currentVolatility: $currentVolatility,
            drift: $finalDrift,
            gravityDrift: 0.0, // <-- Set to zero, handled exactly below
            dt: $dt,
            beta: $beta,
            marketVol: $marketVol,
            marketZ: $marketZ,
            w1: $idiosyncraticShock
        );

        // 6. Exact Ornstein-Uhlenbeck Mean Reversion in Log-Space
        // This replaces calculateLogMeanReversion. 
        // Using exp(-kappa * dt) mathematically guarantees the price never overshoots the fair value.
        $reversionWeight = exp(-$dynamicReversion * $dt);
        
        // Geometrically blend the GBM price with the fundamental Fair Value
        $diffusedPrice = exp(
            $reversionWeight * log($gbmPrice) + 
            (1.0 - $reversionWeight) * log($fairValue)
        );

        // THE FIX: Apply Simultaneous Price Jumps AND M&A Shocks outside the GBM exponent
        $totalShockMultiplier = $jumpData['price_multiplier'] * (1.0 + $maShock);
        $finalPrice = $diffusedPrice * $totalShockMultiplier;
        
        // Calculate the total shock percentage for the UI event payload
        $totalShockPct = ($totalShockMultiplier - 1.0) * 100.0;

        return [
            'price'           => max(0.01, $finalPrice),
            'shock'           => $totalShockMultiplier != 1.0 ? $totalShockPct : null,
            'next_volatility' => $nextVolatility
        ];
    }

    /**
     * Calculates the Intrinsic Fair Value using a Discounted Cash Flow (DCF) Gordon Growth Model.
     */
    private function calculateIntrinsicValueDCF(
        float $fcfPerShare, 
        float $policyRate, 
        float $beta
    ): float {
        // Determine Cost of Equity (CAPM)
        $equityRiskPremium = 0.05; 
        $costOfEquity = $policyRate + ($beta * $equityRiskPremium);
        $terminalGrowthRate = 0.02;

        $spread = $costOfEquity - $terminalGrowthRate;

        // THE FIX: Cap the absolute multiplier at 33.3x, rather than altering the Cost of Equity
        $multiplier = $spread > 0 ? (1 + $terminalGrowthRate) / $spread : 33.33;
        $multiplier = min(33.33, $multiplier); 

        $valuePerShare = $fcfPerShare * $multiplier;

        return max(0.01, $valuePerShare);
    }
}
