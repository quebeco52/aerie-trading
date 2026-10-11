<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Data Transfer Object representing the raw debt metrics and interest expenses of a company.
 */
class DebtMetricsDTO
{
    public function __construct(
        public readonly float $interestExpense,
        public readonly float $blendedRate,
        public readonly float $historicalFixedRate,
        public readonly float $dynamicSpread,
        public readonly float $currentMarketRate,
        public readonly float $wholesaleRate,
        public readonly float $ebit,
        public readonly float $revenue,
        public readonly float $depreciation,
        public readonly float $ebitda,
        /** The issuer's through-the-cycle spread at its current rating: the credit indices at their trend, no volatility premium. */
        public readonly ?float $baselineSpread = null,
        /** The credit index the issuer's class trades off: the IG index for an investment-grade rating, the HY index below it. */
        public readonly ?float $marketIndexSpread = null
    ) {}
}
