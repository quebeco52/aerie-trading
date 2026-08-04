<?php

namespace App\Service\Macro;

use Psr\Log\LoggerInterface;
use App\Service\Math\MathUtility;


class MacroEngine
{
    public const REDIS_MACRO_STATE = 'macroeconomic_state';

    public const TARGET_INFLATION = 0.02;
    public const NATURAL_RATE = 0.0125;
    public const BASE_CORPORATE_TAX_RATE = 0.21;
    public const BASE_EQUITY_RISK_PREMIUM = 0.045;
    public const HABIT_RISK_AVERSION_COEFF = 4.0; // Campbell-Cochrane (1999) habit formation risk aversion sensitivity
    public const MIN_EQUITY_RISK_PREMIUM = 0.02;  // Structural floor: equities must yield more than risk-free T-bills
    public const CASH_YIELD_SPREAD = 0.0025;

    // KALDOR-KALECKI CONSTANTS
    public const KALDOR_MOMENTUM = 0.20;
    public const KALDOR_CAPACITY = 200.0;
    public const KALDOR_MONETARY_DRAG = 1.0;
    public const KALDOR_FISCAL_MULTIPLIER = 0.50;
    public const OUTPUT_GAP_DIFFUSION_SIGMA = 0.010;

    // OKUN'S LAW (LABOR MARKET)
    public const NATURAL_UNEMPLOYMENT = 0.04;
    public const OKUNS_COEFFICIENT = 0.4;
    public const OKUNS_HIRING_SPEED = 1.0;
    public const OKUNS_FIRING_SPEED = 4.0;



    // ENERGY SHOCK JUMP DIFFUSION
    public const ENERGY_JUMP_PROBABILITY = 0.05; // 5% chance of severe shock per year
    public const ENERGY_MEAN_REVERSION = 0.8;    // Speed of reversion to 100 baseline
    public const ENERGY_VOLATILITY = 0.25;       // Log-price volatility (Schwartz 1-factor sigma)
    public const ENERGY_JUMP_MEAN = 0.20;        // Mean log-return of energy shock (20% avg spike)
    public const ENERGY_JUMP_VOL = 0.10;         // Volatility of the jump size
    public const ENERGY_COST_PUSH_TRANSMISSION = 0.025;

    // --- GARCH-MIDAS Macroeconomic Volatility Constants (Engle, Ghysels, & Sohn 2013 Eq. 5) ---
    /** Long-run equilibrium baseline volatility (~15% VIX) during neutral economic conditions. */
    public const MACRO_VOL_BASE_ANCHOR            = 0.15;
    /** Sensitivity of exponential baseline volatility to output gap fluctuations (countercyclical). */
    public const MACRO_VOL_OUTPUT_GAP_SENSITIVITY = 10.0;
    /** Sensitivity of exponential baseline volatility to corporate credit spread deviations from baseline. */
    public const MACRO_VOL_CREDIT_SENSITIVITY     = 10.0;
    /** Sensitivity of exponential baseline volatility to yield curve slope (flattening/inversion increases vol). */
    public const MACRO_VOL_SLOPE_SENSITIVITY      = 8.0;
    /** Lower clamp for baseline volatility during extreme Goldilocks expansions (~10% VIX floor). */
    public const MACRO_VOL_MIN_BASELINE           = 0.10;
    /** Upper clamp for macro-driven baseline volatility to prevent infinite variance explosion. */
    public const MACRO_VOL_MAX_BASELINE           = 0.45;
    public const MACRO_VOL_KAPPA                  = 2.0;
    public const MACRO_VOL_SIGMA                  = 0.30;

    // SVJJ JUMP DIFFUSION CONSTANTS
    public const SVJJ_LAMBDA = 0.80;
    public const SVJJ_P_UP = 0.35;
    public const SVJJ_ETA_UP = 10.0;
    public const SVJJ_ETA_DOWN = 5.0;
    public const SVJJ_MU_V = 0.05;



