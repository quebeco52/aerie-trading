<?php

declare(strict_types=1);

namespace App\Service\Macro\Subsystem\Aggregate;

use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;

/**
 * The supply side's growth: total factor productivity's trend and random-walk level, the speeds at which output and
 * potential absorb its shocks (potential also a credit crisis's scar), and the natural rate that trend growth sets.
 */
final class ProductivityGrowth
{
    // --- SOLOW-SWAN TOTAL FACTOR PRODUCTIVITY (TFP) ---
    /**
     * Level innovation of TFP per sqrt(year): Fernald's utilization-adjusted quarterly TFP 1947-2026 is a random walk
     * at 1-3 year horizons with this sd (annual kurtosis 2.2, so no jump component).
     */
    public const TFP_VOLATILITY = 0.0165;
    /** Endogenous R&D knowledge spillover sensitivity to economic expansion and capital utilization. */
    public const TFP_OUTPUT_GAP_SENSITIVITY = 0.05;
    /** Structural upper bound ceiling for annual TFP growth rate. */
    public const MAX_TFP_GROWTH_RATE = 0.050;
    /** Structural lower bound floor for annual TFP growth rate. */
    public const MIN_TFP_GROWTH_RATE = -0.030;

    // --- Productivity Shock Absorption (Basu, Fernald & Kimball 2006) ---
    /**
     * Rate at which actual output absorbs a TFP level shock through a second-order Pascal lag (Solow 1960); fitted with
     * the potential speed to the CBO gap's response to Fernald TFP shocks over 20 quarters, holding the supply-driven
     * gap to the ~1.2pp sd that response implies.
     */
    public const TFP_OUTPUT_ABSORPTION_SPEED = 1.65;
    /**
     * Time constant (years) over which potential takes a credit crisis's scar: capital idle in the slump is scrapped
     * and the long-term unemployed lose their skills while it lasts (Blanchard & Summers 1986 hysteresis).
     */
    public const CRISIS_SCAR_ABSORPTION_YEARS = 3.0;
    /**
     * Rate at which potential absorbs the same shock, also through a second-order Pascal lag, as capital and
     * organisation adjust.
     */
    public const TFP_POTENTIAL_ABSORPTION_SPEED = 0.75;

    // --- Natural Rate of Interest (Holston, Laubach & Williams 2017) ---
    /** r* per point of trend growth, HLW's c: 1.113 for the US (2023 vintage, 1961-2026 sample). */
    public const NATURAL_RATE_GROWTH_LOADING = 1.113;
    /** Speed of adjustment (kappa) of natural real rate toward fundamental equilibrium. */
    public const NATURAL_RATE_ADJUSTMENT_SPEED = 1.0;
    /** Structural lower bound floor for natural real rate. */
    public const MIN_NATURAL_RATE = 0.005;
    /** Structural upper bound ceiling for natural real rate. */
    public const MAX_NATURAL_RATE = 0.035;

    public function __construct(
        private readonly MathUtility $mathUtility,
        /** Records each TFP innovation. Null in a test or a headless harness, off everywhere the ticker is not. */
        private readonly ?MacroDiagnosticsProbe $diagnostics = null,
    ) {}

    /**
     * Solow-Swan (1956) & Romer (1990) Endogenous Growth.
     *
     * Productivity is a trend path (secular drift plus R&D capital deepening in expansions) times a random-walk
     * level shock: Fernald's utilization-adjusted TFP is a random walk at business-cycle horizons with no fat
     * tails, so a breakthrough is a sequence of ordinary innovations, not a jump. The shock level is kept apart
     * from the trend because output and potential absorb it at their own speeds (absorbProductivityShocks).
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     * @return float Realized annual trend TFP growth rate.
     */
    public function calculateTotalFactorProductivity(MacroState $state, float $dt): float
    {
        $currentTfp = $state->totalFactorProductivityIndex ?? MacroConstants::TFP_BASELINE;

        $endogenousGrowth = $state->outputGapEma * self::TFP_OUTPUT_GAP_SENSITIVITY;
        $trendGrowthRate = MacroConstants::TFP_DRIFT + $endogenousGrowth;
        $clampedTrendGrowthRate = max(self::MIN_TFP_GROWTH_RATE, min(self::MAX_TFP_GROWTH_RATE, $trendGrowthRate));

        $innovation = self::TFP_VOLATILITY * sqrt($dt) * $this->mathUtility->generateStandardNormal();
        $state->tfpShockLevel += $innovation;
        $this->diagnostics?->recordShock('tfpInnovation', $innovation);

        $state->totalFactorProductivityIndex = max(1.0, $currentTfp * exp(($clampedTrendGrowthRate * $dt) + $innovation));

        return $clampedTrendGrowthRate;
    }

