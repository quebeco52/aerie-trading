<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\Data\Company\Sectors;
use App\Entity\Stock;

class CapExEngine
{
    // --- Construction in Progress ---
    /** Construction left below this much (in currency) is placed in service whole: the queue empties exponentially. */
    public const CIP_SNAP_AMOUNT = 1000.0;

    public function allocateGrowthCapEx(Stock $stock, float $amount): void
    {
        $currentCip = (float) $stock->getCipBalance();
        $stock->setCipBalance((string) ($currentCip + $amount));
    }

    /**
     * Processes the CIP balance for a single quarter and returns the amount of CapEx that is now 'placed in service'.
     * The completed asset value is smoothly amortized into the revenue-generating asset base.
     */
    public function processCipQueue(Stock $stock): float
    {
        $strategy = Sectors::strategyFor($stock->getIndustry());
        $completionRate = $strategy->getCapExCompletionRate($stock);

        $cipBalance = (float) $stock->getCipBalance();

        if ($cipBalance <= 0.0) {
            return 0.0;
        }

        // Calculate the amount placed in service this quarter
        $newlyCompletedAssets = $cipBalance * $completionRate;

        // Due to exponential decay, a tiny tail will remain forever unless we snap to zero
        if ($cipBalance - $newlyCompletedAssets < self::CIP_SNAP_AMOUNT) {
            $newlyCompletedAssets = $cipBalance;
        }

        $stock->setCipBalance((string) max(0.0, $cipBalance - $newlyCompletedAssets));

        return $newlyCompletedAssets;
    }
}
