<?php

namespace App\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Math\MathUtility;

/**
 * Handles central bank monetary policy targeting, policy rate smoothing,
 * Nelson-Siegel-Svensson term structure fitting, ACM term premium decomposition,
 * and Quantitative Easing / Tightening balance sheet dynamics.
 */
class MonetaryPolicySubsystem
{
    public function __construct(
        private readonly MathUtility $mathUtility
    ) {}

    /**
     * Taylor (1993) Monetary Policy Rule with Evans (2012) Forward Guidance.
     *
     * Computes the central bank's nominal policy rate target:
     *   r_target = r* + pi_ema + 1.5*(pi_ema - pi*) + gamma_y * y_gap
     * Under the Evans Rule, locks target at 0% (ZLB) if unemployment is elevated
     * while inflation remains contained.
     *
     * @param MacroState $state           Current macroeconomic state.
     * @param float      $targetInflation Statutory central bank target inflation.
     * @param float      $naturalRate     Dynamic natural real rate of interest (r*).
     * @return float Equilibrium policy rate target clamped between 0% and 20%.
     */
    public function calculateTargetRate(MacroState $state, float $targetInflation, float $naturalRate): float
    {
        $trendInflation = $state->inflationEma;

        if ($state->unemploymentRateEma > MacroEngine::EVANS_RULE_UNEMPLOYMENT && $trendInflation < MacroEngine::EVANS_RULE_INFLATION_CAP) {
            return 0.00;
        }

        if ($state->outputGap < 0.0) {
            $gapWeight = MacroEngine::TAYLOR_INFLATION_WEIGHT + min(MacroEngine::TAYLOR_INFLATION_WEIGHT, abs($state->outputGap) * MacroEngine::TAYLOR_RECESSION_SCALE);
        } else {
            $gapWeight = MacroEngine::TAYLOR_BOOM_WEIGHT;
        }

        $targetRate = $naturalRate + $trendInflation
            + MacroEngine::TAYLOR_INFLATION_WEIGHT * ($trendInflation - $targetInflation)
            + $gapWeight * ($state->outputGap);

        return max(0.00, min(0.20, $targetRate));
    }

    /**
     * Clarida, Galí & Gertler (1998) Partial Adjustment Monetary Policy Smoothing.
     *
     * Models inertial interest rate adjustments toward the Taylor target with asymmetric
     * speed: emergency rate cuts proceed swiftly, while rate hikes are gradual during
     * normal expansions but accelerate to Volcker speed during runaway inflation spikes.
     *
     * @param MacroState $state      Current macroeconomic state.
     * @param float      $targetRate Target policy rate from the Taylor Rule.
     * @param float      $dt         Time increment in years.
     * @return float Updated central bank policy rate.
     */
    public function updatePolicyRate(MacroState $state, float $targetRate, float $dt): float
    {
        $currentPolicyRate = $state->policyRate;

        if ($targetRate > $currentPolicyRate) {
            $cbSpeed = MacroEngine::CB_HIKE_SMOOTHING_SPEED;
            $inflationPanicExcess = max(0.0, $state->inflation - MacroEngine::CB_INFLATION_PANIC_THRESHOLD);
            $panicMultiplier = min(MacroEngine::CB_MAX_HIKE_PANIC_SPEED, $inflationPanicExcess * MacroEngine::CB_INFLATION_PANIC_SCALE);
            $cbSpeed += $panicMultiplier;

            $panicFraction = min(1.0, $panicMultiplier / MacroEngine::CB_MAX_HIKE_PANIC_SPEED);
            $maxHikeVelocity = MacroEngine::CB_MAX_NORMAL_HIKE_VELOCITY + $panicFraction * (MacroEngine::CB_MAX_PANIC_HIKE_VELOCITY - MacroEngine::CB_MAX_NORMAL_HIKE_VELOCITY);

            $rawMove = $cbSpeed * ($targetRate - $currentPolicyRate);
            $clampedMove = min($maxHikeVelocity, $rawMove);
        } else {
            $cbSpeed = MacroEngine::CB_CUT_SMOOTHING_SPEED;
            $deflationPanic = max(0.0, MacroEngine::TARGET_INFLATION - $state->inflation) * MacroEngine::CB_INFLATION_PANIC_SCALE;
            $recessionPanic = max(0.0, -$state->outputGap) * MacroEngine::CB_RECESSION_PANIC_SCALE;
            $cbSpeed += min(MacroEngine::CB_MAX_CUT_PANIC_SPEED, $deflationPanic + $recessionPanic);

            $rawMove = $cbSpeed * ($targetRate - $currentPolicyRate);
            $clampedMove = max(MacroEngine::CB_MAX_CUT_VELOCITY, $rawMove);
        }

        $newRate = $currentPolicyRate + $clampedMove * $dt;
        $newRate = max(0.00, min(0.20, $newRate));

        if ($targetRate > $currentPolicyRate) {
            return min($targetRate, $newRate);
        } else {
            return max($targetRate, $newRate);
        }
    }

