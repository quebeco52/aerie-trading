<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Trait;

use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Tests\Support\Model\BareStandardModel;
use PHPUnit\Framework\TestCase;

/**
 * The leverage policy a sector model inherits when it declares none of the three recapitalization rails.
 *
 * Every shipped model declares at least some of WACC_ARBITRAGE_THRESHOLD, MIN_RECAP_ICR_FLOOR and
 * UNDERLEVERAGED_DEBT_RATIO, so the FinancialConstants fallbacks behind those `defined()` gates are the
 * behaviour a newly written sector model gets and the behaviour nothing currently covers.
 */
final class StandardDebtPhysicsTraitTest extends TestCase
{
    private BareStandardModel $model;

    protected function setUp(): void
    {
        $this->model = new BareStandardModel();
    }

    private function health(float $debtTolerance, float $wacc, float $interestExpense, float $ebit): DebtHealthDTO
    {
        $metrics = new DebtMetricsDTO(
            interestExpense: $interestExpense,
            blendedRate: 0.05,
            historicalFixedRate: 0.04,
            dynamicSpread: 0.02,
            currentMarketRate: 0.06,
            wholesaleRate: 0.05,
            ebit: $ebit,
            revenue: $ebit * 5.0,
            depreciation: 0.0,
            ebitda: $ebit
        );

        return new DebtHealthDTO(
            grossCost: 0.05, effectiveCost: 0.04, cashYield: 0.03,
            isNegativeCarry: false, isSevereNegativeCarry: false,
            interestCoverage: $interestExpense > 0 ? $ebit / $interestExpense : 999.0,
            wantsToPaydownDebt: false, canIssueDebt: true,
            debtTolerance: $debtTolerance, wacc: $wacc, costOfEquity: 0.10,
            leveredBeta: 1.0, rawMetrics: $metrics,
            isLiquidityCrisis: false, isLiquidityWarning: false, isUnderLeveraged: false
        );
    }

    /**
     * Interest coverage has no natural value when there is no interest to cover, so the sentinel must carry
     * the sign of EBIT: a profitable unlevered firm is maximally safe, a loss-making one maximally unsafe.
     */
    public function testInterestCoverageSentinelsCarryTheSignOfEbitWhenThereIsNoInterest(): void
    {
        $this->assertSame(4.0, $this->model->getInterestCoverage(200.0, 50.0), 'Coverage is EBIT over interest.');
        $this->assertSame(999.0, $this->model->getInterestCoverage(200.0, 0.0), 'A profitable debt-free firm is maximally covered.');
        $this->assertSame(-999.0, $this->model->getInterestCoverage(-200.0, 0.0), 'A loss-making debt-free firm must not read as safe.');
        $this->assertSame(-999.0, $this->model->getInterestCoverage(0.0, 0.0), 'Zero EBIT and zero interest is not coverage.');
        $this->assertLessThan(0.0, $this->model->getInterestCoverage(-200.0, 50.0), 'A loss under real interest gives negative coverage.');
    }

    /**
     * Trade-off theory: all three rails must clear before a firm will lever up, so any one of them blocks.
     */
    public function testUnderLeveragedRequiresArbitrageCoverageAndHeadroomTogether(): void
    {
        $minIcr = $this->model->getMinIcr();
        $icrFloor = max(FinancialConstants::MIN_ABSOLUTE_ICR_BUFFER, $minIcr * FinancialConstants::REQUIRED_ICR_SAFETY_MULT);
        $tolerance = 1.0;
        $roomyRatio = 0.5 * FinancialConstants::CORPORATE_UNDERLEVERAGED_RATIO;

        // All three rails clear: equity dear, coverage ample, leverage well inside tolerance.
        $this->assertTrue(
            $this->model->isUnderLeveraged($roomyRatio, $tolerance, $icrFloor + 1.0, $minIcr, 0.12, 0.04),
            'Cheap debt, ample coverage and spare capacity together mean the firm is under-levered.'
        );

        // Rail one: the equity/debt spread must exceed the arbitrage buffer, and the boundary is exclusive.
        $this->assertFalse(
            $this->model->isUnderLeveraged($roomyRatio, $tolerance, $icrFloor + 1.0, $minIcr, 0.04 + FinancialConstants::WACC_ARBITRAGE_BUFFER, 0.04),
            'Exactly at the arbitrage buffer there is no gain to capture.'
        );
        $this->assertTrue(
            $this->model->isUnderLeveraged($roomyRatio, $tolerance, $icrFloor + 1.0, $minIcr, 0.04 + FinancialConstants::WACC_ARBITRAGE_BUFFER + 0.001, 0.04),
            'A hair above the buffer does qualify.'
        );

        // Rail two: coverage below the safety floor blocks regardless of how attractive the arbitrage is.
        $this->assertFalse(
            $this->model->isUnderLeveraged($roomyRatio, $tolerance, $icrFloor - 0.01, $minIcr, 0.20, 0.03),
            'Thin coverage must block a recapitalization however cheap the debt.'
        );

        // Rail three: leverage already at tolerance leaves no headroom.
        $this->assertFalse(
            $this->model->isUnderLeveraged($tolerance * FinancialConstants::CORPORATE_UNDERLEVERAGED_RATIO, $tolerance, $icrFloor + 1.0, $minIcr, 0.20, 0.03),
            'At the leverage rail the firm is not under-levered.'
        );
    }