    // YIELD WEIGHTS
    public const BORROWING_POLICY_WEIGHT = 0.70;
    public const BORROWING_YIELD5Y_WEIGHT = 0.30;

    // TAYLOR RULE & MONETARY POLICY CONSTANTS
    public const TAYLOR_INFLATION_WEIGHT = 0.50;
    public const TAYLOR_BOOM_WEIGHT = 0.50;
    public const TAYLOR_RECESSION_SCALE = 5.0;
    public const CB_SMOOTHING_SPEED = 1.0;
    public const CB_INFLATION_PANIC_SCALE = 50.0;
    public const CB_RECESSION_PANIC_SCALE = 100.0;
    public const CB_MAX_HIKE_PANIC_SPEED = 3.0; // Volcker-style inflation panic speed cap (enforces Taylor Principle during stagflation)
    public const CB_MAX_CUT_PANIC_SPEED = 10.0; // Emergency crisis cut speed cap (financial crises crash faster than booms build)
    public const ZLB_PROXIMITY_THRESHOLD = 0.015;

    // NELSON-SIEGEL TERM PREMIUM CONSTANTS
    public const NS_BASE_TERM_PREMIUM = 0.0225;
    public const NS_GAP_TERM_PREMIUM_SCALE = 0.15;

    // NEW KEYNESIAN PHILLIPS CURVE CONSTANTS
    public const PHILLIPS_SLOPE = 0.15;
    public const PHILLIPS_BOTTLENECK_COEFF = 0.25;
    public const INFLATION_MEAN_REVERSION = 0.50;

    // MERTON STRUCTURAL CREDIT SPREAD CONSTANTS (Merton 1974)
    public const BASE_CREDIT_SPREAD = 0.020;        // 200 bps normal corporate spread
    public const MERTON_LEVERAGE_SENSITIVITY = 6.0; // Sensitivity of default risk to GDP contractions
    public const MERTON_VOL_SENSITIVITY = 1.50;     // Sensitivity of default spreads to excess market volatility
    public const MAX_CREDIT_SPREAD = 0.10;          // 1000 bps crisis spread cap
    public const CREDIT_SPREAD_EXCESS_VOL_THRESHOLD = 0.20;

    // BARRO TAX-SMOOTHING & FISCAL STABILIZER CONSTANTS (Barro 1979)
    public const TARGET_CORPORATE_TAX_RATE = 0.20;     // 20% structural baseline corporate tax rate
    public const FISCAL_STABILIZER_SENSITIVITY = 1.0;  // Countercyclical tax response to output gap
    public const FISCAL_ADJUSTMENT_SPEED = 0.15;        // Institutional speed of tax legislation (~4-5 yr half-life)
    public const MIN_CORPORATE_TAX_RATE = 0.12;        // 12% statutory tax floor during deep recessions
    public const MAX_CORPORATE_TAX_RATE = 0.30;        // 30% statutory tax cap during overheating booms

    public function __construct(
        private MathUtility $mathUtility,
        private LoggerInterface $logger,
        private \Redis $redis
    ) {}

    public function getLiveState(): MacroState
    {
        $rawState = $this->redis->get(self::REDIS_MACRO_STATE);
        return $rawState ? MacroState::fromArray(json_decode($rawState, true)) : new MacroState();
    }

