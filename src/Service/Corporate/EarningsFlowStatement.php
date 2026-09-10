<?php

declare(strict_types=1);

namespace App\Service\Corporate;

/**
 * One quarter's reported statement reduced to the magnitudes an earnings-flow (Sankey) diagram draws.
 *
 * A Sankey has to conserve — everything entering a node leaves it — which is why this is a value object
 * rather than arithmetic inline in a controller: the income statement narrows from revenue to net income,
 * and then the CASH the firm has to spend widens again, because capital expenditure, dividends and
 * buybacks are funded by earnings plus the non-cash charges added back to them, and by outside money when
 * that is not enough.
 *
 * Treating those three as an appropriation of reported profit — draining each from net income and capping
 * it by whatever was left — truncated the capex of every firm that outspends its earnings, which is most
 * capital-intensive ones, and blanked the dividend and buyback bars entirely in any quarter that reported
 * a loss, leaving a negative retained figure that made the diagram fail to balance in exactly the quarters
 * a reader most wants to look at.
 */
final readonly class EarningsFlowStatement
{
    private function __construct(
        public float $totalRevenue,
        public float $operatingCosts,
        public float $ebitda,
        public float $depreciation,
        public float $operatingProfit,
        public float $interestExpense,
        public float $preTaxIncome,
        public float $taxes,
        public float $netIncome,
        public float $internalCash,
        public float $externalFunding,
        public float $capitalExpenditures,
        public float $dividends,
        public float $buybacks,
        public float $retainedCash,
    ) {}

    /**
     * @param array<string, mixed> $report One corporate_report row.
     */
    public static function fromReport(array $report): self
    {
        $totalRevenue = (float) ($report['revenue'] ?? 0) + (float) ($report['interest_income'] ?? 0);

        $operatingCostsRaw  = max(0.0, (float) ($report['operating_costs'] ?? 0));
        $depreciationRaw    = max(0.0, (float) ($report['depreciation'] ?? 0));
        $capex              = max(0.0, (float) ($report['capital_expenditures'] ?? 0));
        $interestExpenseRaw = max(0.0, (float) ($report['interest_expense'] ?? 0));
        $taxPaidRaw         = max(0.0, (float) ($report['tax_paid'] ?? 0));
        $dividends          = max(0.0, (float) ($report['dividend_paid'] ?? 0));
        $buybacks           = max(0.0, (float) ($report['stock_buybacks'] ?? 0));

        // The income statement narrows. Each charge is bounded by what is left to charge it against, so no
        // link ever runs negative — a Sankey cannot draw one.
        $operatingCosts = min($totalRevenue, $operatingCostsRaw);
        $ebitda = $totalRevenue - $operatingCosts;

        $depreciation = min($ebitda, $depreciationRaw);
        $operatingProfit = $ebitda - $depreciation;

        $interestExpense = min($operatingProfit, $interestExpenseRaw);
        $preTaxIncome = $operatingProfit - $interestExpense;

        $taxes = min($preTaxIncome, $taxPaidRaw);
        $netIncome = $preTaxIncome - $taxes;

        // Then the cash side widens: earnings plus the depreciation added straight back, topped up from
        // outside when the quarter's spending exceeds it.
        $internalCash = max(0.0, $netIncome) + $depreciation;
        $uses = $capex + $dividends + $buybacks;
        $externalFunding = max(0.0, $uses - $internalCash);
        $retainedCash = max(0.0, ($internalCash + $externalFunding) - $uses);

        return new self(
            totalRevenue: $totalRevenue,
            operatingCosts: $operatingCosts,
            ebitda: $ebitda,
            depreciation: $depreciation,
            operatingProfit: $operatingProfit,
            interestExpense: $interestExpense,
            preTaxIncome: $preTaxIncome,
            taxes: $taxes,
            netIncome: $netIncome,
            internalCash: $internalCash,
            externalFunding: $externalFunding,
            capitalExpenditures: $capex,
            dividends: $dividends,
            buybacks: $buybacks,
            retainedCash: $retainedCash,
        );
    }

    /** Total cash the diagram has to allocate: what the quarter produced plus what was raised. */
    public function cashGenerated(): float
    {
        return $this->internalCash + $this->externalFunding;
    }
}
