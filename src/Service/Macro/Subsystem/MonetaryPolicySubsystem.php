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
    // --- Taylor Rule & The Evans Rule (Forward Guidance) ---
    /** Bernanke (2006) / Rudebusch-Sack-Swanson (2007) long-rate offset: the central bank leans against the part of the ten-year it does not set (term premium away from baseline, the market's drifted view of neutral). FRB/US puts a 100bps term premium move at roughly 50bps of policy; without it a high-premium era is a decade-long slump. */
    public const TAYLOR_LONG_RATE_OFFSET = 0.50;

    // --- Nelson-Siegel-Svensson Term Structure Dynamics (Svensson 1994) ---
    /** Weight on the central bank target in the ten-year inflation expectation that anchors the curve's long end. Well-anchored expectations (surveys barely move) are what let the policy rate swing against a steady long end and invert the curve; a level that tracked the current breakeven would follow the short end up and never invert. */
    public const LONG_RUN_INFLATION_ANCHOR_WEIGHT = 0.75;
    /** Kozicki-Tinsley (2001) shifting endpoint: weight on the market's adaptive long-run policy rate in the curve's anchor, against the model-consistent r* plus expected inflation. A decade at 5% lifts the anchor so the curve flattens like 1995-1999 instead of staying inverted; a decade at the floor drags the ten-year toward the 2% of 2012-2016. */
    public const KOZICKI_TINSLEY_ENDPOINT_WEIGHT = 0.50;
    /** Speed at which the perceived long-run policy rate learns from the realized rate (half-life ~5 years): slow enough that a two-year hiking cycle still inverts the curve, fast enough that a decade-long era reprices the long end, the slow adaptive expectations of Kozicki-Tinsley. */
    public const KOZICKI_TINSLEY_ADAPTATION_SPEED = 0.14;
    /** Diebold-Li (2006) curvature sensitivity to central bank target-policy rate gap (forward guidance channel). */
    public const SVENSSON_CURVATURE1_TARGET_SCALE = 0.85;
    /** Cyclical curvature sensitivity to output gap (positive gap leads to steeper belly). */
    public const SVENSSON_CURVATURE1_GAP_SCALE = 0.15;
    /** Wright (2011) IRP: term premium sensitivity to excess inflation expectations above target. Kept modest: the 2022 episode showed breakevens near 3% adding little premium once expectations are anchored. */
    public const TERM_PREMIUM_IRP_EXPECTATION_SCALE = 0.20;
    /** Floor on the ten-year term premium (-75bps): ACM ran between -50 and -100bps from 2016 to 2021, so flight to safety and QE may push the long end below the expected policy path. */
    public const MIN_TERM_PREMIUM_10Y = -0.0075;

    // --- Term Premium Dynamics (ACM 2013 persistence, Campbell-Pflueger-Viceira 2020 regimes) ---
    /** Mean reversion of transitory term premium shocks (half-life ~8 months): ACM show the premium is persistent but not permanent. */
    public const TERM_PREMIUM_SHOCK_KAPPA = 1.0;
    /** Cap on the transitory shock (200bps either way), the largest ACM swing on record. */
    public const TERM_PREMIUM_SHOCK_CAP = 0.02;
    /** Mean reversion of the structural term premium regime (half-life ~8 years): eras such as the 1990s at 2% and the 2010s near zero, set by the bond-stock correlation. */
    public const TERM_PREMIUM_REGIME_KAPPA = 0.087;
    /** Ceiling of the structural term premium regime (the early 1990s era). */
    public const MAX_TERM_PREMIUM_REGIME = 0.025;

    // --- Diebold-Li (2006) Expected-Path Innovation ---
    /** Mean reversion of the curvature factor's own innovation (half-life ~7 months): Diebold-Li estimate curvature as the least persistent Nelson-Siegel factor, a monthly AR(1) near 0.9. */
    public const EXPECTED_PATH_SHOCK_KAPPA = 1.2;
    /** Annual volatility of that innovation, in curvature units: the 2Y carries 0.29 of it, the 10Y 0.14. Measured over 8 seeds x 60y it takes the 2Y's quarterly change from 24 to 48bps (real ~50) and the 10Y's from 35 to 40, so the vol term structure slopes down past the belly as every real curve does. */
    public const EXPECTED_PATH_SHOCK_SIGMA = 0.030;
    /** Cap on the innovation (~2 stationary sd, ~120bps at the 2Y): a repricing of the next few years of policy, never a regime change, so the 2Y stays within the band it holds at the lower bound. */
    public const EXPECTED_PATH_SHOCK_CAP = 0.04;

    // --- Central Bank Balance Sheet (QE & QT) ---
    /** Minimum reinvestment hold period (years) after QE ends before QT runoff can begin (Bernanke 2020). */
    public const BALANCE_SHEET_REINVESTMENT_HOLD_YEARS = 1.5;

    // --- Taylor Rule & The Evans Rule (Forward Guidance) ---
    /** Weight on inflation deviations from the target in the Taylor Rule. */
    public const TAYLOR_INFLATION_WEIGHT = 0.50;
    /** Canonical Taylor (1993) weight on the output gap in the Taylor Rule. */
    public const TAYLOR_OUTPUT_GAP_WEIGHT = 0.50;
    /** Non-linear scaling factor amplifying rate cuts during recessions: a -1.4% gap doubles the weight on the real economy. */
    public const TAYLOR_RECESSION_SCALE = 70.0;
    /** Ceiling on that recession boost: at a severe gap the rule reaches the Yellen (2012) balanced-approach weight of 1.0 and beyond, while a boom is still met with the standard 0.5. */
    public const TAYLOR_RECESSION_MAX_BOOST = 2.00;
    /** Bernanke (2015) blend: weight on realized core inflation (EMA) in the Taylor Rule inflation measure. */
    public const TAYLOR_INFLATION_CORE_WEIGHT = 0.70;
    /** Bernanke (2015) blend: weight on forward inflation expectations (TIPS breakeven) in the Taylor Rule inflation measure. */
    public const TAYLOR_INFLATION_ANCHOR_WEIGHT = 0.30;
    /** Evans Rule forward guidance: Maximum inflation ceiling tolerated while holding rates at ZLB. */
    public const EVANS_RULE_INFLATION_CAP = 0.025;
    /** Rate hiking partial-adjustment speed per year (Woodford 2003 inertial gradualism, Clarida-Gali-Gertler rho ~0.8 quarterly); at 0.8 the rate trailed a rising target by ~140bp through every boom and policy never turned restrictive. */
    public const CB_HIKE_SMOOTHING_SPEED = 1.00;
    /** Central bank baseline rate cutting smoothing speed per year (rapid crisis easing). */
    public const CB_CUT_SMOOTHING_SPEED = 1.20;
    /** Inflation panic reaction multiplier accelerating rate hikes during extreme inflation spikes. */
    public const CB_INFLATION_PANIC_SCALE = 50.0;
    /** Recession panic reaction multiplier accelerating emergency cuts during downturns. */
    public const CB_RECESSION_PANIC_SCALE = 20.0;
    /** Maximum annual rate hike velocity cap (Volcker-style panic speed cap). */
    public const CB_MAX_HIKE_PANIC_SPEED = 3.0;
    /** Maximum annual rate cut velocity cap during financial crises. */
    public const CB_MAX_CUT_PANIC_SPEED = 10.0;
    /** Maximum annual rate cut velocity cap during economic downturns and crises. */
    public const CB_MAX_CUT_VELOCITY = -0.080;

    // --- Flexible Average Inflation Targeting (FAIT - Powell 2020) ---
    /** FAIT rolling memory persistence speed per year for cumulative price level shortfall. */
    public const FAIT_MEMORY_SPEED = 0.50;
    /** Central bank reaction sensitivity to cumulative inflation shortfall/overshoot. */
    public const FAIT_MAKEUP_COEFFICIENT = 0.25;
    /** Maximum policy rate target offset allowed from FAIT cumulative memory. */
    public const FAIT_MAX_TARGET_OFFSET = 0.015;

    // --- Central Bank Effective Lower Bound & Shadow Rates ---
    /** Shadow-rate accommodation per unit of QE yield suppression: full-scale QE (100bps) reads as a -3% shadow rate, the Wu-Xia trough of 2014. */
    public const WU_XIA_QE_SHADOW_SENSITIVITY = 3.0;

    // --- Nelson-Siegel-Svensson Term Structure Dynamics (Svensson 1994) ---
    /** Flight-to-safety sensitivity: recessions compress term premium via safe-haven demand (Campbell et al. 2017). */
    public const NS_GAP_TERM_PREMIUM_SCALE = 0.05;
    /** Sensitivity of secondary curvature (beta3) to quantitative tightening and long-term fiscal deficits. */
    public const SVENSSON_CURVATURE2_FISCAL_SCALE = 0.02;
    /** Safe-haven flight to safety: financial market panic compresses sovereign term premium (Campbell et al. 2020). */
    public const FLIGHT_TO_SAFETY_SENSITIVITY = 0.015;
    /** Liability-driven long-end demand (Vayanos & Vila 2021 habitat investors; Greenwood & Vissing-Jorgensen 2018): pensions and insurers buy duration once the thirty-year clears the hurdle their liabilities are discounted at, and that demand comes out of the long-end premium. Loads through the half-again duration scale, so a quarter of the excess reaches the thirty-year; read one tick stale off the smoothed thirty-year. */
    public const HABITAT_LONG_END_DEMAND_SENSITIVITY = 0.50;
    /** Restrictive stance term premium compression (ACM 2013): the premium is squeezed as the stance tightens, matching the near-zero ACM premium of 2023. With the Bliss slope decay the inversion itself comes from expected cuts, so this only needs to trim, not erase. */
    public const TERM_PREMIUM_TIGHTENING_COMPRESSION = 0.45;
    /** Annual attenuation speed at which tightening compression fades over a long restrictive phase (half-life ~2.8 years, so a normal two-year peak keeps most of it), as the market accepts higher-for-longer and demands the full premium again. Keyed to the restrictive-stance clock, not the sign of the slope, so a flat curve cannot keep resetting it. */
    public const TERM_PREMIUM_COMPRESSION_DECAY_RATE = 0.25;
    /** Speed at which the restrictive-stance clock unwinds once policy is back at or below neutral (half-life ~4 months). */
    public const RESTRICTIVE_DURATION_UNWIND_RATE = 2.0;

    // --- Term Premium Dynamics (ACM 2013 persistence, Campbell-Pflueger-Viceira 2020 regimes) ---
    /** Annual volatility of transitory term premium shocks: a stationary spread of ~50bps and ~35bps quarterly moves (ACM 2013 quarterly changes run 30-35bps), so a taper tantrum is a two-sigma quarter. */
    public const TERM_PREMIUM_SHOCK_SIGMA = 0.0070;
    /** Annual volatility of the structural regime: a stationary spread of ~50bps around the baseline. */
    public const TERM_PREMIUM_REGIME_SIGMA = 0.0021;
    /** Floor of the structural term premium regime (the 2010s era). */
    public const MIN_TERM_PREMIUM_REGIME = 0.0;

    // --- Central Bank Balance Sheet (QE & QT) ---
    /** Policy rate threshold below which QE bond purchases can be initiated during recessions. */
    public const QE_ACTIVATION_RATE_THRESHOLD = 0.025;
    /** Negative output gap threshold below which central bank initiates QE bond purchases. */
    public const QE_ACTIVATION_GAP_THRESHOLD = -0.005;
    /** Ten-year yield suppression under full-scale QE (~100bps): Gagnon et al. (2011) and Bonis-Ihrig-Wei (2017) put the whole QE1-QE3 stock near 100bps at its 2013 peak. */
    public const QE_MAX_SUPPRESSION = 0.01;
    /** QE dose per unit of negative output gap: full-scale purchases need a ~-2.5% gap with the policy rate at the floor, not a mild slowdown. */
    public const QE_SEVERITY_MULTIPLIER = 0.40;
    /** Annual ramp speed of central bank balance sheet expansion and contraction. */
    public const BALANCE_SHEET_RAMP_SPEED = 1.0;
    /** Positive output gap threshold above which central bank initiates Quantitative Tightening. */
    public const QT_ACTIVATION_GAP_THRESHOLD = 0.010;
    /** Inflation threshold above which central bank initiates Quantitative Tightening */
    public const QT_ACTIVATION_INFLATION_THRESHOLD = 0.022;
    /** Overheating pressure at which runoff reaches full speed; QT is a runoff RATE, not a yield target, because the bank can only return the duration it holds. */
    public const QT_MAX_INTENSITY = 0.005;
    /** Sensitivity multiplier scaling QT bond runoff with economic overheating. */
    public const QT_SEVERITY_MULTIPLIER = 0.40;
    /** Extra runoff speed at full overheating pressure (Fed 2017-19 then 2022 roughly doubled its monthly caps), on top of the passive BALANCE_SHEET_RAMP_SPEED. */
    public const QT_RUNOFF_ACCELERATION = 1.0;

    // --- Sovereign Debt Dynamics (Greenwood-Vayanos 2014) ---
    /** Long-end term premium sensitivity per unit excess debt/GDP above neutral threshold. */
    public const SOVEREIGN_DEBT_YIELD_SENSITIVITY = 0.01;

    // --- Deposits Channel (Drechsler, Savov & Schnabl 2017) ---
    /** Deposit beta at the effective lower bound: banks pass almost nothing through when there is nothing to pass. */
    public const DEPOSIT_BETA_FLOOR = 0.05;
    /** Deposit beta per unit of policy rate, rising with the level as DSS document: the line is anchored so the base beta is paid at the neutral nominal rate, which puts it near 0.30 at 5.25% (the cumulative betas of the 2022-23 cycle) and at the floor below 1%. */
    public const DEPOSIT_BETA_RATE_SENSITIVITY = 6.0;
    /** Ceiling on the system deposit beta; even in the 1980s banks kept a third of the rate. */
    public const MAX_SYSTEM_DEPOSIT_BETA = 0.60;
    /** Time constant (years) of deposit repricing: banks lag the policy rate by about three quarters. */
    public const DEPOSIT_REPRICING_YEARS = 0.75;
    /** Money-market share per unit of deposit spread beyond the neutral spread (the neutral rate less the base beta's share of it): the 2022-23 cycle moved ~5 points of share on ~100 bps of extra spread. */
    public const MMF_SPREAD_SENSITIVITY = 5.0;
    /** Time constant (years) of household migration between deposits and money funds: a slow reallocation, not a run. */
    public const MMF_MIGRATION_YEARS = 1.0;
    /** Floor on the money-market share: a residual institutional base survives a decade at the lower bound. */
    public const MIN_MMF_SHARE = 0.05;
    /** Ceiling on the money-market share. */
    public const MAX_MMF_SHARE = 0.40;

    // --- Monetarist M2 Broad Money Supply Dynamics (Friedman-Schwartz, Brunner-Meltzer) ---
    /** Sensitivity of broad M2 money growth to central bank QE/QT balance sheet operations. */
    public const M2_QE_SENSITIVITY = 2.40;
    /** Sensitivity of commercial bank money creation multiplier to lending standards tightening (SLOOS). */
    public const M2_SLOOS_SENSITIVITY = 0.06;
    /** Cyclical credit demand sensitivity scaling M2 money growth with the output gap. */
    public const M2_GAP_SENSITIVITY = 0.25;
    /** Mean-reversion speed (kappa) of broad money supply growth toward fundamental trajectory. */
    public const M2_KAPPA = 1.80;
    /** Stochastic diffusion volatility of annual M2 money supply growth. */
    public const M2_SIGMA = 0.005;
    /** Lower bound floor for annual M2 money supply growth (-2.0% broad contraction). */
    public const MIN_M2_GROWTH = -0.020;
    /** Upper bound ceiling for annual M2 money supply expansion (+25.0% wartime/crisis expansion). */
    public const MAX_M2_GROWTH = 0.250;

    public function __construct(
        private readonly MathUtility $mathUtility
    ) {}

    /**
     * Short-horizon inflation expectation: the Bernanke (2015) blend of realized core inflation with the
     * forward-looking TIPS breakeven (Shapiro 2022 sectoral core).
     *
     * This is the inflation measure the Taylor rule responds to, and the same measure the IS curve deflates
     * the policy-rate leg of the borrowing cost with: the central bank and the borrowers it acts on look at
     * one expected inflation rate, so the real stance the bank thinks it has set is the one demand feels.
     * Headline is deliberately absent -- a commodity spike is not an expectation.
     *
     * @param MacroState $state           Current macroeconomic state.
     * @param float      $targetInflation Statutory central bank target inflation.
     * @return float Expected inflation over the policy horizon.
     */
    public function calculateExpectedInflation(MacroState $state, float $targetInflation): float
    {
        $coreWeight = MacroAggregateSubsystem::INFLATION_WEIGHT_SUPERCORE + MacroAggregateSubsystem::INFLATION_WEIGHT_GOODS;
        if ($coreWeight > 0 && ($state->supercoreInflationEma !== $targetInflation || $state->coreGoodsInflationEma !== $targetInflation)) {
            $coreInflation = ((MacroAggregateSubsystem::INFLATION_WEIGHT_SUPERCORE * $state->supercoreInflationEma)
                + (MacroAggregateSubsystem::INFLATION_WEIGHT_GOODS * $state->coreGoodsInflationEma)) / $coreWeight;
        } else {
            $coreInflation = $state->inflationEma;
        }

        return (self::TAYLOR_INFLATION_CORE_WEIGHT * $coreInflation)
            + (self::TAYLOR_INFLATION_ANCHOR_WEIGHT * $state->tipsBreakeven);
    }

    /**
     * Taylor (1993) Monetary Policy Rule with Evans (2012) Forward Guidance.
     *
     * Computes the central bank's nominal policy rate target:
     *   r_target = r* + pi_blend + alpha_pi * (pi_blend - pi*) + gamma_y * y_gap - phi_L * long_rate_gap
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
        // Powell (2020) Flexible Average Inflation Targeting (FAIT) cumulative price-level shortfall buffer.
        $inflationShortfall = $state->inflationEma - $targetInflation;
        $state->cumulativeInflationGap += ($inflationShortfall - (self::FAIT_MEMORY_SPEED * $state->cumulativeInflationGap)) * $dt;
        $state->cumulativeInflationGap = max(-0.06, min(0.06, $state->cumulativeInflationGap));
        $faitOffset = min(0.0, self::FAIT_MAKEUP_COEFFICIENT * $state->cumulativeInflationGap);
        $faitOffset = max(-self::FAIT_MAX_TARGET_OFFSET, $faitOffset);

        $inflationMeasure = $this->calculateExpectedInflation($state, $targetInflation);

        // Clarida, Galí & Gertler (2000) forward-looking Taylor rule responding to cyclical trend EMA.
        $cyclicalGap = ($state->outputGapEma === 0.015 && $state->outputGap !== 0.015)
            ? $state->outputGap
            : $state->outputGapEma;

        // Cukierman & Muscatelli (2008) asymmetric recession-averse Taylor rule output gap weighting.
        if ($cyclicalGap < 0.0) {
            $gapWeight = self::TAYLOR_OUTPUT_GAP_WEIGHT
                * (1.0 + min(self::TAYLOR_RECESSION_MAX_BOOST, abs($cyclicalGap) * self::TAYLOR_RECESSION_SCALE));
        } else {
            $gapWeight = self::TAYLOR_OUTPUT_GAP_WEIGHT;
        }

        // Bernanke (2006) offset leaning against exogenous non-monetary long-rate term premium shifts.
        $longRateOffset = self::TAYLOR_LONG_RATE_OFFSET * $this->calculateLongRateGap($state, $naturalRate);

        $unclampedTarget = $naturalRate + $inflationMeasure
            + self::TAYLOR_INFLATION_WEIGHT * ($inflationMeasure - $targetInflation)
            + $gapWeight * $cyclicalGap
            + $faitOffset
            - $longRateOffset;

        // Wu-Xia (2016) / Krippner (2013) shadow rate reflecting unconventional QE accommodation at ZLB.
        $qeShadowAccommodation = $state->qeIntensity * self::WU_XIA_QE_SHADOW_SENSITIVITY;

        return $unclampedTarget - $qeShadowAccommodation;
    }

    /**
     * Long-rate gap the central bank leans against (Bernanke 2006, Curdia-Woodford 2010 spread adjustment).
     *
     * The ten-year moves for reasons the policy rate does not set: the term premium drifting from its structural
     * baseline, and the market's perceived long-run policy rate drifting from the model-consistent endpoint.
     * Both tighten or loosen financial conditions (mortgages, cap rates, sentiment, business borrowing), so the
     * rule offsets a share of them. The central bank's own footprint is excluded twice over: QE is meant to
     * compress the premium and the rule must not undo it, and the compression a restrictive stance itself
     * produces (calculateYieldCurve's tightening term) is not news about the premium either. Read as news, it
     * was a loop: tightening compressed the premium, the rule leaned against the compression by tightening
     * further, and the target sat ~0.4pp above its own rule through every late-cycle inversion.
     *
     * @param MacroState $state       Current macroeconomic state.
     * @param float      $naturalRate Dynamic natural real rate of interest (r*).
     * @return float Deviation of the ten-year from the policy-consistent path, in yield units.
     */
    public function calculateLongRateGap(MacroState $state, float $naturalRate): float
    {
        $habitatShiftAtTenYears = $this->mathUtility->calculatePreferredHabitatTermPremiumShift(
            balanceSheetIntensity: $state->balanceSheetIntensity,
            tau: 10.0,
            habitatSensitivity: MacroEngine::PREFERRED_HABITAT_DURATION_SENSITIVITY
        );
        $ownCompression = $this->restrictiveCompression($state, $naturalRate);
        $premiumDeviation = ($state->termPremium10yEma - $habitatShiftAtTenYears + $ownCompression) - MacroEngine::NS_BASE_TERM_PREMIUM;

        $slopeLoad10y = (1.0 - exp(-10.0 * MacroEngine::SVENSSON_SLOPE_LAMBDA)) / (10.0 * MacroEngine::SVENSSON_SLOPE_LAMBDA);
        $endpointDeviation = self::KOZICKI_TINSLEY_ENDPOINT_WEIGHT
            * ($state->perceivedNeutralRate - ($naturalRate + MacroEngine::TARGET_INFLATION))
            * (1.0 - $slopeLoad10y);

        return $premiumDeviation + $endpointDeviation;
    }

    /**
     * Term premium compression a restrictive stance produces, fading with the time spent restrictive.
     *
     * One definition shared by the curve, which subtracts it, and the long-rate gap, which must not read it
     * as a premium move to lean against.
     *
     * @param MacroState $state       Current macroeconomic state.
     * @param float      $naturalRate Dynamic natural real rate of interest (r*).
     * @return float Premium compression in yield units, zero at or below neutral.
     */
    private function restrictiveCompression(MacroState $state, float $naturalRate): float
    {
        $rawTighteningCompression = self::TERM_PREMIUM_TIGHTENING_COMPRESSION * max(0.0, $state->policyRate - ($naturalRate + MacroEngine::TARGET_INFLATION));
        $compressionDecay = exp(-self::TERM_PREMIUM_COMPRESSION_DECAY_RATE * $state->restrictiveDuration);

        return $rawTighteningCompression * $compressionDecay;
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

        // Evans Rule (FOMC 2012) forward guidance threshold keeping rates at lower bound.
        if (
            $currentPolicyRate <= MacroEngine::ZLB_PROXIMITY_THRESHOLD
            && $effectiveTarget > $currentPolicyRate
            && $state->unemploymentRateEma > MacroEngine::EVANS_RULE_UNEMPLOYMENT
            && max($state->inflationEma, $state->tipsBreakeven) < self::EVANS_RULE_INFLATION_CAP
        ) {
            $effectiveTarget = $currentPolicyRate;
        }

        // Clarida, Galí & Gertler (2000) policy inertia evaluated on cyclical output trend.
        $cyclicalGap = ($state->outputGapEma === 0.015 && $state->outputGap !== 0.015)
            ? $state->outputGap
            : $state->outputGapEma;

        if ($effectiveTarget > $currentPolicyRate) {
            $cbSpeed = self::CB_HIKE_SMOOTHING_SPEED;
            $effectiveInflation = max($state->inflation, $state->inflationEma);
            $inflationPanicExcess = max(0.0, $effectiveInflation - MacroEngine::CB_INFLATION_PANIC_THRESHOLD);
            $panicMultiplier = min(self::CB_MAX_HIKE_PANIC_SPEED, $inflationPanicExcess * self::CB_INFLATION_PANIC_SCALE);
            $cbSpeed += $panicMultiplier;

            // Clarida-Galí-Gertler (2000) emergency rate adjustment ceiling under inflation panic.
            $panicFraction = min(1.0, $panicMultiplier / self::CB_MAX_HIKE_PANIC_SPEED);
            $maxHikeVelocity = MacroEngine::CB_MAX_NORMAL_HIKE_VELOCITY + $panicFraction * (MacroEngine::CB_MAX_PANIC_HIKE_VELOCITY - MacroEngine::CB_MAX_NORMAL_HIKE_VELOCITY);

            $rawMove = $cbSpeed * ($effectiveTarget - $currentPolicyRate);
            $clampedMove = min($maxHikeVelocity, $rawMove);
        } else {
            $cbSpeed = self::CB_CUT_SMOOTHING_SPEED;
            $effectiveDeflation = min($state->inflation, $state->inflationEma);
            $deflationPanic = max(0.0, MacroEngine::TARGET_INFLATION - $effectiveDeflation) * self::CB_INFLATION_PANIC_SCALE;
            $recessionPanic = max(0.0, -$cyclicalGap) * self::CB_RECESSION_PANIC_SCALE;
            $cbSpeed += min(self::CB_MAX_CUT_PANIC_SPEED, $deflationPanic + $recessionPanic);

            $rawMove = $cbSpeed * ($effectiveTarget - $currentPolicyRate);
            $clampedMove = max(self::CB_MAX_CUT_VELOCITY, $rawMove);
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
     * Central Bank Balance Sheet Operations (Bernanke 2020, Vayanos & Vila 2021).
     *
     * Evaluates quantitative asset purchase/runoff targets based on conventional rate room,
     * recession severity, and economic overheating. Manages the reinvestment hold timer
     * and exponential dynamic adjustment speed toward the balance sheet target.
     *
     * The intensity is a STOCK of duration extraction and is floored at zero. Letting it go negative made the
     * bank net short duration whenever the economy ran hot -- measured over 480 simulated years it was negative
     * in 53% of quarters and past -20bp in 18%, lifting the thirty-year by 60bp and more out of a portfolio that
     * in four of eight seeds had never been bought. Duration SUPPLY is already priced through the sovereign debt
     * curvature in beta3; this channel is the central bank's own holdings and can only ever compress.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     * @return array{
     *     new_balance_sheet_intensity: float,
     *     new_hold_timer: float,
     *     new_qe_intensity: float,
     *     new_qt_intensity: float
     * } Updated balance sheet intensity and component intensities.
     */
    public function calculateBalanceSheetOperations(MacroState $state, float $dt): array
    {
        $rateRoomProximity = min(1.0, max(0.0, (self::QE_ACTIVATION_RATE_THRESHOLD - $state->policyRate) / self::QE_ACTIVATION_RATE_THRESHOLD));
        $recessionSeverity = max(0.0, -$state->outputGap);

        if ($rateRoomProximity > 0.0 && $state->outputGap < self::QE_ACTIVATION_GAP_THRESHOLD) {
            $qeYieldSuppressionTarget = min(self::QE_MAX_SUPPRESSION, $rateRoomProximity * $recessionSeverity * self::QE_SEVERITY_MULTIPLIER);
        } else {
            $qeYieldSuppressionTarget = 0.0;
        }

        // Overheating does not create a tightening position out of nothing: it ends the reinvestment phase early
        // and speeds the runoff of whatever is held. Its scale is a rate, not a yield.
        if ($state->outputGap > self::QT_ACTIVATION_GAP_THRESHOLD && $state->inflation > self::QT_ACTIVATION_INFLATION_THRESHOLD) {
            $overheating = ($state->outputGap - self::QT_ACTIVATION_GAP_THRESHOLD) + ($state->inflation - self::QT_ACTIVATION_INFLATION_THRESHOLD);
            $runoffPressure = min(self::QT_MAX_INTENSITY, $overheating * self::QT_SEVERITY_MULTIPLIER);
        } else {
            $runoffPressure = 0.0;
        }

        // Vayanos & Vila (2021) central bank duration extraction portfolio stock floored at zero.
        $portfolio = max(0.0, $state->balanceSheetIntensity);
        $rampSpeed = self::BALANCE_SHEET_RAMP_SPEED;
        $inRunoff = false;

        if ($qeYieldSuppressionTarget > 0.0) {
            // Quantitative easing purchase trajectory ramping toward crisis target dose.
            $balanceSheetTarget = max($portfolio, $qeYieldSuppressionTarget);
            $newHoldTimer = 0.0;
        } elseif ($portfolio > MacroEngine::BALANCE_SHEET_ACTIVE_THRESHOLD) {
            $newHoldTimer = $state->balanceSheetHoldTimer + $dt;

            // Bernanke (2020) reinvestment hold phase preceding systematic balance sheet runoff.
            $inRunoff = $newHoldTimer >= self::BALANCE_SHEET_REINVESTMENT_HOLD_YEARS || $runoffPressure > 0.0;

            if ($inRunoff) {
                $balanceSheetTarget = 0.0;
                $rampSpeed = self::BALANCE_SHEET_RAMP_SPEED
                    * (1.0 + (self::QT_RUNOFF_ACCELERATION * $runoffPressure / self::QT_MAX_INTENSITY));
            } else {
                $balanceSheetTarget = $portfolio;
            }
        } else {
            $balanceSheetTarget = 0.0;
            $newHoldTimer = 0.0;
        }

        $newBalanceSheetIntensity = $balanceSheetTarget + ($portfolio - $balanceSheetTarget) * exp(-$rampSpeed * $dt);
        $newBalanceSheetIntensity = max(0.0, min(self::QE_MAX_SUPPRESSION, $newBalanceSheetIntensity));

        return [
            'new_balance_sheet_intensity' => $newBalanceSheetIntensity,
            'new_hold_timer' => $newHoldTimer,
            // Active cumulative QE duration accommodation (Vayanos & Vila 2021 / Wu & Xia 2016).
            'new_qe_intensity' => $newBalanceSheetIntensity,
            // Quantitative tightening runoff portfolio tracking active balance sheet contraction.
            'new_qt_intensity' => $inRunoff ? $newBalanceSheetIntensity : 0.0,
        ];
    }

    /**
     * Term Premium Dynamics: a two-factor Ornstein-Uhlenbeck decomposition of the structural ten-year premium.
     *
     * Adrian, Crump & Moench (2013) show the term premium is highly persistent but mean-reverting, with
     * transitory swings (the 2013 taper tantrum) riding on slow regime shifts; Campbell, Pflueger & Viceira
     * (2020) tie those regimes to the bond-stock correlation, from the 2% premia of the early 1990s to the
     * near-zero and negative premia of the 2010s. The fast factor reverts to zero, the slow factor to the
     * long-run baseline, and both use the exact OU discretization so the process is tick-rate invariant.
     *
     * Alongside the premium, the expected-path innovation of Diebold & Li (2006): the curvature factor carries
     * a shock of its own, the market repricing the next few years of policy on news between meetings. It is
     * expectations, not premium, so it lives on the curve's beta2 rather than in the premium above.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function updateTermPremiumDynamics(MacroState $state, float $dt): void
    {
        // Diebold & Li (2006) exact Ornstein-Uhlenbeck monetary expectations path innovation.
        $pathDecay = exp(-self::EXPECTED_PATH_SHOCK_KAPPA * $dt);
        $pathVariance = (self::EXPECTED_PATH_SHOCK_SIGMA ** 2 / (2.0 * self::EXPECTED_PATH_SHOCK_KAPPA)) * (1.0 - exp(-2.0 * self::EXPECTED_PATH_SHOCK_KAPPA * $dt));
        $nextPathShock = ($state->expectedPathShock * $pathDecay) + (sqrt($pathVariance) * $this->mathUtility->generateStandardNormal());
        $state->expectedPathShock = max(-self::EXPECTED_PATH_SHOCK_CAP, min(self::EXPECTED_PATH_SHOCK_CAP, $nextPathShock));

        $factors = $this->mathUtility->calculateTwoFactorOU(
            chi: $state->termPremiumShock,
            xi: $state->termPremiumRegime,
            kappaChi: self::TERM_PREMIUM_SHOCK_KAPPA,
            kappaXi: self::TERM_PREMIUM_REGIME_KAPPA,
            thetaChi: 0.0,
            thetaXi: MacroEngine::NS_BASE_TERM_PREMIUM,
            sigChi: self::TERM_PREMIUM_SHOCK_SIGMA,
            sigXi: self::TERM_PREMIUM_REGIME_SIGMA,
            rho: 0.0,
            dt: $dt
        );

        $state->termPremiumShock = max(-self::TERM_PREMIUM_SHOCK_CAP, min(self::TERM_PREMIUM_SHOCK_CAP, $factors['chi']));
        $state->termPremiumRegime = max(self::MIN_TERM_PREMIUM_REGIME, min(self::MAX_TERM_PREMIUM_REGIME, $factors['xi']));
    }

    /**
     * Kozicki & Tinsley (2001) Shifting Endpoints and the restrictive-stance clock.
     *
     * Long-horizon rate expectations are not pinned to the model's r* plus target: the market learns the
     * long-run policy rate slowly from what the central bank actually does, so a decade of 5% policy raises
     * the perceived endpoint and a decade at the floor lowers it. Alongside, a clock accumulates time spent
     * with policy above neutral and unwinds when it is not, so the premium compression of a tightening can
     * fade with its duration instead of resetting whenever a flat curve crosses zero.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function updateMarketExpectations(MacroState $state, float $dt): void
    {
        $state->perceivedNeutralRate += self::KOZICKI_TINSLEY_ADAPTATION_SPEED * ($state->policyRate - $state->perceivedNeutralRate) * $dt;

        $neutralNominalRate = $state->naturalRate + MacroEngine::TARGET_INFLATION;
        if ($state->policyRate > $neutralNominalRate) {
            $state->restrictiveDuration += $dt;
        } else {
            $state->restrictiveDuration *= exp(-self::RESTRICTIVE_DURATION_UNWIND_RATE * $dt);
        }
    }

    /**
     * Nelson-Siegel-Svensson (1994) Term Structure & Adrian-Crump-Moench (2013) ACM Decomposition.
     *
     * Fits the full zero-coupon sovereign yield curve (2Y, 5Y, 10Y, 30Y) with Diebold-Li (2006)
     * forward monetary guidance curvature (beta2) and long-end fiscal/QT supply curvature (beta3).
     * Decomposes the 10Y yield into expected risk-neutral policy rate path and duration term premium.
     *
     * @param MacroState $state           Current macroeconomic state.
     * @param float      $targetInflation Central bank inflation target.
     * @param float      $naturalRate     Dynamic natural real rate of interest (r*).
     * @return array{
     *     level: float,
     *     curvature: float,
     *     curvature2: float,
     *     beta1: float,
     *     base_term_premium: float,
     *     long_end_premium: float,
     *     structural_10y: float,
     *     yield_2y: float,
     *     yield_5y: float,
     *     yield_10y: float,
     *     yield_30y: float,
     *     risk_neutral_10y: float,
     *     term_premium_10y: float
     * } Sovereign yield curve tenors, NSS factors, risk-neutral rate, and term premium.
     */
    public function calculateYieldCurve(MacroState $state, float $targetInflation, float $naturalRate): array
    {
        $inflationRiskPremium = self::TERM_PREMIUM_IRP_EXPECTATION_SCALE * max(0.0, $state->tipsBreakeven - MacroEngine::TARGET_INFLATION);
        $flightToSafetyShift = self::FLIGHT_TO_SAFETY_SENSITIVITY * max(0.0, $state->marketVolatilityEma - MacroEngine::FLIGHT_TO_SAFETY_VOL_THRESHOLD);
        $cyclicalTermPremium = $state->outputGap * self::NS_GAP_TERM_PREMIUM_SCALE;
        $restrictiveCompression = $this->restrictiveCompression($state, $naturalRate);
        // Laubach (2009) structural fiscal debt-to-GDP term premium component.
        $structuralTermPremium = $state->termPremiumRegime + $state->termPremiumShock + $state->sovereignRiskSpreadEma;
        $totalBaseTermPremium = max(self::MIN_TERM_PREMIUM_10Y, $structuralTermPremium + $inflationRiskPremium + $cyclicalTermPremium - $flightToSafetyShift - $restrictiveCompression);

        // Nelson-Siegel (1987) & Diebold-Li (2006) asymptotic risk-neutral rate level beta0.
        $expectedInflation10y = (self::LONG_RUN_INFLATION_ANCHOR_WEIGHT * MacroEngine::TARGET_INFLATION)
            + ((1.0 - self::LONG_RUN_INFLATION_ANCHOR_WEIGHT) * $state->tipsBreakeven);
        // Kozicki & Tinsley (2001) shifting endpoint perception of long-run neutral policy rate.
        $modelEndpoint = $naturalRate + $expectedInflation10y;
        $level = ((1.0 - self::KOZICKI_TINSLEY_ENDPOINT_WEIGHT) * $modelEndpoint)
            + (self::KOZICKI_TINSLEY_ENDPOINT_WEIGHT * $state->perceivedNeutralRate);
        $nsBeta1 = $state->policyRate - $level;

        // Diebold & Li (2006) curvature beta2 capturing forward monetary policy trajectory.
        $monetaryStanceGap = $state->targetRate - $state->policyRate;
        $nsBeta2 = (self::SVENSSON_CURVATURE1_TARGET_SCALE * $monetaryStanceGap)
            + (self::SVENSSON_CURVATURE1_GAP_SCALE * $state->outputGap)
            + $state->expectedPathShock;

        $fiscalShift = ($state->governmentSpendingIndexEma / MacroEngine::GOVT_SPENDING_BASELINE) - 1.0;
        $excessDebt = max(0.0, $state->sovereignDebtToGdpEma - MacroEngine::SOVEREIGN_DEBT_NEUTRAL_THRESHOLD);
        $debtCurvature = $excessDebt * self::SOVEREIGN_DEBT_YIELD_SENSITIVITY;
        $nsBeta3 = (self::SVENSSON_CURVATURE2_FISCAL_SCALE * $fiscalShift) + $debtCurvature;

        // Greenwood & Vayanos (2014) preferred-habitat long-end duration demand hurdle.
        $scale30y = MathUtility::calculateTermPremiumDurationScale(30.0, MacroEngine::TERM_PREMIUM_DURATION_HORIZON_YEARS);
        $longEndHurdle = $naturalRate + $targetInflation + (MacroEngine::NS_BASE_TERM_PREMIUM * $scale30y);
        $habitatDemandShift = self::HABITAT_LONG_END_DEMAND_SENSITIVITY * max(0.0, $state->yield30yEma - $longEndHurdle);
        $longEndPremium = max(0.0, $state->termPremiumRegime - $habitatDemandShift);

        $yield2y  = $this->calculateSvenssonTenor(2.0, $level, $nsBeta1, $nsBeta2, $nsBeta3, $state, $totalBaseTermPremium, $longEndPremium);
        $yield5y  = $this->calculateSvenssonTenor(5.0, $level, $nsBeta1, $nsBeta2, $nsBeta3, $state, $totalBaseTermPremium, $longEndPremium);
        $yield10y = $this->calculateSvenssonTenor(10.0, $level, $nsBeta1, $nsBeta2, $nsBeta3, $state, $totalBaseTermPremium, $longEndPremium);
        $yield30y = $this->calculateSvenssonTenor(30.0, $level, $nsBeta1, $nsBeta2, $nsBeta3, $state, $totalBaseTermPremium, $longEndPremium);

        $durationFactor10y = (1.0 - exp(-10.0 * MacroEngine::SVENSSON_SLOPE_LAMBDA)) / (10.0 * MacroEngine::SVENSSON_SLOPE_LAMBDA);
        $curvatureLoad10y = (1.0 - exp(-10.0 * MacroEngine::SVENSSON_LAMBDA_1)) / (10.0 * MacroEngine::SVENSSON_LAMBDA_1);
        $factor2_10y = $curvatureLoad10y - exp(-10.0 * MacroEngine::SVENSSON_LAMBDA_1);

        // Adrian, Crump & Moench (2013) pure risk-neutral rate path under zero term premium.
        $riskNeutral10y = $level + ($nsBeta1 * $durationFactor10y) + ($nsBeta2 * $factor2_10y);
        $termPremium10y = $yield10y - $riskNeutral10y;

        $structural10y = $this->calculateSvenssonTenor(10.0, $level, $nsBeta1, $nsBeta2, $debtCurvature, $state, $totalBaseTermPremium, $longEndPremium);

        return [
            'level' => $level,
            'curvature' => $nsBeta2,
            'curvature2' => $nsBeta3,
            'beta1' => $nsBeta1,
            'base_term_premium' => $totalBaseTermPremium,
            'long_end_premium' => $longEndPremium,
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
     * Writes a fitted curve onto the state, derives the two reported slopes from it, and runs the inversion
     * clock the district's recession alarm is armed off.
     *
     * The clock lives here rather than beside the restrictive-stance clock in updateMarketExpectations,
     * which is where it looks like it belongs: it reads the STRUCTURAL slope, and that slope does not exist
     * until this curve has been fitted, one step later in the tick. Run it a step early and it measures the
     * previous tick's curve, which is the reading SYSTEMIC_INVERSION_ALARM_YEARS is counting.
     *
     * @param MacroState                   $state     Current macroeconomic state.
     * @param array<string, float>         $yieldData The fitted curve, as calculateYieldCurve() returns it.
     * @param float                        $dt        Time increment in years.
     */
    public function applyYieldCurve(MacroState $state, array $yieldData, float $dt): void
    {
        $state->yield2y = $yieldData['yield_2y'];
        $state->yield5y = $yieldData['yield_5y'];
        $state->yield10y = $yieldData['yield_10y'];
        $state->yield30y = $yieldData['yield_30y'];
        $state->nsLevel = $yieldData['level'];
        $state->nsCurvature = $yieldData['curvature'];
        $state->nsCurvature2 = $yieldData['curvature2'];

        // Nelson-Siegel-Svensson (1994) fitted parameters retained for continuous discounting.
        $state->nsBeta1 = $yieldData['beta1'];
        $state->nsBaseTermPremium = $yieldData['base_term_premium'];
        $state->nsLongEndPremium = $yieldData['long_end_premium'];

        $state->termPremium10y = $yieldData['term_premium_10y'];
        $state->riskNeutral10y = $yieldData['risk_neutral_10y'];

        $state->nsSlope = $state->yield10y - $state->policyRate;
        $state->structuralSlope = $yieldData['structural_10y'] - $state->policyRate;

        if ($state->structuralSlope < 0.0) {
            $state->inversionDuration += $dt;
        } else {
            $state->inversionDuration = 0.0;
        }
    }

    /**
     * Nelson-Siegel-Svensson Term Structure & Balance Sheet Composite Wrapper.
     *
     * Preserves backward compatibility by evaluating both central bank balance sheet
     * operations and the resulting sovereign term structure decomposition in a single call.
     *
     * @param MacroState $state           Current macroeconomic state.
     * @param float      $targetInflation Central bank inflation target.
     * @param float      $naturalRate     Dynamic natural real rate of interest (r*).
     * @param float      $dt              Time increment in years.
     * @return array{
     *     new_balance_sheet_intensity: float,
     *     new_hold_timer: float,
     *     new_qe_intensity: float,
     *     new_qt_intensity: float,
     *     level: float,
     *     curvature: float,
     *     curvature2: float,
     *     beta1: float,
     *     base_term_premium: float,
     *     long_end_premium: float,
     *     structural_10y: float,
     *     yield_2y: float,
     *     yield_5y: float,
     *     yield_10y: float,
     *     yield_30y: float,
     *     risk_neutral_10y: float,
     *     term_premium_10y: float
     * } Decomposition containing yields, NSS factors, and QE/QT intensities.
     */
    public function calculateYieldCurveAndQE(MacroState $state, float $targetInflation, float $naturalRate, float $dt): array
    {
        $bsData = $this->calculateBalanceSheetOperations($state, $dt);

        $evalState = clone $state;
        $evalState->balanceSheetIntensity = $bsData['new_balance_sheet_intensity'];

        $yieldData = $this->calculateYieldCurve($evalState, $targetInflation, $naturalRate);

        return array_merge($bsData, $yieldData);
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
    /**
     * @param float $termPremium10y The ten-year term premium; each tenor up to ten years carries the share of it
     *                              its duration earns (ACM 2013), from nothing at zero maturity to all of it at ten.
     *                              Beyond ten years it lands one-for-one, so the long end moves with the ten-year.
     * @param float $longEndPremium The structural share of that premium which keeps rising with duration past
     *                              ten years: a thirty-year bond carries half again as much of it.
     */
    public function calculateSvenssonTenor(float $t, float $level, float $nsBeta1, float $nsBeta2, float $nsBeta3, MacroState $state, float $termPremium10y = 0.0, float $longEndPremium = 0.0): float
    {
        // Vayanos & Vila (2021) Preferred-Habitat Model: duration extraction under QE/QT compresses term premium by tenor duration
        return $this->mathUtility->calculateSovereignZeroYield(
            tau: $t,
            level: $level,
            slope: $nsBeta1,
            curvature1: $nsBeta2,
            curvature2: $nsBeta3,
            lambda1: MacroEngine::SVENSSON_LAMBDA_1,
            lambda2: MacroEngine::SVENSSON_LAMBDA_2,
            slopeLambda: MacroEngine::SVENSSON_SLOPE_LAMBDA,
            termPremium10y: $termPremium10y,
            longEndPremium: $longEndPremium,
            termPremiumHorizonYears: MacroEngine::TERM_PREMIUM_DURATION_HORIZON_YEARS,
            balanceSheetIntensity: $state->balanceSheetIntensity,
            habitatSensitivity: MacroEngine::PREFERRED_HABITAT_DURATION_SENSITIVITY,
            effectiveLowerBound: MacroEngine::EFFECTIVE_LOWER_BOUND
        );
    }

    /**
     * The deposits channel (Drechsler, Savov & Schnabl 2017): banks' market power over deposits means the
     * deposit rate follows the policy rate only in part, and the part grows with the level of rates. The
     * spread that opens is what money-market funds compete on, so household liquid assets migrate toward
     * them as rates rise and drift back at the lower bound. Both move slowly: deposits reprice over a few
     * quarters and households reallocate over about a year.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateDepositChannel(MacroState $state, float $dt): void
    {
        $policyRate = max(0.0, $state->policyRateEma);
        $neutralRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;

        // Drechsler, Savov & Schnabl (2017) imperfect deposit beta pass-through across rate cycles.
        $targetBeta = MacroEngine::SYSTEM_DEPOSIT_BETA_BASE + (self::DEPOSIT_BETA_RATE_SENSITIVITY * ($policyRate - $neutralRate));
        $targetBeta = max(self::DEPOSIT_BETA_FLOOR, min(self::MAX_SYSTEM_DEPOSIT_BETA, $targetBeta));
        $state->systemDepositBeta = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $state->systemDepositBeta,
            targetValue: $targetBeta,
            dt: $dt,
            lagTimeConstant: self::DEPOSIT_REPRICING_YEARS
        );

        $depositSpread = $policyRate * (1.0 - $state->systemDepositBeta);
        $neutralSpread = $neutralRate * (1.0 - MacroEngine::SYSTEM_DEPOSIT_BETA_BASE);
        $targetShare = MacroEngine::MMF_SHARE_BASE + (self::MMF_SPREAD_SENSITIVITY * ($depositSpread - $neutralSpread));
        $targetShare = max(self::MIN_MMF_SHARE, min(self::MAX_MMF_SHARE, $targetShare));
        $state->moneyMarketFundShare = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $state->moneyMarketFundShare,
            targetValue: $targetShare,
            dt: $dt,
            lagTimeConstant: self::MMF_MIGRATION_YEARS
        );
    }

    /**
     * Estrella & Mishkin (1998) / Wright (2006) 12-Month Forward Recession Probit Model.
     *
     * Evaluates market-implied probability of recession over the next 12 months using the sovereign
     * yield curve slope (10Y minus policy rate), term premium, and Financial Conditions Index:
     *   P(Recession) = NormalCDF(beta0 + betaSlope * slope + betaTp * termPremium + betaFci * FCI)
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public function calculateRecessionProbability(MacroState $state): void
    {
        $slope = $state->yield10y - $state->policyRate;
        $state->recessionProbability = $this->mathUtility->calculateEstrellaMishkinProbability(
            slope: $slope,
            termPremium: $state->termPremium10y,
            fci: $state->financialConditionsIndexEma,
            beta0: MacroEngine::RECESSION_PROBIT_BETA_0,
            betaSlope: MacroEngine::RECESSION_PROBIT_BETA_SLOPE,
            betaTp: MacroEngine::RECESSION_PROBIT_BETA_TP,
            betaFci: MacroEngine::RECESSION_PROBIT_BETA_FCI
        );
    }

    /**
     * Friedman-Schwartz / Brunner-Meltzer M2 Broad Money Supply Growth.
     *
     * Models annual growth rate of broad M2 money stock based on potential nominal GDP expansion,
     * central bank balance sheet liquidity creation (QE/QT), commercial bank underwriting stance (SLOOS),
     * and cyclical credit demand (output gap):
     *   Target = BaseGrowth + beta_QE * BalanceSheet - beta_SLOOS * SLOOS + beta_Y * OutputGap
     *
     * @param MacroState $state         Current macroeconomic state.
     * @param float      $dt            Time step in years.
     * @param float      $tfpGrowthRate Realized annual trend TFP growth rate.
     */
    public function calculateMoneySupplyGrowth(MacroState $state, float $dt, float $tfpGrowthRate): void
    {
        $baseGrowth = MacroEngine::TARGET_INFLATION + $tfpGrowthRate + MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE;

        $dW = $this->mathUtility->generateStandardNormal();

        $params = [
            'qeSens' => self::M2_QE_SENSITIVITY,
            'sloosSens' => self::M2_SLOOS_SENSITIVITY,
            'gapSens' => self::M2_GAP_SENSITIVITY,
            'kappa' => self::M2_KAPPA,
            'sigma' => self::M2_SIGMA,
            'min' => self::MIN_M2_GROWTH,
            'max' => self::MAX_M2_GROWTH,
        ];

        $state->moneySupplyGrowth = $this->mathUtility->calculateBroadMoneyGrowth(
            currentM2Growth: $state->moneySupplyGrowth,
            baseGrowth: $baseGrowth,
            balanceSheetIntensity: $state->balanceSheetIntensity,
            sloosTightening: $state->sloosTighteningIndexEma,
            outputGap: $state->outputGap,
            dt: $dt,
            dW: $dW,
            params: $params
        );
    }
}
