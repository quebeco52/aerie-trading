<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Base class for all Business Models to reduce code duplication for standard financial physics.
 */
abstract class AbstractBusinessModel implements BusinessModelInterface
{
    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        return $macroTaxRate;
    }

    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return $operatingBase * 0.05;
    }

    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return $operatingBase * 0.03;
    }

    public function evaluateHoardingStatus(float $excessCash, float $operatingBase, float $totalDebt): array
    {
        return [
            'is_hoarder'      => $excessCash > ($operatingBase * 0.25),
            'is_mega_hoarder' => $excessCash > ($operatingBase * 0.40),
        ];
    }

    public function calculateDepositBeta(float $totalDebt, float $equity, float $equityLimit, float $customerDeposits): float
    {
        return 0.0;
    }

    public function calculateCapacityModifier(float $totalDebt, float $equity, float $equityLimit, ?float $coreLiabilities = null): float
    {
        return 1.0;
    }

    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float
    {
        return $isMegaHoarder ? $excessCash * 0.30 : $excessCash * 0.10;
    }

    public function getInterestCoverage(float $ebit, float $interestExpense): float
    {
        return $interestExpense > 0 ? ($ebit / $interestExpense) : ($ebit > 0 ? 999.0 : -999.0);
    }

    public function calculateCashYield(array &$macroState, float $policyRate): float
    {
        return max(0.0, $policyRate - MacroEngine::CASH_YIELD_SPREAD);
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier): array
    {
        return [
            'probability' => 0.40 + ($spreadMultiplier * 0.50),
            'aggressiveness' => 0.05 + (0.35 * $spreadMultiplier)
        ];
    }

    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float
    {
        return max($organicSpend, $debtIssued * 0.75);
    }

    public function getUnfundedExpansionCapacity(float $baseCapacity, float $excessCash): float
    {
        return $baseCapacity;
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps): float
    {
        return ($earningsValue * 0.90) + ($pbFairValue * 0.10);
    }

    public function processPassiveLiabilityGrowth(Stock $stock, array &$macroState, array &$state, MathUtility $mathUtility): void
    {
        // No-op by default
    }
}
