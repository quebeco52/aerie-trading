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
     * Diamond-Mortensen-Pissarides (DMP) Beveridge Curve & Wage Phillips Curve.
     *
     * Derives job vacancies (V) and labor market tightness (theta = V/U) along the hyperbolic
     * Beveridge curve, then models nominal wage growth through a tightness-augmented Phillips curve
     * with Bewley (1999) downward nominal wage rigidity.
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

        $targetWageGrowth = $tfpGrowthRate + MacroEngine::TARGET_INFLATION + (MacroEngine::WAGE_TIGHTNESS_SENSITIVITY * ($state->laborTightness - MacroEngine::NATURAL_LABOR_TIGHTNESS));
        $targetWageGrowth = max(0.0, min(0.08, $targetWageGrowth));

        $wageGap = $targetWageGrowth - $state->wageGrowth;
        $adjustmentSpeed = $wageGap > 0
            ? MacroEngine::WAGE_ADJUSTMENT_SPEED
            : MacroEngine::WAGE_ADJUSTMENT_SPEED * MacroEngine::WAGE_DOWNWARD_RIGIDITY_FACTOR;

        $state->wageGrowth += $adjustmentSpeed * $wageGap * $dt;
    }
}
