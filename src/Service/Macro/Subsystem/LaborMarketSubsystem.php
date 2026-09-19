<?php

namespace App\Service\Macro\Subsystem;

use App\Data\Sectors;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Math\MathUtility;

/**
 * Handles labor market dynamics, unemployment frictional adjustments,
 * Diamond-Mortensen-Pissarides Beveridge curve matching, and wage Phillips curve inflation transmission.
 */
class LaborMarketSubsystem
{
    // --- Okun's Law & Diamond-Mortensen-Pissarides Beveridge Curve ---
    /** Asymptotic frictional lower bound on unemployment (3.2%): search friction keeps even a red-hot economy above ~3%. */
    public const MIN_FRICTIONAL_UNEMPLOYMENT = 0.032;

    // --- Okun's Law & Diamond-Mortensen-Pissarides Beveridge Curve ---
    /** Structural Beveridge curve equilibrium constant (k = Natural Unemployment * Natural Vacancies). */
    public const BEVERIDGE_CURVE_CONSTANT = 0.0018;
    /** Sensitivity of wage growth to labor market tightness deviations from equilibrium. */
    public const WAGE_TIGHTNESS_SENSITIVITY = 0.010;
    /** Annual adjustment speed of nominal wage settlements toward market-clearing equilibrium. */
    public const WAGE_ADJUSTMENT_SPEED = 2.0;
    /** Okun's beta: sensitivity of equilibrium unemployment deviation to the GDP output gap. */
    public const OKUNS_COEFFICIENT = 0.5;
    /** Annual adjustment speed of employment expansion during economic recoveries (search & matching friction). */
    public const OKUNS_HIRING_SPEED = 1.5;
    /** Annual adjustment speed of workforce reduction during economic contractions (rapid labor shedding). */
    public const OKUNS_FIRING_SPEED = 3.0;
    /** Annual OU speed of NAIRU scarring drift toward sustained excess unemployment (Blanchard & Summers 1986). */
    public const NAIRU_HYSTERESIS_SPEED = 0.10;
    /** Excess unemployment above NAIRU required before structural scarring activates. */
    public const NAIRU_HYSTERESIS_THRESHOLD = 0.005;
    /** Annual speed at which scarring is re-absorbed once the labour market is no longer slack (Ball 2009): the mirror of the scarring speed, a ~7 year half-life. */
    public const NAIRU_REABSORPTION_SPEED = 0.10;
    /** Structural floor for NAIRU (frictional minimum). */
    public const MIN_NAIRU = 0.025;
    /** Structural ceiling for NAIRU (maximum structural deterioration). */
    public const MAX_NAIRU = 0.08;
    /** Downward wage adjustment speed as fraction of upward speed (nominal rigidity, Bewley 1999). */
    public const WAGE_DOWNWARD_RIGIDITY_FACTOR = 0.30;

    // --- Work Stoppages (BLS major work stoppages) ---
    /** Notable stoppages per year across the district. The BLS counts 20-30 major (1,000+ worker) stoppages a year in a US-scale economy; scaled to a district of some sixty listed employers. */
    public const STRIKE_ARRIVAL_PER_YEAR = 1.5;
    /** Mean stoppage length in years (~5 weeks): BLS major stoppages run from days to months and average about a month. */
    public const STRIKE_MEAN_DURATION_YEARS = 0.10;
    /** Settlement premium on the economy-wide wage target while a stoppage runs (~20 bps): the pattern-bargaining spillover of a headline settlement. */
    public const STRIKE_WAGE_SETTLEMENT_BUMP = 0.002;

    public function __construct(
        private readonly MathUtility $mathUtility,
    ) {}

