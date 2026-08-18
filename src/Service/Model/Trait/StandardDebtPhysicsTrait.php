<?php
declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;

trait StandardDebtPhysicsTrait
{
    public function calculateCapacityModifier(float $totalDebt, float $equity, float $equityLimit, ?float $coreLiabilities = null): float {
        return 1.0;
    }
    
    public function getInterestCoverage(float $ebit, float $interestExpense, float $depreciation = 0.0): float {
        return $interestExpense > 0 ? ($ebit / $interestExpense) : ($ebit > 0 ? 999.0 : -999.0);
    }
    
    public function getDebtExpansionAggressiveness(float $spreadMultiplier, float $totalDebt = 0.0, float $customerDeposits = 0.0, float $targetOperatingCash = 0.0, float $currentTreasury = 0.0): array {
        return [
            'probability' => 0.40 + ($spreadMultiplier * 0.50),
            'aggressiveness' => 0.05 + (0.35 * $spreadMultiplier)
        ];
    }
    
    public function getUnfundedExpansionCapacity(float $baseCapacity, float $excessCash): float {
        return $baseCapacity;
    }
    
    public function isUnderLeveraged(float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool {
        if ($costOfEquity <= ($effectiveCostOfDebt + 0.01)) {
            return false;
        }
        $requiredIcrBuffer = max(2.00, $minIcr * 1.50);
        if ($interestCoverage < $requiredIcrBuffer) {
            return false;
        }
        return $currentDebtRatio < ($targetDebtTolerance * 0.75);
    }
    
    public function supportsUnderleveragedDebtExpansion(): bool {
        return true;
    }
    
    public function getHurdleRate(DebtHealthDTO $health): float {
        return $health->wacc ?? 0.08;
    }
    
    public function getExpansionCapacityBasis(float $equity, float $totalDebt, float $investedCapital): float {
        return $investedCapital;
    }
    
    public function calculateDebtExpansionCapacity(float $equity, float $totalDebt, float $wholesaleDebt, DebtHealthDTO $health, float $newBorrowingRate, float $ebit, float $depreciation): float {
        $evalDebt = $totalDebt;
        $evalTolerance = $health->debtTolerance;
        $balanceSheetCapacity = max(0.0, ($equity * $evalTolerance) - $evalDebt);

        $thresholds = $this->getModelThresholds();
        $minimumIcr = ($thresholds['buyback_min_icr'] ?? 3.0) + 0.5;

        $maxTolerableInterest = max(0.0, $ebit / $minimumIcr);
        $currentInterestExpense = $health->rawMetrics->interestExpense ?? 0.0;
        $availableInterestCapacity = max(0.0, $maxTolerableInterest - $currentInterestExpense);
        $incomeStatementCapacity = $newBorrowingRate > 0 ? ($availableInterestCapacity / $newBorrowingRate) : 0.0;

        return min($incomeStatementCapacity, $balanceSheetCapacity);
    }
    
    public function getLossGivenDefault(): float {
        return 0.40;
    }
    
    public function getRequiredIcrBuffer(): float {
        return 1.5;
    }
    
    public function getMaxFloatingDebtRatio(): float {
        return 0.30;
    }
    
    public function getDeleveragingEvaluationDebt(float $totalDebt, float $wholesaleDebt): float {
        return $totalDebt;
    }
    
    public function getDeleveragingEvaluationLimit(array $modelThresholds, float $macroDebtTolerance): float {
        return $macroDebtTolerance;
    }
    
    public function getDebtCostMetrics(DebtMetricsDTO $debtMetrics, float $currentDebt, float $wholesaleDebt, float $interestExpense): array {
        $grossCostOfDebt = $currentDebt > 0 ? ($interestExpense / $currentDebt) : $debtMetrics->currentMarketRate;
        return [
            'gross_cost_of_debt' => $grossCostOfDebt,
            'total_interest_cost' => $interestExpense
        ];
    }
    
    public function getNetDebtCapital(float $currentDebt, float $wholesaleDebt, float $treasury): float {
        return max(0.0, $currentDebt - $treasury);
    }
    
    public function calculateLeveredBeta(float $baseBeta, float $impliedTaxShieldRate, float $effectiveDebtToEquity, MathUtility $mathUtility): float {
        return $mathUtility->calculateLeveredBeta($baseBeta, $impliedTaxShieldRate, $effectiveDebtToEquity, 0.25);
    }
    
    public function requiresAlternativeZScore(): bool {
        return false;
    }
    
    public function processPassiveLiabilityGrowth(Stock $stock, MacroStateDTO $macroState, array &$state, MathUtility $mathUtility): void {}
}
