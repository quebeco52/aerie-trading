<?php

declare(strict_types=1);

namespace App\Service\Model\Strategy;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;

interface CapitalAllocationStrategyInterface
{
    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float;
    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float;
    public function getSustainableDividendBase(Stock $stock, float $quarterlyEps, float $investedCapital, float $depRate): float;
    public function getMaxOrganicGrowthSpeed(bool $isHoarder, bool $isMegaHoarder): float;
    public function getRegulatoryDividendCap(Stock $stock, float $currentTreasury, ?MacroStateDTO $macroState = null): ?float;
    public function checkBuybackRegulatoryLockout(Stock $stock, float $currentTreasury, ?MacroStateDTO $macroState = null): ?bool;

    /**
     * The share of the firm's capital it currently has no way to put to work at its hurdle, which the
     * life-cycle payout expansion reads as a reason to hand that capital back (DeAngelo & DeAngelo 2006,
     * Jensen 1986). Zero for a firm whose capital is all deployed: the general case is the saturation
     * severity the allocation engine computes for itself. An underwriter is the exception — it can decline
     * to write at the rate on offer, and the surplus behind the business it declines is redundant that
     * quarter however unsaturated its market is.
     */
    public function getUndeployableCapitalShare(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): float;

    /**
     * How far below intrinsic book the market prices the firm — the accretion a repurchase captures
     * directly, before any view about earnings. Zero for an operating company, whose case is the return it
     * makes on capital. A closed-end structure is the exception, and it is why a trust's board buys at all.
     */
    public function resolveRepurchaseAccretion(Stock $stock, float $currentPrice): float;

    /**
     * The book equity-to-assets ratio the firm manages its capital toward, or null for a firm that runs no
     * capital target and distributes on its earnings and cash instead.
     */
    public function getTargetCapitalRatio(Stock $stock): ?float;

    /**
     * The share of taxable income the firm must distribute each year to keep a pass-through tax status, or
     * zero for a firm whose payout is its own choice.
     */
    public function getMinimumDistributionRatio(): float;
}
