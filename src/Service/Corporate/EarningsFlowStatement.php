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
        public float $goodwillImpairment,
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

        $capex              = max(0.0, (float) ($report['capital_expenditures'] ?? 0));
        $taxPaidRaw         = max(0.0, (float) ($report['tax_paid'] ?? 0));
        $goodwillRaw        = max(0.0, (float) ($report['goodwill_impairment'] ?? 0));
        $dividends          = max(0.0, (float) ($report['dividend_paid'] ?? 0));
        $buybacks           = max(0.0, (float) ($report['stock_buybacks'] ?? 0));

        // The spine is the statement the report actually published — its own EBITDA, EBIT and pre-tax
        // income — and each charge is the DIFFERENCE between two of those lines rather than an independently
        // stored figure. That matters because `operating_costs` holds the cash cost base alone: the
        // inventory written down to net realizable value, the trade receivable allowance and the credit-loss
        // level correction are all struck against EBITDA afterwards and appear in none of it. Rebuilding the
        // waterfall from the cost base therefore drew an EBITDA the firm never earned and walked a different
        // number down to the bottom of the diagram than the one printed beside it. Deriving each charge from
        // the spine puts those impairments back where they belong — inside operating costs, which is where
        // the engine charges them — and guarantees every link conserves by construction.
        // Each line falls back to the one above it less its own charge, so a report written before these
        // columns existed still draws the waterfall it always drew.
        $ebitdaStored = (float) ($report['ebitda'] ?? ($totalRevenue - (float) ($report['operating_costs'] ?? 0)));
        $ebitStored   = (float) ($report['ebit'] ?? ($ebitdaStored - (float) ($report['depreciation'] ?? 0)));
        $preTaxStored = (float) ($report['pre_tax_income'] ?? ($ebitStored - (float) ($report['interest_expense'] ?? 0)));

        // The income statement narrows. Each charge is bounded by what is left to charge it against, so no
        // link ever runs negative — a Sankey cannot draw one.
        $ebitda = max(0.0, min($totalRevenue, $ebitdaStored));
        $operatingCosts = $totalRevenue - $ebitda;

        $depreciation = max(0.0, min($ebitda, $ebitdaStored - $ebitStored));
        $operatingProfit = $ebitda - $depreciation;

        // Interest expense net of whatever interest income joined revenue at the top, which is how the two
        // stored lines already differ.
        $interestExpense = max(0.0, min($operatingProfit, $ebitStored - $preTaxStored));
        $preTaxIncome = $operatingProfit - $interestExpense;

        $taxes = min($preTaxIncome, $taxPaidRaw);
        $afterTax = $preTaxIncome - $taxes;

        // Goodwill written off under the annual impairment test is a real charge below the tax line, and the
        // one a reader most wants to see named rather than folded silently into the bottom line.
        $goodwillImpairment = min($afterTax, $goodwillRaw);
        $netIncome = $afterTax - $goodwillImpairment;

        // Then the cash side widens: earnings plus the non-cash charges added straight back — depreciation,
        // and the goodwill just written off, which never cost the firm a dollar — topped up from outside
        // when the quarter's spending exceeds it.
        $internalCash = max(0.0, $netIncome) + $depreciation + $goodwillImpairment;
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
            goodwillImpairment: $goodwillImpairment,
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
