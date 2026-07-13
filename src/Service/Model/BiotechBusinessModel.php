<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Biotechnology and Specialty Drug Manufacturers.
 * 
 * Financial Physics:
 * - CapEx represents high-risk R&D that creates intangible patent assets.
 * - Blockbuster Drug Super-Cycles: High R&D reinvestment generates massive pricing power and patent protection.
 * - Patent Cliff Amortization: If R&D intensity lags, patents expire and generic competition rapidly erodes margins.
 * - Inelastic Demand: Highly immune to macroeconomic output gap recessions.
 * - High Idiosyncratic Variance: Driven by binary clinical trial outcomes (FDA approvals vs. Phase III failures).
 */
class BiotechBusinessModel extends StandardCorporateBusinessModel
{
    // --- Dual-Stream Biotech Portfolio Architecture ---
    /** Baseline fraction of revenue derived from commercially marketed, patent-protected established pharmaceuticals. */
    public const ESTABLISHED_DRUG_WEIGHT = 0.70;
    /** Baseline fraction of revenue derived from high-risk clinical trial pipeline and new indications. */
    public const PIPELINE_DRUG_WEIGHT    = 0.30;

    // --- Inelastic Healthcare Demand & Macro Physics ---
    /** Macroeconomic demand shift sensitivity to output gap for essential medical treatments. */
    public const MACRO_DEMAND_SCALAR       = 0.25;
    /** Minimum beta floor applied when calculating inflation pricing power. */
    public const MIN_PRICING_BETA_FLOOR    = 0.20;
    /** Multiplier scaling stock beta to determine pricing power responsiveness to inflation. */
    public const PRICING_BETA_SCALAR       = 0.50;

    // --- Clinical Trial & Patent Cliff Physics ---
    /** Volatility multiplier for top-line revenue shocks reflecting ongoing clinical trial readouts. */
    public const REVENUE_VARIANCE_SCALAR   = 0.25;
    /** Positive z-score threshold required to trigger landmark FDA approval blockbuster lore. */
    public const TRIAL_APPROVAL_Z_SCORE    = 2.20;
    /** Top-line revenue multiplier applied when a blockbuster specialty drug pipeline is approved. */
    public const TRIAL_APPROVAL_REV_MULT   = 1.20;
    /** Variable margin improvement reflecting high-margin patent monopoly protection. */
    public const TRIAL_APPROVAL_MARGIN_BONUS = -0.08;
    /** Negative z-score threshold required to trigger Phase III clinical failure and patent cliff lore. */
    public const TRIAL_FAILURE_Z_SCORE     = -2.20;
    /** Top-line revenue multiplier applied during major clinical trial failures and generic erosion. */
    public const TRIAL_FAILURE_REV_MULT    = 0.85;
    /** Variable margin compression penalty from generic drug competition after patent expiration. */
    public const TRIAL_FAILURE_MARGIN_PENALTY = 0.10;
    /** Continuous variable margin sensitivity to interim Phase II/III clinical trial readouts. */
    public const CONTINUOUS_PIPELINE_MARGIN_SENSITIVITY = 0.015;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Analyst Visibility & Error ---
    /** Standard deviation of analyst estimation error for quarterly biotech revenues. */
    public const ANALYST_ERROR_STD_DEV     = 0.05;
    /** Base analyst visibility into binary public FDA decisions and trial results. */
    public const EVENT_VISIBILITY_BASE     = 0.85;
    /** Minimum allowable analyst visibility floor for major public clinical trial announcements. */
    public const EVENT_VISIBILITY_MIN      = 0.70;
    /** Base analyst visibility into routine, non-event biotech operational variance. */
    public const ROUTINE_VISIBILITY_BASE   = 0.20;
    /** Minimum allowable analyst visibility floor for routine non-event operational variance. */
    public const ROUTINE_VISIBILITY_MIN    = 0.10;

