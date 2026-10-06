<?php

namespace App\Service\Macro\Subsystem;

use App\Data\MacroFieldRegistry;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
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
    /** Weight on inflation deviations from the target, fitted with the gap weight by indirect inference on the US Clarida-Gali-Gertler rule, 1987-2008 (smoothing 0.85, inflation 1.87, gap 1.84) and on when the funds rate moves against the gap: the engine's rule regression reads 0.86, 1.70, 1.76 (2.4 read 2.49 once the financial scar, which depressed it, was removed). */
    public const TAYLOR_INFLATION_WEIGHT = 1.6;
    /** Weight on the projected output gap, the same in slack as in a boom (the US rule shows no asymmetry: impact 0.24 in booms, 0.29 in slack, se 0.12-0.15); the same fit. */
    public const TAYLOR_OUTPUT_GAP_WEIGHT = 1.5;
    /** Bernanke (2015) blend: weight on realized core inflation (EMA) in the Taylor Rule inflation measure. */
    public const TAYLOR_INFLATION_CORE_WEIGHT = 0.70;
    /** Bernanke (2015) blend: weight on forward inflation expectations (TIPS breakeven) in the Taylor Rule inflation measure. */
    public const TAYLOR_INFLATION_ANCHOR_WEIGHT = 0.30;

    // --- The Rate Committee's Supermajorities (Bordo & Istrefi 2023, Table 6, col. 4; FOMC 1987-2007) ---
    /** Points of rate a dovish supermajority sets less per point of inflation: -0.35 (se 0.15) on the FOMC's inflation response of 1.71. */
    public const DOVISH_MAJORITY_INFLATION_RESPONSE = 0.35;
    /** Points of rate a hawkish supermajority sets less per point of output gap, cutting less into a slump: -0.17 (se 0.08) on 0.71. The other two interactions were insignificant (-0.12, 0.01). */
    public const HAWKISH_MAJORITY_GAP_RESPONSE = 0.17;
    /** Share of the FOMC's meetings with a dovish supermajority, a quarter by its definition (App\Service\Politics\MonetaryAuthority): the rule as fitted is the FOMC's average committee's, so each term is centred on its share. */
    public const DOVISH_MAJORITY_SHARE = 0.25;
    /** Share of the FOMC's meetings with a hawkish supermajority, likewise a quarter. */
    public const HAWKISH_MAJORITY_SHARE = 0.25;

    // --- Giving Ground to Political Pressure (Gavin & Manger 2023; Drechsel 2024) ---
    /** Cut in the rule's rate while the Authority gives ground to the cabinet, fitted with the drift below (var/harness/pressure/irf.sh, 256 paired games) so an episode it gives in to leaves the policy rate 0.3pp lower three quarters on, as a pressure event under a highly populist government does (Gavin & Manger 2023, p. 26). */
    public const PRESSURE_RATE_CONCESSION = 0.0074;
    /** Rate (per year) at which the inflation the public expects the Authority to tolerate drifts up while it gives ground, fitted with the cut above so the episode lifts inflation 0.5pp six quarters on (Gavin & Manger 2023, p. 26); the price level then stands 2.8% higher four years on, as Drechsel's (2024, Figure 7) US pressure shock leaves it (2.9%). */
    public const PRESSURE_ANCHOR_DRIFT_RATE = 0.0325;

    // --- Forward-Looking Policy Horizon (Clarida, Gali & Gertler 2000; Batini & Haldane 1999) ---
    /** Quarters of gap momentum the rule projects forward. One quarter is what a staff projection actually carries; the longer horizons swept here read as foresight but act as gain, buying a broader spectrum with the left tail (0.75y halves the share of quarters below -3%). */
    public const TAYLOR_GAP_FORECAST_YEARS = 0.25;
    /** Cap on that projection in gap units: a quarter's momentum extrapolated a year out is a forecast, not a measurement, and an uncapped derivative hands the rule the diffusion term. */
    public const TAYLOR_GAP_FORECAST_CAP = 0.020;
    /** Evans Rule forward guidance: Maximum inflation ceiling tolerated while holding rates at ZLB. */
    public const EVANS_RULE_INFLATION_CAP = 0.025;
    /** Unemployment over the NAIRU above which forward guidance holds the rate at the floor: the FOMC's 6.5% threshold (Dec 2012) sat 0.9pp over the 5.6% midpoint of its longer-run range (5.2-6.0), so the hold ends at the same slack however much the NAIRU has scarred. */
    public const EVANS_RULE_UNEMPLOYMENT_GAP = 0.009;
    /** Partial-adjustment speed of the policy rate toward its target, the same cutting as hiking (US: 15% vs 13% of the distance closed a quarter, se 4-6%): the rate trails its target by a quarter, and like the funds rate (1985-2008) peaks 2q after the gap. */
    public const CB_SMOOTHING_SPEED = 4.0;
    /** Inflation panic reaction multiplier accelerating rate hikes during extreme inflation spikes. */
    public const CB_INFLATION_PANIC_SCALE = 50.0;
    /** Most the inflation panic adds to the partial-adjustment speed, per year (Volcker-style acceleration). */
    public const CB_MAX_HIKE_PANIC_SPEED = 3.0;
    /** Fastest the policy rate rises, per year: the FOMC's largest modern step (75bp) at each of its eight meetings, 2022's pace (+150bp in Q3); cuts are not capped, the fastest US cuts (-200bp in 2008Q1, with intermeeting moves) outran it. */
    public const CB_MAX_HIKE_VELOCITY = 0.06;

    // --- Nelson-Siegel-Svensson Term Structure Dynamics (Svensson 1994) ---
    /** Flight-to-safety sensitivity: recessions compress term premium via safe-haven demand (Campbell et al. 2017). */
    public const NS_GAP_TERM_PREMIUM_SCALE = 0.05;
    /** Sensitivity of secondary curvature (beta3) to quantitative tightening and long-term fiscal deficits. */
    public const SVENSSON_CURVATURE2_FISCAL_SCALE = 0.02;
    /** Safe-haven flight to safety: financial market panic compresses sovereign term premium (Campbell et al. 2020). */
    public const FLIGHT_TO_SAFETY_SENSITIVITY = 0.015;
    /** Liability-driven long-end demand (Vayanos & Vila 2021 habitat investors; Greenwood & Vissing-Jorgensen 2018): pensions and insurers buy duration once the thirty-year clears the hurdle their liabilities are discounted at, and that demand comes out of the long-end premium. Loads through the half-again duration scale, so a quarter of the excess reaches the thirty-year; read one tick stale off the smoothed thirty-year. */
    public const HABITAT_LONG_END_DEMAND_SENSITIVITY = 0.50;

    // --- Term Premium Dynamics (ACM 2013 persistence, Campbell-Pflueger-Viceira 2020 regimes) ---
    /** Annual volatility of transitory term premium shocks: a stationary spread of ~50bps and ~35bps quarterly moves (ACM 2013 quarterly changes run 30-35bps), so a taper tantrum is a two-sigma quarter. */
    public const TERM_PREMIUM_SHOCK_SIGMA = 0.0070;
    /** Annual volatility of the structural regime: a stationary spread of ~50bps around the baseline. */
    public const TERM_PREMIUM_REGIME_SIGMA = 0.0021;
    /** Floor of the structural term premium regime (the 2010s era). */
    public const MIN_TERM_PREMIUM_REGIME = 0.0;

    // --- Central Bank Balance Sheet (QE & QT) ---
    /** Ten-year yield suppression under full-scale QE (~100bps): Gagnon et al. (2011) and Bonis-Ihrig-Wei (2017) put the whole QE1-QE3 stock near 100bps at its 2013 peak. */
    public const QE_MAX_SUPPRESSION = 0.01;
    /** Cut the floor forbids that the full programme stands in for: the Wu-Xia (2016) shadow rate bottomed near -3% in 2014 with the QE1-QE3 stock at its peak. */
    public const QE_FULL_PROGRAM_SHORTFALL = 0.03;
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


    // --- Deposits Channel (Drechsler, Savov & Schnabl 2017) ---
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
        private readonly MathUtility $mathUtility,
        /** Records what set the target and what held the rate. Null in a test or a headless harness, off everywhere the ticker is not. */
        private readonly ?MacroDiagnosticsProbe $diagnostics = null
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
        return (self::TAYLOR_INFLATION_CORE_WEIGHT * $this->realizedCoreInflation($state, $targetInflation))
            + (self::TAYLOR_INFLATION_ANCHOR_WEIGHT * $state->tipsBreakeven);
    }

    /**
     * The realized core leg of that blend: supercore and core goods at their CPI weights, or headline's EMA while
     * both still sit at their opening.
     *
     * @param MacroState $state           Current macroeconomic state.
     * @param float      $targetInflation Statutory central bank target inflation.
     * @return float Realized core inflation (EMA).
     */
    private function realizedCoreInflation(MacroState $state, float $targetInflation): float
    {
        if ($state->supercoreInflationEma !== $targetInflation || $state->coreGoodsInflationEma !== $targetInflation) {
            return MacroAggregateSubsystem::coreInflationEma($state);
        }

        return $state->inflationEma;
    }

    /**
     * Projected output gap the policy rule responds to (Clarida, Galí & Gertler 2000; Batini & Haldane 1999).
     *
     * CGG's rule responds to E_t[y_{t+k}] rather than to a trailing measure, and the difference is phase,
     * not gain. Decompose a delayed response -k*y(t-tau) at frequency w: it splits into a spring
     * -k*cos(w*tau)*y and a term +k*sin(w*tau)/w * dy/dt that subtracts from the loop's damping, so a lag
     * spends restoring force on anti-damping, and at w*tau = 90 degrees spends all of it. Measured over 45
     * simulated years the delivered stance lagged the gap by 6 of the cycle's 24 quarters -- exactly 90
     * degrees -- and the gap rang at that frequency: 90% of its variance inside the 4-9y band against a real
     * ~50%, autocorrelation -0.79 at lag 13q against a real ~-0.15. The loop stayed net stable (AR(2) zeta
     * +0.38); it was a lightly damped resonance, not a self-exciting one.
     *
     * Projecting the gap forward is the lead compensation, and is what a staff forecast is for. The trend
     * term is exact rather than estimated: the EMA's own ODE gives d(ema)/dt = (y - ema) / horizon, and
     * Brown (1963) linear exponential smoothing forecasts h years out as level + h * trend. During cold
     * start the EMA still holds its seed, so no trend is read off it.
     *
     * The part of the gap a productivity gain opens (output catching up with new technology before potential
     * does) is seen through: the rule answers spending, and meets supply only through the inflation it brings.
     * That is how the late-1990s productivity boom was run (Blinder & Yellen 2001), and it is what US data
     * shows: the gap still stands +0.5% two years after a TFP gain. Leaning on it instead pulled the engine's
     * gap below zero within ten quarters. With the forecast horizon equal to the EMA horizon, the total gap's
     * level-plus-trend forecast less today's supply part is exactly the demand gap's own forecast.
     *
     * @param MacroState $state Current macroeconomic state.
     * @return float Demand-driven output gap projected TAYLOR_GAP_FORECAST_YEARS ahead.
     */
    private function cyclicalGapForecast(MacroState $state): float
    {
        $openingGap = MacroFieldRegistry::defaults()['outputGapEma'];
        if ($state->outputGapEma === $openingGap && $state->outputGap !== $openingGap) {
            return $state->outputGap - $state->productivitySupplyGap;
        }

        $trend = ($state->outputGap - $state->outputGapEma) / MacroAggregateSubsystem::STANDARD_EMA_HORIZON_YEARS;
        $projection = $trend * self::TAYLOR_GAP_FORECAST_YEARS;

        return $state->outputGapEma
            + max(-self::TAYLOR_GAP_FORECAST_CAP, min(self::TAYLOR_GAP_FORECAST_CAP, $projection))
            - $state->productivitySupplyGap;
    }

    /**
     * Taylor (1993) Monetary Policy Rule with Evans (2012) Forward Guidance.
     *
     * Computes the central bank's nominal policy rate target:
     *   r_target = r* + pi_blend + alpha_pi * (pi_blend - pi*) + gamma_y * y_gap - phi_L * long_rate_gap + committee
     * where, while the District's politics hands the macro a rate committee, committee is its supermajority's lean
     * (committeeMajorityTerm()).
     * Under the Evans Rule, locks target at 0% (ZLB) when the central bank is at
     * the lower bound (or unconstrained target <= 0) and unemployment is elevated
     * while inflation remains contained.
     *
     * @param MacroState $state           Current macroeconomic state.
     * @param float      $targetInflation Statutory central bank target inflation.
     * @param float      $naturalRate     Dynamic natural real rate of interest (r*).
     * QE is not subtracted from the rule. Its measured effect on yields (about 100bp on the ten-year, Gagnon et al.
     * 2011) already reaches demand through the balance sheet's term-premium channel in the curve, and the Wu & Xia
     * (2016) shadow rate is a summary of that curve which equals the policy rate once the floor stops binding.
     * Subtracting the held stock here counted QE twice and, through the reinvestment hold after liftoff, kept the
     * rate about 3pp under the rule into the following boom.
     *
     * @param float      $dt              Time increment in years.
     * @return float Unclamped rule rate; below the lower bound it reads the cuts the floor forbids.
     */
    public function calculateTargetRate(MacroState $state, float $targetInflation, float $naturalRate, float $dt = 0.25): float
    {
        $inflationMeasure = $this->calculateExpectedInflation($state, $targetInflation);

        $cyclicalGap = $this->cyclicalGapForecast($state);

        // Bernanke (2006) offset leaning against exogenous non-monetary long-rate term premium shifts.
        $longRateOffset = self::TAYLOR_LONG_RATE_OFFSET * $this->calculateLongRateGap($state, $naturalRate);

        $committee = self::committeeMajorityTerm($state->authorityCommitteeSeated > 0.0 ? $state->authorityMajority : null, $inflationMeasure, $cyclicalGap);

        // Gavin & Manger (2023): the rate the Authority concedes to the cabinet while it gives ground; and while it does, it
        // tolerates the inflation the public has come to expect of it rather than leaning against it (Drechsel 2024).
        $concession = self::PRESSURE_RATE_CONCESSION * $state->authorityConcession;
        $toleratedInflation = $targetInflation + ($state->inflationAnchorDrift * $state->authorityConcession);

        $unclampedTarget = $naturalRate + $inflationMeasure
            + self::TAYLOR_INFLATION_WEIGHT * ($inflationMeasure - $toleratedInflation)
            + self::TAYLOR_OUTPUT_GAP_WEIGHT * $cyclicalGap
            - $longRateOffset
            + $committee
            - $concession;

        $target = $unclampedTarget;

        // The inflation measure is a blend weighted to one, so its level and the response to its gap split exactly
        // into a realized-core leg and an expectations leg on top of r* and the target. The gap response splits into
        // the answer to the whole projected gap and the productivity part the rule sees through.
        if ($this->diagnostics?->isEnabled()) {
            $inflationResponse = 1.0 + self::TAYLOR_INFLATION_WEIGHT;
            $this->diagnostics->recordPolicyTarget([
                'naturalRate' => $naturalRate,
                'inflationTarget' => $targetInflation * (self::TAYLOR_INFLATION_CORE_WEIGHT + self::TAYLOR_INFLATION_ANCHOR_WEIGHT),
                'coreInflationGap' => $inflationResponse * self::TAYLOR_INFLATION_CORE_WEIGHT * ($this->realizedCoreInflation($state, $targetInflation) - $targetInflation),
                'expectationsGap' => $inflationResponse * self::TAYLOR_INFLATION_ANCHOR_WEIGHT * ($state->tipsBreakeven - $targetInflation),
                'outputGap' => self::TAYLOR_OUTPUT_GAP_WEIGHT * ($cyclicalGap + $state->productivitySupplyGap),
                'productivitySeenThrough' => -self::TAYLOR_OUTPUT_GAP_WEIGHT * $state->productivitySupplyGap,
                'longRateOffset' => -$longRateOffset,
                'committeeMajority' => $committee,
                'pressureConcession' => -$concession,
            ], $target, $dt);
        }

        return $target;
    }

    /**
     * The inflation the public expects the Authority to tolerate, as a drift above its target (Drechsel 2024; Kozicki &
     * Tinsley 2001): it climbs while the Authority gives ground to the cabinet, and once the pressure ends the public
     * relearns the target at the slow speed it relearns any long-run level, so prices that rose under it stay risen.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function updateInflationAnchor(MacroState $state, float $dt): void
    {
        $state->inflationAnchorDrift = $this->mathUtility->calculateDistributedLag(
            currentLaggedValue: $state->inflationAnchorDrift,
            targetValue: self::PRESSURE_ANCHOR_DRIFT_RATE * $state->authorityConcession / self::KOZICKI_TINSLEY_ADAPTATION_SPEED,
            dt: $dt,
            lagTimeConstant: 1.0 / self::KOZICKI_TINSLEY_ADAPTATION_SPEED
        );
    }

    /**
     * The rate committee's lean on the rule (Bordo & Istrefi 2023, Table 6, col. 4): a dovish supermajority answers each
     * point of inflation DOVISH_MAJORITY_INFLATION_RESPONSE less, a hawkish one each point of output gap
     * HAWKISH_MAJORITY_GAP_RESPONSE less. The rule as fitted is the FOMC's average committee's, so each term is centred on
     * the quarter of the FOMC's meetings its supermajority held: a committee that leans as the FOMC did adds nothing over
     * the long run, and one that holds a supermajority more often than that leans the rule for good.
     *
     * @param float|null $majority 1 hawkish, -1 dovish, 0 neither; null while no committee is handed over.
     * @param float      $inflation The rule's inflation measure.
     * @param float      $gap       The rule's output gap.
     */
    public static function committeeMajorityTerm(?float $majority, float $inflation, float $gap): float
    {
        if ($majority === null) {
            return 0.0;
        }

        $dovish = $majority < 0.0 ? 1.0 : 0.0;
        $hawkish = $majority > 0.0 ? 1.0 : 0.0;

        return -(self::DOVISH_MAJORITY_INFLATION_RESPONSE * $inflation * ($dovish - self::DOVISH_MAJORITY_SHARE))
            - (self::HAWKISH_MAJORITY_GAP_RESPONSE * $gap * ($hawkish - self::HAWKISH_MAJORITY_SHARE));
    }

    /**
     * Long-rate gap the central bank leans against (Bernanke 2006, Curdia-Woodford 2010 spread adjustment).
     *
     * The ten-year moves for reasons the policy rate does not set: the term premium drifting from its structural
     * baseline, and the market's perceived long-run policy rate drifting from the model-consistent endpoint.
     * Both tighten or loosen financial conditions (mortgages, cap rates, sentiment, business borrowing), so the
     * rule offsets a share of them. The central bank's own balance-sheet footprint is excluded: QE is meant to
     * compress the premium and the rule must not undo it.
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
        // Sovereign credit risk is left in the yield: a rule easing as the debt premium rises is fiscal dominance.
        $premiumDeviation = ($state->termPremium10yEma - $habitatShiftAtTenYears - $state->sovereignRiskSpreadEma) - MacroEngine::NS_BASE_TERM_PREMIUM;

        $slopeLoad10y = (1.0 - exp(-10.0 * MacroEngine::SVENSSON_SLOPE_LAMBDA)) / (10.0 * MacroEngine::SVENSSON_SLOPE_LAMBDA);
        $endpointDeviation = self::KOZICKI_TINSLEY_ENDPOINT_WEIGHT
            * ($state->perceivedNeutralRate - ($naturalRate + MacroEngine::TARGET_INFLATION))
            * (1.0 - $slopeLoad10y);

        return $premiumDeviation + $endpointDeviation;
    }

    /**
     * Clarida, Galí & Gertler (1998) Partial Adjustment Monetary Policy Smoothing.
     *
     * Models inertial interest rate adjustments toward the Taylor target at one speed either way (1987-2008:
     * 0.66/yr cutting, 0.57/yr hiking, not distinguishable), as an exact first-order lag, with hikes accelerating
     * to Volcker speed during runaway inflation spikes. The pace is proportional to the distance up to the fastest
     * the FOMC has hiked since 1985 (75bp a meeting, 2022). Without that ceiling the partial adjustment turned a
     * target that jumps -- liftoff after forward guidance, a demand boom -- into +650bp in a quarter at 2.8%
     * inflation, and 5% of years hiked over 300bp against the Fed's 1% (1985-2008).
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
        $evansHold = $currentPolicyRate <= MacroEngine::ZLB_PROXIMITY_THRESHOLD
            && $effectiveTarget > $currentPolicyRate
            && $state->unemploymentRateEma > $state->nairu + self::EVANS_RULE_UNEMPLOYMENT_GAP
            && max($state->inflationEma, $state->tipsBreakeven) < self::EVANS_RULE_INFLATION_CAP;
        if ($evansHold) {
            $effectiveTarget = $currentPolicyRate;
        }

        $cbSpeed = self::CB_SMOOTHING_SPEED;
        if ($effectiveTarget > $currentPolicyRate) {
            $effectiveInflation = max($state->inflation, $state->inflationEma);
            $inflationPanicExcess = max(0.0, $effectiveInflation - MacroEngine::CB_INFLATION_PANIC_THRESHOLD);
            $cbSpeed += min(self::CB_MAX_HIKE_PANIC_SPEED, $inflationPanicExcess * self::CB_INFLATION_PANIC_SCALE);
        }

        $maxSpeed = $effectiveTarget > $currentPolicyRate ? self::CB_MAX_HIKE_VELOCITY : INF;
        $newPolicyRate = $this->mathUtility->calculateSpeedLimitedDistributedLag($currentPolicyRate, $effectiveTarget, $dt, 1.0 / $cbSpeed, $maxSpeed);

        if ($this->diagnostics?->isEnabled()) {
            $this->diagnostics->recordPolicyRate($newPolicyRate, [
                'lowerBound' => $targetRate <= MacroEngine::EFFECTIVE_LOWER_BOUND,
                'evansHold' => $evansHold,
                // Past the knee of the speed-limited lag, where the partial adjustment would outrun the ceiling.
                'hikeCeiling' => $maxSpeed < INF && ($effectiveTarget - $currentPolicyRate) > ($maxSpeed / $cbSpeed),
                'panicSpeed' => $cbSpeed > self::CB_SMOOTHING_SPEED,
                'targetCap' => $targetRate >= MacroEngine::POLICY_RATE_CEILING,
            ], $dt);
        }

        return $newPolicyRate;
    }

    /**
     * Central Bank Balance Sheet Operations (Bernanke 2020, Vayanos & Vila 2021).
     *
     * Purchases begin only when the policy rule asks for a rate below the floor and are sized by how far below, the
     * shortfall a shadow rate measures; the US bought only at the floor (2008, 2020). Runoff follows a reinvestment
     * hold, sooner when the economy overheats. Manages the hold timer and the exponential adjustment toward target.
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
        // Purchases stand in for the cut the floor forbids (Wu & Xia 2016): none while the rule's own rate is reachable,
        // the full programme once the rule asks for the shadow rate the 2014 stock delivered.
        $floorShortfall = max(0.0, MacroEngine::EFFECTIVE_LOWER_BOUND - $state->targetRate);
        $qeYieldSuppressionTarget = min(self::QE_MAX_SUPPRESSION, self::QE_MAX_SUPPRESSION * $floorShortfall / self::QE_FULL_PROGRAM_SHORTFALL);

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
     * Kozicki & Tinsley (2001) Shifting Endpoints.
     *
     * Long-horizon rate expectations are not pinned to the model's r* plus target: the market learns the
     * long-run policy rate slowly from what the central bank actually does, so a decade of 5% policy raises
     * the perceived endpoint and a decade at the floor lowers it.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function updateMarketExpectations(MacroState $state, float $dt): void
    {
        $state->perceivedNeutralRate += self::KOZICKI_TINSLEY_ADAPTATION_SPEED * ($state->policyRate - $state->perceivedNeutralRate) * $dt;
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
        // Laubach (2009) structural fiscal debt-to-GDP term premium component.
        $structuralTermPremium = $state->termPremiumRegime + $state->termPremiumShock + $state->sovereignRiskSpreadEma;
        $totalBaseTermPremium = max(self::MIN_TERM_PREMIUM_10Y, $structuralTermPremium + $inflationRiskPremium + $cyclicalTermPremium - $flightToSafetyShift);

        // Nelson-Siegel (1987) & Diebold-Li (2006) asymptotic risk-neutral rate level beta0.
        // Kozicki & Tinsley (2001) shifting endpoint perception of long-run neutral policy rate. The model-consistent
        // endpoint prices inflation at the ten-year breakeven, which is already as anchored as the US one.
        $modelEndpoint = $naturalRate + $state->tipsBreakeven;
        $level = ((1.0 - self::KOZICKI_TINSLEY_ENDPOINT_WEIGHT) * $modelEndpoint)
            + (self::KOZICKI_TINSLEY_ENDPOINT_WEIGHT * $state->perceivedNeutralRate);
        $nsBeta1 = $state->policyRate - $level;

        // Diebold & Li (2006) curvature beta2 capturing forward monetary policy trajectory.
        $monetaryStanceGap = $state->targetRate - $state->policyRate;
        $nsBeta2 = (self::SVENSSON_CURVATURE1_TARGET_SCALE * $monetaryStanceGap)
            + (self::SVENSSON_CURVATURE1_GAP_SCALE * $state->outputGap)
            + $state->expectedPathShock;

        // Debt is priced once, through the Laubach (2009) sovereign spread in the term premium above.
        $fiscalShift = ($state->governmentSpendingIndexEma / MacroEngine::GOVT_SPENDING_BASELINE) - 1.0;
        $nsBeta3 = self::SVENSSON_CURVATURE2_FISCAL_SCALE * $fiscalShift;

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

        $structural10y = $this->calculateSvenssonTenor(10.0, $level, $nsBeta1, $nsBeta2, 0.0, $state, $totalBaseTermPremium, $longEndPremium);

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
     * The clock lives here rather than in updateMarketExpectations, which is where it looks like it
     * belongs: it reads the STRUCTURAL slope, and that slope does not exist
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
     * deposit rate follows the policy rate only in part, so the spread they keep scales with the rate. That
     * spread is what money-market funds compete on, so household liquid assets migrate toward them as rates
     * rise and drift back at the lower bound, over about a year.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateDepositChannel(MacroState $state, float $dt): void
    {
        $policyRate = max(0.0, $state->policyRateEma);
        $neutralRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;

        $state->systemDepositBeta = MacroEngine::SYSTEM_DEPOSIT_BETA_BASE;

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
            fci: $state->financialConditionsIndex,
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
     * @param float      $tfpGrowthRate Productivity growth potential output is built on (trend plus absorbed shocks).
     */
    public function calculateMoneySupplyGrowth(MacroState $state, float $dt, float $tfpGrowthRate): void
    {
        $baseGrowth = MacroEngine::TARGET_INFLATION + $tfpGrowthRate + $state->laborForceGrowthRate;

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
