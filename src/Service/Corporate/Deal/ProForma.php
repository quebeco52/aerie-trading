<?php

declare(strict_types=1);

namespace App\Service\Corporate\Deal;

use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;

/**
 * The pro forma restatement both sides of a deal make to the trailing year: credit agreements measure a buyer as if it
 * had owned the target all year, and a seller as if the business it sold had never been its own.
 */
final class ProForma
{
    /**
     * Restates the trailing four quarters pro forma for a deal: each quarter keeps the retained share of what
     * the firm earned and gains a quarter of the acquired business's annual revenue and operating income. A business
     * sold whole that earned at its own margin keeps a different share of the sales than of the income.
     */
    public static function restateOperatingHistory(Stock $stock, float $retainedShare, float $acquiredAnnualRevenue, float $acquiredAnnualOperatingIncome, ?float $retainedRevenueShare = null): void
    {
        $revenueShare = $retainedRevenueShare ?? $retainedShare;
        $history = $stock->getQuarterlyOperatingHistory();
        if ($history === null || $history === []) {
            return;
        }

        $quarters = EarningsEngine::TTM_QUARTERS;
        $stock->setQuarterlyOperatingHistory(array_map(
            static fn(array $quarter): array => [
                'revenue' => ((float) $quarter['revenue'] * $revenueShare) + ($acquiredAnnualRevenue / $quarters),
                'ebit' => ((float) $quarter['ebit'] * $retainedShare) + ($acquiredAnnualOperatingIncome / $quarters),
            ],
            array_values($history),
        ));
    }
}
