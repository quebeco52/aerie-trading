<?php

declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;

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

    public function calculateLiabilityBackingCash(float $currentLiability, float $wholesaleDebt): float
    {
        return 0.0;
    }

    /**
     * Cash above the target reserve, and whether it is a hoard: more than the model's thresholds of its hoarding
     * basis (the operating base, or total debt for a lender whose cash backs its funding).
     */
    public function evaluateHoardingStatus(float $treasury, float $targetCashReserves, float $operatingBase, float $totalDebt): array
    {
        $excessCash = max(0.0, $treasury - $targetCashReserves);
        $basis = match (static::HOARDING_BASIS) {
            'operating_base' => $operatingBase,
            'total_debt'     => $totalDebt,
        };

        return [
            'excess_cash'     => $excessCash,
            'is_hoarder'      => $excessCash > ($basis * static::HOARDER_THRESHOLD),
            'is_mega_hoarder' => $excessCash > ($basis * static::MEGA_HOARDER_THRESHOLD),
        ];
    }

    /** @return array{value: float, expected_return: float} */
    public function resolveEquitySleeve(Stock $stock, MacroStateDTO $macroState): array
    {
        return ['value' => 0.0, 'expected_return' => 0.0];
    }

    public function calculateInterestIncome(Stock $stock, MacroStateDTO $macroState, ?float $realizedWholesaleRate = null): float
    {
        $cash = (float) $stock->getCorporateTreasury();
        $operatingBase = max((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity(), FinancialConstants::MIN_OPERATING_BASE_CASH);

        $targetCash = $this->calculateTargetOperatingCash($operatingBase, 0.0, (float) $stock->getWholesaleDebt());
        $excessCash = max(0.0, $cash - $targetCash);

        return $excessCash * $this->calculateCashYield($macroState);
    }

    public function calculateCashYield(MacroStateDTO $macroState): float
    {
        return $macroState->moneyMarketYield();
    }

    /** An operating company's treasury is cash and money-market paper. Nothing in it has duration to mark. */
    public function resolveSecuritiesBook(Stock $stock, ?float $currentTreasury = null): float
    {
        return 0.0;
    }

    public function getSecuritiesFloatingShare(Stock $stock): float
    {
        return 0.0;
    }

    public function getDefaultSecuritiesDuration(): float
    {
        return 0.0;
    }

    /** Cash is deployed on the firm's own judgement of returns, never automatically. */
    public function deploysFundingIntoEarningAssets(): bool
    {
        return false;
    }
}