    /**
     * Advances the macroeconomic state by one tick.
     * Calculates Inflation, Output Gap, Taylor Rule (Short Rate), and the Yield Curve.
     */
    public function updateMacroState(float $dt): \App\DTO\MacroStateDTO
    {
        $rawState = $this->redis->get(self::REDIS_MACRO_STATE);
        $state = $rawState ? MacroState::fromArray(json_decode($rawState, true)) : new MacroState();

        $state->targetRate = $this->calculateTargetRate($state, self::TARGET_INFLATION, self::NATURAL_RATE);
        $state->policyRate = $this->updatePolicyRate($state, $state->targetRate, $dt);

        $yieldData = $this->calculateYieldCurveAndQE($state, self::TARGET_INFLATION, self::NATURAL_RATE, $dt);

        $state->yield2y = $yieldData['yield_2y'];
        $state->yield5y = $yieldData['yield_5y'];
        $state->yield10y = $yieldData['yield_10y'];
        $state->yield30y = $yieldData['yield_30y'];
        $state->nsLevel = $yieldData['level'];
        $state->nsCurvature = $yieldData['curvature'];
        $state->qeActive = $yieldData['qe_suppression'] > 0.001;

        $state->nsSlope = $state->yield10y - $state->policyRate;
        $state->structuralSlope = $yieldData['structural_10y'] - $state->policyRate;

        if ($state->structuralSlope < 0.0) {
            $state->inversionDuration += $dt;
        } else {
            $state->inversionDuration = 0.0;
        }

        $state->marketZ = $this->mathUtility->generateStandardNormal();

        $stressMultiplier = 1.0 + (abs($state->outputGap) * 10.0);
        $state->outputGap = $this->calculateOutputGap($state, $state->yield5y, self::NATURAL_RATE, $dt, $stressMultiplier);

        $this->calculateUnemployment($state, $dt);
        $this->calculateEnergyShock($state, $dt);

        $state->inflation = $this->calculateInflation($state, self::TARGET_INFLATION, $stressMultiplier, $dt);
        $state->marketVolatility = $this->calculateMarketVolatility($state, $dt);

        $this->updateExponentialMovingAverages($state, $dt);
        $this->calculateMacroCreditSpread($state);

        $this->calculatePotentialAndNominalGdp($state, self::NATURAL_RATE, $dt);
        $this->calculateDynamicFiscalPolicy($state, $dt);
        $this->calculateEquityRiskPremium($state);


        $payload = $state->toArray();
        $this->redis->set(self::REDIS_MACRO_STATE, json_encode($payload));
        return \App\DTO\MacroStateDTO::fromMacroState($state);
    }

