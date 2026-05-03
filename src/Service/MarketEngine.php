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
     * @param float $totalDebt          The total debt on the balance sheet.
     * @param float $totalEquity        The total equity on the balance sheet.
     * @param float $creditSpread       The company's baseline credit spread (borrowing premium).
     * @param float $dividendPerShare   The absolute quarterly dividend per share.
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
        array $macroState = [],
        ?float $fcfPerShare = null,
        float $bookValuePerShare = 0.0,
        float $maShock = 0.0,
        float $currentRoic = 0.10,
        float $dividendPerShare = 0.0,
        float $liveWacc = 0.08
    ): array {
        
        // =====================================================================
        // CAPM & MACRO TRANSMISSION MECHANISM
        // =====================================================================
        $riskFreeRate = $macroState['policy_rate'] ?? 0.04;
        $outputGap = $macroState['output_gap'] ?? 0.0;
        $inflation = $macroState['inflation'] ?? 0.02;
        $corporateTaxRate = $macroState['corporate_tax_rate'] ?? 0.21;

        $finalDrift = $this->calculateMacroDrift($outputGap, $inflation, $macroState['ns_slope'] ?? 0.0, $drift, $beta, $riskFreeRate);

        // State Variables
        $currentVar = $currentVolatility * $currentVolatility;
        $longTermVar = $longTermVolatility * $longTermVolatility;

        // The SVJJ Jump Process (Kou Distribution)
        $jumpData = $this->mathUtility->calculateSVJJJumps(
            lambda: $lambda,
            pUp: 0.30,     // Asymmetric tails: 30% chance of upside jump, 70% chance of downside crash
            etaUp: 10.0,   // ~10% avg up-jump
            etaDown: 8.0,  // ~12.5% avg down-jump (fatter left tail)
            muV: 0.015,    // Base variance jump size (reduced to prevent excessive volatility drain)
            dt: $dt
        );

        $cycleVolModifier = 1.0;
        if (!empty($macroState)) {
            // Positive output gap (boom) reduces vol slightly, negative gap (bust) increases vol
            $cycleVolModifier = 1.0 - ($macroState['output_gap'] ?? 0.0);
        }

        // Adjust theta downwards to account for the continuous positive variance jumps from the SVJJ model
        // E[VarJump] = (pUp * muV * 0.5) + (pDown * muV)
        $expectedVarJump = (0.30 * 0.015 * 0.5) + (0.70 * 0.015);
        $jumpVarianceDrag = ($lambda * $expectedVarJump) / $kappa;
        
        $adjustedTheta = max(0.0001, ($longTermVar * $cycleVolModifier) - $jumpVarianceDrag);

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

        // Enforce bounds: Min 1% (0.01) to prevent flatlining in extreme bull markets, Max 500% (5.00) for DB safety
        $nextVolatility = max(0.01, min(5.00, $nextVolatility));

        // Mean Reversion to Fundamental Value (Gravity Drift)
        $fairValue = $this->calculateFundamentalFairValue(
            $earningsPerShare, 
            $currentRoic,
            $fcfPerShare, 
            $riskFreeRate, 
            $beta, 
            $bookValuePerShare, 
            $dividendPerShare,
            $liveWacc
        );
        // Panic Gravity (Flight to Safety)
        $macroStress = abs($outputGap) + abs($inflation - 0.02);
        $dynamicReversion = $reversionSpeed + ($macroStress * 2.5);

        // Pure Geometric Brownian Motion (GBM) Step
        $idiosyncraticShock = $this->mathUtility->generateStandardNormal();

        // Calculate pure continuous price diffusion WITHOUT the linear gravity drift
        $gbmPrice = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: $currentPrice,
            currentVolatility: $currentVolatility,
            drift: $finalDrift,
            gravityDrift: 0.0, // <-- Set to zero, handled below
            dt: $dt,
            beta: $beta,
            marketVol: $marketVol,
            marketZ: $marketZ,
            w1: $idiosyncraticShock
        );

        // Exact Ornstein-Uhlenbeck Mean Reversion in Log-Space
        // This replaces calculateLogMeanReversion. 
        // Using exp(-kappa * dt) mathematically guarantees the price never overshoots the fair value.
        $reversionWeight = exp(-$dynamicReversion * $dt);
        
        // Geometrically blend the GBM price with the fundamental Fair Value
        $diffusedPrice = exp(
            $reversionWeight * log($gbmPrice) + 
            (1.0 - $reversionWeight) * log($fairValue)
        );

        // Apply Simultaneous Price Jumps AND M&A Shocks outside the GBM exponent
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
     * Calculates the macro-adjusted drift utilizing CAPM and transmission mechanisms.
     *
     * Adjusts the baseline drift by factoring in asymmetric sentiment (fear vs greed),
     * the stagflation tax (inflation penalty), and liquidity drains (yield curve inversion).
     *
     * @param float $outputGap    The current macroeconomic output gap.
     * @param float $inflation    The current inflation rate.
     * @param float $nsSlope      The slope of the yield curve (Nelson-Siegel).
     * @param float $drift        The expected baseline return.
     * @param float $beta         The stock's beta (market sensitivity).
     * @param float $riskFreeRate The current central bank policy rate.
     * @return float The final calculated drift rate.
     */
    private function calculateMacroDrift(float $outputGap, float $inflation, float $nsSlope, float $drift, float $beta, float $riskFreeRate): float
    {
        $outputGapModifier = $outputGap > 0 ? ($outputGap * 0.5) : ($outputGap * 2.0);
        $inflationPenalty = $inflation > 0.04 ? -($inflation - 0.04) * 1.5 : 0.0;
        $yieldCurveInversionPenalty = min(0.0, $nsSlope) * 3.0;

        $totalMarketPremium = $drift + $yieldCurveInversionPenalty + $outputGapModifier + $inflationPenalty;
        return $riskFreeRate + ($totalMarketPremium * $beta);
    }

    /**
     * Calculates the intrinsic fair value of the stock using dynamic EVA-adjusted P/E and DCF.
     */
    private function calculateFundamentalFairValue(
        float $earningsPerShare, 
        float $currentRoic, 
        ?float $fcfPerShare, 
        float $riskFreeRate, 
        float $beta, 
        float $bookValuePerShare, 
        float $dividendPerShare,
        float $liveWacc
    ): float {
        // Use the live WACC passed down from the centralized DebtEngine
        $wacc = $liveWacc;

        // Dynamic P/E Re-Rating (The EVA Premium)
        $marketBasePE = max(8.0, min(30.0, 1.0 / max(0.01, $riskFreeRate)));
        $evaSpread = $currentRoic - $wacc;
        
        $qualityPremium = max(0.0, $evaSpread * 100) * 1.5;
        $distressDiscount = min(0.0, $evaSpread * 100) * 2.0;

        $fairValuePE = max(4.0, min(60.0, $marketBasePE + $qualityPremium + $distressDiscount));
        $peFairValue = max(0.01, $earningsPerShare * $fairValuePE);

        // Discounted Cash Flow (DCF) Value
        if ($fcfPerShare !== null && $fcfPerShare > 0.0) {
            $terminalGrowthRate = 0.02;
            $spread = $wacc - $terminalGrowthRate;
            $multiplier = $spread > 0 ? (1 + $terminalGrowthRate) / $spread : 33.33;
            $multiplier = min(33.33, $multiplier); 
            $dcfFairValue = max(0.01, $fcfPerShare * $multiplier);

            // Blend the Earnings value and the Cash Flow value
            $earningsValue = ($peFairValue + $dcfFairValue) / 2.0;
        } else {
            $earningsValue = $peFairValue;
        }
        
        // Dividend Yield Support (The Dividend Discount Model)
        // High dividends create a hard psychological and mathematical price floor for investors
        $dividendSupportValue = 0.0;
        if ($dividendPerShare > 0.0) {
            $annualDividend = $dividendPerShare * 4.0;
            // Investors demand the Cost of Equity, minus an assumed 1% long-term growth rate
            $requiredYield = max(0.02, $wacc - 0.01);
            $dividendSupportValue = $annualDividend / $requiredYield;
        }

        // The stock's fair value is the highest of its Earnings power, its Yield Support, or its physical Book Value
        $fairValue = max($earningsValue, $dividendSupportValue, $bookValuePerShare * 0.80);
        
        return max(0.01, $fairValue);
    }
}
