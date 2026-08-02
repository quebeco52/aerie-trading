<?php
declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\Entity\Stock;

trait StandardCapitalAllocationTrait
{
    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float {
        return $isMegaHoarder ? $excessCash * 0.30 : $excessCash * 0.10;
    }
    
    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float {
        return max($organicSpend, $debtIssued * 0.75);
    }
    
    public function getSustainableDividendBase(Stock $stock, float $quarterlyEps, float $investedCapital, float $depRate): float {
        return $quarterlyEps;
    }
    
    public function getMaxOrganicGrowthSpeed(bool $isHoarder, bool $isMegaHoarder): float {
        return $isHoarder ? 0.15 : 0.08;
    }
}