    /**
     * Basu, Fernald & Kimball (2006): a technology improvement reaches output only with a delay (hours fall on
     * impact, output catches up over two years), and potential absorbs it more slowly still as capital and
     * organisation adjust. Both follow the shock level through second-order Pascal lags (Solow 1960), output
     * the faster; their difference is the supply-side part of the output gap. With monetary policy seeing
     * through it, the whole gap opens positive, peaks near +0.4% at eighteen months and closes within about
     * three and a half years, against the CBO gap's +0.5% at two and a half years and close within four and a
     * half after a Fernald TFP shock.
     *
     * @param MacroState $state     Current macroeconomic state.
     * @param float      $trendRate Trend TFP growth from calculateTotalFactorProductivity().
     * @param float      $dt        Time increment in years.
     * @return float Productivity growth potential output is built on: the trend plus the shock and crisis scar
     *               potential absorbed this tick.
     */
    public function absorbProductivityShocks(MacroState $state, float $trendRate, float $dt): float
    {
        $state->tfpOutputStage1 = TimeSeries::calculateDistributedLag($state->tfpOutputStage1, $state->tfpShockLevel, $dt, 1.0 / self::TFP_OUTPUT_ABSORPTION_SPEED);
        $state->tfpOutputStage2 = TimeSeries::calculateDistributedLag($state->tfpOutputStage2, $state->tfpOutputStage1, $dt, 1.0 / self::TFP_OUTPUT_ABSORPTION_SPEED);

        $priorPotential = $state->tfpPotentialAbsorbed;
        $state->tfpPotentialStage1 = TimeSeries::calculateDistributedLag($state->tfpPotentialStage1, $state->tfpShockLevel, $dt, 1.0 / self::TFP_POTENTIAL_ABSORPTION_SPEED);
        $state->tfpPotentialAbsorbed = TimeSeries::calculateDistributedLag($priorPotential, $state->tfpPotentialStage1, $dt, 1.0 / self::TFP_POTENTIAL_ABSORPTION_SPEED);

        // Blanchard & Summers (1986) hysteresis: potential takes a credit crisis's scar while the slump lasts, so
        // output the slump holds down reads, as potential falls to meet it, as less slack
        // (OutputGap::calculateOutputGap()).
        $state->crisisScarStep = $state->crisisScarPending * TimeSeries::smoothingWeight($dt, self::CRISIS_SCAR_ABSORPTION_YEARS);
        $state->crisisScarPending -= $state->crisisScarStep;

        return $trendRate + (($state->tfpPotentialAbsorbed - $priorPotential + $state->crisisScarStep) / $dt);
    }

    /**
     * Holston, Laubach & Williams (2017) natural rate of interest: r* = c g + z.
     *
     * r* moves with the TREND growth of potential, productivity's and the labour force's (which the immigration regime
     * sets). A level shock to potential (their sigma_y*, here a productivity
     * shock being absorbed) shifts output, not its trend, and leaves r* alone; the output gap does not enter r* at
     * all, it enters the IS curve r* is the neutral point of. HLW's one-sided estimate does move with the cycle,
     * but that is the filter reading IS residuals as z, not the natural rate. Their z, the slow drift from
     * demographics and safe-asset demand, is not modelled.
     *
     * @param MacroState $state           Current macroeconomic state.
     * @param float      $trendGrowthRate Secular trend productivity growth, before absorbed level shocks and the
     *                                    cycle's R&D term.
     * @param float      $dt              Time increment in years.
     */
    public function calculateNaturalRate(MacroState $state, float $trendGrowthRate, float $dt): void
    {
        $trendPotentialGrowthGap = ($trendGrowthRate - MacroConstants::TFP_DRIFT) + ($state->laborForceGrowthRate - MacroConstants::STRUCTURAL_LABOR_GROWTH_RATE);
        $targetNaturalRate = MacroConstants::BASE_NATURAL_RATE + (self::NATURAL_RATE_GROWTH_LOADING * $trendPotentialGrowthGap);
        $targetNaturalRate = max(self::MIN_NATURAL_RATE, min(self::MAX_NATURAL_RATE, $targetNaturalRate));

        $state->naturalRate = TimeSeries::calculateDistributedLag($state->naturalRate, $targetNaturalRate, $dt, 1.0 / self::NATURAL_RATE_ADJUSTMENT_SPEED);
    }
}
