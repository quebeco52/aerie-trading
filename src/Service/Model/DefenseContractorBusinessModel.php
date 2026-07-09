<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Defense Contractors & Government Security.
 * 
 * Financial Physics:
 * - Revenue is locked into multi-decade government budgets.
 * - Cost-Plus Contracts: Inflation is actually a positive, because the government guarantees 
 *   a fixed percentage margin ON TOP of whatever the materials cost.
 * - Immune to consumer recessions.
 */
class DefenseContractorBusinessModel extends StandardCorporateBusinessModel
{
    // --- Dual-Stream Defense & Security Architecture ---
    /** Baseline fraction of revenue derived from long-term government defense contracts (Cost-Plus). */
    public const GOVERNMENT_CONTRACT_WEIGHT = 0.80;
    /** Baseline fraction of revenue derived from commercial security, cybersecurity, and protection systems. */
    public const COMMERCIAL_SERVICES_WEIGHT = 0.20;

    // --- Government Contracting & Cost-Plus Physics ---
    /** Volatility multiplier for top-line revenue shocks in stable government budget models. */
    public const REVENUE_VARIANCE_SCALAR   = 0.05;
    /** Multiplier scaling excess inflation into revenue bonuses reflecting cost-plus contracting. */
    public const COST_PLUS_BONUS_SCALAR    = 1.50;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Geopolitical Contract Lore & Shock Thresholds ---
    /** Negative z-score threshold indicating major contract loss to a defense rival. */
    public const CONTRACT_LOSS_Z_SCORE     = -2.50;
    /** Top-line revenue multiplier applied when a major defense contract is lost. */
    public const CONTRACT_LOSS_MULT        = 0.90;
    /** Positive z-score threshold indicating major multi-decade contract award. */
    public const CONTRACT_WIN_Z_SCORE      = 2.50;
    /** Top-line revenue multiplier applied when a major defense contract is won. */
    public const CONTRACT_WIN_MULT         = 1.10;

    // --- Analyst Visibility & Error ---
    /** Base analyst visibility into opaque government defense contract profitability. */
    public const ANALYST_BASE_VISIBILITY   = 0.50;
    /** Standard deviation of analyst estimation error for quarterly defense revenues. */
    public const ANALYST_ERROR_STD_DEV     = 0.10;

    // --- ROIC Annualization & Smoothing ---
    /** Annualization multiplier applied to quarterly NOPAT. */
    public const ROIC_ANNUALIZATION_MULT   = 4.00;
    /** Minimum allowable ROIC floor to prevent catastrophic negative overflow. */
    public const MIN_ROIC_CLAMP            = -0.50;
    /** Maximum allowable ROIC ceiling to prevent unrealistic hyperinflation. */
    public const MAX_ROIC_CLAMP            = 1.00;
    /** Weight given to current quarter ROIC when updating trailing twelve-month ROIC EMA. */
    public const ROIC_TTM_EMA_WEIGHT       = 0.20;
    /** Weight given to historical trailing twelve-month ROIC when updating ROIC EMA. */
    public const ROIC_TTM_HIST_WEIGHT      = 0.80;

    public function getMacroPhysics(Stock $stock, array &$macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        // Cost-plus contracts perfectly capture inflation dynamically. 
        // We strip generic pricing power to prevent double-dipping.
        $physics['pricing_power_multiplier'] = 1.0;
        return $physics;
    }

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $params = $this->resolveModelParameters($stock, [
            'government_contract_weight' => self::GOVERNMENT_CONTRACT_WEIGHT,
            'commercial_services_weight' => self::COMMERCIAL_SERVICES_WEIGHT,
        ]);

        $govtWeight       = $params['government_contract_weight'];
        $commercialWeight = $params['commercial_services_weight'];

        // Independent stream Z-scores
        $contractZ   = $mathUtility->generateStandardNormal(); // Core sovereign defense contracts
        $commercialZ = $mathUtility->generateStandardNormal(); // Commercial security & protection consulting

        // Cost-Plus Contracting (The Inflation Blessing):
        // Applies specifically to long-term sovereign government defense contracts ($govtWeight).
        $inflation = $macroState['inflation_ema'] ?? MacroEngine::TARGET_INFLATION;
        $costPlusBonus = ($inflation - MacroEngine::TARGET_INFLATION) * self::COST_PLUS_BONUS_SCALAR;

        $govtRevenue       = $expectedRevenue * $govtWeight * (1.0 + ($contractZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $costPlusBonus);
        $commercialRevenue = $expectedRevenue * $commercialWeight * (1.0 + ($commercialZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));

        // Tail Risk: Geopolitical Contract Wins/Losses impact the sovereign government contract stream directly
        $eventZ = $mathUtility->generateStandardNormal();
        $eventLore = null;

        if ($eventZ < self::CONTRACT_LOSS_Z_SCORE) {
            $govtRevenue *= self::CONTRACT_LOSS_MULT;
            $eventLore = "Lost a multi-billion dollar next-generation government defense contract to a rival.";
        } elseif ($eventZ > self::CONTRACT_WIN_Z_SCORE) {
            $govtRevenue *= self::CONTRACT_WIN_MULT;
            $eventLore = "Secured a massive, multi-decade international defense contract.";
        }

        $actualRevenue = max(0.0, $govtRevenue + $commercialRevenue);

        $actualVariableCosts = $actualRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin));
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        // Analyst Visibility
        // Cost-plus inflation is 100% public. Defense contracts are mostly public (~50% visibility).
        $analystError = $mathUtility->generateStandardNormal() * self::ANALYST_ERROR_STD_DEV;
        $dynamicVisibility = min(1.0, max(0.0, self::ANALYST_BASE_VISIBILITY + $analystError));
        $analystExpectedRevenue = max(0.0, $expectedRevenue * (1.0 + (($contractZ * $govtWeight + $commercialZ * $commercialWeight) * $dynamicVisibility) + ($costPlusBonus * $govtWeight)));
        $analystExpectedVariableCosts = $analystExpectedRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin));

        $primaryShockZ = abs($eventZ) > abs($contractZ) ? $eventZ : $contractZ;

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

    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate): float
    {
        // NOPAT (Net Operating Profit After Tax)
        $nopatProxy = $ebit > 0 ? $ebit * (1.0 - $corporateTaxRate) : $ebit;

        $effectiveCapital = max(1.0, abs($investedCapital));
        $truePostTaxReturn = ($nopatProxy / $effectiveCapital) * self::ROIC_ANNUALIZATION_MULT;

        $stock->setCurrentRoic((string) max(self::MIN_ROIC_CLAMP, min(self::MAX_ROIC_CLAMP, $truePostTaxReturn)));

        // Defense Contractors experience massive, lumpy shocks when winning/losing multi-billion dollar geopolitical contracts.
        // Use a 0.20 smoothing factor to prevent violent P/E whipsaws when a single contract is won or lost.
        $oldTtm = (float) $stock->getRoicTtm();
        $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * self::ROIC_TTM_EMA_WEIGHT) + ($oldTtm * self::ROIC_TTM_HIST_WEIGHT);
        $stock->setRoicTtm((string) max(self::MIN_ROIC_CLAMP, min(self::MAX_ROIC_CLAMP, $newTtm)));

        return $truePostTaxReturn;
    }
}

