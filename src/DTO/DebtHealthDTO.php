<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Data Transfer Object representing the overarching debt health and capital structure of a company.
 */
class DebtHealthDTO
{
    public function __construct(
        public readonly float $grossCost,
        public readonly float $effectiveCost,
        public readonly float $cashYield,
        public readonly bool $isNegativeCarry,
        public readonly bool $isSevereNegativeCarry,
        public readonly float $interestCoverage,
        public readonly bool $wantsToPaydownDebt,
        public readonly bool $canIssueDebt,
        public readonly float $debtTolerance,
        public readonly float $wacc,
        public readonly float $costOfEquity,
        public readonly float $leveredBeta,
        public readonly DebtMetricsDTO $rawMetrics,
        public readonly bool $isLiquidityCrisis,
        public readonly bool $isLiquidityWarning,
        public readonly bool $isUnderLeveraged
    ) {}
}
