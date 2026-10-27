<?php

declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;

trait StandardMaTrait
{
    public function getAcquisitionType(string $defaultType): string
    {
        return $defaultType;
    }

    public function applyMaSpendCap(float $purchasePrice, float $equity): float
    {
        return $purchasePrice;
    }

    public function blendAcquisitionDNA(Stock $acquirer, float $oldCapitalBase, float $purchasePrice, float $effectiveTargetRoic, float $totalNewCapital): void
    {
        $oldBaselineRoic = (float) $acquirer->getBaselineRoic();
        $blendedBaselineRoic = (($oldCapitalBase * $oldBaselineRoic) + ($purchasePrice * $effectiveTargetRoic)) / max(1.0, $totalNewCapital);
        $acquirer->setBaselineRoic((string) max(0.01, $blendedBaselineRoic));
        $this->blendAnchorReturn($acquirer, $oldCapitalBase, $purchasePrice, $effectiveTargetRoic, $totalNewCapital);
    }

    /**
     * The level the buyer's filed returns revert to (EarningsEngine::STATE_ANCHOR_RETURN) blends as the seeded return
     * does, by capital, as the business bought files its return in them from now on.
     */
    protected function blendAnchorReturn(Stock $acquirer, float $oldCapitalBase, float $purchasePrice, float $effectiveTargetRoic, float $totalNewCapital): void
    {
        $momentum = $acquirer->getEarningsMomentumZ() ?? [];
        if (isset($momentum[EarningsEngine::STATE_ANCHOR_RETURN])) {
            $momentum[EarningsEngine::STATE_ANCHOR_RETURN] = (($oldCapitalBase * (float) $momentum[EarningsEngine::STATE_ANCHOR_RETURN]) + ($purchasePrice * $effectiveTargetRoic)) / max(1.0, $totalNewCapital);
            $acquirer->setEarningsMomentumZ($momentum);
        }
    }

    /**
     * Capital-proxy estimate of the book value a divested division takes with it. Only reached for a
     * balance sheet with no plant ledger; once the ledgers exist the M&A engine reads the actual balances.
     */
    public function calculateDivestedEquity(Stock $seller, float $divestedFraction, float $currentEquity, float $currentDebt, float $treasury, float $investedCapital, float $lostDebt): float
    {
        $lostInvestedCapital = $investedCapital * $divestedFraction;
        return $lostInvestedCapital - $lostDebt;
    }

    public function shedDivestedLiabilities(Stock $seller, float $divestedFraction, float $currentTreasury): void {}
}
