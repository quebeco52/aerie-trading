<?php

declare(strict_types=1);

namespace App\Service\Macro\Subsystem;

use App\Data\Macro\MacroFieldRegistry;
use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
use App\Service\Math\CreditRisk;
use App\Service\Math\FixedIncome;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;

/**
 * Handles central bank monetary policy targeting, policy rate smoothing,
 * Nelson-Siegel-Svensson term structure fitting, ACM term premium decomposition,
 * and Quantitative Easing / Tightening balance sheet dynamics.
 */
class MonetaryPolicySubsystem
{
    // --- Long-Rate Offset (Bernanke 2006) ---
    /**
     * Bernanke (2006) / Rudebusch-Sack-Swanson (2007) long-rate offset: the central bank leans against the part of the
     * ten-year it does not set (term premium away from baseline, the market's drifted view of neutral). FRB/US puts a
     * 100bps term premium move at roughly 50bps of policy; without it a high-premium era is a decade-long slump.
     */
    public const TAYLOR_LONG_RATE_OFFSET = 0.50;

    // --- Nelson-Siegel-Svensson Term Structure Dynamics (Svensson 1994) ---
    /**
     * Kozicki-Tinsley (2001) shifting endpoint: weight on the market's adaptive long-run policy rate in the curve's
     * anchor, against the model-consistent r* plus expected inflation. A decade at 5% lifts the anchor so the curve
     * flattens like 1995-1999 instead of staying inverted; a decade at the floor drags the ten-year toward the 2% of
     * 2012-2016.
     */
    public const KOZICKI_TINSLEY_ENDPOINT_WEIGHT = 0.50;
    /**
     * Speed at which the perceived long-run policy rate learns from the realized rate (half-life ~5 years): slow enough
     * that a two-year hiking cycle still inverts the curve, fast enough that a decade-long era reprices the long end,
     * the slow adaptive expectations of Kozicki-Tinsley.
     */
    public const KOZICKI_TINSLEY_ADAPTATION_SPEED = 0.14;
    /** Diebold-Li (2006) curvature sensitivity to central bank target-policy rate gap (forward guidance channel). */
    public const SVENSSON_CURVATURE1_TARGET_SCALE = 0.85;
    /** Cyclical curvature sensitivity to output gap (positive gap leads to steeper belly). */
    public const SVENSSON_CURVATURE1_GAP_SCALE = 0.15;
    /**
     * Wright (2011) IRP: term premium sensitivity to excess inflation expectations above target. Kept modest: the 2022
     * episode showed breakevens near 3% adding little premium once expectations are anchored.
     */
    public const TERM_PREMIUM_IRP_EXPECTATION_SCALE = 0.20;
    /**
     * Floor on the ten-year term premium (-75bps): ACM ran between -50 and -100bps from 2016 to 2021, so flight to
     * safety and QE may push the long end below the expected policy path.
     */
    public const MIN_TERM_PREMIUM_10Y = -0.0075;

    // --- Term Premium Dynamics (ACM 2013 persistence, Campbell-Pflueger-Viceira 2020 regimes) ---
    /**
     * Mean reversion of transitory term premium shocks (half-life ~8 months): ACM show the premium is persistent but
     * not permanent.
     */
    public const TERM_PREMIUM_SHOCK_KAPPA = 1.0;
    /**
     * Loading of the District term premium on the mainland's (one global factor; Jotikasthira, Le & Lundblad 2015: long
     * yields co-move across currencies mostly through risk compensation), the own factors scaled by sqrt(1 - b^2) so
     * the premium's variance is unchanged. Calibrated to a District-on-mainland 10-year pass-through of 0.40, between
     * Switzerland's 0.43 and Singapore's 0.35 (Bayoumi, Gagnon et al. 2015, daily, second-hand).
     */
    public const GLOBAL_TERM_PREMIUM_LOADING = 0.45;
    /** Cap on the transitory shock (200bps either way), the largest ACM swing on record. */
    public const TERM_PREMIUM_SHOCK_CAP = 0.02;
    /**
     * Mean reversion of the structural term premium regime (half-life ~8 years): eras such as the 1990s at 2% and the
     * 2010s near zero, set by the bond-stock correlation.
     */
    public const TERM_PREMIUM_REGIME_KAPPA = 0.087;
    /** Ceiling of the structural term premium regime (the early 1990s era). */
    public const MAX_TERM_PREMIUM_REGIME = 0.025;

