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
        private MathUtility $mathUtility
    ) {}

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
     * @return array{price: float, shock: float|null, next_volatility: float, analyst_targets: array} The calculated next price, shock percentage, updated volatility, and analyst targets.
     */
    public function calculateNextPrice(
        float $currentPrice,
        float $currentVolatility,
        float $longTermVolatility,
        float $earningsPerShare,
        float $dt,
        float $lambda = 2.0,
        float $beta = 1.0,
        float $marketZ = 0.0,
        float $marketVol = 0.15,
        float $drift = 0.08,
        float $reversionSpeed = 0.25,
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

        // CAPM & MACRO TRANSMISSION MECHANISM

        $riskFreeRate = $macroState['policy_rate'] ?? 0.04;
        $outputGap = $macroState['output_gap'] ?? 0.0;
        $inflation = $macroState['inflation'] ?? 0.02;
        $corporateTaxRate = $macroState['corporate_tax_rate'] ?? 0.21;

        $finalDrift = $this->calculateMacroDrift($outputGap, $inflation, $macroState['ns_slope'] ?? 0.0, $drift, $beta, $riskFreeRate);

        // State Variables
        $currentVar = $currentVolatility * $currentVolatility;
        $longTermVar = $longTermVolatility * $longTermVolatility;

        $baseJumpSize = max(0.02, $currentVolatility * 0.50);

        $dynamicEtaUp = 1.0 / $baseJumpSize;
        $dynamicEtaDown = 1.0 / ($baseJumpSize * 1.25);

        $dynamicMuV = ($currentVolatility * $currentVolatility) * 0.50;

        // The SVJJ Jump Process (Kou Distribution)
        $jumpData = $this->mathUtility->calculateSVJJJumps(
            lambda: $lambda,
            pUp: 0.30,             // Maintain the 30/70 behavioral skew
            etaUp: $dynamicEtaUp,  // Calibrated upside jump
            etaDown: $dynamicEtaDown, // Calibrated downside crash
            muV: $dynamicMuV,      // Calibrated volatility explosion
            dt: $dt
        );

        $cycleVolModifier = 1.0;
        if (!empty($macroState)) {
            // Positive output gap (boom) reduces vol slightly, negative gap (bust) increases vol
            $cycleVolModifier = 1.0 - ($macroState['output_gap'] ?? 0.0);
        }

        // Adjust theta downwards to account for the continuous positive variance jumps from the SVJJ model
        // E[VarJump] = (pUp * dynamicMuV * 0.5) + (pDown * dynamicMuV)
        $expectedVarJump = (0.30 * $dynamicMuV * 0.5) + (0.70 * $dynamicMuV);
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

        // Enforce bounds: Min 1% (0.01) to prevent flatlining in extreme bull markets, Max 150% for market safety
        $nextVolatility = max(0.01, min(1.50, $nextVolatility));

        $fundamentalState = $this->evaluateFundamentalState(
            $currentPrice,
            $outputGap,
            $inflation,
            $beta,
            $liveWacc,
            $reversionSpeed,
            $earningsPerShare,
            $currentRoic,
            $fcfPerShare,
            $riskFreeRate,
            $bookValuePerShare,
            $dividendPerShare
        );

        $perceivedFairValue = $fundamentalState['perceived_fair_value'];
        $dynamicReversion = $fundamentalState['dynamic_reversion'];

        // Exact Ornstein-Uhlenbeck Mean Reversion in Log-Space
        // Using exp(-kappa * dt) mathematically guarantees the price never overshoots the fair value.
        $reversionWeight = exp(-$dynamicReversion * $dt);

        // Pure Geometric Brownian Motion (GBM) Step
        $idiosyncraticShock = $this->mathUtility->generateStandardNormal();

        // Calculate pure continuous price diffusion WITHOUT the linear gravity drift
        $gbmPrice = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: $currentPrice,
            currentVolatility: $currentVolatility,
            drift: $finalDrift,
            gravityDrift: 0.0, // Set to zero, handled below
            dt: $dt,
            beta: $beta,
            marketVol: $marketVol,
            marketZ: $marketZ,
            w1: $idiosyncraticShock
        );



        // Geometrically blend the GBM price with the fundamental Fair Value
        $diffusedPrice = exp(
            $reversionWeight * log($gbmPrice) +
                (1.0 - $reversionWeight) * log($perceivedFairValue)
        );

        // Apply Simultaneous Price Jumps AND M&A Shocks outside the GBM exponent
        $totalShockMultiplier = $jumpData['price_multiplier'] * (1.0 + $maShock);
        $finalPrice = $diffusedPrice * $totalShockMultiplier;


        return [
            'price'             => max(0.01, $finalPrice),
            'shock'             => $jumpData['shock_pct'],
            'next_volatility'   => $nextVolatility,
            'analyst_targets'   => $fundamentalState['analyst_targets']
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
        $inflationPenalty = $inflation > 0.04 ? - ($inflation - 0.04) * 1.5 : 0.0;
        $yieldCurveInversionPenalty = min(0.0, $nsSlope) * 3.0;

        $totalMarketPremium = $drift + $yieldCurveInversionPenalty + $outputGapModifier + $inflationPenalty;
        return $riskFreeRate + ($totalMarketPremium * $beta);
    }

    /**
     * Evaluates the fundamental state of the stock under macroeconomic stress.
     * Calculates the systemic stress index, dynamic WACC, flight-to-quality reversion speed,
     * and the intrinsic fair value of the asset.
     * 
     * @return array{perceived_fair_value: float, dynamic_reversion: float, analyst_targets: array}
     */
    private function evaluateFundamentalState(
        float $currentPrice,
        float $outputGap,
        float $inflation,
        float $beta,
        float $liveWacc,
        float $reversionSpeed,
        float $earningsPerShare,
        float $currentRoic,
        ?float $fcfPerShare,
        float $riskFreeRate,
        float $bookValuePerShare,
        float $dividendPerShare
    ): array {
        // MACROECONOMIC STRESS INDEX (MSI)
        $recessionStress = max(0.0, -$outputGap); // Negative output gap = economic contraction
        $inflationStress = abs($inflation - 0.02); // Deviation from price stability
        $systemicStressIndex = $recessionStress + $inflationStress;

        // FLIGHT-TO-QUALITY REVERSION (Liquidity Drain)
        $dynamicReversion = $reversionSpeed * (1.0 + ($systemicStressIndex * 5.0));

        // DYNAMIC FUNDAMENTAL VALUATION

        // Dynamic P/E Re-Rating (The EVA Premium)
        $evaSpread = $currentRoic - $liveWacc;
        $fairValuePE = $this->mathUtility->calculateIntrinsicFairValuePE($riskFreeRate, $evaSpread);
        $peFairValue = max(0.01, $earningsPerShare * $fairValuePE);

        // Discounted Cash Flow (DCF) Value
        if ($fcfPerShare !== null) {
            if ($fcfPerShare > 0.0) {
                $multiplier = $this->mathUtility->calculateDcfMultiplier($liveWacc, 0.02);
                $dcfFairValue = max(0.01, $fcfPerShare * $multiplier);

                // Blend the Earnings value and the Cash Flow value
                $earningsValue = ($peFairValue + $dcfFairValue) / 2.0;
            } else {
                // Apply a 25% "Cash Burn" penalty to the P/E valuation
                $earningsValue = $peFairValue * 0.75;
            }
        } else {
            $earningsValue = $peFairValue;
        }

        // Dividend Yield Support (The Dividend Discount Model)
        // High dividends create a hard psychological and mathematical price floor for investors
        $dividendSupportValue = $this->mathUtility->calculateDividendDiscountModel(
            $dividendPerShare * 4.0,
            $liveWacc,
            0.01
        );

        // The stock's fair value is the highest of its Earnings power, its Yield Support, or its physical Book Value
        $fairValue = max($earningsValue, $dividendSupportValue, $bookValuePerShare * 0.80);
        $perceivedFairValue = max(0.01, $fairValue);

        $valuationRatio = $currentPrice / $perceivedFairValue;

        $overvaluation = max(0.0, $valuationRatio - 1.0);
        $gravityCurve = pow($overvaluation, 2.0); 
        
        // Smoothly scale macro resistance based on the output gap instead of a hard cliff.
        // Base resistance is 0.02. As the economy dips into recession, fear scales up linearly.
        $macroResistance = 0.02 + (max(0.0, -$outputGap) * 6.0); 
        $bubbleGravity = $gravityCurve * $macroResistance;
        
        $dynamicReversion += min(10.0, $bubbleGravity); // Cap max panic reversion

        return [
            'perceived_fair_value' => $perceivedFairValue,
            'dynamic_reversion'    => $dynamicReversion,
            'analyst_targets'      => [
                'growth_analyst' => max(0.01, $earningsValue),
                'income_analyst' => max(0.01, $dividendSupportValue),
                'value_analyst'  => max(0.01, $bookValuePerShare * 0.80)
            ]
        ];
    }
}
