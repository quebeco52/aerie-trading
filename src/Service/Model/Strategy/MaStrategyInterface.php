<?php

declare(strict_types=1);

namespace App\Service\Model\Strategy;

use App\Entity\Stock;

interface MaStrategyInterface
{
    public function getAcquisitionType(string $defaultType): string;
    public function applyMaSpendCap(float $purchasePrice, float $equity, bool $isMegaHoarder, bool $isEmpireBuilder): float;
    public function blendAcquisitionDNA(Stock $acquirer, float $oldCapitalBase, float $purchasePrice, float $effectiveTargetRoic, float $totalNewCapital): void;
    public function calculateDivestedEquity(Stock $seller, float $divestedFraction, float $currentEquity, float $currentDebt, float $treasury, float $investedCapital, float $lostDebt): float;
    public function shedDivestedLiabilities(Stock $seller, float $divestedFraction, float $currentTreasury): void;
    public function boostStructuralEfficiency(Stock $seller, float $divestedFraction, float $bumpMultiplier): void;
    public function getMaArchetypeStrategy(string $archetype): array;
}