    // --- Diebold-Li (2006) Expected-Path Innovation ---
    /**
     * Mean reversion of the curvature factor's own innovation (half-life ~7 months): Diebold-Li estimate curvature as
     * the least persistent Nelson-Siegel factor, a monthly AR(1) near 0.9.
     */
    public const EXPECTED_PATH_SHOCK_KAPPA = 1.2;
    /**
     * Annual volatility of that innovation, in curvature units: the 2Y carries 0.29 of it, the 10Y 0.14. Measured over
     * 8 seeds x 60y it takes the 2Y's quarterly change from 24 to 48bps (real ~50) and the 10Y's from 35 to 40, so the
     * vol term structure slopes down past the belly as every real curve does.
     */
    public const EXPECTED_PATH_SHOCK_SIGMA = 0.030;
    /**
     * Cap on the innovation (~2 stationary sd, ~120bps at the 2Y): a repricing of the next few years of policy, never a
     * regime change, so the 2Y stays within the band it holds at the lower bound.
     */
    public const EXPECTED_PATH_SHOCK_CAP = 0.04;

    // --- Central Bank Balance Sheet (QE & QT) ---
    /** Minimum reinvestment hold period (years) after QE ends before QT runoff can begin (Bernanke 2020). */
    public const BALANCE_SHEET_REINVESTMENT_HOLD_YEARS = 1.5;

    // --- The Authority's Published Rule (Taylor 1993) ---
    /**
     * Rate per point of inflation over target beyond the point that keeps the real rate level: 1.5, so the rate answers
     * inflation two and a half for one, an activist Authority well past the Taylor principle.
     */
    public const TAYLOR_INFLATION_WEIGHT = 1.5;
    /**
     * Rate per point of projected output gap: 1.5, leaning hard on slack, as this economy's slow transmission needs to
     * keep its cycles readable.
     */
    public const TAYLOR_OUTPUT_GAP_WEIGHT = 1.5;
    /** Bernanke (2015) blend: weight on realized core inflation (EMA) in the Taylor Rule inflation measure. */
    public const TAYLOR_INFLATION_CORE_WEIGHT = 0.70;
    /**
     * Bernanke (2015) blend: weight on forward inflation expectations (TIPS breakeven) in the Taylor Rule inflation
     * measure.
     */
    public const TAYLOR_INFLATION_ANCHOR_WEIGHT = 0.30;

    // --- The Rate Committee's Camps (Bordo & Istrefi 2023) ---
    /** Points of rate a dovish camp sets less per point of inflation over target; under target it follows the rule. */
    public const DOVISH_MAJORITY_INFLATION_RESPONSE = 0.35;
    /**
     * Points of rate a hawkish camp sets above the rule per point of negative output gap, cutting less into a slump; in
     * a boom it follows the rule.
     */
    public const HAWKISH_MAJORITY_GAP_RESPONSE = 0.17;

    // --- Shifting Inflation Endpoint (Kozicki & Tinsley 2001; Orphanides & Williams 2005) ---
    /**
     * Constant gain, per year, at which the public learns long-run inflation from realized inflation: Orphanides &
     * Williams' baseline 0.05 a half-year period, about twenty years of data (FRB FEDS 2003-41); on US T10YIE 2003-2026
     * the breakeven fit's R2 peaks at 0.10-0.14.
     */
    public const INFLATION_ENDPOINT_LEARNING_GAIN = 0.10;

    // --- Forward-Looking Policy Horizon (Clarida, Gali & Gertler 2000; Batini & Haldane 1999) ---
    /**
     * Quarters of gap momentum the rule projects forward. One quarter is what a staff projection actually carries; the
     * longer horizons swept here read as foresight but act as gain, buying a broader spectrum with the left tail (0.75y
     * halves the share of quarters below -3%).
     */
    public const TAYLOR_GAP_FORECAST_YEARS = 0.25;
    /**
     * Cap on that projection in gap units: a quarter's momentum extrapolated a year out is a forecast, not a
     * measurement, and an uncapped derivative hands the rule the diffusion term.
     */
    public const TAYLOR_GAP_FORECAST_CAP = 0.020;
    /**
     * Speed (a year) at which the Authority's view of the right rate, its shadow rate, closes on the rule: 0.65, 15% of
     * the distance a quarter.
     */
    public const CB_SMOOTHING_SPEED = 0.65;

    // --- Rate Meetings ---
    /** Rate meetings a year: the decision is taken at each and holds until the next. */
    public const MEETINGS_PER_YEAR = 8;
    /** The step a decision moves the rate in: 25bp. */
    public const POLICY_RATE_STEP = 0.0025;

