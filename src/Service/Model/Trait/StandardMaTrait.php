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
    
    public function getMaArchetypeStrategy(string $archetype): array {
        if ($archetype === 'empire_builder') {
            return ['prob' => 0.050, 'spend' => 0.80, 'type' => 'LEVERAGED BUYOUT', 'use_leverage' => true, 'use_stock' => false];
        }
        if ($archetype === 'mega_hoarder') {
            return ['prob' => 0.015, 'spend' => 0.60, 'type' => 'CONGLOMERATE EXPANSION', 'use_leverage' => false, 'use_stock' => false];
        }
        if ($archetype === 'hoarder') {
            return ['prob' => 0.020, 'spend' => 0.40, 'type' => 'CONGLOMERATE EXPANSION', 'use_leverage' => false, 'use_stock' => false];
        }
        return ['prob' => 0.035, 'spend' => 0.40, 'type' => 'LEVERAGED BUYOUT', 'use_leverage' => true, 'use_stock' => false];
    }
}
