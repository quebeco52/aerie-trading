<?php

namespace App\Service\Market;

use App\Service\Macro\MacroEngine;
use App\DTO\MacroStateDTO;
use App\Service\Math\FinancialConstants;
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
     * @param MacroStateDTO|null $macroState The current macroeconomic state, which can influence the base drift.
     * @param float $bookValuePerShare  The physical equity value per share.
     * @param float $totalDebt          The total debt on the balance sheet.
     * @param float $totalEquity        The total equity on the balance sheet.
     * @param float $creditSpread       The company's baseline credit spread (borrowing premium).
     * @param float $dividendPerShare   The absolute quarterly dividend per share.
     *
     * @return array{price: float, shock: float|null, next_volatility: float, analyst_targets: array, perceived_fair_value: float} The calculated next price, shock percentage, updated volatility, and analyst targets.
     */
    public function calculateNextPrice(\App\DTO\MarketPricingContext $ctx): array {
        $currentPrice = $ctx->currentPrice;
        $currentVolatility = $ctx->currentVolatility;
        $longTermVolatility = $ctx->longTermVolatility;
        $earningsPerShare = $ctx->earningsPerShare;
        $dt = $ctx->dt;
        $lambda = $ctx->lambda;
        $jump_vol = $ctx->jumpVol;
        $beta = $ctx->beta;
        $marketZ = $ctx->marketZ;
        $marketVol = $ctx->marketVol;
        $drift = $ctx->drift;
        $reversionSpeed = $ctx->reversionSpeed;
        $kappa = $ctx->kappa;
        $volOfVol = $ctx->volOfVol;
        $macroState = $ctx->macroState;
        $fcfPerShare = $ctx->fcfPerShare;
        $bookValuePerShare = $ctx->bookValuePerShare;
        $maShock = $ctx->maShock;
        $currentRoic = $ctx->currentRoic;
        $roicTtm = $ctx->roicTtm;
        $dividendPerShare = $ctx->dividendPerShare;
        $liveWacc = $ctx->liveWacc;
        $baselineIndustryPE = $ctx->baselineIndustryPE;
        $revenuePerShare = $ctx->revenuePerShare;
        $businessModel = $ctx->businessModel;
        $liveCostOfEquity = $ctx->liveCostOfEquity;

        // CAPM & MACRO TRANSMISSION MECHANISM

        $riskFreeRate = $macroState?->policyRate ?? 0.04;
        $outputGap = $macroState?->outputGap ?? 0.0;
        $inflation = $macroState?->inflation ?? 0.02;
        $erp = $macroState?->equityRiskPremium ?? MacroEngine::BASE_EQUITY_RISK_PREMIUM;

        $finalDrift = $this->mathUtility->calculateCAPM($riskFreeRate, $beta, $erp);

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
        if ($macroState !== null) {
            // Positive output gap (boom) reduces vol slightly, negative gap (bust) increases vol
            $cycleVolModifier = 1.0 - $macroState->outputGap;
        }

        // Dynamically scale variance reversion speed (kappa) during jump diffusion regimes
        // instead of linearly clamping theta, preventing artificial volatility suppression
        $dynamicKappa = $kappa * (1.0 + ($lambda * 0.15));
        $adjustedTheta = max(0.0001, $longTermVar * $cycleVolModifier);

        // Variance Process via Quadratic-Exponential (QE) Scheme
        $nextVar = $this->mathUtility->calculateQEVarianceStep(
            currentVar: $currentVar,
            theta: $adjustedTheta,
            kappa: $dynamicKappa,
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
            $liveCostOfEquity,
            $currentVolatility
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
        float $liveCostOfEquity = 0.10,
        float $currentVolatility = 0.20
    ): array {

        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);
        $hurdleRate = $isFinancial ? $liveCostOfEquity : $liveWacc;

        // Structural ROIC is simply the TTM ROIC.
        $structuralRoic = $roicTtm;

        // MACROECONOMIC STRESS INDEX (MSI)
        $recessionStress = max(0.0, -$outputGap);
        $inflationStress = abs($inflation - 0.02);
        $systemicStressIndex = $recessionStress + $inflationStress;

        // 1. MACRO FORWARD GUIDANCE & FUNDAMENTAL P/E
        // We use the Gordon Growth Model derivation for Fair Value P/E.
        // Expected perpetual growth rate is tied to inflation and beta-adjusted output gap, capped at 2.5% (long-run nominal GDP growth).
        $expectedGrowth = max(0.0, min(0.025, $inflation + ($outputGap * 0.5 * abs($beta))));

        $fairValuePE = $this->mathUtility->calculateIntrinsicFairValuePE($hurdleRate, $structuralRoic, $expectedGrowth);

        if ($isFinancial) { // Use isFinancial
            // For Financials, Cash IS their operating inventory. Do not penalize them.
            $trueStructuralEps = $bookValuePerShare * $structuralRoic;
        } else {
            // Standard corporates & special models: Operating Base per share is max(Revenue, Book Value)
            $operatingBasePerShare = max($revenuePerShare, $bookValuePerShare);

            // Delegate operating cash determination directly to the domain business model!
            $cashPerShare = $strategy->calculateTargetOperatingCash($operatingBasePerShare, 0.0, 0.0);
            $operatingBookValue = max(0.01, $bookValuePerShare - $cashPerShare);

            $structuralOperatingEps = $operatingBookValue * $structuralRoic;
            $structuralCashYieldEps = $cashPerShare * $riskFreeRate;
            $trueStructuralEps = $structuralOperatingEps + $structuralCashYieldEps;
        }

        // 2. STRUCTURAL EPS SMOOTHING (Past Performance via Kalman Filter)
        // Real analysts value a company based on its established structural run-rate.
        // We use a 1D Kalman Filter to optimally estimate the true EPS by weighing the structural prior 
        // against the noisy quarterly measurement.
        $safeStructuralEps = max(0.01, $trueStructuralEps);

        $normalizedEps = $this->mathUtility->calculateKalmanSmoothedEps(
            $safeStructuralEps,
            $earningsPerShare,
            $currentVolatility,
            $systemicStressIndex
        );

        $peFairValue = max(0.00, $normalizedEps * $fairValuePE);

        // THE ZOMBIE FIX: Revenue Floor (Margin-Adjusted Price-to-Sales)
        // High-turnover physical corporations (retail, grocers) have thin net margins and cannot support software-like P/S multiples.
        $impliedMargin = $revenuePerShare > 0.0
            ? max(0.01, min(0.30, abs($safeStructuralEps) / max(0.01, $revenuePerShare)))
            : 0.10;
        $psMultiple = max(
            FinancialConstants::MIN_PS_FALLBACK_MULT,
            min(FinancialConstants::MAX_PS_FALLBACK_MULT, max(10.0, $fairValuePE) * $impliedMargin)
        );
        $revenueFloorValue = $revenuePerShare * $psMultiple;

        // THE BANKING DCF BYPASS
        $earningsValue = $strategy->calculateEarningsValue($revenueFloorValue, $peFairValue, $fcfPerShare, $liveWacc, $this->mathUtility);

        // Dividend Yield Support (The Dividend Discount Model)
        $dividendSupportValue = 0.0;
        if ($dividendPerShare > 0.0) {
            // Forward annualized dividend run-rate discounted by Cost of Equity minus expected perpetual growth.
            // If normalized EPS or FCF is temporarily negative, statutory distributable surplus and cash buffers 
            // sustain the dividend unless structural solvency breaks.
            $sustainableDividend = $dividendPerShare * 4.0;

            $assumedGrowth = FinancialConstants::DEFAULT_DDM_GROWTH_RATE;
            $requiredYield = max(0.02, $liveCostOfEquity);

            $dividendSupportValue = $this->mathUtility->calculateDividendDiscountModel(
                $sustainableDividend,
                $requiredYield,
                $assumedGrowth
            );
        }

        // Intrinsic Price-to-Book (P/B) Valuation
        // A living company rarely trades below 0.4x Book Value unless bankruptcy is imminent.
        $pbMultiple = max(FinancialConstants::MIN_INTRINSIC_PB, min(FinancialConstants::MAX_INTRINSIC_PB, $structuralRoic / max(0.01, $hurdleRate)));
        $pbFairValue = $bookValuePerShare * $pbMultiple;

        // PERFECTED WEIGHTED CONSENSUS MODEL
        $fairValue = $strategy->calculateFairValue($earningsValue, $pbFairValue, $normalizedEps, $dividendSupportValue);

        $perceivedFairValue = max(0.01, $fairValue);

        // 1. ESTAR (Exponential Smooth Transition Autoregressive) Mean Reversion
        // Explains non-linear institutional arbitrage around a fundamental target (Taylor, Peel, & Sarno, 2001).
        // Within narrow valuation bands, transaction costs and noise-trader risk keep institutional arbitrage near zero.
        // As mispricing spreads widen, institutions enter aggressively, scaling reversion speed smoothly toward an upper asymptotic limit.
        $logValuationGap = abs(log($currentPrice / max(0.01, $perceivedFairValue)));
        $arbitrageElasticity = FinancialConstants::ESTAR_ARBITRAGE_ELASTICITY;
        $maxReversionCap = FinancialConstants::MAX_REVERSION_FORCE_CAP;

        $estarTransition = 1.0 - exp(-$arbitrageElasticity * ($logValuationGap * $logValuationGap));
        $effectiveReversion = $reversionSpeed + (($maxReversionCap - $reversionSpeed) * $estarTransition);

        // 2. Brunnermeier-Pedersen Funding Liquidity Dampener (2009)
        // During macroeconomic stress and systemic crises, funding liquidity dries up and capital-constrained 
        // arbitrageurs pull back, slowing down market efficiency and price correction speed.
        $liquidityDampener = 1.0 / (1.0 + ($systemicStressIndex * FinancialConstants::FUNDING_LIQUIDITY_STRESS_FACTOR));
        $dynamicReversion = $effectiveReversion * $liquidityDampener;

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