    // --- Treasury Rate Volatility (RiskMetrics 1996 EWMA of ten-year yield changes) ---
    /**
     * Window (years) of the realized ten-year yield volatility: one quarter, the period a dealer's rates revenue is
     * booked over.
     */
    public const TREASURY_VOLATILITY_WINDOW_YEARS = 0.25;
    /**
     * Opening realized ten-year yield volatility, annualized: 87bp, the mean of the 2005-2024 calendar-year realized
     * volatilities of daily FRED DGS10 changes (55bp in 2018 to 140bp in 2008).
     */
    public const OPENING_TREASURY_VOLATILITY = 0.0087;

    // --- Nelson-Siegel-Svensson Term Structure Dynamics (Svensson 1994) ---
    /** Flight-to-safety sensitivity: recessions compress term premium via safe-haven demand (Campbell et al. 2017). */
    public const NS_GAP_TERM_PREMIUM_SCALE = 0.05;
    /** Sensitivity of secondary curvature (beta3) to quantitative tightening and long-term fiscal deficits. */
    public const SVENSSON_CURVATURE2_FISCAL_SCALE = 0.02;
    /** Safe-haven flight to safety: financial market panic compresses sovereign term premium (Campbell et al. 2020). */
    public const FLIGHT_TO_SAFETY_SENSITIVITY = 0.015;
    /**
     * Liability-driven long-end demand (Vayanos & Vila 2021 habitat investors; Greenwood & Vissing-Jorgensen 2018):
     * pensions and insurers buy duration once the thirty-year clears the hurdle their liabilities are discounted at,
     * and that demand comes out of the long-end premium. Loads through the half-again duration scale, so a quarter of
     * the excess reaches the thirty-year; read one tick stale off the smoothed thirty-year.
     */
    public const HABITAT_LONG_END_DEMAND_SENSITIVITY = 0.50;

    // --- Term Premium Dynamics (ACM 2013 persistence, Campbell-Pflueger-Viceira 2020 regimes) ---
    /**
     * Annual volatility of transitory term premium shocks: a stationary spread of ~50bps and ~35bps quarterly moves
     * (ACM 2013 quarterly changes run 30-35bps), so a taper tantrum is a two-sigma quarter.
     */
    public const TERM_PREMIUM_SHOCK_SIGMA = 0.0070;
    /** Annual volatility of the structural regime: a stationary spread of ~50bps around the baseline. */
    public const TERM_PREMIUM_REGIME_SIGMA = 0.0021;
    /** Floor of the structural term premium regime (the 2010s era). */
    public const MIN_TERM_PREMIUM_REGIME = 0.0;

    // --- Central Bank Balance Sheet (QE & QT) ---
    /**
     * Ten-year yield suppression under full-scale QE (~100bps): Gagnon et al. (2011) and Bonis-Ihrig-Wei (2017) put the
     * whole QE1-QE3 stock near 100bps at its 2013 peak.
     */
    public const QE_MAX_SUPPRESSION = 0.01;
    /**
     * Cut the floor forbids that the full programme stands in for: the Wu-Xia (2016) shadow rate bottomed near -3% in
     * 2014 with the QE1-QE3 stock at its peak.
     */
    public const QE_FULL_PROGRAM_SHORTFALL = 0.03;
    /** Annual ramp speed of central bank balance sheet expansion and contraction. */
    public const BALANCE_SHEET_RAMP_SPEED = 1.0;
    /** Positive output gap threshold above which central bank initiates Quantitative Tightening. */
    public const QT_ACTIVATION_GAP_THRESHOLD = 0.010;
    /** Inflation threshold above which central bank initiates Quantitative Tightening */
    public const QT_ACTIVATION_INFLATION_THRESHOLD = 0.022;
    /**
     * Overheating pressure at which runoff reaches full speed; QT is a runoff RATE, not a yield target, because the
     * bank can only return the duration it holds.
     */
    public const QT_MAX_INTENSITY = 0.005;
    /** Sensitivity multiplier scaling QT bond runoff with economic overheating. */
    public const QT_SEVERITY_MULTIPLIER = 0.40;
    /**
     * Extra runoff speed at full overheating pressure (Fed 2017-19 then 2022 roughly doubled its monthly caps), on top
     * of the passive BALANCE_SHEET_RAMP_SPEED.
     */
    public const QT_RUNOFF_ACCELERATION = 1.0;

    // --- The Authority's Income ---
    /**
     * Currency in circulation over GDP, the Authority's interest-free funding: 7.8%, the US in 2018Q4 (St. Louis Fed,
     * On the Economy, May 2019).
     */
    public const CURRENCY_TO_GDP = 0.078;
    /**
     * Bonds the full programme holds, over GDP: 19.5%, the Fed's securities held outright at QE3's end ($4.21T, H.4.1
     * 22 Oct 2014) less the ~$0.79T held before the crisis, on 2014's $17.5T GDP.
     */
    public const QE_HOLDINGS_TO_GDP_AT_FULL_PROGRAM = 0.195;