    // --- Patent Moat & Capital Structure Rails ---
    /** Operating margin mean reversion speed: slower speed reflects multi-year patent monopoly protection. */
    public const PATENT_REVERSION_SPEED    = 2.5;
    /** Minimum WACC arbitrage spread required before under-leveraged recapitalization is permitted. */
    public const WACC_ARBITRAGE_THRESHOLD  = 0.03;
    /** Minimum interest coverage ratio required to permit recapitalization for binary R&D models. */
    public const MIN_RECAP_ICR_FLOOR       = 15.0;
    /** Maximum debt tolerance threshold fraction triggering under-leveraged status. */
    public const UNDERLEVERAGED_DEBT_RATIO = 0.50;

    // --- Patent Cliff & Blockbuster Capital Reinvestment Physics ---
    /** Quarterly margin decay rate per unit of R&D underinvestment below patent replacement rate. */
    public const PATENT_CLIFF_DECAY_RATE      = 0.025;
    /** Quarterly margin gain scalar per unit of logarithmic R&D overinvestment above replacement rate. */
    public const BLOCKBUSTER_GAIN_RATE        = 0.012;
    /** Structural minimum operating margin floor under severe generic drug competition (off-patent). */
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.08;
    /** Structural maximum operating margin ceiling for proprietary patented biologic blockbusters. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.50;

    // --- R&D Pipeline Valuation Rails ---
    /** Valuation discount applied when FCF is negative due to heavy clinical trial funding. */
    public const BIOTECH_RESEARCH_BURN_DISCOUNT = 0.88;

    public function getMacroPhysics(Stock $stock, array &$macroState): array
    {
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $inflation = $macroState['inflation_ema'] ?? MacroEngine::TARGET_INFLATION;
        $beta = (float) $stock->getBeta();

        // Inelastic healthcare demand: people require medical treatments regardless of the economic cycle.
        return [
            'macro_demand_shift' => $outputGap * $beta * self::MACRO_DEMAND_SCALAR,
            'pricing_power_multiplier' => 1.0 + ($inflation * max(self::MIN_PRICING_BETA_FLOOR, $beta * self::PRICING_BETA_SCALAR)),
        ];
    }

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $params = $this->resolveModelParameters($stock, [
            'established_drug_weight' => self::ESTABLISHED_DRUG_WEIGHT,
            'pipeline_drug_weight'    => self::PIPELINE_DRUG_WEIGHT,
        ]);

        $establishedWeight = $params['established_drug_weight'];
        $pipelineWeight    = $params['pipeline_drug_weight'];

        // Independent stream Z-scores
        $establishedZ = $mathUtility->generateStandardNormal(); // Commercial prescription volume variance
        $pipelineZ    = $mathUtility->generateStandardNormal(); // Clinical trial milestone readouts

        $establishedRevenue = $expectedRevenue * $establishedWeight * (1.0 + ($establishedZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));
        $pipelineRevenue    = $expectedRevenue * $pipelineWeight * (1.0 + ($pipelineZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));

        // Patent Cliff vs. Blockbuster R&D Super-Cycle
        // Applies directly to the high-risk pipeline drug stream ($pipelineWeight).
        // Continuous pipeline clinical progress ($pipelineZ) smoothly adjusts variable margin.
        $continuousPipelineShift = -self::CONTINUOUS_PIPELINE_MARGIN_SENSITIVITY * $pipelineZ * $pipelineWeight;
        $patentModifier = $continuousPipelineShift;
        $eventLore = null;

        $trialZ = $mathUtility->generateStandardNormal();
        if ($trialZ > self::TRIAL_APPROVAL_Z_SCORE) {
            $pipelineRevenue *= self::TRIAL_APPROVAL_REV_MULT;
            $patentModifier += self::TRIAL_APPROVAL_MARGIN_BONUS * $pipelineWeight;
            $eventLore = "Received landmark regulatory approval for a blockbuster specialty drug pipeline.";
        } elseif ($trialZ < self::TRIAL_FAILURE_Z_SCORE) {
            $pipelineRevenue *= self::TRIAL_FAILURE_REV_MULT;
            $patentModifier += self::TRIAL_FAILURE_MARGIN_PENALTY * $pipelineWeight;
            $eventLore = "Suffered a major clinical trial setback and patent cliff generic erosion.";
        }