    public function recordMacroSnapshot(\App\DTO\MacroStateDTO $macroState, \Doctrine\DBAL\Connection $conn): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $conn->executeStatement(
            "INSERT INTO macro_report (recorded_at, inflation, inflation_ema, output_gap, output_gap_ema, policy_rate, policy_rate_ema, yield2y, yield2y_ema, yield5y, yield5y_ema, yield10y, yield10y_ema, yield30y, yield30y_ema, corporate_tax_rate, equity_risk_premium, nominal_gdp_index, market_volatility, macro_credit_spread, macro_credit_spread_ema, unemployment_rate, unemployment_rate_ema, energy_price_index) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $now,
                $macroState->inflation,
                $macroState->inflationEma,
                $macroState->outputGap,
                $macroState->outputGapEma,
                $macroState->policyRate,
                $macroState->policyRateEma,
                $macroState->yield2y,
                $macroState->yield2yEma,
                $macroState->yield5y,
                $macroState->yield5yEma,
                $macroState->yield10y,
                $macroState->yield10yEma,
                $macroState->yield30y,
                $macroState->yield30yEma,
                $macroState->corporateTaxRate,
                $macroState->equityRiskPremium,
                $macroState->nominalGdpIndex,
                $macroState->marketVolatility,
                $macroState->macroCreditSpread,
                $macroState->macroCreditSpreadEma,
                $macroState->unemploymentRate,
                $macroState->unemploymentRateEma,
                $macroState->energyPriceIndex,
            ]
        );
    }

    private function calculateTargetRate(MacroState $state, float $targetInflation, float $naturalRate): float
    {
        $trendInflation = $state->inflationEma;

        if ($state->outputGap < 0.0) {
            $gapWeight = self::TAYLOR_INFLATION_WEIGHT + min(self::TAYLOR_INFLATION_WEIGHT, abs($state->outputGap) * self::TAYLOR_RECESSION_SCALE);
        } else {
            $gapWeight = self::TAYLOR_BOOM_WEIGHT; // Benign neglect during a boom
        }

        $targetRate = $naturalRate + $trendInflation
            + self::TAYLOR_INFLATION_WEIGHT * ($trendInflation - $targetInflation)
            + $gapWeight * ($state->outputGap);

        return max(0.00, min(0.20, $targetRate));
    }

    private function updatePolicyRate(MacroState $state, float $targetRate, float $dt): float
    {
        $currentPolicyRate = $state->policyRate;
        $cbSpeed = self::CB_SMOOTHING_SPEED;

        if ($targetRate > $currentPolicyRate) {
            $inflationExcess = max(0.0, $state->inflation - self::TARGET_INFLATION);
            $cbSpeed += min(self::CB_MAX_HIKE_PANIC_SPEED, $inflationExcess * self::CB_INFLATION_PANIC_SCALE);
        } else {
            $deflationPanic = max(0.0, self::TARGET_INFLATION - $state->inflation) * self::CB_INFLATION_PANIC_SCALE;
            $recessionPanic = max(0.0, -$state->outputGap) * self::CB_RECESSION_PANIC_SCALE;
            $cbSpeed += min(self::CB_MAX_CUT_PANIC_SPEED, $deflationPanic + $recessionPanic);
        }

        $rawMove = $cbSpeed * ($targetRate - $currentPolicyRate);
        $clampedMove = max(-0.06, min(0.06, $rawMove)); // Tightened max annual velocity to -600 bps to +600 bps/year

        $newRate = $currentPolicyRate + $clampedMove * $dt;
        $newRate = max(0.00, min(0.20, $newRate)); // Explicit bounds

        if ($targetRate > $currentPolicyRate) {
            return min($targetRate, $newRate);
        } else {
            return max($targetRate, $newRate);
        }
    }

    private function calculateYieldCurveAndQE(MacroState $state, float $targetInflation, float $naturalRate, float $dt): array
    {
        $zlbProximity = min(1.0, max(0.0, (self::ZLB_PROXIMITY_THRESHOLD - $state->policyRate) / self::ZLB_PROXIMITY_THRESHOLD));
        $recessionSeverity = max(0.0, -$state->outputGap);

        if ($zlbProximity > 0.90 && $state->outputGap < -0.02) {
            $qeYieldSuppressionTarget = min(0.02, $zlbProximity * $recessionSeverity * 0.5);
        } else {
            $qeYieldSuppressionTarget = 0.0;
        }

        // QE Intensity smoothly ramps toward target
        $qeSpeed = 1.0;
        $state->qeIntensity += $qeSpeed * ($qeYieldSuppressionTarget - $state->qeIntensity) * $dt;

        $expectedInflation = $state->inflationEma;
        $level = $naturalRate + (0.5 * $targetInflation) + (0.5 * $expectedInflation);
        $nsBeta1 = $state->policyRate - $level;
        $nsBeta2 = max(-0.01, 0.015 + ($state->outputGapEma * 0.25));

        $yield2y  = $this->calculateNelsonSiegelTenor(2.0, $level, $nsBeta1, $nsBeta2, $state, $state->qeIntensity);
        $yield5y  = $this->calculateNelsonSiegelTenor(5.0, $level, $nsBeta1, $nsBeta2, $state, $state->qeIntensity);
        $yield10y = $this->calculateNelsonSiegelTenor(10.0, $level, $nsBeta1, $nsBeta2, $state, $state->qeIntensity);
        $yield30y = $this->calculateNelsonSiegelTenor(30.0, $level, $nsBeta1, $nsBeta2, $state, $state->qeIntensity);

        return [
            'level' => $level,
            'curvature' => $nsBeta2,
            'qe_suppression' => $state->qeIntensity,
            'structural_10y' => max(0.00, $yield10y + $state->qeIntensity),
            'yield_2y' => max(0.00, $yield2y),
            'yield_5y' => max(0.00, $yield5y),
            'yield_10y' => max(0.00, $yield10y),
            'yield_30y' => max(0.00, $yield30y)
        ];
    }

    private function calculateNelsonSiegelTenor(float $t, float $level, float $nsBeta1, float $nsBeta2, MacroState $state, float $qeYieldSuppression): float
    {
        $timeScale = ($t / 10.0);
        $termPremium = (self::NS_BASE_TERM_PREMIUM * $timeScale) + ($state->outputGapEma * self::NS_GAP_TERM_PREMIUM_SCALE * $timeScale);

        $qeTimeScale = min(1.0, $timeScale);
        $qeTargetedSuppression = $qeYieldSuppression * $qeTimeScale;

        $pureYield = $this->mathUtility->calculateNelsonSiegelYield($level, $nsBeta1, $nsBeta2, $t);
        return $pureYield + $termPremium - $qeTargetedSuppression;
    }

    private function calculateOutputGap(MacroState $state, float $yield5y, float $naturalRate, float $dt, float $stressMultiplier): float
    {
        $y = $state->outputGap;
        $outZ = $this->mathUtility->generateStandardNormal();

        $borrowingCost = (self::BORROWING_POLICY_WEIGHT * $state->policyRate) + (self::BORROWING_YIELD5Y_WEIGHT * $yield5y);
        $realRate = $borrowingCost - $state->inflation;

        $momentum = self::KALDOR_MOMENTUM * $y;
        $cubicConstraint = self::KALDOR_CAPACITY * pow($y, 3);
        $monetaryDrag = self::KALDOR_MONETARY_DRAG * ($realRate - $naturalRate);

        // Fiscal stimulus: Tax cuts below the target rate boost aggregate demand.
        $fiscalStimulus = self::KALDOR_FISCAL_MULTIPLIER * (self::TARGET_CORPORATE_TAX_RATE - $state->corporateTaxRate);

        // QE automatically lowers monetary drag through the reduced $yield5y in the borrowing cost calculation.
        $drift = ($momentum - $cubicConstraint - $monetaryDrag + $fiscalStimulus) * $dt;
        $volatility = self::OUTPUT_GAP_DIFFUSION_SIGMA * $stressMultiplier * sqrt($dt) * $outZ;

        $newGap = $y + $drift + $volatility;

        return max(-0.12, min(0.10, $newGap));
    }

    private function calculateInflation(MacroState $state, float $targetInflation, float $stressMultiplier, float $dt): float
    {
        $infZ = $this->mathUtility->generateStandardNormal();
        // Inflation expectations are fully anchored. Revert structurally toward the target rate.
        $inflationDrift = self::INFLATION_MEAN_REVERSION * ($targetInflation - $state->inflation) * $dt;

        $phillipsSlope = $state->outputGap * self::PHILLIPS_SLOPE;

        if ($state->outputGap > 0.0) {
            $phillipsSlope += self::PHILLIPS_BOTTLENECK_COEFF * pow($state->outputGap, 2);
        }

        // Add energy cost-push inflation
        $energyCostPush = ($state->energyPriceShock / 100.0) * self::ENERGY_COST_PUSH_TRANSMISSION; // Moderated transmission of energy shock

        $phillipsEffect = ($phillipsSlope + $energyCostPush) * $dt;

        $newInflation = $state->inflation + $inflationDrift + $phillipsEffect + (0.005 * $stressMultiplier * sqrt($dt) * $infZ);
        return max(-0.02, min(0.25, $newInflation));
    }

    private function calculateMarketVolatility(MacroState $state, float $dt): float
    {
        $currentMarketVol = $state->marketVolatility;

        // Continuous exponential macroeconomic link (Engle, Ghysels, & Sohn 2013 Eq. 5):
        // Long-run volatility smoothly scales across all economic states without piecewise kinks.
        $spreadDeviation = max(0.0, $state->macroCreditSpread - self::BASE_CREDIT_SPREAD);
        $macroDriver = (-$state->outputGap * self::MACRO_VOL_OUTPUT_GAP_SENSITIVITY)
            + ($spreadDeviation * self::MACRO_VOL_CREDIT_SENSITIVITY)
            + (-min(0.0, $state->structuralSlope) * self::MACRO_VOL_SLOPE_SENSITIVITY); // Only penalize true inversions

        $longTermVol = min(
            self::MACRO_VOL_MAX_BASELINE,
            max(self::MACRO_VOL_MIN_BASELINE, self::MACRO_VOL_BASE_ANCHOR * exp($macroDriver))
        );

        $currentVar = $currentMarketVol * $currentMarketVol;
        $longTermVar = $longTermVol * $longTermVol;

        $jumpData = $this->mathUtility->calculateSVJJJumps(
            lambda: self::SVJJ_LAMBDA,
            pUp: self::SVJJ_P_UP,
            etaUp: self::SVJJ_ETA_UP,
            etaDown: self::SVJJ_ETA_DOWN,
            muV: self::SVJJ_MU_V,
            dt: $dt
        );

        $expectedVarJump = (self::SVJJ_P_UP * self::SVJJ_MU_V * 0.5) + ((1.0 - self::SVJJ_P_UP) * self::SVJJ_MU_V);
        $jumpVarianceDrag = (self::SVJJ_LAMBDA * $expectedVarJump) / 3.0;
        $adjustedTheta = max(0.0001, $longTermVar - $jumpVarianceDrag);

        $nextVar = $this->mathUtility->calculateQEVarianceStep($currentVar, $adjustedTheta, self::MACRO_VOL_KAPPA, self::MACRO_VOL_SIGMA, $dt);
        $nextVar += $jumpData['var_jump'];

        return max(0.08, min(0.80, sqrt($nextVar)));
    }

    private function calculatePotentialAndNominalGdp(MacroState $state, float $naturalRate, float $dt): void
    {
        $nominalPotentialGrowth = $naturalRate + $state->inflationEma;
        $state->potentialGdpIndex = max(0.10, $state->potentialGdpIndex * exp($nominalPotentialGrowth * $dt));
        $state->nominalGdpIndex = $state->potentialGdpIndex * (1.0 + $state->outputGap);
    }

    private function updateExponentialMovingAverages(MacroState $state, float $dt): void
    {
        $emaWeight = min(1.0, $dt / 0.25);

        $state->outputGapEma += $emaWeight * ($state->outputGap - $state->outputGapEma);
        $state->policyRateEma += $emaWeight * ($state->policyRate - $state->policyRateEma);
        $state->inflationEma += $emaWeight * ($state->inflation - $state->inflationEma);
        $state->nsSlopeEma += $emaWeight * ($state->nsSlope - $state->nsSlopeEma);

        $state->yield2yEma += $emaWeight * ($state->yield2y - $state->yield2yEma);
        $state->yield5yEma += $emaWeight * ($state->yield5y - $state->yield5yEma);
        $state->yield10yEma += $emaWeight * ($state->yield10y - $state->yield10yEma);
        $state->yield30yEma += $emaWeight * ($state->yield30y - $state->yield30yEma);

        $state->marketVolatilityEma += $emaWeight * ($state->marketVolatility - $state->marketVolatilityEma);
        $state->macroCreditSpreadEma += $emaWeight * ($state->macroCreditSpread - $state->macroCreditSpreadEma);
        $state->unemploymentRateEma += $emaWeight * ($state->unemploymentRate - $state->unemploymentRateEma);
    }

    private function calculateDynamicFiscalPolicy(MacroState $state, float $dt): void
    {
        // Barro's Countercyclical Fiscal Policy Rule (Barro, 1979):
        // Replaces arbitrary dice rolls and step hikes with a smooth continuous institutional feedback loop.
        // As the output gap expands (boom), automatic stabilizers and tax legislation increase the effective 
        // tax burden to cool aggregate demand. In recessions, fiscal stimulus smoothly reduces corporate tax burden.
        $targetTaxRate = self::TARGET_CORPORATE_TAX_RATE + (self::FISCAL_STABILIZER_SENSITIVITY * $state->outputGapEma);
        $targetTaxRate = max(self::MIN_CORPORATE_TAX_RATE, min(self::MAX_CORPORATE_TAX_RATE, $targetTaxRate));

        // Smooth Ornstein-Uhlenbeck institutional adjustment toward the fiscal target
        $state->corporateTaxRate += self::FISCAL_ADJUSTMENT_SPEED * ($targetTaxRate - $state->corporateTaxRate) * $dt;
    }

    private function calculateEquityRiskPremium(MacroState $state): void
    {
        // Campbell-Cochrane (1999) Habit Formation Model:
        // As the output gap contracts below potential, consumer surplus shrinks and aggregate risk aversion
        // scales exponentially, widening the required equity risk premium without ad-hoc piecewise branches.
        $habitErp = self::BASE_EQUITY_RISK_PREMIUM * exp(-self::HABIT_RISK_AVERSION_COEFF * $state->outputGapEma);

        $state->equityRiskPremium = max(self::MIN_EQUITY_RISK_PREMIUM, min(0.12, $habitErp));
    }

    private function calculateMacroCreditSpread(MacroState $state): void
    {
        // Merton (1974) Structural Credit Spread Model:
        // Corporate debt default probability scales exponentially with economic downturns (leverage effect)
        // and linearly with excess macroeconomic volatility (option volatility effect).
        $cycleSpread = self::BASE_CREDIT_SPREAD * exp(-self::MERTON_LEVERAGE_SENSITIVITY * $state->outputGapEma);
        $excessVol = max(0.0, $state->marketVolatilityEma - 0.20);
        $volSpread = self::MERTON_VOL_SENSITIVITY * $excessVol;

        $state->macroCreditSpread = max(0.008, min(self::MAX_CREDIT_SPREAD, $cycleSpread + $volSpread));
    }

    private function calculateUnemployment(MacroState $state, float $dt): void
    {
        // Dynamic Okun's Law with Asymmetric Hysteresis
        // Recessions cause rapid spikes in unemployment (firing is fast), expansions cause slow decay (hiring is slow/frictional)
        $targetUnemployment = max(0.01, self::NATURAL_UNEMPLOYMENT - (self::OKUNS_COEFFICIENT * $state->outputGap));

        $unemploymentGap = $targetUnemployment - $state->unemploymentRate;

        // Asymmetric speed of adjustment
        $adjustmentSpeed = $unemploymentGap > 0 ? self::OKUNS_FIRING_SPEED : self::OKUNS_HIRING_SPEED; // 4x faster to fire than to hire

        $state->unemploymentRate += $adjustmentSpeed * $unemploymentGap * $dt;
    }



    private function calculateEnergyShock(MacroState $state, float $dt): void
    {
        // Schwartz 1-Factor Model (1997) for Commodity Pricing
        // Uses Ornstein-Uhlenbeck on the log-price for mathematically sound log-normal distribution
        $dW = $this->mathUtility->generateStandardNormal();
        $baseProcess = $this->mathUtility->calculateSchwartz1Factor(
            currentPrice: $state->energyPriceIndex,
            kappa: self::ENERGY_MEAN_REVERSION,
            theta: 100.0,
            sigma: self::ENERGY_VOLATILITY,
            dt: $dt,
            dW: $dW
        );

        // Exogenous Poisson Jump Shocks (e.g., Geopolitics, Supply Cuts)
        $jumpData = $this->mathUtility->calculateJumpDiffusion(
            lambda: self::ENERGY_JUMP_PROBABILITY,
            jumpMean: self::ENERGY_JUMP_MEAN,
            jumpVol: self::ENERGY_JUMP_VOL,
            dt: $dt
        );

        $jumpAmount = 0.0;
        if ($jumpData['multiplier'] !== 1.0) {
            $jumpAmount = $baseProcess * ($jumpData['multiplier'] - 1.0);
        }

        $state->energyPriceIndex = $baseProcess + $jumpAmount;
        $state->energyPriceIndex = max(10.0, min(500.0, $state->energyPriceIndex)); // Clamp extremes
        $state->energyPriceShock = $state->energyPriceIndex - 100.0;
    }
}