    /**
     * Debt appetite rises with the spread multiplier on both axes, and stays a probability.
     */
    public function testDebtExpansionAppetiteRisesWithTheSpreadMultiplier(): void
    {
        $calm = $this->model->getDebtExpansionAggressiveness(0.0);
        $this->assertEqualsWithDelta(0.40, $calm->probability, 0.0000001, 'The base issuance probability is the intercept.');
        $this->assertEqualsWithDelta(0.05, $calm->aggressiveness, 0.0000001, 'The base aggressiveness is the intercept.');

        $rich = $this->model->getDebtExpansionAggressiveness(1.0);
        $this->assertGreaterThan($calm->probability, $rich->probability, 'A wider spread multiplier must raise the odds of issuing.');
        $this->assertGreaterThan($calm->aggressiveness, $rich->aggressiveness, 'And the size of the issue.');
        $this->assertLessThanOrEqual(1.0, $rich->probability, 'The probability must stay a probability at the top of the range.');
    }

    /**
     * Expansion capacity is the binding minimum of a balance-sheet limit and an income-statement limit,
     * so a firm with room on one and none on the other cannot borrow.
     */
    public function testDebtExpansionCapacityTakesTheBindingOfBalanceSheetAndCoverageLimits(): void
    {
        $equity = 1_000_000_000.0;
        $rate = 0.05;

        // Balance sheet binds: coverage is abundant but equity already carries its tolerated debt.
        $balanceBound = $this->model->calculateDebtExpansionCapacity(
            $equity, 900_000_000.0, 900_000_000.0, $this->health(1.0, 0.08, 1_000_000.0, 5_000_000_000.0), $rate, 5_000_000_000.0, 0.0
        );
        $this->assertEqualsWithDelta(100_000_000.0, $balanceBound, 1.0, 'The equity tolerance must cap the raise.');

        // Coverage binds: the balance sheet is empty but EBIT cannot service much more interest.
        $coverageBound = $this->model->calculateDebtExpansionCapacity(
            $equity, 0.0, 0.0, $this->health(1.0, 0.08, 0.0, 10_000_000.0), $rate, 10_000_000.0, 0.0
        );
        $expectedInterestRoom = 10_000_000.0 / ($this->model->getBuybackMinIcr() + 0.5);
        $this->assertEqualsWithDelta($expectedInterestRoom / $rate, $coverageBound, 1.0, 'Coverage must cap the raise when the balance sheet does not.');
        $this->assertLessThan($equity, $coverageBound, 'The coverage limit is the binding one here.');

        // Neither: a firm already past its tolerance has no capacity at all, never negative capacity.
        $this->assertSame(
            0.0,
            $this->model->calculateDebtExpansionCapacity($equity, 2_000_000_000.0, 2_000_000_000.0, $this->health(1.0, 0.08, 1_000_000.0, 5_000_000_000.0), $rate, 5_000_000_000.0, 0.0),
            'An over-levered firm has zero capacity, not negative capacity.'
        );
    }

    /**
     * Net debt nets cash against debt but stops at zero: a cash-rich firm is unlevered, not negatively levered.
     */
    public function testNetDebtCapitalFloorsAtZero(): void
    {
        $this->assertSame(400.0, $this->model->getNetDebtCapital(1000.0, 1000.0, 600.0));
        $this->assertSame(0.0, $this->model->getNetDebtCapital(1000.0, 1000.0, 1000.0));
        $this->assertSame(0.0, $this->model->getNetDebtCapital(1000.0, 1000.0, 5000.0), 'Surplus cash cannot make debt capital negative.');
    }

    /**
     * The standard model dampens the Hamada relevering to a quarter, so a corporate's beta responds to
     * leverage far less violently than the textbook identity implies.
     */
    public function testLeveredBetaAppliesTheStandardDampening(): void
    {
        $math = new MathUtility();
        $levered = $this->model->calculateLeveredBeta(1.0, 0.21, 2.0, $math);

        $this->assertEqualsWithDelta($math->calculateLeveredBeta(1.0, 0.21, 2.0, 0.25), $levered, 0.0000001, 'The trait must pass its dampening through.');
        $this->assertLessThan($math->calculateLeveredBeta(1.0, 0.21, 2.0), $levered, 'The dampened beta must sit below the undampened Hamada value.');
        $this->assertGreaterThan(1.0, $levered, 'Leverage still raises beta above the unlevered base.');
        $this->assertSame(1.0, $this->model->calculateLeveredBeta(1.0, 0.21, 0.0, $math), 'With no debt the levered beta is the unlevered beta.');
    }

