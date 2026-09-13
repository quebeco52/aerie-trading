<?php
declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\DTO\DebtExpansionAppetiteDTO;
use App\DTO\DebtCostDTO;

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
    
    public function getDebtExpansionAggressiveness(float $spreadMultiplier, float $totalDebt = 0.0, float $customerDeposits = 0.0, float $targetOperatingCash = 0.0, float $currentTreasury = 0.0): DebtExpansionAppetiteDTO {
        return new DebtExpansionAppetiteDTO(probability: 0.40 + ($spreadMultiplier * 0.50), aggressiveness: 0.05 + (0.35 * $spreadMultiplier));
    }
    
    public function getUnfundedExpansionCapacity(float $baseCapacity, float $excessCash): float {
        return $baseCapacity;
    }
    
    /**
     * Trade-off theory recapitalization test: a firm is under-leveraged only when equity is clearly more
     * expensive than debt, coverage leaves ample headroom, and leverage sits well below tolerance. Sector
     * models tune the three rails through class constants: intangible-heavy software and biotech (high
     * distress costs, near-zero optimal debt) demand a 300bps arbitrage and 15x coverage, while regulated
     * utilities with rate-base backed cash flows accept far less.
     */
    public function isUnderLeveraged(float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool {
        $arbitrageThreshold = defined('static::WACC_ARBITRAGE_THRESHOLD')
            ? (float) static::WACC_ARBITRAGE_THRESHOLD
            : \App\Service\Math\FinancialConstants::WACC_ARBITRAGE_BUFFER;
        $icrFloor = defined('static::MIN_RECAP_ICR_FLOOR')
            ? (float) static::MIN_RECAP_ICR_FLOOR
            : max(\App\Service\Math\FinancialConstants::MIN_ABSOLUTE_ICR_BUFFER, $minIcr * \App\Service\Math\FinancialConstants::REQUIRED_ICR_SAFETY_MULT);
        $debtRatioTolerance = defined('static::UNDERLEVERAGED_DEBT_RATIO')
            ? (float) static::UNDERLEVERAGED_DEBT_RATIO
            : \App\Service\Math\FinancialConstants::CORPORATE_UNDERLEVERAGED_RATIO;

        if ($costOfEquity <= ($effectiveCostOfDebt + $arbitrageThreshold)) {
            return false;
        }
        if ($interestCoverage < $icrFloor) {
            return false;
        }
        return $currentDebtRatio < ($targetDebtTolerance * $debtRatioTolerance);
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

        $minimumIcr = $this->getBuybackMinIcr() + 0.5;

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

    public function getDebtMaturityRolloverRate(): float {
        return \App\Service\Math\FinancialConstants::DEFAULT_QUARTERLY_DEBT_ROLLOVER;
    }
    
    public function getDeleveragingEvaluationDebt(float $totalDebt, float $wholesaleDebt): float {
        return $totalDebt;
    }
    
    public function getDeleveragingEvaluationLimit(float $macroDebtTolerance): float {
        return $macroDebtTolerance;
    }
    
    public function getDebtCostMetrics(DebtMetricsDTO $debtMetrics, float $currentDebt, float $wholesaleDebt, float $interestExpense): DebtCostDTO {
        $grossCostOfDebt = $currentDebt > 0 ? ($interestExpense / $currentDebt) : $debtMetrics->currentMarketRate;
        return new DebtCostDTO(grossCostOfDebt: $grossCostOfDebt, totalInterestCost: $interestExpense);
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

    public function getMinIcr(): float { return 2.00; }
    public function getBankruptEquityThreshold(): float { return 0.0; }
    public function getDistressEquityThreshold(): float { return 0.0; }
    public function getWarningEquityThreshold(): float { return 0.0; }
    public function getWholesaleLeverageLimit(): float { return 1.0; }
    public function getDividendCrisisIcr(): float { return 1.50; }
    public function getBuybackMinIcr(): float { return 2.00; }
    public function appliesDistressPremiumToCostOfEquity(): bool { return false; }
    public function shouldForceDeleveragingOnJunkOrHoarding(): bool { return true; }
}
