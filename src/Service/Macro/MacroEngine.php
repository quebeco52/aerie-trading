<?php

namespace App\Service\Macro;

use Psr\Log\LoggerInterface;
use App\Service\Math\MathUtility;

class MacroEngine
{
    private const REDIS_MACRO_STATE = 'macroeconomic_state';

    public const TARGET_INFLATION = 0.02;
    public const NATURAL_RATE = 0.02;
    public const BASE_CORPORATE_TAX_RATE = 0.21;
    public const BASE_EQUITY_RISK_PREMIUM = 0.045;
    public const CASH_YIELD_SPREAD = 0.0025;

    public function __construct(
        private MathUtility $mathUtility,
        private LoggerInterface $logger,
        private \Redis $redis
    ) {}

    /**
     * Advances the macroeconomic state by one tick.
     * Calculates Inflation, Output Gap, Taylor Rule (Short Rate), and the Yield Curve.
     *
     * @return array{
     * inflation: float, output_gap: float, target_rate: float, policy_rate: float,
     * ns_level: float, ns_slope: float, ns_curvature: float, yield_10y: float
     * }
     */
    public function updateMacroState(float $dt): array
    {
        // Load Current State or Set Defaults
        $rawState = $this->redis->get(self::REDIS_MACRO_STATE);
        $state = $rawState ? json_decode($rawState, true) : [
            'inflation' => self::TARGET_INFLATION,
            'output_gap' => 0.02,
            'policy_rate' => 0.02,
            'inflation_ema' => self::TARGET_INFLATION,
            'output_gap_ema' => 0.02,
            'policy_rate_ema' => 0.02,
            'ns_slope_ema' => 0.00,
            'corporate_tax_rate' => self::BASE_CORPORATE_TAX_RATE,
            'nominal_gdp_index' => 1.0,
            'market_volatility' => 0.15,
            'market_volatility_ema' => 0.20,
        ];

        $targetInflation = self::TARGET_INFLATION;
        $naturalRate = self::NATURAL_RATE;

        // DYNAMIC VOLATILITY (Heteroskedasticity)
        $stressMultiplier = 1.0 + (abs($state['output_gap']) * 10.0);


        $targetRate = $this->calculateTargetRate($state, $targetInflation, $naturalRate);
        $state['policy_rate'] = $this->updatePolicyRate($state, $targetRate, $dt);

        $yieldData = $this->calculateYieldCurveAndQE($state, $targetInflation, $naturalRate);
        $yield2y = $yieldData['yield_2y'];
        $yield5y = $yieldData['yield_5y'];
        $yield10y = $yieldData['yield_10y'];
        $yield30y = $yieldData['yield_30y'];
        $currentNsSlope = $yield10y - $state['policy_rate'];

        $marketZ = $this->mathUtility->generateStandardNormal();

        $state['output_gap'] = $this->calculateOutputGap($state, $yield5y, $naturalRate, $stressMultiplier, $dt);
        $state['inflation'] = $this->calculateInflation($state, $targetInflation, $stressMultiplier, $dt);

        // Safely initialize if pulling from an older Redis cache payload
        $state['nominal_gdp_index'] = $state['nominal_gdp_index'] ?? 1.0;
        $state['potential_gdp_index'] = $state['potential_gdp_index'] ?? ($state['nominal_gdp_index'] / (1.0 + $state['output_gap']));
        $state['inflation_ema'] = $state['inflation_ema'] ?? $state['inflation'];
        $state['output_gap_ema'] = $state['output_gap_ema'] ?? $state['output_gap'];
        $state['policy_rate_ema'] = $state['policy_rate_ema'] ?? $state['policy_rate'];
        $state['ns_slope_ema'] = $state['ns_slope_ema'] ?? $currentNsSlope;
        $state['yield_2y_ema'] = $state['yield_2y_ema'] ?? $yield2y;
        $state['yield_5y_ema'] = $state['yield_5y_ema'] ?? $yield5y;
        $state['yield_10y_ema'] = $state['yield_10y_ema'] ?? $yield10y;
        $state['yield_30y_ema'] = $state['yield_30y_ema'] ?? $yield30y;
        $state['market_volatility'] = $this->calculateMarketVolatility($state, $dt);
        $state['market_volatility_ema'] = $state['market_volatility_ema'] ?? $state['market_volatility'];

        // 1. Calculate underlying Potential GDP (Smooth exponential growth over time)
        $nominalPotentialGrowth = $naturalRate + $state['inflation'];
        $state['potential_gdp_index'] = max(0.10, $state['potential_gdp_index'] * exp($nominalPotentialGrowth * $dt));

        // 2. ACTUAL Nominal GDP shrinks and expands with the business cycle!
        $state['nominal_gdp_index'] = $state['potential_gdp_index'] * (1.0 + $state['output_gap']);

        // A quarter is 0.25 years.
        // tick data into a rolling 3-month average.
        $emaWeight = min(1.0, $dt / 0.25);

        $state['output_gap_ema'] += $emaWeight * ($state['output_gap'] - $state['output_gap_ema']);
        $state['policy_rate_ema'] += $emaWeight * ($state['policy_rate'] - $state['policy_rate_ema']);
        $state['inflation_ema'] += $emaWeight * ($state['inflation'] - $state['inflation_ema']);
        $state['ns_slope_ema'] += $emaWeight * ($currentNsSlope - $state['ns_slope_ema']);
        $state['yield_2y_ema'] += $emaWeight * ($yield2y - $state['yield_2y_ema']);
        $state['yield_5y_ema'] += $emaWeight * ($yield5y - $state['yield_5y_ema']);
        $state['yield_10y_ema'] += $emaWeight * ($yield10y - $state['yield_10y_ema']);
        $state['yield_30y_ema'] += $emaWeight * ($yield30y - $state['yield_30y_ema']);
        $state['market_volatility_ema'] += $emaWeight * ($state['market_volatility'] - $state['market_volatility_ema']);



        // DYNAMIC FISCAL POLICY (Government Taxes)
        // Base tax rate is 21%. If the economy overheats, the government hikes taxes to cool it down.
        // If the economy enters a deep recession, they pass emergency tax cuts to stimulate corporate recovery.
        $fiscalPolicyTarget = self::BASE_CORPORATE_TAX_RATE + ($state['output_gap_ema'] * 1.0);
        $state['corporate_tax_rate'] = max(0.12, min(0.30, $fiscalPolicyTarget));

        // DYNAMIC EQUITY RISK PREMIUM (ERP)
        // During recessions, fearful investors demand a higher premium to hold risky stocks.
        $erp = self::BASE_EQUITY_RISK_PREMIUM;
        if ($state['output_gap_ema'] < 0.0) {
            $erp += abs($state['output_gap_ema']) * 0.5; // e.g., -4% gap adds 2.0% to ERP (6.5% total)
        } else {
            // Complacency: During booms, greedy investors accept lower risk premiums.
            $erp -= $state['output_gap_ema'] * 0.10;
        }

        $erp = max(0.02, $erp); // Floor at 2% to prevent WACC from collapsing completely

        $payload = [
            'inflation' => $state['inflation'],
            'inflation_ema' => $state['inflation_ema'],
            'output_gap' => $state['output_gap'],
            'output_gap_ema' => $state['output_gap_ema'],
            'target_rate' => $targetRate,
            'policy_rate' => $state['policy_rate'],
            'policy_rate_ema' => $state['policy_rate_ema'],
            'ns_level' => $yieldData['level'],
            // Export the traditional Wall Street definition: Long Rate minus Short Rate. (Negative = Inversion)
            'ns_slope' => $currentNsSlope,
            'ns_slope_ema' => $state['ns_slope_ema'],
            'ns_curvature' => $yieldData['curvature'],
            'yield_2y' => $yield2y,
            'yield_2y_ema' => $state['yield_2y_ema'],
            'yield_5y' => $yield5y,
            'yield_5y_ema' => $state['yield_5y_ema'],
            'yield_10y' => $yield10y,
            'yield_10y_ema' => $state['yield_10y_ema'],
            'yield_30y' => $yield30y,
            'yield_30y_ema' => $state['yield_30y_ema'],
            'qe_active' => $yieldData['qe_suppression'] > 0,
            'corporate_tax_rate' => $state['corporate_tax_rate'] ?? self::BASE_CORPORATE_TAX_RATE,
            'equity_risk_premium' => $erp,
            'potential_gdp_index' => $state['potential_gdp_index'],
            'nominal_gdp_index' => $state['nominal_gdp_index'],
            'market_volatility' => $state['market_volatility'],
            'market_volatility_ema' => $state['market_volatility_ema'],
            'market_z' => $marketZ
        ];

        //$this->logger->info('Macro Data', $payload);

        $this->redis->set(self::REDIS_MACRO_STATE, json_encode($payload));
        return $payload;
    }