    /**
     * Nelson-Siegel-Svensson (1994) Term Structure & Adrian-Crump-Moench (2013) ACM Decomposition.
     *
     * Fits the full zero-coupon sovereign yield curve (2Y, 5Y, 10Y, 30Y) with Diebold-Li (2006)
     * forward monetary guidance curvature (beta2) and long-end fiscal/QT supply curvature (beta3).
     * Decomposes the 10Y yield into expected risk-neutral policy rate path and duration term premium.
     * Models Central Bank Quantitative Easing (QE) and Quantitative Tightening (QT) balance sheet runoff.
     *
     * @param MacroState $state           Current macroeconomic state.
     * @param float      $targetInflation Central bank inflation target.
     * @param float      $naturalRate     Dynamic natural real rate of interest (r*).
     * @param float      $dt              Time increment in years.
     * @return array<string, float> Decomposition containing yields, NSS factors, and QE/QT intensities.
     */
    public function calculateYieldCurveAndQE(MacroState $state, float $targetInflation, float $naturalRate, float $dt): array
    {
        $zlbProximity = min(1.0, max(0.0, (MacroEngine::ZLB_PROXIMITY_THRESHOLD - $state->policyRate) / MacroEngine::ZLB_PROXIMITY_THRESHOLD));
        $recessionSeverity = max(0.0, -$state->outputGap);

        if ($zlbProximity > MacroEngine::QE_ACTIVATION_ZLB_THRESHOLD && $state->outputGap < MacroEngine::QE_ACTIVATION_GAP_THRESHOLD) {
            $qeYieldSuppressionTarget = min(MacroEngine::QE_MAX_SUPPRESSION, $zlbProximity * $recessionSeverity * MacroEngine::QE_SEVERITY_MULTIPLIER);
        } else {
            $qeYieldSuppressionTarget = 0.0;
        }

        if ($state->outputGap > MacroEngine::QT_ACTIVATION_GAP_THRESHOLD && $state->inflation > MacroEngine::QT_ACTIVATION_INFLATION_THRESHOLD) {
            $overheating = ($state->outputGap - MacroEngine::QT_ACTIVATION_GAP_THRESHOLD) + ($state->inflation - MacroEngine::QT_ACTIVATION_INFLATION_THRESHOLD);
            $qtTighteningTarget = min(MacroEngine::QT_MAX_INTENSITY, $overheating * MacroEngine::QT_SEVERITY_MULTIPLIER);
        } else {
            $qtTighteningTarget = 0.0;
        }

        $balanceSheetTarget = $qeYieldSuppressionTarget - $qtTighteningTarget;
        $newBalanceSheetIntensity = $balanceSheetTarget + ($state->balanceSheetIntensity - $balanceSheetTarget) * exp(-MacroEngine::BALANCE_SHEET_RAMP_SPEED * $dt);

        $expectedInflation = $state->tipsBreakeven;
        $level = $naturalRate + (MacroEngine::INFLATION_LEVEL_WEIGHT * $targetInflation) + (MacroEngine::INFLATION_LEVEL_WEIGHT * $expectedInflation);
        $nsBeta1 = $state->policyRate - $level;

        $monetaryStanceGap = $state->targetRate - $state->policyRate;
        $nsBeta2 = (MacroEngine::SVENSSON_CURVATURE1_TARGET_SCALE * $monetaryStanceGap)
            + (MacroEngine::SVENSSON_CURVATURE1_GAP_SCALE * $state->outputGap);

        $fiscalShift = ($state->governmentSpendingIndexEma / MacroEngine::GOVT_SPENDING_BASELINE) - 1.0;
        $balanceSheetCurvature = -$newBalanceSheetIntensity * MacroEngine::SVENSSON_CURVATURE2_BS_SCALE;
        $nsBeta3 = (MacroEngine::SVENSSON_CURVATURE2_FISCAL_SCALE * $fiscalShift) + $balanceSheetCurvature;

        $yield2y  = $this->calculateSvenssonTenor(2.0, $level, $nsBeta1, $nsBeta2, $nsBeta3, $state);
        $yield5y  = $this->calculateSvenssonTenor(5.0, $level, $nsBeta1, $nsBeta2, $nsBeta3, $state);
        $yield10y = $this->calculateSvenssonTenor(10.0, $level, $nsBeta1, $nsBeta2, $nsBeta3, $state);
        $yield30y = $this->calculateSvenssonTenor(30.0, $level, $nsBeta1, $nsBeta2, $nsBeta3, $state);

        $durationFactor10y = (1.0 - exp(-10.0 * MacroEngine::SVENSSON_LAMBDA_1)) / (10.0 * MacroEngine::SVENSSON_LAMBDA_1);
        $riskNeutral10y = $level + ($nsBeta1 * $durationFactor10y);
        $termPremium10y = $yield10y - $riskNeutral10y;

        $nsBeta3Structural = MacroEngine::SVENSSON_CURVATURE2_FISCAL_SCALE * $fiscalShift;
        $structural10y = $this->calculateSvenssonTenor(10.0, $level, $nsBeta1, $nsBeta2, $nsBeta3Structural, $state);

        return [
            'level' => $level,
            'curvature' => $nsBeta2,
            'curvature2' => $nsBeta3,
            'new_balance_sheet_intensity' => $newBalanceSheetIntensity,
            'new_qe_intensity' => max(0.0, $newBalanceSheetIntensity),
            'new_qt_intensity' => max(0.0, -$newBalanceSheetIntensity),
            'structural_10y' => $structural10y,
            'yield_2y'  => $yield2y,
            'yield_5y'  => $yield5y,
            'yield_10y' => $yield10y,
            'yield_30y' => $yield30y,
            'risk_neutral_10y' => $riskNeutral10y,
            'term_premium_10y' => $termPremium10y,
        ];
    }

