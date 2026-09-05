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
     *   r_target = r* + pi_blend + alpha_pi * (pi_blend - pi*) + gamma_y * y_gap
     * Under the Evans Rule, locks target at 0% (ZLB) when the central bank is at
     * the lower bound (or unconstrained target <= 0) and unemployment is elevated
     * while inflation remains contained.
     *
     * @param MacroState $state           Current macroeconomic state.
     * @param float      $targetInflation Statutory central bank target inflation.
     * @param float      $naturalRate     Dynamic natural real rate of interest (r*).
     * @param float      $dt              Time increment in years.
     * @return float Unclamped equilibrium policy rate target (Wu-Xia shadow rate).
     */
    public function calculateTargetRate(MacroState $state, float $targetInflation, float $naturalRate, float $dt = 0.25): float
    {
        // Continuous accumulation of cumulative price-level shortfall under Flexible Average Inflation Targeting (FAIT - Powell 2020)
        // FAIT is an asymmetric make-up framework: persistent shortfalls (pi < target) create an accommodative buffer (offset < 0)
        // that tolerates moderate overshoots before rate hikes are initiated.
        $inflationShortfall = $state->inflationEma - $targetInflation;
        $state->cumulativeInflationGap += ($inflationShortfall - (MacroEngine::FAIT_MEMORY_SPEED * $state->cumulativeInflationGap)) * $dt;
        $state->cumulativeInflationGap = max(-0.06, min(0.06, $state->cumulativeInflationGap));
        $faitOffset = min(0.0, MacroEngine::FAIT_MAKEUP_COEFFICIENT * $state->cumulativeInflationGap);
        $faitOffset = max(-MacroEngine::FAIT_MAX_TARGET_OFFSET, $faitOffset);

        // Shapiro (2022) / Bernanke (2015): Blend core inflation measures with forward-looking expectations (TIPS breakeven)
        $coreWeight = MacroEngine::INFLATION_WEIGHT_SUPERCORE + MacroEngine::INFLATION_WEIGHT_GOODS;
        if ($coreWeight > 0 && ($state->supercoreInflationEma !== $targetInflation || $state->coreGoodsInflationEma !== $targetInflation)) {
            $coreInflation = ((MacroEngine::INFLATION_WEIGHT_SUPERCORE * $state->supercoreInflationEma)
                + (MacroEngine::INFLATION_WEIGHT_GOODS * $state->coreGoodsInflationEma)) / $coreWeight;
        } else {
            $coreInflation = $state->inflationEma;
        }

        $inflationMeasure = (MacroEngine::TAYLOR_INFLATION_CORE_WEIGHT * $coreInflation)
            + (MacroEngine::TAYLOR_INFLATION_ANCHOR_WEIGHT * $state->tipsBreakeven);

        // Clarida, Galí & Gertler (1998, 2000): Taylor rule responds to the cyclical trend (EMA)
        // to prevent stochastic tick diffusion from causing erratic swings in the policy stance.
        $cyclicalGap = ($state->outputGapEma === 0.015 && $state->outputGap !== 0.015)
            ? $state->outputGap
            : $state->outputGapEma;

        // Smooth asymmetric output gap weighting: continuous bounded multiplier preventing panic cliff drops
        if ($cyclicalGap < 0.0) {
            $gapWeight = MacroEngine::TAYLOR_OUTPUT_GAP_WEIGHT
                * (1.0 + min(0.50, abs($cyclicalGap) * MacroEngine::TAYLOR_RECESSION_SCALE));
        } else {
            $gapWeight = MacroEngine::TAYLOR_OUTPUT_GAP_WEIGHT;
        }

        $unclampedTarget = $naturalRate + $inflationMeasure
            + MacroEngine::TAYLOR_INFLATION_WEIGHT * ($inflationMeasure - $targetInflation)
            + $gapWeight * $cyclicalGap
            + $faitOffset;

        // Wu-Xia (2016) / Krippner (2013) Unconstrained Shadow Rate:
        // Incorporates unconventional monetary accommodation (QE balance sheet expansion)
        // during Zero Lower Bound regimes, allowing the shadow rate to drop negative to quantify policy stance.
        $qeShadowAccommodation = $state->qeIntensity * MacroEngine::WU_XIA_QE_SHADOW_SENSITIVITY;

        return $unclampedTarget - $qeShadowAccommodation;
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
        $effectiveTarget = max(MacroEngine::EFFECTIVE_LOWER_BOUND, min(0.20, $targetRate));

        // Evans Rule (FOMC Dec 2012): Forward guidance holds policy rate at lower bound during recovery
        // as long as unemployment remains elevated and inflation remains contained below the threshold ceiling.
        if (
            $currentPolicyRate <= MacroEngine::ZLB_PROXIMITY_THRESHOLD
            && $effectiveTarget > $currentPolicyRate
            && $state->unemploymentRateEma > MacroEngine::EVANS_RULE_UNEMPLOYMENT
            && max($state->inflationEma, $state->tipsBreakeven) < MacroEngine::EVANS_RULE_INFLATION_CAP
        ) {
            $effectiveTarget = $currentPolicyRate;
        }

        if ($effectiveTarget > $currentPolicyRate) {
            $cbSpeed = MacroEngine::CB_HIKE_SMOOTHING_SPEED;
            $effectiveInflation = max($state->inflation, $state->inflationEma);
            $inflationPanicExcess = max(0.0, $effectiveInflation - MacroEngine::CB_INFLATION_PANIC_THRESHOLD);
            $panicMultiplier = min(MacroEngine::CB_MAX_HIKE_PANIC_SPEED, $inflationPanicExcess * MacroEngine::CB_INFLATION_PANIC_SCALE);
            $cbSpeed += $panicMultiplier;

            $panicFraction = min(1.0, $panicMultiplier / MacroEngine::CB_MAX_HIKE_PANIC_SPEED);
            $maxHikeVelocity = MacroEngine::CB_MAX_NORMAL_HIKE_VELOCITY + $panicFraction * (MacroEngine::CB_MAX_PANIC_HIKE_VELOCITY - MacroEngine::CB_MAX_NORMAL_HIKE_VELOCITY);

            $rawMove = $cbSpeed * ($effectiveTarget - $currentPolicyRate);
            $clampedMove = min($maxHikeVelocity, $rawMove);
        } else {
            $cyclicalGap = ($state->outputGapEma === 0.015 && $state->outputGap !== 0.015)
                ? $state->outputGap
                : $state->outputGapEma;

            $cbSpeed = MacroEngine::CB_CUT_SMOOTHING_SPEED;
            $effectiveDeflation = min($state->inflation, $state->inflationEma);
            $deflationPanic = max(0.0, MacroEngine::TARGET_INFLATION - $effectiveDeflation) * MacroEngine::CB_INFLATION_PANIC_SCALE;
            $recessionPanic = max(0.0, -$cyclicalGap) * MacroEngine::CB_RECESSION_PANIC_SCALE;
            $cbSpeed += min(MacroEngine::CB_MAX_CUT_PANIC_SPEED, $deflationPanic + $recessionPanic);

            $rawMove = $cbSpeed * ($effectiveTarget - $currentPolicyRate);
            $clampedMove = max(MacroEngine::CB_MAX_CUT_VELOCITY, $rawMove);
        }

        $newRate = $currentPolicyRate + $clampedMove * $dt;
        $newRate = max(MacroEngine::EFFECTIVE_LOWER_BOUND, min(0.20, $newRate));

        if ($effectiveTarget > $currentPolicyRate) {
            return min($effectiveTarget, $newRate);
        } else {
            return max($effectiveTarget, $newRate);
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
        $rateRoomProximity = min(1.0, max(0.0, (MacroEngine::QE_ACTIVATION_RATE_THRESHOLD - $state->policyRate) / MacroEngine::QE_ACTIVATION_RATE_THRESHOLD));
        $recessionSeverity = max(0.0, -$state->outputGap);

        if ($rateRoomProximity > 0.0 && $state->outputGap < MacroEngine::QE_ACTIVATION_GAP_THRESHOLD) {
            $qeYieldSuppressionTarget = min(MacroEngine::QE_MAX_SUPPRESSION, $rateRoomProximity * $recessionSeverity * MacroEngine::QE_SEVERITY_MULTIPLIER);
        } else {
            $qeYieldSuppressionTarget = 0.0;
        }

        if ($state->outputGap > MacroEngine::QT_ACTIVATION_GAP_THRESHOLD && $state->inflation > MacroEngine::QT_ACTIVATION_INFLATION_THRESHOLD) {
            $overheating = ($state->outputGap - MacroEngine::QT_ACTIVATION_GAP_THRESHOLD) + ($state->inflation - MacroEngine::QT_ACTIVATION_INFLATION_THRESHOLD);
            $qtTighteningTarget = min(MacroEngine::QT_MAX_INTENSITY, $overheating * MacroEngine::QT_SEVERITY_MULTIPLIER);
        } else {
            $qtTighteningTarget = 0.0;
        }
        // --- Balance Sheet Phase Logic (Bernanke 2020, Vayanos-Vila 2021) ---
        if ($qeYieldSuppressionTarget > 0.0) {
            // Active QE: reset hold timer, ramp toward QE target
            $balanceSheetTarget = $qeYieldSuppressionTarget;
            $newHoldTimer = 0.0;
        } elseif ($state->balanceSheetIntensity > 0.001) {
            // QE ended: hold balance sheet constant during reinvestment phase (Bernanke 2020)
            $newHoldTimer = $state->balanceSheetHoldTimer + $dt;

            if ($newHoldTimer >= MacroEngine::BALANCE_SHEET_REINVESTMENT_HOLD_YEARS) {
                // Hold period expired: begin passive runoff to baseline (or active QT if overheating)
                $balanceSheetTarget = -$qtTighteningTarget;
            } else {
                // Reinvestment hold: maintain current balance sheet size
                $balanceSheetTarget = $state->balanceSheetIntensity;
            }
        } else {
            // No QE history or runoff completed, normal QT or neutral
            $balanceSheetTarget = -$qtTighteningTarget;
            $newHoldTimer = 0.0;
        }

        $newBalanceSheetIntensity = $balanceSheetTarget + ($state->balanceSheetIntensity - $balanceSheetTarget) * exp(-MacroEngine::BALANCE_SHEET_RAMP_SPEED * $dt);

        $inflationRiskPremium = MacroEngine::TERM_PREMIUM_IRP_EXPECTATION_SCALE * max(0.0, $state->tipsBreakeven - MacroEngine::TARGET_INFLATION);
        $flightToSafetyShift = MacroEngine::FLIGHT_TO_SAFETY_SENSITIVITY * max(0.0, $state->marketVolatilityEma - 0.25);
        $cyclicalTermPremium = $state->outputGap * MacroEngine::NS_GAP_TERM_PREMIUM_SCALE;
        $restrictiveCompression = MacroEngine::TERM_PREMIUM_TIGHTENING_COMPRESSION * max(0.0, $state->policyRate - ($naturalRate + MacroEngine::TARGET_INFLATION));
        $totalBaseTermPremium = max(0.0, MacroEngine::NS_BASE_TERM_PREMIUM + $inflationRiskPremium + $cyclicalTermPremium - $flightToSafetyShift - $restrictiveCompression);

        // Long-term asymptotic yield level beta0 (Nelson-Siegel 1987, Diebold-Li 2006):
        // Anchored to expected inflation over the 10-year horizon (Fisher hypothesis) plus term premium
        $expectedInflation10y = (MacroEngine::TIPS_TARGET_WEIGHT * MacroEngine::TARGET_INFLATION)
            + ((1.0 - MacroEngine::TIPS_TARGET_WEIGHT) * $state->tipsBreakeven);
        $level = $naturalRate + $expectedInflation10y + $totalBaseTermPremium;
        $nsBeta1 = $state->policyRate - $level;

        // Diebold-Li (2006) Curvature beta2: forward monetary tightening/easing expectations
        $monetaryStanceGap = $state->targetRate - $state->policyRate;
        $nsBeta2 = (MacroEngine::SVENSSON_CURVATURE1_TARGET_SCALE * $monetaryStanceGap)
            + (MacroEngine::SVENSSON_CURVATURE1_GAP_SCALE * $state->outputGap);

        $fiscalShift = ($state->governmentSpendingIndexEma / MacroEngine::GOVT_SPENDING_BASELINE) - 1.0;
        $excessDebt = max(0.0, $state->sovereignDebtToGdpEma - MacroEngine::SOVEREIGN_DEBT_NEUTRAL_THRESHOLD);
        $debtCurvature = $excessDebt * MacroEngine::SOVEREIGN_DEBT_YIELD_SENSITIVITY;
        $nsBeta3 = (MacroEngine::SVENSSON_CURVATURE2_FISCAL_SCALE * $fiscalShift) + $debtCurvature;

        $yield2y  = $this->calculateSvenssonTenor(2.0, $level, $nsBeta1, $nsBeta2, $nsBeta3, $state);
        $yield5y  = $this->calculateSvenssonTenor(5.0, $level, $nsBeta1, $nsBeta2, $nsBeta3, $state);
        $yield10y = $this->calculateSvenssonTenor(10.0, $level, $nsBeta1, $nsBeta2, $nsBeta3, $state);
        $yield30y = $this->calculateSvenssonTenor(30.0, $level, $nsBeta1, $nsBeta2, $nsBeta3, $state);

        $durationFactor10y = (1.0 - exp(-10.0 * MacroEngine::SVENSSON_LAMBDA_1)) / (10.0 * MacroEngine::SVENSSON_LAMBDA_1);
        $factor2_10y = $durationFactor10y - exp(-10.0 * MacroEngine::SVENSSON_LAMBDA_1);

        // Adrian, Crump & Moench (2013) Pure Risk-Neutral Rate: Expected path of policy rates under zero term premium
        $nsBeta1RiskNeutral = $state->policyRate - ($naturalRate + MacroEngine::TARGET_INFLATION);
        $riskNeutral10y = ($naturalRate + MacroEngine::TARGET_INFLATION) + ($nsBeta1RiskNeutral * $durationFactor10y) + ($nsBeta2 * $factor2_10y);
        $termPremium10y = $yield10y - $riskNeutral10y;

        $structural10y = $this->calculateSvenssonTenor(10.0, $level, $nsBeta1, $nsBeta2, $debtCurvature, $state);

        return [
            'level' => $level,
            'curvature' => $nsBeta2,
            'curvature2' => $nsBeta3,
            'new_balance_sheet_intensity' => $newBalanceSheetIntensity,
            'new_hold_timer' => $newHoldTimer,
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
     * Evaluates the nominal yield for maturity t using the Svensson four-parameter formula.
     * Absorbs the structural base term premium, Wright (2011) Inflation Risk Premium (IRP),
     * and Campbell et al. (2017) countercyclical flight-to-safety into the asymptotic long-run
     * yield level (beta0), while adjusting beta1 to maintain the exact policy rate anchor at t=0.
     * Enforces the central bank Effective Lower Bound (ELB) floor on all nominal maturities.
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
        // Vayanos & Vila (2021) Preferred-Habitat Model: duration extraction under QE/QT compresses term premium by tenor duration
        $preferredHabitatShift = $this->mathUtility->calculatePreferredHabitatTermPremiumShift(
            balanceSheetIntensity: $state->balanceSheetIntensity,
            tau: $t,
            habitatSensitivity: MacroEngine::PREFERRED_HABITAT_DURATION_SENSITIVITY
        );

        $yield = $this->mathUtility->calculateSvenssonYield(
            level: $level,
            slope: $nsBeta1,
            curvature1: $nsBeta2,
            curvature2: $nsBeta3,
            tau: $t,
            lambda1: MacroEngine::SVENSSON_LAMBDA_1,
            lambda2: MacroEngine::SVENSSON_LAMBDA_2
        );

        return max(MacroEngine::EFFECTIVE_LOWER_BOUND, $yield + $preferredHabitatShift);
    }
}
