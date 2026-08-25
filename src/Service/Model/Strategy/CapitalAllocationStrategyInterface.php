<?php

declare(strict_types=1);

namespace App\Service\Model\Strategy;

use App\Entity\Stock;

interface CapitalAllocationStrategyInterface
{
    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float;
    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float;
    public function getSustainableDividendBase(Stock $stock, float $quarterlyEps, float $investedCapital, float $depRate): float;
    public function getMaxOrganicGrowthSpeed(bool $isHoarder, bool $isMegaHoarder): float;
    public function getRegulatoryDividendCap(Stock $stock, float $currentTreasury): ?float;
}
