<?php

declare(strict_types=1);

namespace App\Service\Corporate;

use App\Entity\Stock;
use App\Data\Sectors;

class CapExEngine
{

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
        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';

        $strategy = Sectors::getBusinessModelStrategy($businessModel);
        $completionRate = $strategy->getCapExCompletionRate($stock);

        $cipBalance = (float) $stock->getCipBalance();

        if ($cipBalance <= 0.0) {
            return 0.0;
        }

        // Calculate the amount placed in service this quarter
        $newlyCompletedAssets = $cipBalance * $completionRate;

        // Due to exponential decay, a tiny tail will remain forever unless we snap to zero
        if ($cipBalance - $newlyCompletedAssets < 1000.0) {
            $newlyCompletedAssets = $cipBalance;
        }

        $stock->setCipBalance((string) max(0.0, $cipBalance - $newlyCompletedAssets));

        return $newlyCompletedAssets;
    }
}