    /**
     * The distress and capital-policy thresholds a non-financial inherits. These are read by the bankruptcy
     * and capital allocation engines, so their ordering is a solvency invariant rather than a preference.
     */
    public function testInheritedDistressThresholdsAreInternallyOrdered(): void
    {
        $this->assertSame(2.00, $this->model->getMinIcr());
        $this->assertSame(1.50, $this->model->getDividendCrisisIcr());
        $this->assertSame(2.00, $this->model->getBuybackMinIcr());

        $this->assertLessThan(
            $this->model->getMinIcr(),
            $this->model->getDividendCrisisIcr(),
            'A firm must cut the dividend before it breaches its own minimum coverage, not after.'
        );
        $this->assertGreaterThanOrEqual(
            $this->model->getDividendCrisisIcr(),
            $this->model->getBuybackMinIcr(),
            'Buybacks must stop no later than dividends do.'
        );

        // A non-financial fails on book equity alone, with no regulatory capital layer above zero.
        $this->assertSame(0.0, $this->model->getBankruptEquityThreshold());
        $this->assertSame(0.0, $this->model->getDistressEquityThreshold());
        $this->assertSame(0.0, $this->model->getWarningEquityThreshold());

        $this->assertSame(0.40, $this->model->getLossGivenDefault(), 'Senior unsecured recovery is the standard 60%.');
        $this->assertSame(0.30, $this->model->getMaxFloatingDebtRatio(), 'A corporate terms out most of its debt.');
        $this->assertSame(FinancialConstants::DEFAULT_QUARTERLY_DEBT_ROLLOVER, $this->model->getDebtMaturityRolloverRate());
        $this->assertFalse($this->model->requiresAlternativeZScore(), 'Altman Z applies to a normal balance sheet.');
        $this->assertFalse($this->model->appliesDistressPremiumToCostOfEquity());
        $this->assertTrue($this->model->supportsUnderleveragedDebtExpansion());
        $this->assertTrue($this->model->shouldForceDeleveragingOnJunkOrHoarding());
    }

    /**
     * A corporate has no deposit franchise, so capacity is flat and its evaluation bases are the plain totals.
     */
    public function testCorporateCapacityAndEvaluationBasesAreUnadjusted(): void
    {
        $this->assertSame(1.0, $this->model->calculateCapacityModifier(1000.0, 500.0, 2.0), 'No deposit funding means no capacity adjustment.');
        $this->assertSame(1.0, $this->model->calculateCapacityModifier(0.0, 1.0, 99.0, 5000.0), 'Core liabilities are a bank concept here.');
        $this->assertSame(750.0, $this->model->getUnfundedExpansionCapacity(750.0, 900.0), 'Excess cash does not extend a corporate borrowing base.');
        $this->assertSame(600.0, $this->model->getExpansionCapacityBasis(400.0, 200.0, 600.0), 'Capacity is measured on invested capital.');
        $this->assertSame(900.0, $this->model->getDeleveragingEvaluationDebt(900.0, 300.0), 'Deleveraging is judged on total debt, not the wholesale slice.');
        $this->assertSame(1.4, $this->model->getDeleveragingEvaluationLimit(1.4), 'The macro tolerance passes through unmodified.');
        $this->assertSame(1.0, $this->model->getWholesaleLeverageLimit());
    }

    public function testHurdleRateFallsBackToACostOfCapitalWhenHealthCarriesNone(): void
    {
        $this->assertSame(0.11, $this->model->getHurdleRate($this->health(1.0, 0.11, 1.0, 1.0)), 'The hurdle is the live WACC.');
    }

    /**
     * Cost of debt is the realized rate on the book, falling back to the market rate for a debt-free firm
     * so a division by zero never reaches the valuation.
     */
    public function testDebtCostMetricsUseRealizedRateAndFallBackToMarketWhenDebtFree(): void
    {
        $health = $this->health(1.0, 0.08, 50.0, 500.0);

        $levered = $this->model->getDebtCostMetrics($health->rawMetrics, 1000.0, 1000.0, 50.0);
        $this->assertEqualsWithDelta(0.05, $levered->grossCostOfDebt, 0.0000001, 'The realized cost is interest over debt.');
        $this->assertSame(50.0, $levered->totalInterestCost);

        $unlevered = $this->model->getDebtCostMetrics($health->rawMetrics, 0.0, 0.0, 0.0);
        $this->assertSame($health->rawMetrics->currentMarketRate, $unlevered->grossCostOfDebt, 'A debt-free firm is marked at the market rate.');
    }
}