    // --- Deposits Channel (Drechsler, Savov & Schnabl 2017) ---
    /**
     * Money-market share per unit of deposit spread beyond the neutral spread (the neutral rate less the base beta's
     * share of it): the 2022-23 cycle moved ~5 points of share on ~100 bps of extra spread.
     */
    public const MMF_SPREAD_SENSITIVITY = 5.0;
    /**
     * Time constant (years) of household migration between deposits and money funds: a slow reallocation, not a run.
     */
    public const MMF_MIGRATION_YEARS = 1.0;
    /** Floor on the money-market share: a residual institutional base survives a decade at the lower bound. */
    public const MIN_MMF_SHARE = 0.05;
    /** Ceiling on the money-market share. */
    public const MAX_MMF_SHARE = 0.40;

    // --- Broad Money (loans create deposits; McLeay, Radia & Thomas 2014) ---
    /**
     * Broad money over GDP, the stock the Authority's purchases add reserves to: about a year of output in a
     * deposit-funded economy.
     */
    public const BROAD_MONEY_TO_GDP = 1.0;
    /** Horizon (years) money growth is read over, as a year-on-year rate. */
    public const MONEY_GROWTH_HORIZON_YEARS = 1.0;
    /**
     * Time constant (years) of M2 growth's measured trend: a decade, past the 2-4 year QE programmes and credit swings
     * its readers respond to, so the trend is the medium-term growth a reference value is set on (ECB 1998's M3
     * reference value).
     */
    public const M2_TREND_YEARS = 10.0;

