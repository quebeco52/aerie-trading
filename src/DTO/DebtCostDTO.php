<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * The cost of debt a firm is evaluated on for WACC and credit purposes.
 */
class DebtCostDTO
{
    public function __construct(
        /** Pre-tax rate on the debt that counts toward cost of capital. */
        public readonly float $grossCostOfDebt,
        /** Quarterly interest attributable to that same debt. */
        public readonly float $totalInterestCost,
    ) {}
}
