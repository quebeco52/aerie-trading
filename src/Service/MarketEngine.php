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
     * @param float $rho                The correlation between price and volatility.
     * 
     * @param array $macroState         The current macroeconomic state, which can influence the base drift.
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
        float $kappa = 6.0,
        float $volOfVol = 0.3,
        float $rho = -0.7,
        array $macroState = [],
        ?float $fcfPerShare = null,
    ): array {
        
        // CAPM & Macro Drift
        // The Risk-Free Rate is exactly the Central Bank's smoothed Taylor Rule rate
        $riskFreeRate = $macroState['policy_rate'] ?? 0.04;

        // If the Yield Curve is inverted (Slope is negative), it signals a recession.
        // Apply a severe penalty to the market's expected premium.
        $yieldCurveInversionPenalty = min(0.0, $macroState['ns_slope'] ?? 0.0) * 2.0;
        
        // If the Output Gap is deeply negative, the economy is slumping.
        $outputGapModifier = ($macroState['output_gap'] ?? 0.0) * 0.5;

        $totalMarketPremium = $drift + $yieldCurveInversionPenalty + $outputGapModifier;
        
        // Final Drift
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
        // E[VarJump] = (pUp * muV * 0.5) + (pDown * muV)
        $expectedVarJump = (0.30 * 0.04 * 0.5) + (0.70 * 0.04);
        $jumpVarianceDrag = ($lambda * $expectedVarJump) / $kappa;
        $adjustedTheta = max(0.0001, $longTermVar - $jumpVarianceDrag);

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

        // Hard bounds to prevent the stock from completely freezing unbounded explosions
        $nextVolatility = max(0.05, min(2.00, $nextVolatility));

        // 2. Mean Reversion to Fundamental Value (Gravity Drift)
        // Pull the price towards its fair value derived from Earnings and Sector Target P/E
        $peFairValue = $earningsPerShare * $targetPE;

        // If Free Cash Flow is available, blend the P/E valuation with a DCF valuation
        if ($fcfPerShare !== null) {
            $dcfFairValue = $this->calculateIntrinsicValueDCF(
                fcfPerShare: $fcfPerShare,
                policyRate: $riskFreeRate,
                beta: $beta
            );
            $fairValue = max(0.01, ($peFairValue + $dcfFairValue) / 2.0);
        } else {
            $fairValue = max(0.01, $peFairValue);
        }

        $gravityDrift = $this->mathUtility->calculateLogMeanReversion(
            currentValue: $currentPrice,
            targetValue: $fairValue,
            reversionSpeed: $reversionSpeed
        );

        // Correlated Price Diffusion
        $z1 = $this->mathUtility->generateStandardNormal();
        $w1 = $z1; 

        // Calculate continuous price diffusion using the current variance
        $gbmPrice = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: $currentPrice,
            currentVolatility: $currentVolatility,
            drift: $finalDrift,
            gravityDrift: $gravityDrift,
            dt: $dt,
            beta: $beta,
            marketVol: $marketVol,
            marketZ: $marketZ,
            w1: $w1
        );

        // Apply Simultaneous Price Jump
        $finalPrice = $gbmPrice * $jumpData['price_multiplier'];

        return [
            'price'           => max(0.01, $finalPrice),
            'shock'           => $jumpData['shock_pct'],
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
        $fcfPerShare = max($fcfPerShare, 0.01);

        // Determine WACC
        $equityRiskPremium = 0.05; 
        $wacc = $policyRate + ($beta * $equityRiskPremium);

        // Determine Terminal Growth Rate (g)
        $terminalGrowthRate = 0.02;

        // Failsafe: WACC must be strictly greater than growth, or value goes to infinity
        if ($wacc <= $terminalGrowthRate) {
            $wacc = $terminalGrowthRate + 0.01; 
        }

        return ($fcfPerShare * (1 + $terminalGrowthRate)) / ($wacc - $terminalGrowthRate);
    }
}
