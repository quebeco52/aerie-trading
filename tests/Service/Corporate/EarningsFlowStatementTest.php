<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Service\Corporate\EarningsFlowStatement;
use PHPUnit\Framework\TestCase;

/**
 * The earnings-flow (Sankey) waterfall.
 *
 * Capital expenditure, dividends and buybacks used to be drawn OUT of net income and each capped by
 * whatever was left of it, which is not how any of the three are funded. The effect was that a firm
 * spending more than it earns — most capital-intensive ones — had its capex silently truncated, and a
 * quarter reporting a loss lost its dividend and buyback bars entirely.
 */
final class EarningsFlowStatementTest extends TestCase
{
    /** @param array<string, float|int|string> $overrides */
    private function report(array $overrides = []): EarningsFlowStatement
    {
        return EarningsFlowStatement::fromReport($overrides + [
            'revenue' => 1000.0,
            'interest_income' => 0.0,
            'operating_costs' => 700.0,
            'depreciation' => 100.0,
            'interest_expense' => 40.0,
            'tax_paid' => 30.0,
            'capital_expenditures' => 120.0,
            'dividend_paid' => 20.0,
            'stock_buybacks' => 10.0,
        ]);
    }

    /** The income statement narrows exactly as the accounts do. */
    public function testIncomeStatementWaterfall(): void
    {
        $flow = $this->report();

        $this->assertSame(1000.0, $flow->totalRevenue);
        $this->assertSame(300.0, $flow->ebitda);
        $this->assertSame(200.0, $flow->operatingProfit);
        $this->assertSame(160.0, $flow->preTaxIncome);
        $this->assertSame(130.0, $flow->netIncome);
    }

    /** Interest income is revenue for a lender, so it belongs at the top of the diagram. */
    public function testInterestIncomeJoinsRevenue(): void
    {
        $this->assertSame(1500.0, $this->report(['interest_income' => 500.0])->totalRevenue);
    }

    /**
     * The regression: capex above earnings is reported in full and funded, not truncated.
     */
    public function testCapexAboveEarningsIsFundedRatherThanTruncated(): void
    {
        $flow = $this->report(['capital_expenditures' => 900.0]);

        $this->assertSame(900.0, $flow->capitalExpenditures, 'The whole outlay must be drawn.');
        $this->assertGreaterThan(0.0, $flow->externalFunding, 'Spending beyond internal cash has to come from somewhere.');
        $this->assertSame(0.0, $flow->retainedCash);
    }

    /** Depreciation is added straight back: it is struck against EBITDA but no money moves. */
    public function testDepreciationIsAddedBackToCash(): void
    {
        $flow = $this->report();

        $this->assertSame($flow->netIncome + $flow->depreciation, $flow->internalCash);
    }

    /** A loss-making quarter still shows what it actually paid out. */
    public function testLossMakingQuarterStillReportsDistributions(): void
    {
        $flow = $this->report(['operating_costs' => 1000.0]);

        $this->assertSame(0.0, $flow->netIncome);
        $this->assertSame(120.0, $flow->capitalExpenditures);
        $this->assertSame(20.0, $flow->dividends);
        $this->assertSame(10.0, $flow->buybacks);
        $this->assertSame(150.0, $flow->externalFunding, 'All of it was funded from outside.');
    }

    /** Conservation: everything the cash node receives, it pays out. A Sankey cannot draw otherwise. */
    public function testCashNodeConserves(): void
    {
        foreach ([[], ['capital_expenditures' => 900.0], ['operating_costs' => 1000.0], ['dividend_paid' => 0.0]] as $overrides) {
            $flow = $this->report($overrides);

            $this->assertEqualsWithDelta(
                $flow->cashGenerated(),
                $flow->capitalExpenditures + $flow->dividends + $flow->buybacks + $flow->retainedCash,
                1e-9,
                'Cash in must equal cash out.'
            );
        }
    }

    /** No link is ever negative, at any input. */
    public function testNoFlowIsNegative(): void
    {
        $flow = $this->report([
            'operating_costs' => 5000.0,
            'depreciation' => 5000.0,
            'interest_expense' => 5000.0,
            'tax_paid' => 5000.0,
        ]);

        foreach ([
            $flow->operatingCosts, $flow->ebitda, $flow->depreciation, $flow->operatingProfit,
            $flow->interestExpense, $flow->preTaxIncome, $flow->taxes, $flow->netIncome,
            $flow->internalCash, $flow->externalFunding, $flow->retainedCash,
        ] as $magnitude) {
            $this->assertGreaterThanOrEqual(0.0, $magnitude);
        }
    }

    /** Surplus cash that was not spent is retained rather than disappearing. */
    public function testUnspentCashIsRetained(): void
    {
        $flow = $this->report(['capital_expenditures' => 0.0, 'dividend_paid' => 0.0, 'stock_buybacks' => 0.0]);

        $this->assertSame(0.0, $flow->externalFunding);
        $this->assertSame($flow->internalCash, $flow->retainedCash);
    }

    /** A report with missing columns degrades to zeros rather than erroring. */
    public function testMissingColumnsDegradeToZero(): void
    {
        $flow = EarningsFlowStatement::fromReport(['revenue' => 100.0]);

        $this->assertSame(100.0, $flow->totalRevenue);
        $this->assertSame(100.0, $flow->netIncome);
        $this->assertSame(100.0, $flow->retainedCash);
    }
}
