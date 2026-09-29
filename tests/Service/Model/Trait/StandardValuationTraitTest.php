<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Trait;

use App\Service\Math\FinancialConstants;
use App\Tests\Support\Model\BareStandardModel;
use App\Tests\Support\Model\ConfiguredStandardModel;
use PHPUnit\Framework\TestCase;

/**
 * The valuation a sector model inherits when it declares no rails of its own.
 */
final class StandardValuationTraitTest extends TestCase
{
    private BareStandardModel $model;

    protected function setUp(): void
    {
        $this->model = new BareStandardModel();
    }

    /**
     * The earnings leg is the value-driver P/E value, never below what the revenue floor is worth. The multiple is
     * already the discounted free cash flow a funded growth path leaves, so a quarter's realised cash flow is no
     * second input: there is none to pass, and no step where it changes sign.
     */
    public function testEarningsValueIsTheMultipleNeverBelowTheRevenueFloor(): void
    {
        $this->assertSame(100.0, $this->model->calculateEarningsValue(40.0, 100.0), 'Above the floor, the multiple is the value.');
        $this->assertSame(120.0, $this->model->calculateEarningsValue(120.0, 100.0), 'The revenue floor binds when the multiple falls below it.');
    }

    /**
     * Fair value is a fixed earnings/book blend, and what the firm pays out is not a further input (Miller &
     * Modigliani 1961): a dividend discount value above or below the consensus leaves it where it is, so paying,
     * raising or omitting a dividend cannot move an operating company's value by itself.
     */
    public function testFairValueBlendsEarningsAndBookWhateverTheFirmPaysOut(): void
    {
        $earnings = 100.0;
        $book = 50.0;
        $base = ($earnings * (1.0 - FinancialConstants::FAIR_VALUE_BOOK_WEIGHT)) + ($book * FinancialConstants::FAIR_VALUE_BOOK_WEIGHT);

        $this->assertEqualsWithDelta($base, $this->model->calculateFairValue($earnings, $book, 5.0), 0.0000001);
        foreach ([0.0, 20.0, 120.0] as $dividendSupport) {
            $this->assertEqualsWithDelta($base, $this->model->calculateFairValue($earnings, $book, 5.0, $dividendSupport), 0.0000001);
        }
    }

    /**
     * Structural EPS charges the firm for the debt inside its invested capital and credits it for the cash
     * it must hold, so a levered firm earns strictly less than an unlevered one at the same ROIC.
     */
    public function testStructuralEpsChargesInterestOnImpliedDebtAndCreditsOperatingCash(): void
    {
        $roic = 0.12;
        $riskFree = 0.03;
        $capital = 20.0;

        // Unlevered: book equals invested capital, so no implied debt and no interest drag.
        $unlevered = $this->model->calculateStructuralEps(20.0, $roic, 30.0, $riskFree, $capital);
        $cashCredit = $capital * FinancialConstants::TARGET_OPERATING_CASH_RATIO * $riskFree;
        $this->assertEqualsWithDelta(($capital * $roic) + $cashCredit, $unlevered, 0.0000001, 'With no implied debt, EPS is NOPAT plus the cash yield.');

        // Levered: half the capital is debt, charged at the risk-free rate plus 200bps, after tax.
        $levered = $this->model->calculateStructuralEps(10.0, $roic, 30.0, $riskFree, $capital);
        $expectedDrag = 10.0 * ($riskFree + 0.02) * (1.0 - 0.21);
        $this->assertEqualsWithDelta($unlevered - $expectedDrag, $levered, 0.0000001, 'Leverage must cost the after-tax interest on the implied debt.');
        $this->assertLessThan($unlevered, $levered, 'A levered firm earns less per share at the same ROIC.');

        // The operating leg floors at zero on its own, before the cash yield is added: an interest bill that
        // swamps NOPAT wipes out operating earnings but cannot eat into the yield on required cash.
        $crushed = $this->model->calculateStructuralEps(0.0, 0.0, 0.0, 0.50, 100.0);
        $this->assertEqualsWithDelta(
            100.0 * FinancialConstants::TARGET_OPERATING_CASH_RATIO * 0.50,
            $crushed,
            0.0000001,
            'Interest cannot push structural EPS below the yield on the cash the firm must hold.'
        );
        $this->assertGreaterThan(0.0, $crushed, 'Structural EPS never goes negative.');

        // With no capital and no yield to fall back on, the hard floor is what remains.
        $this->assertSame(0.01, $this->model->calculateStructuralEps(0.0, 0.0, 0.0, 0.0, 0.0), 'Structural EPS floors rather than reaching zero.');

        // Without a real capital figure the approximation is used, and it must still be positive.
        $approximated = $this->model->calculateStructuralEps(20.0, $roic, 30.0, $riskFree);
        $this->assertGreaterThan(0.0, $approximated, 'The revenue-and-book approximation must remain usable.');
        $this->assertNotEqualsWithDelta($unlevered, $approximated, 0.0000001, 'The approximation is a distinct capital base from the real one.');
    }

    /**
     * A non-financial's structural return is its ROIC as measured; only the financial models re-derive it.
     */
    public function testStructuralRoicPassesThroughForNonFinancials(): void
    {
        $this->assertSame(0.14, $this->model->calculateStructuralRoic(0.14, 0.09, 30.0, 20.0, 0.25));
        $this->assertSame(-0.03, $this->model->calculateStructuralRoic(-0.03, 0.09, 30.0, 20.0, 0.25), 'A negative return passes through unclamped at this layer.');
    }
}
