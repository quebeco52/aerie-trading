<?php

declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Math\FinancialConstants;
use App\Service\Macro\MacroEngine;

trait StandardTreasuryTrait
{
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

    public function calculateInterestIncome(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): float
    {
        $cash = (float) $stock->getCorporateTreasury();
        $operatingBase = max((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity(), FinancialConstants::MIN_OPERATING_BASE_CASH);

        $targetCash = $this->calculateTargetOperatingCash($operatingBase, 0.0, (float) $stock->getWholesaleDebt());
        $excessCash = max(0.0, $cash - $targetCash);

        return $excessCash * $this->calculateCashYield($macroState);
    }

    public function calculateCashYield(MacroStateDTO $macroState): float
    {
        return max(0.0, $macroState->policyRateEma - MacroEngine::CASH_YIELD_SPREAD);
    }
}
