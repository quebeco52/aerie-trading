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
     * The share of the firm's capital it currently has no way to put to work at its hurdle, which the
     * life-cycle payout expansion reads as a reason to hand that capital back (DeAngelo & DeAngelo 2006,
     * Jensen 1986). Zero for a firm whose capital is all deployed: the general case is the saturation
     * severity the allocation engine computes for itself. An underwriter is the exception — it can decline
     * to write at the rate on offer, and the surplus behind the business it declines is redundant that
     * quarter however unsaturated its market is.
     */
    public function getUndeployableCapitalShare(Stock $stock, \App\DTO\MacroStateDTO $macroState, \App\Service\Math\MathUtility $mathUtility): float;

    /**
     * How far below intrinsic book the market prices the firm — the accretion a repurchase captures
     * directly, before any view about earnings. Zero for an operating company, whose case is the return it
     * makes on capital. A closed-end structure is the exception, and it is why a trust's board buys at all.
     */
    public function resolveRepurchaseAccretion(Stock $stock, float $currentPrice): float;
}
