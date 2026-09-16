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
    public function checkBuybackRegulatoryLockout(Stock $stock, float $currentTreasury): ?bool;

    /**
     * How far below intrinsic book the market prices the firm — the accretion a repurchase captures
     * directly, before any view about earnings. Zero for an operating company, whose case is the return it
     * makes on capital. A closed-end structure is the exception, and it is why a trust's board buys at all.
     */
    public function resolveRepurchaseAccretion(Stock $stock, float $currentPrice): float;
}
