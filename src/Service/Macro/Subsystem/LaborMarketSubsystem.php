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
        if ($state->outputGap <= 0.0) {
            $targetUnemployment = MacroEngine::NATURAL_UNEMPLOYMENT - (MacroEngine::OKUNS_COEFFICIENT * $state->outputGap);
        } else {
            $effectiveRange = MacroEngine::NATURAL_UNEMPLOYMENT - MacroEngine::MIN_FRICTIONAL_UNEMPLOYMENT;
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
     * Beveridge curve, then models nominal wage growth through a tightness-augmented Phillips curve.
     *
     * @param MacroState $state         Current macroeconomic state.
     * @param float      $tfpGrowthRate Realized annual trend TFP growth rate.
     * @param float      $dt            Time increment in years.
     */
    public function calculateLaborMarketAndWages(MacroState $state, float $tfpGrowthRate, float $dt): void
    {
        $effectiveUnemployment = max(MacroEngine::MIN_FRICTIONAL_UNEMPLOYMENT, $state->unemploymentRate);
        $state->jobVacanciesRate = max(0.01, min(0.12, MacroEngine::BEVERIDGE_CURVE_CONSTANT / $effectiveUnemployment));
        $state->laborTightness = $state->jobVacanciesRate / $effectiveUnemployment;

        $targetWageGrowth = $tfpGrowthRate + MacroEngine::TARGET_INFLATION + (MacroEngine::WAGE_TIGHTNESS_SENSITIVITY * ($state->laborTightness - MacroEngine::NATURAL_LABOR_TIGHTNESS));
        $targetWageGrowth = max(0.0, min(0.08, $targetWageGrowth));

        $state->wageGrowth += MacroEngine::WAGE_ADJUSTMENT_SPEED * ($targetWageGrowth - $state->wageGrowth) * $dt;
    }
}
