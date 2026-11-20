<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Data Transfer Object representing the overarching debt health and capital structure of a company.
 */
class DebtHealthDTO
{
    /**
     * The cost of equity at the through-the-cycle premium: what fair value and the operating return state are struck
     * at, since the market factor carries the premium's cycle into prices itself. costOfEquity is the hurdle at the
     * premium the market's price implies.
     */
    public readonly float $valuationCostOfEquity;
    /** The WACC on valuationCostOfEquity; wacc is the hurdle at the market's premium. */
    public readonly float $valuationWacc;

    public function __construct(
        public readonly float $grossCost,
        public readonly float $effectiveCost,
        public readonly float $cashYield,
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
        public readonly bool $isUnderLeveraged,
        /** False when Net Debt / EBITDA has breached the sector covenant: no discretionary new leverage. */
        public readonly bool $hasLeverageHeadroom = true,
        /** Funded net debt over EBITDA, capped; the cash-flow leverage the covenant is tested on. */
        public readonly float $netDebtToEbitda = 0.0,
        /** The sector's Net Debt / EBITDA covenant level; null where the sector's lenders test none. */
        public readonly ?float $ebitdaCovenantLimit = null,
        /**
         * Book debt, capitalized leases included, over book equity, net of cash above the operating balance for an
         * operating company: the leverage the recapitalization test reads.
         */
        public readonly float $debtToEquity = 0.0,
        /** The debt-to-equity ratio the firm recapitalizes toward. */
        public readonly float $leverageTarget = 0.0,
        /**
         * Whether the primary market will lend to the issuer at any price (open market, rating above the floor,
         * coverage of one): the gate for funding a shortfall, as canIssueDebt is the gate for borrowing by choice.
         */
        public readonly bool $hasMarketAccess = true,
        ?float $valuationCostOfEquity = null,
        ?float $valuationWacc = null,
        /**
         * Equity leverage multiplier DebtEngine levers operating risk with: Hamada (1 + (1 - t) x D/E) blended with
         * net cash V / (V + C), or 1.0 for a financial institution. Identical to leveredBeta / assetBeta.
         */
        public readonly float $equityLeverageMultiplier = 1.0,
    ) {
        $this->valuationCostOfEquity = $valuationCostOfEquity ?? $costOfEquity;
        $this->valuationWacc = $valuationWacc ?? $wacc;
    }
}