        $actualRevenue = max(0.0, $establishedRevenue + $pipelineRevenue);

        $actualVariableCosts = $actualRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + $patentModifier));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        // Analyst Visibility
        // Clinical trial results and FDA decisions are sudden, binary public news events (~85% visibility when they occur).
        $analystError = $mathUtility->generateStandardNormal() * self::ANALYST_ERROR_STD_DEV;
        $dynamicVisibility = $eventLore !== null ? min(1.0, max(self::EVENT_VISIBILITY_MIN, self::EVENT_VISIBILITY_BASE + $analystError)) : min(1.0, max(self::ROUTINE_VISIBILITY_MIN, self::ROUTINE_VISIBILITY_BASE + $analystError));
        $analystExpectedRevenue = $expectedRevenue * (1.0 + (($establishedZ * $establishedWeight + $pipelineZ * $pipelineWeight) * $dynamicVisibility));
        $analystExpectedVariableCosts = $analystExpectedRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin + ($patentModifier * $dynamicVisibility)));

        $primaryShockZ = abs($trialZ) > abs($establishedZ) ? $trialZ : $establishedZ;

        return [
            'actual_revenue'                  => $actualRevenue,
            'actual_variable_costs'           => $actualVariableCosts,
            'analyst_expected_revenue'        => $analystExpectedRevenue,
            'analyst_expected_variable_costs' => $analystExpectedVariableCosts,
            'ebit'                            => $ebit,
            'primary_shock_z'                 => $primaryShockZ,
            'event_lore'                      => $eventLore
        ];
    }

    public function getMarginReversionSpeed(): float
    {
        // Patents provide multi-year protection, so excess margins revert more slowly than standard tech
        return self::PATENT_REVERSION_SPEED;
    }

    public function isUnderLeveraged(bool $isFinancial, float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool
    {
        // Biotech firms face binary R&D clinical trial outcomes and carry high financial distress costs.
        // Their optimal capital structure is near zero debt. They should only recapitalize under extreme
        // WACC arbitrage (Ke > Kd + 3.0%) and extraordinary cash flow safety (ICR > 15.0).
        if ($costOfEquity <= ($effectiveCostOfDebt + self::WACC_ARBITRAGE_THRESHOLD)) {
            return false;
        }
        if ($interestCoverage < self::MIN_RECAP_ICR_FLOOR) {
            return false;
        }
        return $currentDebtRatio < ($targetDebtTolerance * self::UNDERLEVERAGED_DEBT_RATIO);
    }

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        return 0.10; // Clinical drug inventory and specialized biologic materials buffer
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            // Patent Cliff Amortization: underinvestment causes patents to expire without replacement
            $decayRate = self::PATENT_CLIFF_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            // Blockbuster Pipeline Expansion: R&D overinvestment creates proprietary biologic monopolies
            $modGain = self::BLOCKBUSTER_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }

    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float
    {
        if ($fcfPerShare !== null && $fcfPerShare > 0.0) {
            $multiplier = $mathUtility->calculateDcfMultiplier($liveWacc, self::DCF_TERMINAL_GROWTH_RATE);
            $annualFcf = $fcfPerShare * 4.0;
            $dcfFairValue = min(max(0.01, $annualFcf * $multiplier), $peFairValue * self::MAX_DCF_TO_PE_CAP_MULT);
            return ($peFairValue + $dcfFairValue) / 2.0;
        }
        // During clinical R&D cash burn cycles, value biotech firms on their clinical revenue pipeline
        return $fcfPerShare !== null ? max($revenueFloorValue, $peFairValue * self::BIOTECH_RESEARCH_BURN_DISCOUNT) : max($revenueFloorValue, $peFairValue);
    }
}