    /**
     * Work stoppages: a Poisson arrival of one struck sector at a time, held for an exponential duration.
     *
     * Only the clock lives here. The lost output is applied to the struck sector's persistent demand factor
     * by the engine, after the factor's own step, and the settlement premium enters the wage target below,
     * so the Okun and Beveridge mechanics stay deterministic for a given state.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function advanceWorkStoppages(MacroState $state, float $dt): void
    {
        if ($state->strikeSector !== null) {
            $state->strikeRemainingYears -= $dt;
            if ($state->strikeRemainingYears <= 0.0) {
                $state->strikeSector = null;
                $state->strikeRemainingYears = 0.0;
            }

            return;
        }

        if (!$this->mathUtility->checkProbability(self::STRIKE_ARRIVAL_PER_YEAR * $dt)) {
            return;
        }

        $sectors = array_keys(Sectors::MACRO_SECTORS);
        $index = min(count($sectors) - 1, (int) floor($this->mathUtility->generateUniform() * count($sectors)));

        $state->strikeSector = $sectors[$index];
        $state->strikeRemainingYears = max($dt, $this->mathUtility->generateExponential(1.0 / self::STRIKE_MEAN_DURATION_YEARS));
        $state->strikeStartedAt = $state->totalTime;
    }

    /**
     * Dynamic Okun's Law (Okun 1962) with Convex Search-Matching Friction (Knotek 2007).
     *
     * Models unemployment adjustment to the output gap: firing is rapid and linear during
     * contractions, while hiring decelerates convexly toward frictional search floor during expansions.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     */
    public function calculateUnemployment(MacroState $state, float $dt): void
    {
        $excessSlack = max(0.0, $state->unemploymentRateEma - $state->nairu - self::NAIRU_HYSTERESIS_THRESHOLD);
        $state->nairu += self::NAIRU_HYSTERESIS_SPEED * $excessSlack * $dt;

        // Scarring has to unwind at a rate of its own. Re-absorption used to be the PRODUCT of two
        // deviations -- (nairu - natural) x (nairu - u) x 0.5 -- and a product of two small numbers is a
        // rounding error: offsetting one year of scarring at a NAIRU 2pp above natural needed a 25pp
        // unemployment gap, which no reachable state supplies. Measured over 960 simulated years NAIRU
        // therefore only ever ratcheted UP, +0.58pp per 80 years in all twelve seeds, its maximum always
        // equal to its final value. A tight labour market re-absorbs the long-term unemployed (Ball 2009),
        // so the erosion is linear in the scarring itself and gated on the market no longer being slack.
        if ($state->unemploymentRateEma <= $state->nairu) {
            $reabsorption = max(0.0, $state->nairu - MacroEngine::NATURAL_UNEMPLOYMENT) * self::NAIRU_REABSORPTION_SPEED;
            $state->nairu -= $reabsorption * $dt;
        }

        $state->nairu = max(self::MIN_NAIRU, min(self::MAX_NAIRU, $state->nairu));

        if ($state->outputGap <= 0.0) {
            $targetUnemployment = $state->nairu - (self::OKUNS_COEFFICIENT * $state->outputGap);
        } else {
            $effectiveRange = max(0.001, $state->nairu - self::MIN_FRICTIONAL_UNEMPLOYMENT);
            $targetUnemployment = self::MIN_FRICTIONAL_UNEMPLOYMENT + ($effectiveRange * exp(- (self::OKUNS_COEFFICIENT * $state->outputGap) / $effectiveRange));
        }

        $unemploymentGap = $targetUnemployment - $state->unemploymentRate;
        $adjustmentSpeed = $unemploymentGap > 0 ? self::OKUNS_FIRING_SPEED : self::OKUNS_HIRING_SPEED;

        $state->unemploymentRate += $adjustmentSpeed * $unemploymentGap * $dt;
    }

    /**
     * Diamond-Mortensen-Pissarides (DMP) Beveridge Curve & Expectations-Augmented Wage Phillips Curve.
     *
     * Derives job vacancies (V) and labor market tightness (theta = V/U) along the hyperbolic
     * Beveridge curve, then models nominal wage growth through a tightness-augmented Phillips curve
     * with Bewley (1999) downward nominal wage rigidity.
     *
     * Wage demands are indexed to EXPECTED inflation rather than to the target, at the unit coefficient
     * Friedman (1968) and Phelps (1967) require: anything less prices permanent money illusion into the
     * long run and tilts the long-run Phillips curve. Anchored against the fixed target instead, a cost
     * shock could raise prices but never the wages paid out of them, so the second round never happened
     * and no supply shock could outlive its own impulse -- the spiral had only its downstream half, wages
     * pushing the supercore basket through SUPERCORE_WAGE_TRANSMISSION and unit labour cost into the PPI.
     *
     * The anchoring lives in the expectation itself, not here: calculateTipsBreakeven() already holds 40%
     * of the breakeven on the central bank's target, so a credible regime damps the pass-through at source
     * and a regime losing its anchor stops damping it. The resulting loop gain is
     * 0.55 x 0.25 (wages into headline) x 0.65 (headline into the breakeven) = 0.089, comfortably
     * inside unity, so the spiral converges to roughly a tenth more inflation rather than running away.
     *
     * Read one tick stale: expectations are formed at step 4 and the labour market runs at step 3. That is
     * the right direction for a bargain struck against expectations that already existed, and the smoothed
     * series is a quarter wide, so the lag is immaterial at any production tick rate.
     *
     * @param MacroState $state         Current macroeconomic state.
     * @param float      $tfpGrowthRate Realized annual trend TFP growth rate.
     * @param float      $dt            Time increment in years.
     */
    public function calculateLaborMarketAndWages(MacroState $state, float $tfpGrowthRate, float $dt): void
    {
        $effectiveUnemployment = max(self::MIN_FRICTIONAL_UNEMPLOYMENT, $state->unemploymentRate);
        $beveridgeConstant = self::BEVERIDGE_CURVE_CONSTANT * ($state->nairu / MacroEngine::NATURAL_UNEMPLOYMENT);
        $state->jobVacanciesRate = max(0.01, min(0.12, $beveridgeConstant / $effectiveUnemployment));
        $state->laborTightness = $state->jobVacanciesRate / $effectiveUnemployment;

        $settlementPremium = $state->strikeSector !== null ? self::STRIKE_WAGE_SETTLEMENT_BUMP : 0.0;
        $targetWageGrowth = $tfpGrowthRate + $state->tipsBreakevenEma + (self::WAGE_TIGHTNESS_SENSITIVITY * ($state->laborTightness - MacroEngine::NATURAL_LABOR_TIGHTNESS)) + $settlementPremium;
        $targetWageGrowth = max(0.0, min(0.08, $targetWageGrowth));

        $wageGap = $targetWageGrowth - $state->wageGrowth;
        $adjustmentSpeed = $wageGap > 0
            ? self::WAGE_ADJUSTMENT_SPEED
            : self::WAGE_ADJUSTMENT_SPEED * self::WAGE_DOWNWARD_RIGIDITY_FACTOR;

        $state->wageGrowth += $adjustmentSpeed * $wageGap * $dt;
    }
}
