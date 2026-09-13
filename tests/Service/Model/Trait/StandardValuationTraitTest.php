<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Trait;

use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Tests\Support\Model\BareStandardModel;
use App\Tests\Support\Model\ConfiguredStandardModel;
use PHPUnit\Framework\TestCase;

/**
 * The valuation a sector model inherits when it declares none of the DCF rails.
 *
 * All three constants sit behind `defined()` gates, so a model that leaves them out falls back to
 * FinancialConstants and hard-coded rails that no sector test reaches.
 */
final class StandardValuationTraitTest extends TestCase
{
    private BareStandardModel $model;
    private MathUtility $math;

    protected function setUp(): void
    {
        $this->model = new BareStandardModel();
        $this->math = new MathUtility();
    }

    /**
     * With positive free cash flow the earnings value is the midpoint of the multiple and a capped DCF.
     */
    public function testPositiveFreeCashFlowBlendsTheMultipleWithACappedDcf(): void
    {
        $peFairValue = 100.0;
        $wacc = 0.09;
        $fcfPerShare = 4.0;

        $multiplier = $this->math->calculateDcfMultiplier($wacc, FinancialConstants::DEFAULT_PERPETUAL_GROWTH_RATE);
        $expectedDcf = min(max(0.01, $fcfPerShare * $multiplier), $peFairValue * 1.50);

        $this->assertEqualsWithDelta(
            ($peFairValue + $expectedDcf) / 2.0,
            $this->model->calculateEarningsValue(40.0, $peFairValue, $fcfPerShare, $wacc, $this->math),
            0.0000001,
            'The earnings value is the midpoint of the multiple and the capped DCF.'
        );
    }

    /**
     * The DCF cap is what stops a low discount rate from producing an unbounded perpetuity.
     */
    public function testTheDcfIsCappedAtAMultipleOfThePeFairValue(): void
    {
        $peFairValue = 50.0;
        $cappedDcf = $peFairValue * 1.50;

        // A very low WACC against high FCF would otherwise value the perpetuity at many times the multiple.
        $value = $this->model->calculateEarningsValue(10.0, $peFairValue, 30.0, 0.001, $this->math);

        $this->assertEqualsWithDelta(($peFairValue + $cappedDcf) / 2.0, $value, 0.0000001, 'A collapsing discount rate must hit the cap, not run away.');
        $this->assertLessThanOrEqual($peFairValue * 1.50, $value, 'The blended value can never exceed the cap itself.');

        // Raising the cap through the class constant must raise the answer; the gate is live.
        $configured = new ConfiguredStandardModel();
        $this->assertGreaterThan($value, $configured->calculateEarningsValue(10.0, $peFairValue, 30.0, 0.001, $this->math), 'A sector raising the cap must be able to carry a richer DCF.');
    }

    /**
     * A burning firm is discounted on the multiple but never below the revenue floor: the floor is what a
     * buyer pays for the top line regardless of this quarter's cash burn.
     */
    public function testNegativeFreeCashFlowDiscountsTheMultipleButNeverTheRevenueFloor(): void
    {
        $peFairValue = 80.0;

        // Discounted multiple clears the floor: the discount is the binding number.
        $this->assertEqualsWithDelta(
            $peFairValue * 0.75,
            $this->model->calculateEarningsValue(10.0, $peFairValue, -5.0, 0.09, $this->math),
            0.0000001,
            'A burning firm is marked down to the default discount on its multiple.'
        );

        // Floor clears the discounted multiple: the floor is the binding number.
        $this->assertSame(
            70.0,
            $this->model->calculateEarningsValue(70.0, $peFairValue, -5.0, 0.09, $this->math),
            'The revenue floor must survive a cash burn.'
        );

        // Unknown FCF is not the same as negative FCF: it carries no discount at all.
        $this->assertSame(
            $peFairValue,
            $this->model->calculateEarningsValue(10.0, $peFairValue, null, 0.09, $this->math),
            'An unknown cash flow must not be punished as if it were a burn.'
        );

        // Zero is a burn for this purpose, since the DCF branch requires strictly positive cash flow.
        $this->assertEqualsWithDelta($peFairValue * 0.75, $this->model->calculateEarningsValue(10.0, $peFairValue, 0.0, 0.09, $this->math), 0.0000001);

        // A sector declaring a harsher discount must actually get it: the gate is read, not ignored.
        $this->assertEqualsWithDelta(
            $peFairValue * 0.40,
            (new ConfiguredStandardModel())->calculateEarningsValue(10.0, $peFairValue, -5.0, 0.09, $this->math),
            0.0000001,
            'A declared burn discount must override the fallback.'
        );
    }

    /**
     * Fair value is a fixed earnings/book blend, with the dividend model displacing part of it when present.
     */
    public function testFairValueBlendsEarningsAndBookAndYieldsToDividendSupport(): void
    {
        $earnings = 100.0;
        $book = 50.0;
        $base = ($earnings * FinancialConstants::FAIR_VALUE_EARNINGS_WEIGHT) + ($book * FinancialConstants::FAIR_VALUE_BOOK_WEIGHT);

        $this->assertEqualsWithDelta($base, $this->model->calculateFairValue($earnings, $book, 5.0), 0.0000001, 'Without a dividend the blend is earnings and book only.');
        $this->assertEqualsWithDelta(1.0, FinancialConstants::FAIR_VALUE_EARNINGS_WEIGHT + FinancialConstants::FAIR_VALUE_BOOK_WEIGHT, 0.0000001, 'The two weights must partition the blend.');

        $withDividend = $this->model->calculateFairValue($earnings, $book, 5.0, 120.0);
        $this->assertEqualsWithDelta(
            ($base * (1.0 - FinancialConstants::FAIR_VALUE_DDM_WEIGHT)) + (120.0 * FinancialConstants::FAIR_VALUE_DDM_WEIGHT),
            $withDividend,
            0.0000001,
            'Dividend support displaces its weight from the base consensus.'
        );
        $this->assertGreaterThan($base, $withDividend, 'Dividend support above the consensus must lift fair value.');
        $this->assertSame($base, $this->model->calculateFairValue($earnings, $book, 5.0, 0.0), 'A zero dividend support is absent, not a zero valuation input.');
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