    /**
     * Records a historical snapshot of the macroeconomic state directly to the database.
     *
     * @param array                     $macroState The current state to record.
     * @param \Doctrine\DBAL\Connection $conn       The active database connection.
     */
    public function recordMacroSnapshot(array $macroState, \Doctrine\DBAL\Connection $conn): void
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $conn->executeStatement(
            "INSERT INTO macro_report (recorded_at, inflation, inflation_ema, output_gap, output_gap_ema, policy_rate, policy_rate_ema, yield2y, yield2y_ema, yield5y, yield5y_ema, yield10y, yield10y_ema, yield30y, yield30y_ema, corporate_tax_rate, equity_risk_premium, nominal_gdp_index, market_volatility) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $now,
                $macroState['inflation'],
                $macroState['inflation_ema'] ?? $macroState['inflation'],
                $macroState['output_gap'],
                $macroState['output_gap_ema'] ?? $macroState['output_gap'],
                $macroState['policy_rate'],
                $macroState['policy_rate_ema'] ?? $macroState['policy_rate'],
                $macroState['yield_2y'],
                $macroState['yield_2y_ema'] ?? $macroState['yield_2y'],
                $macroState['yield_5y'],
                $macroState['yield_5y_ema'] ?? $macroState['yield_5y'],
                $macroState['yield_10y'],
                $macroState['yield_10y_ema'] ?? $macroState['yield_10y'],
                $macroState['yield_30y'],
                $macroState['yield_30y_ema'] ?? $macroState['yield_30y'],
                $macroState['corporate_tax_rate'] ?? self::BASE_CORPORATE_TAX_RATE,
                $macroState['equity_risk_premium'],
                $macroState['nominal_gdp_index'],
                $macroState['market_volatility']
            ]
        );
    }

    /**
     * Calculates the Central Bank's target policy rate using the Taylor Rule.
     * Modified for Modern Central Bank behavior (Trend Inflation & Asymmetric Boom Tolerance).
     *
     * @param array $state            The current macroeconomic state.
     * @param float $targetInflation  The central bank's inflation target.
     * @param float $naturalRate      The natural rate of interest (R-star).
     * @return float The target policy rate, bounded by the zero lower bound and a realistic ceiling.
     */
    private function calculateTargetRate(array $state, float $targetInflation, float $naturalRate): float
    {
        // 1. USE TREND INFLATION: Central banks ignore noisy spot data and target the EMA.
        $trendInflation = $state['inflation_ema'] ?? $state['inflation'];

        // 2. MODERN ASYMMETRIC GAP WEIGHT: 
        // - Deep Recession: Max panic, weight scales up to 1.0.
        // - Mild Recession: Standard 0.5 weight.
        // - Booming Economy: Weight drops to 0.15. The Central Bank "lets it run hot" 
        //   and only hikes if inflation actually materializes.
        if ($state['output_gap'] < 0.0) {
            $gapWeight = 0.5 + min(0.5, abs($state['output_gap']) * 10.0);
        } else {
            $gapWeight = 0.15; // Benign neglect during a boom
        }

        // The Modern Taylor Rule
        $targetRate = $naturalRate + $trendInflation
            + 0.5 * ($trendInflation - $targetInflation)
            + $gapWeight * ($state['output_gap']);

        // Floor at ZLB, Cap at 15% (preventing extreme mathematical singularities)
        return max(0.00, min(0.15, $targetRate));
    }

    /**
     * Smoothly transitions the current policy rate toward the target rate.
     * Modified for Asymmetric Central Bank Behavior ("Stairs up, Elevator down").
     *
     * @param array $state      The current macroeconomic state.
     * @param float $targetRate The desired target policy rate.
     * @param float $dt         The time step for the simulation (in years).
     * @return float The updated policy rate for the current tick.
     */
    private function updatePolicyRate(array $state, float $targetRate, float $dt): float
    {
        $currentPolicyRate = $state['policy_rate'];

        // Default Speed: Slower base adjustment
        $cbSpeed = 1.0;

        if ($targetRate > $currentPolicyRate) {
            // THE INFLATION PANIC: "Taking the stairs up"
            $inflationExcess = max(0.0, $state['inflation'] - 0.02);
            $cbSpeed += min(4.0, $inflationExcess * 50.0);
        } else {
            // THE RECESSION/DEFLATION PANIC: "Taking the elevator down"
            // Panic triggers when inflation drops below the 2% target, not just below zero.
            $deflationPanic = max(0.0, 0.02 - $state['inflation']) * 50.0;
            $recessionPanic = max(0.0, -$state['output_gap']) * 100.0;

            $cbSpeed += min(10.0, $deflationPanic + $recessionPanic);
        }

        // ASYMMETRIC SPEED LIMIT: 
        // Hikes capped at 5.0% per year (Allows for 2022-style emergency tightening).
        // Cuts capped at 10.0% per year (Allows for 2008-style immediate liquidity).
        $rawMove = $cbSpeed * ($targetRate - $currentPolicyRate);
        $clampedMove = max(-0.10, min(0.05, $rawMove));

        $newRate = $currentPolicyRate + $clampedMove * $dt;

        if ($targetRate > $currentPolicyRate) {
            return min($targetRate, $newRate); // Don't hike past the target
        } else {
            return max($targetRate, $newRate); // Don't cut past the target
        }
    }

    /**
     * Calculates the Yield Curve (Nelson-Siegel) and applies Quantitative Easing (QE) suppression.
     *
     * Models the 10-year yield based on level, slope, and curvature. If at the Zero Lower Bound
     * during a recession, artificially suppresses long-term yields via QE.
     *
     * @param array $state            The current macroeconomic state.
     * @param float $targetInflation  The inflation target.
     * @param float $naturalRate      The natural rate of interest.
     * @return array{level: float, curvature: float, qe_suppression: float, yield_10y: float}
     */
    private function calculateYieldCurveAndQE(array $state, float $targetInflation, float $naturalRate): array
    {

        // QUANTITATIVE EASING (QE) YIELD SUPPRESSION
        // Smoothly scale QE as rates approach the Zero Lower Bound (ZLB) 
        // and the recession deepens.
        $zlbProximity = min(1.0, max(0.0, (0.015 - $state['policy_rate']) / 0.015)); // 1.0 at <=0% rate, 0.0 at >=1.5% rate
        $recessionSeverity = max(0.0, -$state['output_gap']);

        $qeYieldSuppression = min(0.02, $zlbProximity * $recessionSeverity * 0.5);

        // THE NELSON-SIEGEL CURVE
        // Long-term yields are anchored to natural rates + expected long-term inflation.
        $expectedInflation = $state['inflation_ema'] ?? $state['inflation'];

        // PURE LEVEL (Beta 0): Anchored strictly to secular macroeconomic factors.
        $level = $naturalRate + (0.5 * $targetInflation) + (0.5 * $expectedInflation);

        // PURE SLOPE (Beta 1): Constrained perfectly to pin the short-end to the Central Bank's policy rate.
        // Because the policy rate reacts to the Output Gap (via the Taylor Rule), the cyclical economic 
        // business cycle is inherently and perfectly captured within this slope parameter.
        $nsBeta1 = $state['policy_rate'] - $level;

        // Beta 2 (The medium-term curvature/hump). Cap the extreme flattening at -1%
        $nsBeta2 = max(-0.01, 0.015 + ($state['output_gap'] * 0.25));

        // Yield Generator: Calculates the exact yield for any maturity $t
        $calculateYield = function (float $t) use ($level, $nsBeta1, $nsBeta2, $state, $qeYieldSuppression) {
            // Finance Physics: Risk scales by the square root of time, not linearly.
            // Normalized to 10.0 so the 10Y bond behaves exactly at the 1.0x baseline.
            // (2Y = 0.44x, 5Y = 0.70x, 10Y = 1.00x, 30Y = 1.73x)
            $timeScale = sqrt($t / 10.0);

            // Term Premium COMPRESSES during economic stress (Flight to Safety) 
            // and EXPANDS during a booming, inflationary economy.
            $termPremium = (0.015 * $timeScale) + ($state['output_gap'] * 0.15 * $timeScale);

            // QE suppression is heaviest at the 10Y mark. It does not scale up infinitely for the 30Y bond.
            $qeTimeScale = min(1.0, $timeScale);
            $qeTargetedSuppression = $qeYieldSuppression * $qeTimeScale;

            $pureYield = $this->mathUtility->calculateNelsonSiegelYield($level, $nsBeta1, $nsBeta2, $t);
            return $pureYield + $termPremium - $qeTargetedSuppression;
        };

        $yield2y  = $calculateYield(2.0);
        $yield5y  = $calculateYield(5.0);
        $yield10y = $calculateYield(10.0);
        $yield30y = $calculateYield(30.0);


        return [
            'level' => $level,
            'curvature' => $nsBeta2,
            'qe_suppression' => $qeYieldSuppression,
            'yield_2y' => max(0.00, $yield2y),
            'yield_5y' => max(0.00, $yield5y),
            'yield_10y' => max(0.00, $yield10y),
            'yield_30y' => max(0.00, $yield30y)

        ];
    }

    /**
     * Calculates the next step of the Output Gap using the Kaldor-Kalecki non-linear IS Curve.
     * Generates endogenous business cycles using a van der Pol limit-cycle oscillator.
     *
     * @param array $state            The current macroeconomic state.
     * @param float $yield5y          The 5-year bond yield (borrowing cost basis).
     * @param float $naturalRate      The natural rate of interest.
     * @param float $stressMultiplier The dynamic volatility multiplier.
     * @param float $dt               The time step (in years).
     * @return float The updated output gap.
     */
    private function calculateOutputGap(array $state, float $yield5y, float $naturalRate, float $stressMultiplier, float $dt): float
    {
        $y = $state['output_gap'];
        $outZ = $this->mathUtility->generateStandardNormal();

        $borrowingCost = (0.70 * $state['policy_rate']) + (0.30 * $yield5y);
        $realRate = $borrowingCost - $state['inflation'];

        // THE KALDOR-KALECKI PARAMETERS
        $alpha = 0.20;  // Momentum coefficient (allows the economy to build steam)
        $beta = 150.0;  // Cubic capacity constraint (The Rubber Band)
        $gamma = 1.5;   // Sensitivity to Central Bank real rates

        // Momentum (Linear Accelerator)
        $momentum = $alpha * $y;

        // The Cubic Constraint (Prevents infinite expansion/depression)
        $cubicConstraint = $beta * pow($y, 3);

        // Monetary Drag (Central Bank slowing down or speeding up the economy)
        $monetaryDrag = $gamma * ($realRate - $naturalRate);

        // Calculate the deterministic drift (dy/dt)
        $drift = ($momentum - $cubicConstraint - $monetaryDrag) * $dt;

        // Stochastic Volatility (dW_t)
        $volatility = 0.010 * $stressMultiplier * sqrt($dt) * $outZ;

        // Apply the Kaldor-Kalecki SDE step
        $newGap = $y + $drift + $volatility;

        // Failsafe bounds (the cubic constraint does the heavy lifting naturally, 
        // but hard-caps prevent extreme random float math anomalies during massive shocks)
        return max(-0.12, min(0.10, $newGap));
    }

    /**
     * Calculates the next step of Inflation using the Phillips Curve model.
     *
     * @param array $state            The current macroeconomic state.
     * @param float $targetInflation  The inflation target.
     * @param float $stressMultiplier The dynamic volatility multiplier.
     * @param float $dt               The time step (in years).
     * @return float The updated inflation rate.
     */
    private function calculateInflation(array $state, float $targetInflation, float $stressMultiplier, float $dt): float
    {
        $infZ = $this->mathUtility->generateStandardNormal();
        $infDiff = $targetInflation - $state['inflation'];
        $inflationDrift = 0.5 * $infDiff * $dt;

        // NON-LINEAR PHILLIPS CURVE (Capacity Bottlenecks)
        $phillipsSlope = $state['output_gap'] * 0.50; // Dampened linear slope

        // If the economy is booming, we add a quadratic acceleration.
        if ($state['output_gap'] > 0.0) {
            $phillipsSlope += 0.5 * pow($state['output_gap'], 2); // Dampened capacity bottleneck
        }

        $phillipsEffect = $phillipsSlope * $dt;

        $newInflation = $state['inflation'] + $inflationDrift + $phillipsEffect + (0.005 * $stressMultiplier * sqrt($dt) * $infZ);
        return max(-0.02, min(0.25, $newInflation));
    }

    /**
     * Updates the overarching market volatility (The District VIX).
     *
     * Applies the advanced Quadratic-Exponential (QE) scheme for the variance process,
     * along with the SVJJ Kou double-exponential jump mechanism to simulate 
     * mathematically rigorous market-wide panics.
     *
     * @param array $state The current macro state (inflation, output gap, etc).
     * @param float $dt    The time step delta.
     * @return float The updated market volatility.
     */
    private function calculateMarketVolatility(array $state, float $dt): float
    {
        $currentMarketVol = $state['market_volatility'] ?? 0.20;

        $longTermVol = 0.20;

        $currentVar = $currentMarketVol * $currentMarketVol;
        $longTermVar = $longTermVol * $longTermVol;

        $jumpData = $this->mathUtility->calculateSVJJJumps(
            lambda: 0.80,
            pUp: 0.10,
            etaUp: 10.0,
            etaDown: 5.0,  // Very fat left tail for deep macroeconomic panics
            muV: 0.05,     // Base variance jump size
            dt: $dt
        );

        $expectedVarJump = (0.10 * 0.05 * 0.5) + (0.90 * 0.05);
        $jumpVarianceDrag = (0.80 * $expectedVarJump) / 3.0;
        $adjustedTheta = max(0.0001, $longTermVar - $jumpVarianceDrag);

        // Reduced Kappa (reversion speed) from 6.0 to 2.0 so market panics persist realistically for 3-6 months
        $nextVar = $this->mathUtility->calculateQEVarianceStep($currentVar, $adjustedTheta, 3.0, 0.30, $dt);
        $nextVar += $jumpData['var_jump'];

        return max(0.08, min(0.80, sqrt($nextVar)));
    }
}
