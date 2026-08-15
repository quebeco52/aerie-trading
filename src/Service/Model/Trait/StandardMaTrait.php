<?php
declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\Entity\Stock;

trait StandardMaTrait
{
    public function getAcquisitionType(string $defaultType): string {
        return $defaultType;
    }
    
    public function applyMaSpendCap(float $purchasePrice, float $equity, bool $isMegaHoarder, bool $isEmpireBuilder): float {
        return $purchasePrice;
    }
    
    public function blendAcquisitionDNA(Stock $acquirer, float $oldCapitalBase, float $purchasePrice, float $effectiveTargetRoic, float $totalNewCapital): void {
        $oldBaselineRoic = (float) $acquirer->getBaselineRoic();
        $blendedBaselineRoic = (($oldCapitalBase * $oldBaselineRoic) + ($purchasePrice * $effectiveTargetRoic)) / max(1.0, $totalNewCapital);
        $acquirer->setBaselineRoic((string) max(0.01, $blendedBaselineRoic));
    }
    
    public function calculateDivestedEquity(Stock $seller, float $divestedFraction, float $currentEquity, float $currentDebt, float $treasury, float $investedCapital, float $lostDebt): float {
        $lostInvestedCapital = $investedCapital * $divestedFraction;
        return $lostInvestedCapital - $lostDebt;
    }
    
    public function shedDivestedLiabilities(Stock $seller, float $divestedFraction, float $currentTreasury): void {}
    
    public function boostStructuralEfficiency(Stock $seller, float $divestedFraction, float $bumpMultiplier): void {
        $baselineRoic = (float) $seller->getBaselineRoic();
        $roicBump = $baselineRoic * ($divestedFraction * $bumpMultiplier);
        $seller->setBaselineRoic((string) ($baselineRoic + $roicBump));
    }
}
