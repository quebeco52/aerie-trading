<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;

/**
 * Base class for all Business Models to reduce code duplication for standard financial physics.
 */
abstract class AbstractBusinessModel implements BusinessModelInterface
{
    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        return $macroTaxRate;
    }

    protected function getOperatingBase(Stock $stock): float
    {
        return max((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity(), FinancialConstants::MIN_OPERATING_BASE_CASH);
    }

    public function calculateInterestIncome(Stock $stock, array &$macroState, MathUtility $mathUtility): float
    {
        $cash = (float) $stock->getCorporateTreasury();
        $operatingBase = $this->getOperatingBase($stock);

        // Default behavior: Cash above target operating cash earns money-market yields.
        $targetCash = $this->calculateTargetOperatingCash($operatingBase, 0.0, (float) $stock->getWholesaleDebt());
        $excessCash = max(0.0, $cash - $targetCash);

        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        return $excessCash * $this->calculateCashYield($macroState, $policyRate);
    }

    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return $operatingBase * FinancialConstants::TARGET_OPERATING_CASH_RATIO;
    }

    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return $operatingBase * FinancialConstants::MIN_OPERATING_CASH_RATIO;
    }

    public function evaluateHoardingStatus(float $treasury, float $targetCashReserves, float $operatingBase, float $totalDebt): array
    {
        $excessCash = max(0.0, $treasury - $targetCashReserves);
        return [
            'excess_cash'     => $excessCash,
            'is_hoarder'      => $excessCash > ($operatingBase * FinancialConstants::HOARDER_THRESHOLD_RATIO),
            'is_mega_hoarder' => $excessCash > ($operatingBase * FinancialConstants::MEGA_HOARDER_THRESHOLD_RATIO),
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

    public function getInterestCoverage(float $ebit, float $interestExpense, float $depreciation = 0.0): float
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
        // Real financial theory dictates that companies optimize their WACC by maintaining a target capital structure.
        // They do NOT self-fund CapEx with cash if issuing debt lowers their cost of capital.
        // Instead, they issue debt to maintain their optimal Debt/Equity ratio, and use excess cash to buy back shares.
        return $baseCapacity;
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps): float
    {
        return ($earningsValue * 0.90) + ($pbFairValue * 0.10);
    }

    public function getSustainableDividendBase(Stock $stock, float $quarterlyEps, float $investedCapital, float $depRate): float
    {
        return $quarterlyEps;
    }

    public function getMarginReversionSpeed(): float
    {
        return 4.0;
    }

    public function processPassiveLiabilityGrowth(Stock $stock, array &$macroState, array &$state, MathUtility $mathUtility): void
    {
        // No-op by default
    }
}
