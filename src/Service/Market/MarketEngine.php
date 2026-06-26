<?php

namespace App\Service\Market;

use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;

/**
 * Service responsible for calculating stock price movements based on various market factors.
 *
 * This engine uses a hybrid model incorporating Geometric Brownian Motion (GBM),
 * Jump Diffusion processes, and Mean Reversion to simulate realistic stock price behavior.
 */
class MarketEngine
{
    // Volatility Bounds
    private const MIN_VOLATILITY = 0.01; // 1% absolute floor
    private const MAX_VOLATILITY = 1.50; // 150% absolute ceiling

    // Jump Diffusion Constants
    private const SVJJ_P_UP = 0.40;
    private const SVJJ_P_DOWN = 0.60;

    // Valuation Multiple Bounds
    private const MIN_BASE_PE = 8.0;
    private const MAX_BASE_PE = 80.0;
    private const MIN_FAIR_VALUE_PE = 4.0;
    private const MAX_FAIR_VALUE_PE = 150.0;

    // Analyst Multipliers
    private const VALUE_ANALYST_BOOK_MULT = 0.80;

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
     * @return array{price: float, shock: float|null, next_volatility: float, analyst_targets: array, perceived_fair_value: float} The calculated next price, shock percentage, updated volatility, and analyst targets.
     */
    public function calculateNextPrice(
        float $currentPrice,
        float $currentVolatility,
        float $longTermVolatility,
        float $earningsPerShare,
        float $dt,
        float $lambda = 2.0,
        float $jump_vol = 0.05,
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
        float $roicTtm = 0.10,
        float $dividendPerShare = 0.0,
        float $liveWacc = 0.08,
        float $baselineIndustryPE = 20.0,
        float $revenuePerShare = 0.0,
        string $businessModel = 'none',
        float $liveCostOfEquity = 0.10,
    ): array {

        // CAPM & MACRO TRANSMISSION MECHANISM

        $riskFreeRate = $macroState['policy_rate'] ?? 0.04;
        $outputGap = $macroState['output_gap'] ?? 0.0;
        $inflation = $macroState['inflation'] ?? 0.02;
        $corporateTaxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;

        $finalDrift = $this->calculateMacroDrift($outputGap, $inflation, $macroState['ns_slope'] ?? 0.0, $drift, $beta, $riskFreeRate);

        // State Variables
        $currentVar = $currentVolatility * $currentVolatility;
        $longTermVar = $longTermVolatility * $longTermVolatility;


        $dynamicEtaUp = 1.0 / $jump_vol;
        $dynamicEtaDown = 1.0 / ($jump_vol * 1.25);

        $dynamicMuV = ($currentVolatility * $currentVolatility) * 0.50;

        // The SVJJ Jump Process (Kou Distribution)
        $jumpData = $this->mathUtility->calculateSVJJJumps(
            lambda: $lambda,
            pUp: self::SVJJ_P_UP,  // Maintain the 30/70 behavioral skew
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
        $expectedVarJump = (self::SVJJ_P_UP * $dynamicMuV * 0.5) + (self::SVJJ_P_DOWN * $dynamicMuV);
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

        // Enforce bounds to prevent flatlining in extreme bull markets and runaway chaos in crashes
        $nextVolatility = max(self::MIN_VOLATILITY, min(self::MAX_VOLATILITY, $nextVolatility));

        $fundamentalState = $this->evaluateFundamentalState(
            $currentPrice,
            $outputGap,
            $inflation,
            $beta,
            $liveWacc,
            $reversionSpeed,
            $earningsPerShare,
            $currentRoic,
            $roicTtm,
            $fcfPerShare,
            $riskFreeRate,
            $bookValuePerShare,
            $dividendPerShare,
            $baselineIndustryPE,
            $revenuePerShare,
            $businessModel,
            $liveCostOfEquity
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
            'analyst_targets'   => $fundamentalState['analyst_targets'],
            'perceived_fair_value' => $perceivedFairValue
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
     * @param float $currentPrice        The current market price of the stock.
     * @param float $outputGap           The macroeconomic output gap (boom vs bust).
     * @param float $inflation           The current inflation rate.
     * @param float $beta                The stock's sensitivity to systemic market moves.
     * @param float $liveWacc            The true dynamic Weighted Average Cost of Capital.
     * @param float $reversionSpeed      The baseline speed at which the stock reverts to fair value.
     * @param float $earningsPerShare    The current EPS (Earnings Per Share).
     * @param float $currentRoic         The current Return on Invested Capital (or ROE for banks).
     * @param float $roicTtm             The Trailing Twelve Month ROIC (or ROE).
     * @param float|null $fcfPerShare    The Free Cash Flow per share (null for banks).
     * @param float $riskFreeRate        The central bank's policy rate.
     * @param float $bookValuePerShare   The equity value per share.
     * @param float $dividendPerShare    The absolute quarterly dividend per share.
     * @param float $baselineIndustryPE  The standard P/E multiple for this industry.
     * @param float $revenuePerShare     The total revenue per share.
     * @param bool  $isLeveragedIndustry True if the company is a bank or financial institution.
     * @param float $liveCostOfEquity    The Cost of Equity (CAPM) for financial institutions.
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
        float $roicTtm,
        ?float $fcfPerShare,
        float $riskFreeRate,
        float $bookValuePerShare,
        float $dividendPerShare,
        float $baselineIndustryPE = 20.0,
        float $revenuePerShare = 0.0,
        string $businessModel = 'none',
        float $liveCostOfEquity = 0.10
    ): array {

        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);
        $hurdleRate = $isFinancial ? $liveCostOfEquity : $liveWacc;

        // Structural ROIC is simply the TTM ROIC.
        $structuralRoic = $roicTtm;

        // MACROECONOMIC STRESS INDEX (MSI)
        $recessionStress = max(0.0, -$outputGap);
        $inflationStress = abs($inflation - 0.02);
        $systemicStressIndex = $recessionStress + $inflationStress;

        // 1. MACRO FORWARD GUIDANCE (Future Expectations)
        $rateModifier = pow(0.04 / max(0.01, $riskFreeRate), 0.5);

        // Markets are forward-looking. They expand multiples during economic booms (future growth)
        // and compress them during recessions and high inflation.
        $forwardGrowthPremium = $outputGap * 100.0; // e.g. +2% gap = +2.0 P/E
        $inflationDiscount = max(0.0, $inflation - 0.02) * -100.0; // e.g. 5% inflation = -3.0 P/E

        $macroBasePE = max(self::MIN_BASE_PE, min(self::MAX_BASE_PE, ($baselineIndustryPE * $rateModifier) + $forwardGrowthPremium + $inflationDiscount));

        // The EVA Premium (Quality Spread)
        $evaSpread = $structuralRoic - $hurdleRate;

        // Use a logarithmic curve for Quality Premium to prevent hyper-profitable Asset-Light companies 
        // (with 100%+ ROIC) from getting astronomical P/E multiples.
        $positiveSpread = max(0.0, $evaSpread * 100);
        $qualityPremium = $positiveSpread > 0 ? (log($positiveSpread + 1) * 4.0) : 0.0;
        $distressDiscount = min(0.0, $evaSpread * 100) * 2.0;

        $fairValuePE = max(self::MIN_FAIR_VALUE_PE, min(self::MAX_FAIR_VALUE_PE, $macroBasePE + $qualityPremium + $distressDiscount));

        if ($isFinancial) { // Use isFinancial
            // For Financials, Cash IS their operating inventory. Do not penalize them.
            $trueStructuralEps = $bookValuePerShare * $structuralRoic;
        } else {
            // Standard corporates: Operating Equity = Book Value - Cash
            $cashPerShare = max(0.0, $revenuePerShare > 0 ? ($revenuePerShare * 0.10) : 0.0);
            $operatingBookValue = max(0.01, $bookValuePerShare - $cashPerShare);

            $structuralOperatingEps = $operatingBookValue * $structuralRoic;
            $structuralCashYieldEps = $cashPerShare * $riskFreeRate;
            $trueStructuralEps = $structuralOperatingEps + $structuralCashYieldEps;
        }

        // 2. STRUCTURAL EPS SMOOTHING (Past Performance)
        // Real analysts value a company based on its established structural run-rate (past performance)
        // rather than overreacting to a single quarterly print.
        $safeStructuralEps = max(0.01, $trueStructuralEps);
        $deviation = abs($earningsPerShare - $safeStructuralEps) / $safeStructuralEps;

        // Trust in the single quarterly print decays exponentially the more it deviates from the structural norm.
        // Base weight on the single quarter is 25% (since it's 1 quarter out of 4 for an annual run-rate).
        // As deviation approaches 100%+, the weight decays towards ~9%, treating it as a pure anomaly.
        $recentEpsWeight = 0.25 * exp(-$deviation);
        $structuralWeight = 1.0 - $recentEpsWeight;

        $normalizedEps = ($earningsPerShare * $recentEpsWeight) + ($trueStructuralEps * $structuralWeight);

        $peFairValue = max(0.00, $normalizedEps * $fairValuePE);

        // THE ZOMBIE FIX: Revenue Floor
        $psMultiple = max(0.2, min(5.0, ($structuralRoic + 0.10) * 10));
        $revenueFloorValue = $revenuePerShare * $psMultiple;

        // THE BANKING DCF BYPASS
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        $earningsValue = $strategy->calculateEarningsValue($revenueFloorValue, $peFairValue, $fcfPerShare, $liveWacc, $this->mathUtility);

        // Dividend Yield Support (The Dividend Discount Model)
        $dividendSupportValue = 0.0;
        if ($dividendPerShare > 0.0) {
            // Use Normalized EPS to determine if dividend is structurally sustainable, 
            // rather than raw TTM cash flow which might be temporarily negative.
            $cashFlowProxy = $isFinancial ? $normalizedEps : max($fcfPerShare ?? 0.0, $normalizedEps);
            $sustainableDividend = min($dividendPerShare * 4.0, max(0.0, $cashFlowProxy));

            $assumedGrowth = 0.01;
            $requiredYield = max(0.02, $liveCostOfEquity);

            $dividendSupportValue = $this->mathUtility->calculateDividendDiscountModel(
                $sustainableDividend,
                $requiredYield,
                $assumedGrowth
            );
        }

        // Intrinsic Price-to-Book (P/B) Valuation
        // A living company rarely trades below 0.4x Book Value unless bankruptcy is imminent.
        $pbMultiple = max(0.40, min(10.0, $structuralRoic / max(0.01, $hurdleRate)));
        $pbFairValue = $bookValuePerShare * $pbMultiple;

        // PERFECTED WEIGHTED CONSENSUS MODEL
        $fairValue = $strategy->calculateFairValue($earningsValue, $pbFairValue, $normalizedEps);

        $perceivedFairValue = max(0.01, $fairValue, $dividendSupportValue);



        // OVERVALUATION (The Bubble Gravity)
        $valuationRatio = $currentPrice / $perceivedFairValue;
        $overvaluation = max(0.0, $valuationRatio - 1.0) * 0.5;
        $gravityCurve = ($overvaluation) + pow($overvaluation, 2.0);

        // UNDERVALUATION (Value Spring)
        $inverseRatio = $perceivedFairValue / max(0.01, $currentPrice);
        $undervaluation = max(0.0, $inverseRatio - 1.0) * 0.5;
        $springCurve = ($undervaluation) + pow($undervaluation, 2.0);

        // Smoothly scale macro resistance based on the output gap instead of a hard cliff.
        // Base resistance is 0.02. As the economy dips into recession, fear scales up linearly.
        $macroResistance = 0.02 + (max(0.0, -$outputGap) * 10.0);
        $bubbleGravity = $gravityCurve * $macroResistance;

        // Enthusiasm scales up in a booming economy, accelerating the spring
        $macroEnthusiasm = 0.02 + (max(0.0, $outputGap) * 10.0);
        $valueSpring = $springCurve * $macroEnthusiasm;


        // FLIGHT-TO-QUALITY REVERSION (Liquidity Drain)
        $dynamicReversion = $reversionSpeed * (1.0 + ($systemicStressIndex * 5.0));

        $dynamicReversion += min(15.0, $bubbleGravity); // Cap max panic reversion
        $dynamicReversion += min(15.0, $valueSpring);

        return [
            'perceived_fair_value' => $perceivedFairValue,
            'dynamic_reversion'    => $dynamicReversion,
            'analyst_targets'      => [
                'growth_analyst' => max(0.01, $earningsValue),
                'income_analyst' => max(0.01, $dividendSupportValue),
                'value_analyst'  => max(0.01, $pbFairValue * self::VALUE_ANALYST_BOOK_MULT),
            ]
        ];
    }
}
