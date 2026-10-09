<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ShadowBankBusinessModel;
use App\Service\Math\FinancialConstants;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ShadowBankBusinessModelTest extends TestCase
{
    private ShadowBankBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new ShadowBankBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testIndependentOriginationAndDirectLendingStreams(): void
    {
        $stock = new Stock();
        $stock->setTicker('RITM');
        $stock->setBeta('1.3');
        $stock->setTotalEquity('1000000000');
        $stock->setWholesaleDebt('8000000000');

        $macro = new MacroStateDTO(
            outputGapEma: 0.01,
            policyRateEma: 0.05,
            yield30yEma: 0.065,
            macroCreditSpreadEma: 0.02
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 400_000_000.0,
            realizedVariableMargin: 0.50,
            fixedCosts: 80_000_000.0,
            baselineVol: 0.12,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('origination_fees', $result->streamRevenue);
        $this->assertArrayHasKey('direct_lending', $result->streamRevenue);

        $this->assertArrayHasKey('origination_fees', $result->streamZ);
        $this->assertArrayHasKey('direct_lending', $result->streamZ);
        $this->assertArrayNotHasKey('credit', $result->streamZ, 'losses are systematic: no idiosyncratic default draw');

        $this->assertGreaterThan(0.0, $result->actualRevenue);
        $this->assertEqualsWithDelta(
            $result->actualRevenue,
            $result->streamRevenue['origination_fees'] + $result->streamRevenue['direct_lending'],
            1.0
        );
    }

    public function testWholesaleLeverageLimitMatchesOperatingCapacity(): void
    {
        $this->assertSame(8.0, $this->model->getWholesaleLeverageLimit());
    }

    /**
     * The book opens priced as its two portfolios: mortgages at the 30-year plus their spread and direct loans at SOFR
     * plus theirs, on the funded book (equity + total debt - treasury with no ledger yet), and the excess cash earns
     * the cash yield beside it.
     */
    public function testTheBookOpensPricedAsItsTwoPortfolios(): void
    {
        $stock = $this->pool();
        $opening = new MacroStateDTO();
        $book = 55e9 + 225e9 - 27.5e9;
        $bookYield = (0.60 * ($opening->yield30yEma + ShadowBankBusinessModel::MORTGAGE_PORTFOLIO_SPREAD))
            + (0.40 * ($opening->policyRateEma + ShadowBankBusinessModel::DIRECT_LENDING_PORTFOLIO_SPREAD));
        $excessCash = 27.5e9 - (55e9 * ShadowBankBusinessModel::TARGET_OPERATING_BUFFER);

        $this->assertEqualsWithDelta(
            ($book * $bookYield) + ($excessCash * $this->model->calculateCashYield($opening)),
            $this->model->calculateInterestIncome($stock, $opening, $this->mathUtility),
            1_000.0
        );
    }

    /**
     * Fees are struck once so that, at the opening macro, fees at the lore margin plus the book's interest less the
     * wholesale funding cost and the through-the-cycle credit charge earn the lender its structural return.
     */
    public function testTheFranchiseEarnsItsStructuralReturnAtTheOpeningMacro(): void
    {
        $stock = $this->pool();
        $opening = new MacroStateDTO();
        $metrics = $this->model->getTargetMetrics($stock, $opening, $this->mathUtility);
        $tax = $opening->corporateTaxRate;
        $feeRevenue = $metrics['invested_capital'] * $metrics['baseline_roic'] / (0.50 * (1.0 - $tax));
        $interestExpense = 225e9 * ((0.30 * 0.050) + (0.70 * ($opening->policyRateEma + 0.019)));
        $provision = $this->model->getThroughTheCycleCreditLossRate($stock) * $metrics['invested_capital'];

        $netIncome = (($feeRevenue * 0.50) + $this->model->calculateInterestIncome($stock, $opening, $this->mathUtility) - $interestExpense - $provision) * (1.0 - $tax);

        $this->assertEqualsWithDelta(0.29, $netIncome / 55e9, 1e-9);
    }

    /**
     * A rise in the policy rate reaches the direct loans at their next reset, inside the quarter, on the share of the
     * book they make up; the mortgages, priced off the long end, and the fees do not move.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testARateRiseLandsInDirectLendingIncomeWithinAQuarterAndFeesDoNotReprice(): void
    {
        $stock = $this->pool();
        $opening = new MacroStateDTO();
        $raised = new MacroStateDTO(policyRateEma: $opening->policyRateEma + 0.01);
        $mathMock = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generatePersistentZ'])->getMock();
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $stock->setEarningsMomentumZ($this->model->computeActualFinancials($stock, 1_000_000_000.0, 0.50, 200_000_000.0, 0.0, $opening, $mathMock)->streamZ);
        $carried = $stock->getEarningsMomentumZ();
        $before = $this->model->resolveInterestYield($stock, $opening);
        $base = $this->model->computeActualFinancials($stock, 1_000_000_000.0, 0.50, 200_000_000.0, 0.0, $opening, $mathMock);
        $stock->setEarningsMomentumZ($carried);
        $after = $this->model->computeActualFinancials($stock, 1_000_000_000.0, 0.50, 200_000_000.0, 0.0, $raised, $mathMock);
        $stock->setEarningsMomentumZ($after->streamZ);

        $directLendingShare = (1.0 - FinancialConstants::SECURITIES_SHARE_OF_EARNING_ASSETS) * 0.40;
        $this->assertEqualsWithDelta($directLendingShare * 0.01, $this->model->resolveInterestYield($stock, $raised) - $before, 1e-9);
        $this->assertEqualsWithDelta($base->streamRevenue['origination_fees'], $after->streamRevenue['origination_fees'], 1e-3);
        $this->assertEqualsWithDelta($base->streamRevenue['direct_lending'], $after->streamRevenue['direct_lending'], 1e-3, 'deal fees do not reprice');
        $this->assertEqualsWithDelta(
            $this->model->getTargetMetrics($stock, $opening, $this->mathUtility)['baseline_roic'],
            $this->model->getTargetMetrics($stock, $raised, $this->mathUtility)['baseline_roic'],
            1e-12,
            'the fee target is not re-solved to hold the return'
        );
    }

    /** Brine Pool Capital's opening balance sheet and lore. */
    private function pool(): Stock
    {
        $stock = (new Stock())->setTicker('POOL');
        $stock->setTotalEquity('55000000000');
        $stock->setWholesaleDebt('225000000000');
        $stock->setCustomerDeposits('0');
        $stock->setCorporateTreasury('27500000000');
        $stock->setBaselineRoe('0.29');
        $stock->setOperatingMargin('0.50');
        $stock->setFloatingDebtRatio('0.70');
        $stock->setHistoricalFixedRate('0.050');
        $stock->setCreditSpread('0.0190');
        $stock->setBeta('1.6');

        return $stock;
    }

    /**
     * The mortgage and direct-lending legs are struck on the funded book, so the split between term notes
     * and drawn revolver cannot change portfolio income. Interest expense runs on getTotalDebt(), and a leg
     * priced off getWholesaleDebt() left drawn revolver paying a coupon against no asset.
     */
    public function testPortfolioIncomeDependsOnTotalFundingNotItsComposition(): void
    {
        $macro = new MacroStateDTO(
            policyRateEma: 0.04,
            yield30yEma: 0.055
        );

        $allTermNotes = new Stock();
        $allTermNotes->setTicker('POOL');
        $allTermNotes->setWholesaleDebt('400000000000.0');
        $allTermNotes->setCorporateTreasury('40000000000.0');

        $partlyOnTheRevolver = new Stock();
        $partlyOnTheRevolver->setTicker('POOL');
        $partlyOnTheRevolver->setWholesaleDebt('250000000000.0');
        $partlyOnTheRevolver->setRevolverDrawn('150000000000.0');
        $partlyOnTheRevolver->setCorporateTreasury('40000000000.0');

        $this->assertSame(
            (float) $allTermNotes->getTotalDebt(),
            (float) $partlyOnTheRevolver->getTotalDebt(),
            'fixture guard: both lenders must carry the same total funding'
        );

        $this->assertEqualsWithDelta(
            $this->model->calculateInterestIncome($allTermNotes, $macro, $this->mathUtility),
            $this->model->calculateInterestIncome($partlyOnTheRevolver, $macro, $this->mathUtility),
            1_000_000.0
        );
    }

    /**
     * The mortgage book defaults with households: a surge in the retail default rate is charged off (the allowance
     * roll-forward replaces it through EBIT), not overlaid on the operating margin.
     */
    public function testHouseholdDefaultSurgeIsChargedOffOnTheMortgageBook(): void
    {
        $stock = new Stock();
        $stock->setTicker('RITM');
        $stock->setBeta('1.0');

        [$base, $baseRate] = $this->chargeOffs($stock, new MacroStateDTO(retailDefaultRateEma: 0.025));
        [$distress, $distressRate] = $this->chargeOffs($stock, new MacroStateDTO(retailDefaultRateEma: 0.050));

        $this->assertGreaterThan(1.5 * $baseRate, $distressRate);
        $this->assertEqualsWithDelta($base->clampedMargin, $distress->clampedMargin, 1e-12, 'credit losses do not touch the operating margin');
    }

    /** The loans are its two portfolios at the bank segment rates: 60% mortgages at 0.43% and 40% direct loans at 0.73%, on the loan share of the book. */
    public function testThroughTheCycleLossIsTheMortgageAndDirectLendingBlend(): void
    {
        $pool = (new Stock())->setTicker('POOL');

        $this->assertEqualsWithDelta(
            (1.0 - FinancialConstants::SECURITIES_SHARE_OF_EARNING_ASSETS) * (0.60 * ShadowBankBusinessModel::RESIDENTIAL_CHARGE_OFF_RATE + 0.40 * ShadowBankBusinessModel::BUSINESS_CHARGE_OFF_RATE),
            $this->model->getThroughTheCycleCreditLossRate($pool),
            1e-12
        );
        $this->assertEqualsWithDelta($this->model->getThroughTheCycleCreditLossRate($pool), $this->model->getThroughTheCycleCreditLossRate(), 1e-12);
    }

    /**
     * Annualized charge-offs over the book for one quarter at this macro state, with the collateral reference at par.
     *
     * @return array{0: \App\DTO\ActualFinancialsDTO, 1: float}
     */
    private function chargeOffs(Stock $stock, MacroStateDTO $macro): array
    {
        $stock->setEarningsMomentumZ([ShadowBankBusinessModel::STATE_RESIDENTIAL_ORIGINATION_PRICE => 100.0]);
        $result = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $macro, $this->mathUtility);

        return [$result, $result->netChargeOffs * 4.0 / $this->model->resolveEarningAssets($stock)];
    }

    public function testResidentialPropertyIndexDrivesMortgageOriginationAndProvisions(): void
    {
        $stock = new Stock();
        $stock->setTicker('SHOR');
        $stock->setBeta('1.0');

        $depressedMacro = new MacroStateDTO(residentialPropertyIndexEma: 75.0);
        $boomMacro = new MacroStateDTO(residentialPropertyIndexEma: 130.0);

        [$depressedResult, $depressedRate] = $this->chargeOffs($stock, $depressedMacro);
        [$boomResult, $boomRate] = $this->chargeOffs($stock, $boomMacro);

        // High residential property values stimulate mortgage origination
        $this->assertGreaterThan(
            $depressedResult->streamRevenue['origination_fees'],
            $boomResult->streamRevenue['origination_fees'],
            'Residential property index growth should expand mortgage origination fee volume.'
        );

        $this->assertGreaterThan($boomRate, $depressedRate, 'Homes below the price they were lent against lose more on each mortgage default (Frye 2000).');
    }

    /**
     * An interbank freeze raises the repo funding cost (DebtEngine) but not what the book earns: direct loans reset
     * over SOFR, a secured rate. Nor is it a cost-ratio term; rate risk is the repricing gap.
     */
    public function testAnInterbankSpikeDoesNotLiftTheBookOrTheCostRatio(): void
    {
        $stock = $this->pool();
        $calmMacro = new MacroStateDTO(interbankLiquiditySpreadEma: 0.0010);
        $tedSpikeMacro = new MacroStateDTO(interbankLiquiditySpreadEma: 0.0200);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $calmResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $calmMacro, $mathMock);
        $spikeResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $tedSpikeMacro, $mathMock);

        $this->assertEqualsWithDelta($calmResult->clampedMargin, $spikeResult->clampedMargin, 1e-12);
        $this->assertEqualsWithDelta(
            $this->model->calculateInterestIncome($stock, $calmMacro, $this->mathUtility),
            $this->model->calculateInterestIncome($stock, $tedSpikeMacro, $this->mathUtility),
            1.0
        );
    }

    public function testSloosCreditTighteningExpandsDirectLendingOrigination(): void
    {
        $stock = new Stock();
        $stock->setTicker('BXSL');
        $stock->setBeta('1.0');

        $neutralMacro = new MacroStateDTO(sloosTighteningIndexEma: 0.0, policyRateEma: 0.05);
        $tighteningMacro = new MacroStateDTO(sloosTighteningIndexEma: 0.40, policyRateEma: 0.05);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $neutralResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $neutralMacro, $mathMock);
        $tighteningResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $tighteningMacro, $mathMock);

        $this->assertGreaterThan(
            $neutralResult->streamRevenue['direct_lending'],
            $tighteningResult->streamRevenue['direct_lending'],
            'Commercial bank credit tightening (SLOOS) drives corporate borrowers to private credit, expanding direct lending volume.'
        );
    }

    public function testCorporateDefaultAndRecessionRiskIncreaseLossProvisions(): void
    {
        $stock = new Stock();
        $stock->setTicker('BXSL');
        $stock->setBeta('1.0');

        $benignMacro = new MacroStateDTO(
            corporateDefaultRateEma: 0.018,
            recessionProbabilityEma: 0.05
        );

        $stressMacro = new MacroStateDTO(
            corporateDefaultRateEma: 0.080,
            recessionProbabilityEma: 0.65
        );

        [, $benignRate] = $this->chargeOffs($stock, $benignMacro);
        [, $stressRate] = $this->chargeOffs($stock, $stressMacro);

        $this->assertGreaterThan($benignRate, $stressRate, 'Surging corporate defaults are charged off on the direct lending book.');
        $this->assertGreaterThan(
            $this->model->getForwardCreditLossMultiplier($stock, $benignMacro),
            $this->model->getForwardCreditLossMultiplier($stock, $stressMacro),
            'Forward recession risk raises the reserve target.'
        );
    }

    public function testBaselineCreditSpreadDoesNotImposeUnprovokedCeclDrag(): void
    {
        $stock = new Stock();
        $stock->setTicker('BXSL');
        $stock->setBeta('1.0');

        $neutralMacro = new MacroStateDTO(
            macroCreditSpreadEma: 0.020, // Baseline 200 bps
            recessionProbabilityEma: 0.15, // Baseline 15%
            yield30yEma: 0.055,
            policyRateEma: 0.040,
            interbankLiquiditySpreadEma: 0.0010
        );

        $widenedMacro = new MacroStateDTO(
            macroCreditSpreadEma: 0.040, // 400 bps blowout (+200 bps)
            recessionProbabilityEma: 0.15,
            yield30yEma: 0.055,
            policyRateEma: 0.040,
            interbankLiquiditySpreadEma: 0.0010
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $neutralResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $neutralMacro, $mathMock);
        $widenedResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.50, 20_000_000.0, 0.0, $widenedMacro, $mathMock);

        // The forward reserve is a balance, not a flow: a widened spread raises the lifetime loss target the
        // allowance converges to (booked once by the ledger) and leaves the running margin alone.
        $this->assertEqualsWithDelta($neutralResult->clampedMargin, $widenedResult->clampedMargin, 1e-12, 'the forecast reserve is not a running cost');
        $this->assertGreaterThan(
            $this->model->getForwardCreditLossMultiplier($stock, $neutralMacro),
            $this->model->getForwardCreditLossMultiplier($stock, $widenedMacro),
            'Widened credit spread must raise the forward lifetime loss estimate above baseline.'
        );
        $this->assertEqualsWithDelta(
            0.020 * ShadowBankBusinessModel::CECL_RESERVE_SPREAD_SENSITIVITY,
            $this->model->getForwardCreditLossMultiplier($stock, $widenedMacro) - $this->model->getForwardCreditLossMultiplier($stock, $neutralMacro),
            1e-9
        );
    }
}
