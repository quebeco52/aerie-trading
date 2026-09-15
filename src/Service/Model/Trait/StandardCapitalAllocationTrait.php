<?php

declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\Entity\Stock;
use App\Service\Math\FinancialConstants;

trait StandardCapitalAllocationTrait
{
    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float
    {
        return $isMegaHoarder
            ? $excessCash * FinancialConstants::BUYBACK_SPEND_MEGA_HOARDER_RATIO
            : $excessCash * FinancialConstants::BUYBACK_SPEND_NORMAL_RATIO;
    }

    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float
    {
        return max($organicSpend, $debtIssued * FinancialConstants::ORGANIC_CAPEX_DEBT_RATIO);
    }

    public function getSustainableDividendBase(Stock $stock, float $quarterlyEps, float $investedCapital, float $depRate): float
    {
        return $quarterlyEps;
    }

    public function getMaxOrganicGrowthSpeed(bool $isHoarder, bool $isMegaHoarder): float
    {
        return $isHoarder
            ? FinancialConstants::STD_HOARDER_GROWTH_LIMIT
            : FinancialConstants::STD_STANDARD_GROWTH_LIMIT;
    }

    public function getRegulatoryDividendCap(Stock $stock, float $currentTreasury): ?float
    {
        return null;
    }

    public function checkBuybackRegulatoryLockout(Stock $stock, float $currentTreasury): ?bool
    {
        return null;
    }
}
