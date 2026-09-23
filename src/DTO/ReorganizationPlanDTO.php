<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * A confirmed Chapter 11 plan: the debt the reorganized firm keeps, the claims swapped for its equity, and
 * how that equity is split between the old shareholders and the creditors.
 */
class ReorganizationPlanDTO
{
    public function __construct(
        /** Debt claims at filing, the drawn revolver included. */
        public readonly float $claims,
        /** Debt the reorganized firm can carry and still pass its own lender test. */
        public readonly float $exitDebt,
        /** Claims exchanged for the reorganized equity. */
        public readonly float $convertedClaims,
        /** Share of the reorganized equity the old shareholders keep under absolute priority. */
        public readonly float $oldEquityShare,
        /** Value of the reorganized equity: the firm's assets less the exit debt. */
        public readonly float $reorganizedEquityValue,
        /** Rate the exit notes are issued at. */
        public readonly float $exitRate,
    ) {}
}