    /**
     * Svensson (1994) Zero-Coupon Yield with Wright (2011) Inflation Risk Premium.
     *
     * Evaluates the nominal yield for maturity t using the Svensson four-parameter formula,
     * augmented with concave duration-scaled structural term premium, Wright (2011) Inflation
     * Risk Premium (IRP), and Campbell et al. (2017) countercyclical safe-haven flight-to-safety.
     *
     * @param float      $t       Tenor maturity in years (e.g. 2.0, 5.0, 10.0, 30.0).
     * @param float      $level   Asymptotic long-term yield level (beta0).
     * @param float      $nsBeta1 Short-rate slope parameter (beta1).
     * @param float      $nsBeta2 Medium-term curvature / belly hump parameter (beta2).
     * @param float      $nsBeta3 Long-term secondary curvature / fiscal hump parameter (beta3).
     * @param MacroState $state   Current macroeconomic state.
     * @return float Nominal sovereign yield for the specified tenor.
     */
    public function calculateSvenssonTenor(float $t, float $level, float $nsBeta1, float $nsBeta2, float $nsBeta3, MacroState $state): float
    {
        $durationScale = (1.0 - exp(-$t / 10.0)) / (1.0 - exp(-1.0));

        $inflationRiskPremium = (MacroEngine::TERM_PREMIUM_IRP_EXPECTATION_SCALE * max(0.0, $state->tipsBreakeven - MacroEngine::TARGET_INFLATION))
            + (MacroEngine::TERM_PREMIUM_IRP_VOLATILITY_SCALE * max(0.0, $state->marketVolatilityEma - 0.20));

        $termPremium = ((MacroEngine::NS_BASE_TERM_PREMIUM + $inflationRiskPremium) * $durationScale)
            + ($state->outputGap * MacroEngine::NS_GAP_TERM_PREMIUM_SCALE * $durationScale);

        $pureYield = $this->mathUtility->calculateSvenssonYield(
            level: $level,
            slope: $nsBeta1,
            curvature1: $nsBeta2,
            curvature2: $nsBeta3,
            tau: $t,
            lambda1: MacroEngine::SVENSSON_LAMBDA_1,
            lambda2: MacroEngine::SVENSSON_LAMBDA_2
        );

        return $pureYield + $termPremium;
    }
}