    public function __construct(
        private readonly MathUtility $mathUtility,
        /**
         * Records what set the target and what held the rate. Null in a test or a headless harness, off everywhere the
         * ticker is not.
         */
        private readonly ?MacroDiagnosticsProbe $diagnostics = null,
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
     * The Authority's published rule, Taylor's (1993) form:
     *   r_target = r* + pi_blend + alpha_pi * (pi_blend - pi*) + gamma_y * y_gap - phi_L * long_rate_gap + committee
     * where, while the District's politics hands the macro a rate committee, committee is its supermajority's lean
     * (committeeMajorityTerm()).
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

        // The gaps the rule answers, which each camp on the committee weighs its own way (campTilt()).
        $state->ruleInflationGap = $inflationMeasure - $targetInflation;
        $state->ruleOutputGap = $cyclicalGap;
        $committee = self::committeeMajorityTerm($state->authorityCommitteeSeated > 0.0 ? $state->authorityMajority : null, $state->ruleInflationGap, $cyclicalGap);

        $unclampedTarget = $naturalRate + $inflationMeasure
            + self::TAYLOR_INFLATION_WEIGHT * ($inflationMeasure - $targetInflation)
            + self::TAYLOR_OUTPUT_GAP_WEIGHT * $cyclicalGap
            - $longRateOffset
            + $committee;

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
            ], $target, $dt);
        }

        return $target;
    }

    /**
     * The long-run inflation the public has learned from what inflation actually did (Kozicki & Tinsley 2001 shifting
     * endpoint, learned at Orphanides & Williams' 2005 constant gain), kept as its gap over the target.
     *
     * @param MacroState $state           Current macroeconomic state.
     * @param float      $targetInflation Statutory central bank target inflation.
     * @param float      $dt              Time increment in years.
     */
    public function updateInflationEndpoint(MacroState $state, float $targetInflation, float $dt): void
    {
        $state->inflationEndpointGap = TimeSeries::calculateDistributedLag(
            currentLaggedValue: $state->inflationEndpointGap,
            targetValue: $state->inflationEma - $targetInflation,
            dt: $dt,
            lagTimeConstant: 1.0 / self::INFLATION_ENDPOINT_LEARNING_GAIN,
        );
    }

    /**
     * The rate committee's lean on the rule: a supermajority leans it its camp's way (campTilt()), and a committee
     * with neither leaves the rule as published.
     *
     * @param float|null $majority     1 hawkish, -1 dovish, 0 neither; null while no committee is handed over.
     * @param float      $inflationGap The rule's inflation over target.
     * @param float      $gap          The rule's output gap.
     */
    public static function committeeMajorityTerm(?float $majority, float $inflationGap, float $gap): float
    {
        return $majority === null ? 0.0 : self::campTilt($majority, $inflationGap, $gap);
    }

    /**
     * How far a camp would set the rate from the published rule (Bordo & Istrefi 2023): doves answer inflation over
     * target less, hawks cut less into a slump. Each leans only on its own side and follows the rule on the other: a
     * hawk tightens with a boom as the rule does, and a dove eases with inflation under target. Leaning both ways, a
     * hawkish majority set the rate below the rule in every boom and its hawks dissented for cuts.
     *
     * @param float $camp         Above zero hawkish, below zero dovish, zero neither.
     * @param float $inflationGap The rule's inflation over target.
     * @param float $gap          The rule's output gap.
     */
    public static function campTilt(float $camp, float $inflationGap, float $gap): float
    {
        return match (true) {
            $camp < 0.0 => -self::DOVISH_MAJORITY_INFLATION_RESPONSE * max(0.0, $inflationGap),
            $camp > 0.0 => -self::HAWKISH_MAJORITY_GAP_RESPONSE * min(0.0, $gap),
            default => 0.0,
        };
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
        $habitatShiftAtTenYears = FixedIncome::calculatePreferredHabitatTermPremiumShift(
            balanceSheetIntensity: CreditFiscalSubsystem::durationExtraction($state->balanceSheetIntensity, $state->sovereignDebtToGdpEma, $state->sovereignDebtAverageMaturity),
            tau: 10.0,
            habitatSensitivity: MacroEngine::PREFERRED_HABITAT_DURATION_SENSITIVITY,
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
     * The policy rate set at meetings. Between meetings the Authority's view of the right rate, its shadow rate, closes
     * on the rule as an exact first-order lag (Clarida, Gali & Gertler 2000 inertia); at each of its eight meetings the
     * decision is that shadow rate to the nearest 25bp, at least the floor, and the rate holds until the next. The rate
     * the market expects at the next meeting is the shadow rate projected to it, so a decision differs from it only by
     * what changed in the rule since the last reading: the surprise.
     *
     * @param MacroState $state      Current macroeconomic state.
     * @param float      $targetRate Target policy rate from the rule, clamped to the floor and ceiling.
     * @param float      $dt         Time increment in years.
     * @return float The policy rate in force after this tick.
     */
    public function updatePolicyRate(MacroState $state, float $targetRate, float $dt): float
    {
        $speed = self::CB_SMOOTHING_SPEED;
        $state->policyRateShadow = $targetRate + (($state->policyRateShadow - $targetRate) * exp(-$speed * $dt));

        $policyRate = $state->policyRate;
        $meeting = TimeSeries::crossedSimulatedBoundary($state->totalTime, $dt, 1.0 / self::MEETINGS_PER_YEAR);
        $state->policySurprise = 0.0;
        if ($meeting) {
            $policyRate = self::decision($state->policyRateShadow);
            $state->policySurprise = $policyRate - $state->policyRateExpected;
        }

        $untilNext = self::nextMeetingAt($state->totalTime) - $state->totalTime;
        $state->policyRateExpected = self::decision($targetRate + (($state->policyRateShadow - $targetRate) * exp(-$speed * $untilNext)));

        if ($this->diagnostics?->isEnabled()) {
            $this->diagnostics->recordPolicyRate($policyRate, [
                'lowerBound' => $targetRate <= MacroEngine::EFFECTIVE_LOWER_BOUND,
                'meeting' => $meeting,
                'surprise' => $meeting && abs($state->policySurprise) > 1e-12,
                'targetCap' => $targetRate >= MacroEngine::POLICY_RATE_CEILING,
            ], $dt);
        }

        return $policyRate;
    }

    /** A meeting's decision on a shadow rate: the nearest step, never under the floor. */
    public static function decision(float $shadowRate): float
    {
        return max(MacroEngine::EFFECTIVE_LOWER_BOUND, self::POLICY_RATE_STEP * round($shadowRate / self::POLICY_RATE_STEP));
    }

    /** When the next rate meeting after a moment falls. */
    public static function nextMeetingAt(float $time): float
    {
        return (floor(($time * self::MEETINGS_PER_YEAR) + 1e-9) + 1.0) / self::MEETINGS_PER_YEAR;
    }

    /**
     * The Authority's net interest income, a share of GDP a year: the Exchequer's paper backing the currency earns what
     * the debt pays (CreditFiscalSubsystem::sovereignEffectiveRate()), and the bonds its purchases hold earn their own
     * coupon, the yields they were bought at (rollAuthorityBookCoupon()), less the policy rate paid on the reserves
     * that bought them. It reproduces the Fed's remittances: 0.24% of GDP in 2007, about 0.57% in 2015 (0.54% paid) and
     * 0.23% in 2019 (0.26%). Negative when the policy rate rises past the coupon on a large book, as in 2022-23.
     */
    public static function authorityNetIncomeToGdp(MacroState|MacroStateDTO $state): float
    {
        $holdings = self::authorityHoldingsToGdp($state);

        return (self::CURRENCY_TO_GDP * CreditFiscalSubsystem::sovereignEffectiveRate($state))
            + ($holdings * ($state->authorityBookCouponRate - $state->policyRate));
    }

    /**
     * Rolls the coupon the Authority's bond book earns over a tick of its balance sheet: purchases come in at the
     * market yield, runoff takes bonds out pro rata and leaves the coupon, and while the book is not running off its
     * maturing bonds are reinvested at the market yield over the debt's repricing horizon. The whole stock's coupon
     * carries debt issued before the programme; a book bought at the lower bound earns what it paid.
     *
     * @param MacroState $state           State whose balance sheet intensity has just been set.
     * @param float      $holdingsBefore  The book over GDP before this tick's operations.
     * @param float      $dt              Time increment in years.
     */
    public static function rollAuthorityBookCoupon(MacroState $state, float $holdingsBefore, float $dt): void
    {
        $holdings = self::authorityHoldingsToGdp($state);
        if ($holdings <= 0.0) {
            return;
        }

        $kept = min($holdingsBefore, $holdings);
        $keptCoupon = $holdings >= $holdingsBefore
            ? $state->authorityBookCouponRate + ((1.0 - exp(-$dt / CreditFiscalSubsystem::couponRepricingYears($state))) * ($state->yield10y - $state->authorityBookCouponRate))
            : $state->authorityBookCouponRate;
        $state->authorityBookCouponRate = (($keptCoupon * $kept) + ($state->yield10y * max(0.0, $holdings - $holdingsBefore))) / $holdings;
    }

    /**
     * Bonds the Authority's purchases hold, over GDP: the full programme's holdings at the balance sheet's intensity.
     */
    public static function authorityHoldingsToGdp(MacroState|MacroStateDTO $state): float
    {
        return self::QE_HOLDINGS_TO_GDP_AT_FULL_PROGRAM * max(0.0, $state->balanceSheetIntensity) / self::QE_MAX_SUPPRESSION;
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
        // Purchases stand in for the cut the floor forbids (Wu & Xia 2016): none while the rule's own rate is
        // reachable, the full programme once the rule asks for the shadow rate the 2014 stock delivered.
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
            dt: $dt,
        );

        $state->termPremiumShock = max(-self::TERM_PREMIUM_SHOCK_CAP, min(self::TERM_PREMIUM_SHOCK_CAP, $factors['chi']));
        $state->termPremiumRegime = max(self::MIN_TERM_PREMIUM_REGIME, min(self::MAX_TERM_PREMIUM_REGIME, $factors['xi']));

        // The mainland's own premium, the global factor: the same ACM-fitted process, since the mainland is the US.
        $foreign = $this->mathUtility->calculateTwoFactorOU(
            chi: $state->foreignTermPremiumShock,
            xi: $state->foreignTermPremiumRegime,
            kappaChi: self::TERM_PREMIUM_SHOCK_KAPPA,
            kappaXi: self::TERM_PREMIUM_REGIME_KAPPA,
            thetaChi: 0.0,
            thetaXi: MacroEngine::NS_BASE_TERM_PREMIUM,
            sigChi: self::TERM_PREMIUM_SHOCK_SIGMA,
            sigXi: self::TERM_PREMIUM_REGIME_SIGMA,
            rho: 0.0,
            dt: $dt,
        );
        $state->foreignTermPremiumShock = max(-self::TERM_PREMIUM_SHOCK_CAP, min(self::TERM_PREMIUM_SHOCK_CAP, $foreign['chi']));
        $state->foreignTermPremiumRegime = max(self::MIN_TERM_PREMIUM_REGIME, min(self::MAX_TERM_PREMIUM_REGIME, $foreign['xi']));
    }

    /**
     * The District term premium's transitory and structural parts: its own factors and the mainland's, in a one-factor
     * split whose weights' squares sum to one, so each part keeps the ACM-fitted variance and correlates with the
     * mainland's at the global loading.
     *
     * @return array{shock: float, regime: float}
     */
    public static function districtTermPremiumFactors(MacroState $state): array
    {
        $global = self::GLOBAL_TERM_PREMIUM_LOADING;
        $own = sqrt(1.0 - ($global ** 2));

        return [
            'shock' => ($own * $state->termPremiumShock) + ($global * $state->foreignTermPremiumShock),
            'regime' => MacroEngine::NS_BASE_TERM_PREMIUM
                + ($own * ($state->termPremiumRegime - MacroEngine::NS_BASE_TERM_PREMIUM))
                + ($global * ($state->foreignTermPremiumRegime - MacroEngine::NS_BASE_TERM_PREMIUM)),
        ];
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
     * The short rate bonds are priced on: the Authority's unrounded view, which its published rule and the data let the
     * market follow between meetings, floored where decisions are. Each decision rounds it to a step, so the decision
     * itself carries news only when it rounds to a step the market did not expect (policySurprise).
     */
    public static function marketShortRate(MacroState $state): float
    {
        return max(MacroEngine::EFFECTIVE_LOWER_BOUND, $state->policyRateShadow);
    }

    /**
     * Nelson-Siegel-Svensson (1994) Term Structure & Adrian-Crump-Moench (2013) ACM Decomposition.
     *
     * Fits the full zero-coupon sovereign yield curve (2Y, 5Y, 10Y, 30Y) with Diebold-Li (2006)
     * forward monetary guidance curvature (beta2) and long-end fiscal/QT supply curvature (beta3).
     * Decomposes the 10Y yield into expected risk-neutral policy rate path and duration term premium.
     * The short end is anchored on the rate the market sees coming (marketShortRate()), not the last decision, so a
     * decision it expected moves no bond; only a surprise does.
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
        $termPremiumFactors = self::districtTermPremiumFactors($state);
        $structuralTermPremium = $termPremiumFactors['regime'] + $termPremiumFactors['shock'] + $state->sovereignRiskSpreadEma;
        $totalBaseTermPremium = max(self::MIN_TERM_PREMIUM_10Y, $structuralTermPremium + $inflationRiskPremium + $cyclicalTermPremium - $flightToSafetyShift);

        // Nelson-Siegel (1987) & Diebold-Li (2006) asymptotic risk-neutral rate level beta0.
        // Kozicki & Tinsley (2001) shifting endpoint perception of long-run neutral policy rate. The model-consistent
        // endpoint prices inflation at the ten-year breakeven, which is already as anchored as the US one.
        $modelEndpoint = $naturalRate + $state->tipsBreakeven;
        $level = ((1.0 - self::KOZICKI_TINSLEY_ENDPOINT_WEIGHT) * $modelEndpoint)
            + (self::KOZICKI_TINSLEY_ENDPOINT_WEIGHT * $state->perceivedNeutralRate);
        $shortRate = self::marketShortRate($state);
        $nsBeta1 = $shortRate - $level;

        // Diebold & Li (2006) curvature beta2 capturing forward monetary policy trajectory.
        $monetaryStanceGap = $state->targetRate - $shortRate;
        $nsBeta2 = (self::SVENSSON_CURVATURE1_TARGET_SCALE * $monetaryStanceGap)
            + (self::SVENSSON_CURVATURE1_GAP_SCALE * $state->outputGap)
            + $state->expectedPathShock;

        // Debt is priced once, through the Laubach (2009) sovereign spread in the term premium above.
        $fiscalShift = CreditFiscalSubsystem::discretionarySpendingShift($state);
        $nsBeta3 = self::SVENSSON_CURVATURE2_FISCAL_SCALE * $fiscalShift;

        // Greenwood & Vayanos (2014) preferred-habitat long-end duration demand hurdle.
        $scale30y = FixedIncome::calculateTermPremiumDurationScale(30.0, MacroEngine::TERM_PREMIUM_DURATION_HORIZON_YEARS);
        $longEndHurdle = $naturalRate + $targetInflation + (MacroEngine::NS_BASE_TERM_PREMIUM * $scale30y);
        $habitatDemandShift = self::HABITAT_LONG_END_DEMAND_SENSITIVITY * max(0.0, $state->yield30yEma - $longEndHurdle);
        $longEndPremium = max(0.0, $termPremiumFactors['regime'] - $habitatDemandShift);

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
        // Realized rate volatility off the ten-year's move this step, and its through-the-cycle level read the
        // way the credit spread's is (a decade's average), so a desk reads a busy market against a measured norm.
        $state->treasuryVolatility = sqrt(TimeSeries::ewmaAnnualizedVariance($state->treasuryVolatility ** 2, $yieldData['yield_10y'] - $state->yield10y, $dt, self::TREASURY_VOLATILITY_WINDOW_YEARS));
        $state->treasuryVolatilityTrend = TimeSeries::ewmaLevel($state->treasuryVolatilityTrend, $state->treasuryVolatility, $dt, CreditFiscalSubsystem::CREDIT_SPREAD_TREND_YEARS);

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

        // The slope against the short rate the market expects, as the curve itself is built on: a rounded decision
        // would step the slope, and the inversion clock with it, on meeting days.
        $shortRate = self::marketShortRate($state);
        $state->nsSlope = $state->yield10y - $shortRate;
        $state->structuralSlope = $yieldData['structural_10y'] - $shortRate;

        if ($state->structuralSlope < 0.0) {
            $state->inversionDuration += $dt;
        } else {
            $state->inversionDuration = 0.0;
        }
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
        // Vayanos & Vila (2021) Preferred-Habitat Model: duration extraction under QE/QT compresses term premium by
        // tenor duration
        return FixedIncome::calculateSovereignZeroYield(
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
            balanceSheetIntensity: CreditFiscalSubsystem::durationExtraction($state->balanceSheetIntensity, $state->sovereignDebtToGdpEma, $state->sovereignDebtAverageMaturity),
            habitatSensitivity: MacroEngine::PREFERRED_HABITAT_DURATION_SENSITIVITY,
            effectiveLowerBound: MacroEngine::EFFECTIVE_LOWER_BOUND,
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

        $depositSpread = $policyRate * (1.0 - MacroEngine::SYSTEM_DEPOSIT_BETA_BASE);
        $neutralSpread = $neutralRate * (1.0 - MacroEngine::SYSTEM_DEPOSIT_BETA_BASE);
        $targetShare = MacroEngine::MMF_SHARE_BASE + (self::MMF_SPREAD_SENSITIVITY * ($depositSpread - $neutralSpread));
        $targetShare = max(self::MIN_MMF_SHARE, min(self::MAX_MMF_SHARE, $targetShare));
        $state->moneyMarketFundShare = TimeSeries::calculateDistributedLag(
            currentLaggedValue: $state->moneyMarketFundShare,
            targetValue: $targetShare,
            dt: $dt,
            lagTimeConstant: self::MMF_MIGRATION_YEARS,
        );
    }

    /**
     * Estrella & Mishkin (1998) / Wright (2006) 12-Month Forward Recession Probit Model.
     *
     * Evaluates market-implied probability of recession over the next 12 months using the sovereign
     * yield curve slope (10Y minus the market's short rate), term premium, and Financial Conditions Index:
     *   P(Recession) = NormalCDF(beta0 + betaSlope * slope + betaTp * termPremium + betaFci * FCI)
     *
     * @param MacroState $state Current macroeconomic state.
     */
    public function calculateRecessionProbability(MacroState $state): void
    {
        $slope = $state->yield10y - self::marketShortRate($state);
        $state->recessionProbability = CreditRisk::calculateEstrellaMishkinProbability(
            slope: $slope,
            termPremium: $state->termPremium10y,
            fci: $state->financialConditionsIndex,
            beta0: MacroEngine::RECESSION_PROBIT_BETA_0,
            betaSlope: MacroEngine::RECESSION_PROBIT_BETA_SLOPE,
            betaTp: MacroEngine::RECESSION_PROBIT_BETA_TP,
            betaFci: MacroEngine::RECESSION_PROBIT_BETA_FCI,
        );
    }

    /**
     * Broad money growth: deposits are created by the loans banks make and the bonds the Authority buys (McLeay, Radia
     * & Thomas 2014). Money grows with nominal income, with household credit beyond income, and with the purchases'
     * reserves; read year on year.
     *
     * @param MacroState $state         Current macroeconomic state.
     * @param float      $dt            Time step in years.
     * @param float      $tfpGrowthRate Productivity growth potential output is built on (trend plus absorbed shocks).
     */
    public function calculateMoneySupplyGrowth(MacroState $state, float $dt, float $tfpGrowthRate): void
    {
        $horizon = self::MONEY_GROWTH_HORIZON_YEARS;
        // The year's purchases as a flow: a lag's own change is the exponentially weighted average of what fed it, so
        // the whole of a programme is counted once.
        $priorHoldings = $state->authorityHoldingsToGdpEma;
        $state->authorityHoldingsToGdpEma += (1.0 - exp(-$dt / $horizon)) * (self::authorityHoldingsToGdp($state) - $priorHoldings);
        $purchasesFlow = ($state->authorityHoldingsToGdpEma - $priorHoldings) / $dt;

        $state->moneySupplyGrowth = MacroTransmission::broadMoneyGrowth(
            nominalIncomeGrowth: $state->inflationEma + $tfpGrowthRate + $state->laborForceGrowthRate + $state->cyclicalMigrationRate,
            householdNewBorrowing: $state->householdNewBorrowing,
            authorityPurchasesToGdp: $purchasesFlow,
            broadMoneyToGdp: self::BROAD_MONEY_TO_GDP,
        );
        // The level its cyclical readers measure a liquidity surge against, measured rather than assumed.
        $state->moneySupplyGrowthTrend += (1.0 - exp(-$dt / self::M2_TREND_YEARS)) * ($state->moneySupplyGrowth - $state->moneySupplyGrowthTrend);
    }
}
