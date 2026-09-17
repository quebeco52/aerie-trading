<?php

namespace App\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;

/**
 * Handles labor market dynamics, unemployment frictional adjustments,
 * Diamond-Mortensen-Pissarides Beveridge curve matching, and wage Phillips curve inflation transmission.
 */
class LaborMarketSubsystem
{
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
        $excessSlack = max(0.0, $state->unemploymentRateEma - $state->nairu - MacroEngine::NAIRU_HYSTERESIS_THRESHOLD);
        $state->nairu += MacroEngine::NAIRU_HYSTERESIS_SPEED * $excessSlack * $dt;

        $recovery = max(0.0, $state->nairu - MacroEngine::NATURAL_UNEMPLOYMENT)
            * max(0.0, $state->nairu - $state->unemploymentRateEma) * 0.5;
        $state->nairu -= $recovery * $dt;
        $state->nairu = max(MacroEngine::MIN_NAIRU, min(MacroEngine::MAX_NAIRU, $state->nairu));

        if ($state->outputGap <= 0.0) {
            $targetUnemployment = $state->nairu - (MacroEngine::OKUNS_COEFFICIENT * $state->outputGap);
        } else {
            $effectiveRange = max(0.001, $state->nairu - MacroEngine::MIN_FRICTIONAL_UNEMPLOYMENT);
            $targetUnemployment = MacroEngine::MIN_FRICTIONAL_UNEMPLOYMENT + ($effectiveRange * exp(- (MacroEngine::OKUNS_COEFFICIENT * $state->outputGap) / $effectiveRange));
        }

        $unemploymentGap = $targetUnemployment - $state->unemploymentRate;
        $adjustmentSpeed = $unemploymentGap > 0 ? MacroEngine::OKUNS_FIRING_SPEED : MacroEngine::OKUNS_HIRING_SPEED;

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
        $effectiveUnemployment = max(MacroEngine::MIN_FRICTIONAL_UNEMPLOYMENT, $state->unemploymentRate);
        $beveridgeConstant = MacroEngine::BEVERIDGE_CURVE_CONSTANT * ($state->nairu / MacroEngine::NATURAL_UNEMPLOYMENT);
        $state->jobVacanciesRate = max(0.01, min(0.12, $beveridgeConstant / $effectiveUnemployment));
        $state->laborTightness = $state->jobVacanciesRate / $effectiveUnemployment;

        $targetWageGrowth = $tfpGrowthRate + $state->tipsBreakevenEma + (MacroEngine::WAGE_TIGHTNESS_SENSITIVITY * ($state->laborTightness - MacroEngine::NATURAL_LABOR_TIGHTNESS));
        $targetWageGrowth = max(0.0, min(0.08, $targetWageGrowth));

        $wageGap = $targetWageGrowth - $state->wageGrowth;
        $adjustmentSpeed = $wageGap > 0
            ? MacroEngine::WAGE_ADJUSTMENT_SPEED
            : MacroEngine::WAGE_ADJUSTMENT_SPEED * MacroEngine::WAGE_DOWNWARD_RIGIDITY_FACTOR;

        $state->wageGrowth += $adjustmentSpeed * $wageGap * $dt;
    }
}
