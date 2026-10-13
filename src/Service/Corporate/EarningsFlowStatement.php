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
        public float $provisionsAndCharges,
        public float $ebitda,
        public float $depreciation,
        public float $operatingProfit,
        public float $interestExpense,
        public float $preTaxIncome,
        public float $taxes,
        public float $goodwillImpairment,
        public float $bankLevy,
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
        // Interest earned on cash joins revenue at the top, so the interest line below is the gross expense. The stored
        // EBITDA, EBIT and pre-tax lines leave it out until pre-tax (pre-tax = EBIT - expense + income); netting it from
        // the expense as well counted it twice and pushed the same sum into operating costs.
        $sales = (float) ($report['revenue'] ?? 0);
        $interestIncome = max(0.0, (float) ($report['interest_income'] ?? 0));
        $totalRevenue = $sales + $interestIncome;

        $capex              = max(0.0, (float) ($report['capital_expenditures'] ?? 0));
        $taxPaidRaw         = max(0.0, (float) ($report['tax_paid'] ?? 0));
        $goodwillRaw        = max(0.0, (float) ($report['goodwill_impairment'] ?? 0));
        $bankLevyRaw        = max(0.0, (float) ($report['bank_levy'] ?? 0));
        $dividends          = max(0.0, (float) ($report['dividend_paid'] ?? 0));
        $buybacks           = max(0.0, (float) ($report['stock_buybacks'] ?? 0));

        // Income statement waterfall: reconstruct EBITDA, EBIT, and pre-tax income directly from published report lines.
        $operatingCostsStored = (float) ($report['operating_costs'] ?? 0);
        $ebitdaStored = (float) ($report['ebitda'] ?? ($sales - $operatingCostsStored));
        $ebitStored   = (float) ($report['ebit'] ?? ($ebitdaStored - (float) ($report['depreciation'] ?? 0)));
        $preTaxStored = (float) ($report['pre_tax_income']
            ?? ($ebitStored - (float) ($report['interest_expense'] ?? 0) + $interestIncome));

        // Clamp intermediate income metrics to prevent negative waterfall flows.
        $ebitda = max(0.0, min($totalRevenue, $ebitdaStored + $interestIncome));
        $costsAboveEbitda = $totalRevenue - $ebitda;
        // The charges struck against EBITDA after the cost base is set (credit-loss provisions, inventory and
        // receivable write-downs, severance, plant impairment) are the gap between sales less operating costs and
        // EBITDA; folded into operating costs they hid a bank's credit losses inside its running costs.
        $provisionsAndCharges = max(0.0, min($costsAboveEbitda, $sales - $operatingCostsStored - $ebitdaStored));
        $operatingCosts = $costsAboveEbitda - $provisionsAndCharges;

        $depreciation = max(0.0, min($ebitda, $ebitdaStored - $ebitStored));
        $operatingProfit = $ebitda - $depreciation;

        $interestExpense = max(0.0, min($operatingProfit, $ebitStored + $interestIncome - $preTaxStored));
        $preTaxIncome = $operatingProfit - $interestExpense;

        $taxes = min($preTaxIncome, $taxPaidRaw);
        $afterTax = $preTaxIncome - $taxes;

        // Goodwill written off under the annual impairment test is a real charge below the tax line, and the
        // one a reader most wants to see named rather than folded silently into the bottom line.
        $goodwillImpairment = min($afterTax, $goodwillRaw);
        // A bank levy is charged on the balance sheet below the tax line too, and is cash.
        $bankLevy = min($afterTax - $goodwillImpairment, $bankLevyRaw);
        $netIncome = $afterTax - $goodwillImpairment - $bankLevy;

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
            provisionsAndCharges: $provisionsAndCharges,
            ebitda: $ebitda,
            depreciation: $depreciation,
            operatingProfit: $operatingProfit,
            interestExpense: $interestExpense,
            preTaxIncome: $preTaxIncome,
            taxes: $taxes,
            goodwillImpairment: $goodwillImpairment,
            bankLevy: $bankLevy,
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
