<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\DTO\MacroStateDTO;
use App\Data\ManagementStyle;
use App\Entity\Stock;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Industry\InMemoryIndustryShareStore;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\MergerAndAcquisitionEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class MergerAndAcquisitionEngineTest extends TestCase
{
    private MarketEventPublisher&Stub $marketEventPublisherMock;
    private DebtEngine&Stub $debtEngineMock;
    private MathUtility&Stub $mathUtilityMock;
    private CorporateMetrics&Stub $corporateMetricsMock;
    private MergerAndAcquisitionEngine $engine;

    protected function setUp(): void
    {
        $this->marketEventPublisherMock = $this->createStub(MarketEventPublisher::class);
        $this->debtEngineMock = $this->createStub(DebtEngine::class);
        $this->mathUtilityMock = $this->createStub(MathUtility::class);
        $this->corporateMetricsMock = $this->createStub(CorporateMetrics::class);

        $this->engine = new MergerAndAcquisitionEngine(
            $this->marketEventPublisherMock,
            $this->debtEngineMock,
            $this->mathUtilityMock,
            $this->corporateMetricsMock
        );
    }

    public function testEvaluatePrivateAcquisitionAppliesSynergiesSuccessfully(): void
    {
        $stock = new Stock();
        $stock->setTicker('ACQR');
        $stock->setName('Acquirer Corp');
        $stock->setIndustry('Technology');
        $stock->setPrice('100.00');
        $stock->setSharesOutstanding('100000000');
        $stock->setCorporateTreasury('5000000000');
        $stock->setTotalEquity('10000000000');
        $stock->setWholesaleDebt('1000000000');
        $stock->setTotalRevenue('20000000000');
        $stock->setOperatingMargin('0.25');
        $stock->setRetainedEarnings('5000000000');
        $stock->setEarningsPerShare('1.00');

        $macroState = new MacroStateDTO(
            policyRateEma: 0.03,
            corporateTaxRate: 0.21,
            yield5yEma: 0.035
        );

        $debtMetricsMock = new DebtMetricsDTO(
            interestExpense: 50000000.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.015,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 5000000000.0,
            revenue: 20000000000.0,
            depreciation: 500000000.0,
            ebitda: 5500000000.0
        );

        $debtHealthMock = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.035,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 100.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 1.5,
            wacc: 0.06,
            costOfEquity: 0.08,
            leveredBeta: 1.0,
            rawMetrics: $debtMetricsMock,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        );

        $this->debtEngineMock->method('analyzeDebtHealth')->willReturn($debtHealthMock);
        $this->debtEngineMock->method('analyzeTrailingDebtHealth')->willReturn($debtHealthMock);
        $this->corporateMetricsMock->method('calculateOperatingBase')->willReturn(15000000000.0);
        $this->mathUtilityMock->method('calculateManagementFairValuePE')->willReturn(15.0);
        $this->mathUtilityMock->method('calculateLogNormalSynergy')->willReturn(1.10);
        $this->mathUtilityMock->method('checkProbability')->willReturn(true);
        $this->mathUtilityMock->method('generateUniformBetween')->willReturn(0.80);
        $this->marketEventPublisherMock->method('publish')->willReturn([]);

        $result = $this->engine->evaluatePrivateAcquisition($stock, $macroState, 1.0);

        $this->assertIsArray($result);
        $this->assertGreaterThan(0.0, (float) $stock->getTotalEquity());
        $this->assertGreaterThan(0.0, (float) $stock->getOperatingMargin());
        $this->assertGreaterThan(0.0, (float) $stock->getTotalRevenue());

        // Purchase accounting (ASC 805): the premium over net identifiable assets is booked as goodwill and
        // no day-one equity is conjured beyond any shares issued to fund the deal.
        $this->assertGreaterThan(0.0, (float) $stock->getGoodwill());
        $this->assertLessThanOrEqual((float) $result['spent'], (float) $stock->getGoodwill());
        $this->assertLessThanOrEqual(10_000_000_000.0 + (float) $result['spent'] + 1.0, (float) $stock->getTotalEquity());
    }

    /**
     * Turnover is revenue per dollar of operating capital. The target's identifiable net assets join that
     * base and its revenue joins the numerator; the premium paid over them is goodwill and runs no plant.
     * The acquirer's side is its capital BEFORE the deal was funded, counted once.
     */
    public function testPrivateAcquisitionBlendsTurnoverOverIdentifiableNetAssets(): void
    {
        $stock = new Stock();
        $stock->setTicker('ACQT');
        $stock->setName('Acquirer Turnover Corp');
        $stock->setIndustry('Technology');
        $stock->setPrice('100.00');
        $stock->setSharesOutstanding('100000000');
        $stock->setCorporateTreasury('5000000000');
        $stock->setTotalEquity('10000000000');
        $stock->setWholesaleDebt('1000000000');
        $stock->setTotalRevenue('20000000000');
        $stock->setOperatingMargin('0.25');
        $stock->setRetainedEarnings('5000000000');
        $stock->setEarningsPerShare('1.00');
        $stock->setAssetTurnover('2.0000');

        $macroState = new MacroStateDTO(policyRateEma: 0.03, corporateTaxRate: 0.21, yield5yEma: 0.035);

        $debtMetricsMock = new DebtMetricsDTO(
            interestExpense: 50000000.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.015,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 5000000000.0,
            revenue: 20000000000.0,
            depreciation: 500000000.0,
            ebitda: 5500000000.0
        );
        $debtHealthMock = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.035,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 100.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 1.5,
            wacc: 0.06,
            costOfEquity: 0.08,
            leveredBeta: 1.0,
            rawMetrics: $debtMetricsMock,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        );

        $this->debtEngineMock->method('analyzeDebtHealth')->willReturn($debtHealthMock);
        $this->debtEngineMock->method('analyzeTrailingDebtHealth')->willReturn($debtHealthMock);
        $this->corporateMetricsMock->method('calculateOperatingBase')->willReturn(15000000000.0);
        $this->mathUtilityMock->method('calculateManagementFairValuePE')->willReturn(15.0);
        $this->mathUtilityMock->method('calculateLogNormalSynergy')->willReturn(1.10);
        $this->mathUtilityMock->method('checkProbability')->willReturn(true);
        $this->mathUtilityMock->method('generateUniformBetween')->willReturn(0.80);
        $this->marketEventPublisherMock->method('publish')->willReturn([]);

        $preDealCapital = $stock->getInvestedCapital();
        $result = $this->engine->evaluatePrivateAcquisition($stock, $macroState, 1.0);
        $this->assertIsArray($result);

        $spent = (float) $result['spent'];
        $this->assertGreaterThan(0.0, $spent);

        $acquiredRevenue = (float) $stock->getTotalRevenue() - 20_000_000_000.0;
        $netAssets = $spent - (float) $stock->getGoodwill();
        $this->assertGreaterThan(0.0, $netAssets);
        $this->assertLessThan($spent, $netAssets, 'the fixture must book goodwill, or it cannot tell the two bases apart');

        $this->assertEqualsWithDelta(
            (($preDealCapital * 2.0) + $acquiredRevenue) / ($preDealCapital + $netAssets),
            (float) $stock->getAssetTurnover(),
            1e-9
        );
    }

    /**
     * Operating income adds across the two businesses: the acquirer keeps every dollar it earned and gains
     * the target's. The acquirer's margin used to be multiplied by 0.9 on every deal on top of the blend, a
     * permanent firm-wide haircut that compounded to 0.59 over five deals and dragged a 16% industrial
     * below its interest bill. The target's after-tax ROIC is grossed up by the DuPont identity the
     * earnings engine rebuilds revenue with, so the capacity booked earns the return the deal was priced on.
     */
    public function testAcquisitionAddsTheTargetsOperatingIncomeToTheAcquirersOwn(): void
    {
        $this->primeHealthyDeal(operatingBase: 15_000_000_000.0);
        $this->bookIssuedDebt();

        // A modest treasury keeps invested capital off its half-of-equity floor, where it would stop moving
        // one for one with the cheque and no identity on it could hold.
        $stock = $this->buildLedgeredAcquirer('ADDI', 4_000_000_000.0, 1_000_000_000.0, 100_000_000.0);
        $stock->setManagementStyle(ManagementStyle::Steward);
        $stock->setAssetTurnover('1.5000');

        $revenueCapital = static fn (Stock $s): float => CorporateMetrics::revenueGeneratingCapital(
            $s->getInvestedCapital(),
            $s->getTotalCipAmount(),
            (float) $s->getGoodwill()
        );
        $operatingIncome = static fn (Stock $s): float => $revenueCapital($s) * (float) $s->getAssetTurnover() * (float) $s->getOperatingMargin();

        $before = $operatingIncome($stock);
        $result = $this->engine->evaluatePrivateAcquisition($stock, $this->healthyMacro(), 1.0);
        $this->assertIsArray($result);

        // The harness pins synergy at 1.10 and the target ROIC draw to the 0.25 ceiling; a steward pays no premium.
        $effectiveTargetRoic = MergerAndAcquisitionEngine::MA_TARGET_ROIC_CEILING * 1.10;
        $taxRate = \App\Data\Sectors::getBusinessModelStrategy(\App\Data\Sectors::INDUSTRY_METRICS['Technology']['business_model'] ?? 'none')
            ->getEffectiveTaxRate(0.21);
        $acquiredOperatingIncome = (float) $result['spent'] * $effectiveTargetRoic / (1.0 - $taxRate);

        $this->assertEqualsWithDelta($before + $acquiredOperatingIncome, $operatingIncome($stock), max(1.0, $before * 1e-9));
    }

    /**
     * Funding moves the cash, debt or shares before the synergies are booked, so invested capital read at that
     * point already contains the price. The acquirer's return has to be weighted on what it held before.
     */
    public function testReturnBlendWeighsTheAcquirersPreDealCapitalOnce(): void
    {
        $this->primeHealthyDeal(operatingBase: 15_000_000_000.0);
        $this->bookIssuedDebt();

        $stock = $this->buildLedgeredAcquirer('ONCE', 40_000_000_000.0, 1_000_000_000.0, 100_000_000.0);
        $stock->setManagementStyle(ManagementStyle::Steward);
        $preDealCapital = $stock->getInvestedCapital();

        $result = $this->engine->evaluatePrivateAcquisition($stock, $this->healthyMacro(), 1.0);
        $this->assertIsArray($result);
        $spent = (float) $result['spent'];

        $targetReturn = MergerAndAcquisitionEngine::MA_TARGET_ROIC_CEILING * 1.10;
        $this->assertEqualsWithDelta(
            (($preDealCapital * 0.15) + ($spent * $targetReturn)) / ($preDealCapital + $spent),
            (float) $stock->getBaselineRoic(),
            1e-9
        );
    }

    /**
     * ASC 805: the identifiable assets acquired come on at fair value, so the acquirer's plant ledger grows
     * by the net assets bought and only the premium becomes goodwill. Without this the acquirer would book
     * the target's revenue in full while depreciating nothing but its own original plant.
     */
    public function testPrivateAcquisitionAddsNetIdentifiableAssetsToThePlantLedger(): void
    {
        $stock = new Stock();
        $stock->setTicker('ACQP');
        $stock->setName('Acquirer Plant Corp');
        $stock->setIndustry('Technology');
        $stock->setPrice('100.00');
        $stock->setSharesOutstanding('100000000');
        $stock->setCorporateTreasury('5000000000');
        $stock->setTotalEquity('10000000000');
        $stock->setWholesaleDebt('1000000000');
        $stock->setTotalRevenue('20000000000');
        $stock->setOperatingMargin('0.25');
        $stock->setRetainedEarnings('5000000000');
        $stock->setEarningsPerShare('1.00');
        $stock->setGrossPpe('8000000000');
        $stock->setAccumulatedDepreciation('3000000000');

        $macroState = new MacroStateDTO(policyRateEma: 0.03, corporateTaxRate: 0.21, yield5yEma: 0.035);

        $debtMetricsMock = new DebtMetricsDTO(
            interestExpense: 50000000.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.015,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 5000000000.0,
            revenue: 20000000000.0,
            depreciation: 500000000.0,
            ebitda: 5500000000.0
        );
        $debtHealthMock = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.035,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 100.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 1.5,
            wacc: 0.06,
            costOfEquity: 0.08,
            leveredBeta: 1.0,
            rawMetrics: $debtMetricsMock,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        );

        $this->debtEngineMock->method('analyzeDebtHealth')->willReturn($debtHealthMock);
        $this->debtEngineMock->method('analyzeTrailingDebtHealth')->willReturn($debtHealthMock);
        $this->corporateMetricsMock->method('calculateOperatingBase')->willReturn(15000000000.0);
        $this->mathUtilityMock->method('calculateManagementFairValuePE')->willReturn(15.0);
        $this->mathUtilityMock->method('calculateLogNormalSynergy')->willReturn(1.10);
        $this->mathUtilityMock->method('checkProbability')->willReturn(true);
        $this->mathUtilityMock->method('generateUniformBetween')->willReturn(0.80);
        $this->marketEventPublisherMock->method('publish')->willReturn([]);

        $grossBefore = (float) $stock->getGrossPpe();
        $ageBefore = $stock->getAssetAge();

        $result = $this->engine->evaluatePrivateAcquisition($stock, $macroState, 1.0);
        $this->assertIsArray($result);

        $spent = (float) $result['spent'];
        $goodwill = (float) $stock->getGoodwill();
        $grossAfter = (float) $stock->getGrossPpe();

        $this->assertGreaterThan(0.0, $spent);
        $this->assertGreaterThan($grossBefore, $grossAfter, 'the acquired plant must reach the ledger');

        // Only the net identifiable assets are capitalized: the premium is goodwill, which is never depreciated.
        $this->assertLessThanOrEqual($spent - $goodwill + 1.0, $grossAfter - $grossBefore);

        // Assets bought at fair value carry no accumulated depreciation, so the blended plant looks younger.
        $this->assertLessThan($ageBefore, $stock->getAssetAge());
    }

    public function testEvaluateCorporateDivestitureExecutesSuccessfullyAndUpdatesBalanceSheet(): void
    {
        $stock = new Stock();
        $stock->setTicker('DIVEST');
        $stock->setName('Divesting Corp');
        $stock->setIndustry('Technology');
        $stock->setPrice('20.00');
        $stock->setSharesOutstanding('10000000');
        $stock->setCorporateTreasury('1000000'); // Low cash
        $stock->setTotalEquity('50000000');
        $stock->setWholesaleDebt('20000000');
        $stock->setTotalRevenue('30000000');
        $stock->setOperatingMargin('-0.05'); // Negative margin -> distressed
        $stock->setRetainedEarnings('5000000');
        $stock->setEarningsPerShare('-0.10');
        $stock->setTotalNetIncome('-1000000');

        $macroState = new MacroStateDTO(
            policyRateEma: 0.04,
            corporateTaxRate: 0.20,
            yield5yEma: 0.04,
            nominalGdpIndex: 1.0
        );

        $debtMetricsMock = new DebtMetricsDTO(
            interestExpense: 1000000.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.02,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: -1500000.0,
            revenue: 30000000.0,
            depreciation: 1000000.0,
            ebitda: -500000.0
        );

        $debtHealthMock = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.02,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: -1.5,
            wantsToPaydownDebt: true,
            canIssueDebt: false,
            debtTolerance: 1.0,
            wacc: 0.12,
            costOfEquity: 0.14,
            leveredBeta: 1.5,
            rawMetrics: $debtMetricsMock,
            isLiquidityCrisis: true,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        );

        $this->debtEngineMock->method('analyzeDebtHealth')->willReturn($debtHealthMock);
        $this->debtEngineMock->method('analyzeTrailingDebtHealth')->willReturn($debtHealthMock);
        $this->corporateMetricsMock->method('calculateOperatingBase')->willReturn(10000000.0);
        $this->corporateMetricsMock->method('calculateScaleRatio')->willReturn(0.10);
        $this->mathUtilityMock->method('checkProbability')->willReturn(true);
        $this->mathUtilityMock->method('generateUniformBetween')->willReturn(0.25); // Divest 25%
        $this->marketEventPublisherMock->method('publish')->willReturn(['event_type' => 'DIVESTITURE']);

        $initialTreasury = (float) $stock->getCorporateTreasury();
        $initialDebt = (float) $stock->getWholesaleDebt();

        $result = $this->engine->evaluateCorporateDivestiture($stock, $macroState, 1.0);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('event', $result);
        $this->assertArrayHasKey('shock', $result);
        // Cash proceeds must increase treasury
        $this->assertGreaterThan($initialTreasury, (float) $stock->getCorporateTreasury());
        // Debt must be shed
        $this->assertLessThan($initialDebt, (float) $stock->getWholesaleDebt());
    }

    public function testEvaluateCorporateDivestitureAbortsForBankruptStock(): void
    {
        $stock = new Stock();
        $stock->setTicker('DEAD');
        $stock->setIsBankrupt(true);

        $macro = new MacroStateDTO();
        $result = $this->engine->evaluateCorporateDivestiture($stock, $macro, 1.0);

        $this->assertNull($result);
    }

    // =========================================================================
    // BALANCE SHEET IDENTITY THROUGH DEALS
    // =========================================================================

    /**
     * A cash deal swaps cash for goodwill plus the net assets bought. Every dollar of those net assets must
     * land on a ledger (trade cycle or plant) or the asset side shrinks with no claim moving to match.
     */
    public function testCashAcquisitionKeepsTheBalanceSheetBalancedAndBooksAcquiredWorkingCapital(): void
    {
        $stock = $this->buildLedgeredAcquirer('CASH', treasury: 20_000_000_000.0, debt: 1_000_000_000.0, shares: 100_000_000.0);
        $macroState = new MacroStateDTO(policyRateEma: 0.03, corporateTaxRate: 0.21, yield5yEma: 0.035);
        $this->primeHealthyDeal(operatingBase: 1_000_000_000.0); // tiny operating base: the treasury is a hoard

        $this->assertBalanced($stock, 'the fixture must open balanced');
        $treasuryBefore = (float) $stock->getCorporateTreasury();
        $equityBefore = (float) $stock->getTotalEquity();
        $debtBefore = (float) $stock->getWholesaleDebt();
        $receivablesBefore = (float) $stock->getReceivables();
        $inventoryBefore = (float) $stock->getInventory();
        $payablesBefore = (float) $stock->getPayables();
        $workingCapitalBefore = (float) $stock->getNetWorkingCapital();
        $grossBefore = (float) $stock->getGrossPpe();
        $taxBasisBefore = (float) $stock->getPpeTaxBasis();
        $goodwillBefore = (float) $stock->getGoodwill();

        $result = $this->engine->evaluatePrivateAcquisition($stock, $macroState, 1.0);
        $this->assertIsArray($result);
        $spent = (float) $result['spent'];
        $this->assertGreaterThan(0.0, $spent);

        // Paid entirely from cash: no shares, no debt, no day-one equity (ASC 805).
        $this->assertEqualsWithDelta($treasuryBefore - $spent, (float) $stock->getCorporateTreasury(), 1.0);
        $this->assertEqualsWithDelta($debtBefore, (float) $stock->getWholesaleDebt(), 1.0);
        $this->assertEqualsWithDelta($equityBefore, (float) $stock->getTotalEquity(), 1.0);

        // What came in: goodwill, working capital in the acquirer's own mix, and plant at fair value.
        $goodwillRecorded = (float) $stock->getGoodwill() - $goodwillBefore;
        $workingCapitalAdded = (float) $stock->getNetWorkingCapital() - $workingCapitalBefore;
        $plantAdded = (float) $stock->getGrossPpe() - $grossBefore;
        $this->assertGreaterThan(0.0, $goodwillRecorded);
        $this->assertGreaterThan(0.0, $workingCapitalAdded, 'the target brought a trade cycle with it');
        $this->assertGreaterThan(0.0, $plantAdded);
        $this->assertEqualsWithDelta($spent, $goodwillRecorded + $workingCapitalAdded + $plantAdded, 1.0, 'cash out equals assets in');
        $this->assertEqualsWithDelta($plantAdded, (float) $stock->getPpeTaxBasis() - $taxBasisBefore, 1.0, 'an asset purchase steps the tax basis up to cost');
        $this->assertEqualsWithDelta($receivablesBefore / $inventoryBefore, (float) $stock->getReceivables() / (float) $stock->getInventory(), 1e-9, 'the mix is preserved');
        $this->assertGreaterThan($payablesBefore, (float) $stock->getPayables(), 'the target owed its suppliers too');

        $this->assertBalanced($stock, 'a cash acquisition');
    }

    public function testStockForStockAcquisitionKeepsTheBalanceSheetBalanced(): void
    {
        // Overvalued acquirer: P/E 100 against a fair 15, P/B well above 2, returns above the hurdle.
        $stock = $this->buildLedgeredAcquirer('PAPR', treasury: 2_000_000_000.0, debt: 1_000_000_000.0, shares: 1_000_000_000.0);
        $stock->setRoicTtm('0.20');
        $macroState = new MacroStateDTO(policyRateEma: 0.03, corporateTaxRate: 0.21, yield5yEma: 0.035);
        $this->primeHealthyDeal(operatingBase: 20_000_000_000.0);

        $treasuryBefore = (float) $stock->getCorporateTreasury();
        $equityBefore = (float) $stock->getTotalEquity();
        $sharesBefore = (float) $stock->getSharesOutstanding();

        $result = $this->engine->evaluatePrivateAcquisition($stock, $macroState, 1.0);
        $this->assertIsArray($result);
        $spent = (float) $result['spent'];
        $this->assertGreaterThan(0.0, $spent);

        $this->assertGreaterThan($sharesBefore, (float) $stock->getSharesOutstanding(), 'paid in paper');
        $this->assertEqualsWithDelta($treasuryBefore, (float) $stock->getCorporateTreasury(), 1.0, 'no cash changed hands');
        $this->assertEqualsWithDelta($equityBefore + $spent, (float) $stock->getTotalEquity(), 1.0, 'the shares issued are paid-in capital');

        $this->assertBalanced($stock, 'a stock-for-stock acquisition');
    }

    public function testLeveragedAcquisitionKeepsTheBalanceSheetBalanced(): void
    {
        // Cash sits at its operating target, so the deal is funded by borrowing against an under-levered book.
        $stock = $this->buildLedgeredAcquirer('LEVR', treasury: 1_000_000_000.0, debt: 1_000_000_000.0, shares: 100_000_000.0);
        $macroState = new MacroStateDTO(policyRateEma: 0.03, corporateTaxRate: 0.21, yield5yEma: 0.035);
        $this->primeHealthyDeal(operatingBase: 20_000_000_000.0);
        $this->debtEngineMock->method('issueDebt')->willReturnCallback(
            static function (Stock $borrower, float $amount): void {
                $borrower->setWholesaleDebt((string) ((float) $borrower->getWholesaleDebt() + $amount));
            }
        );

        $treasuryBefore = (float) $stock->getCorporateTreasury();
        $equityBefore = (float) $stock->getTotalEquity();
        $debtBefore = (float) $stock->getWholesaleDebt();

        $result = $this->engine->evaluatePrivateAcquisition($stock, $macroState, 1.0);
        $this->assertIsArray($result);
        $spent = (float) $result['spent'];
        $this->assertGreaterThan(0.0, $spent);

        $cashUsed = $treasuryBefore - (float) $stock->getCorporateTreasury();
        $debtIssued = (float) $stock->getWholesaleDebt() - $debtBefore;
        $this->assertGreaterThan(0.0, $debtIssued, 'the deal was levered');
        $this->assertEqualsWithDelta($spent, $cashUsed + $debtIssued, 1.0, 'funded by cash and new debt only');
        $this->assertEqualsWithDelta($equityBefore, (float) $stock->getTotalEquity(), 1.0);

        $this->assertBalanced($stock, 'a leveraged acquisition');
    }

    /**
     * The pro-forma credit check strikes the acquirer's Merton distance at its business risk. It used to
     * de-lever equity volatility across the enlarged balance sheet, so the more a deal borrowed the SAFER the
     * combined assets looked and the cheaper the new debt was priced.
     */
    public function testALeveragedDealIsUnderwrittenAtTheAcquirersAssetRisk(): void
    {
        $stock = $this->buildLedgeredAcquirer('LEVR', treasury: 1_000_000_000.0, debt: 1_000_000_000.0, shares: 100_000_000.0);
        $macroState = new MacroStateDTO(policyRateEma: 0.03, corporateTaxRate: 0.21, yield5yEma: 0.035);
        $this->primeHealthyDeal(operatingBase: 20_000_000_000.0);
        $this->bookIssuedDebt();
        $this->debtEngineMock->method('resolveAssetVolatility')->willReturn(0.27);
        $struckAt = [];
        $this->mathUtilityMock->method('calculateDistanceToDefault')->willReturnCallback(
            static function (float $assets, float $debt, float $assetVolatility) use (&$struckAt): float {
                $struckAt[] = $assetVolatility;

                return 2.0;
            }
        );

        $result = $this->engine->evaluatePrivateAcquisition($stock, $macroState, 1.0);

        $this->assertIsArray($result, 'the levered control deal must execute');
        $this->assertNotEmpty($struckAt, 'a debt-funded deal is credit-checked');
        $this->assertSame([0.27], array_values(array_unique($struckAt)));
    }

    /**
     * A divestiture retires a pro rata slice of every ledger and books the difference between the proceeds
     * and that book value as the gain or loss. A fire sale below book is a real loss charged in full.
     */
    public function testDivestitureRetiresEveryLedgerProRataAndKeepsTheBalanceSheetBalanced(): void
    {
        $stock = $this->buildLedgeredAcquirer('DIVL', treasury: 1_000_000_000.0, debt: 4_000_000_000.0, shares: 10_000_000.0);
        $stock->setOperatingMargin('-0.05');
        $stock->setEarningsPerShare('-0.10');
        $stock->setTotalNetIncome('-1000000000');
        $stock->setRoicTtm('0.00');
        $macroState = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, nominalGdpIndex: 1.0);
        $this->primeDistressedDivestiture(operatingBase: 10_000_000_000.0, fraction: 0.25);

        $this->assertBalanced($stock, 'the fixture must open balanced');
        $equityBefore = (float) $stock->getTotalEquity();
        $treasuryBefore = (float) $stock->getCorporateTreasury();
        $debtBefore = (float) $stock->getWholesaleDebt();
        $before = [
            'grossPpe' => (float) $stock->getGrossPpe(),
            'accumulated' => (float) $stock->getAccumulatedDepreciation(),
            'taxBasis' => (float) $stock->getPpeTaxBasis(),
            'cip' => (float) $stock->getCipBalance(),
            'goodwill' => (float) $stock->getGoodwill(),
            'receivables' => (float) $stock->getReceivables(),
            'allowance' => (float) $stock->getReceivablesAllowance(),
            'inventory' => (float) $stock->getInventory(),
            'payables' => (float) $stock->getPayables(),
            'deferredTax' => (float) $stock->getDeferredTaxLiability(),
        ];
        $bookValueDisposed = 0.25 * (
            $stock->getNetPpe() + $before['cip'] + $before['goodwill'] + $stock->getNetReceivables() + $before['inventory']
            - $before['payables'] - $before['deferredTax']
        );
        $ageBefore = $stock->getAssetAge();

        $result = $this->engine->evaluateCorporateDivestiture($stock, $macroState, 1.0);
        $this->assertIsArray($result);

        $proceeds = (float) $stock->getCorporateTreasury() - $treasuryBefore;
        $debtShed = $debtBefore - (float) $stock->getWholesaleDebt();
        $this->assertGreaterThan(0.0, $proceeds);
        $this->assertEqualsWithDelta($debtBefore * 0.25, $debtShed, 1.0, 'the buyer assumes its share of the debt');

        $getters = [
            'grossPpe' => 'getGrossPpe', 'accumulated' => 'getAccumulatedDepreciation', 'taxBasis' => 'getPpeTaxBasis',
            'cip' => 'getCipBalance', 'goodwill' => 'getGoodwill', 'receivables' => 'getReceivables',
            'allowance' => 'getReceivablesAllowance', 'inventory' => 'getInventory', 'payables' => 'getPayables',
            'deferredTax' => 'getDeferredTaxLiability',
        ];
        foreach ($getters as $ledger => $getter) {
            $this->assertEqualsWithDelta($before[$ledger] * 0.75, (float) $stock->{$getter}(), 1.0, "{$ledger} leaves pro rata");
        }
        $this->assertEqualsWithDelta($ageBefore, $stock->getAssetAge(), 1e-9, 'the plant that stays is no older or younger');

        // Gain or loss on sale is proceeds less the book value that left, net of the debt the buyer took on.
        $expectedGain = $proceeds - ($bookValueDisposed - $debtShed);
        $this->assertEqualsWithDelta($equityBefore + $expectedGain, (float) $stock->getTotalEquity(), 1.0);
        $this->assertLessThan(0.0, $expectedGain, 'a fire sale at cents on the dollar is a loss, and it is not floored away');

        $this->assertBalanced($stock, 'a divestiture');
    }

    /**
     * Opens every ledger on a non-financial balance sheet and derives equity from the identity, so the
     * fixture starts balanced to the dollar and any drift is the engine's doing.
     */
    /**
     * Roll's (1986) hubris hypothesis, wired to the management style: the empire builder pays a premium over
     * the target's standalone value, and that premium buys nothing.
     *
     * The two runs share every draw the harness fixes — synergy, target ROIC, the hurdle — so the whole
     * difference in the goodwill booked per dollar spent is the overpayment. Net identifiable assets scale
     * with what was BOUGHT and goodwill absorbs the gap to what was PAID, which is what leaves the premium
     * exposed to the annual impairment test instead of quietly capitalised as plant.
     */
    public function testEmpireBuilderHubrisPremiumLandsInGoodwillRatherThanNetAssets(): void
    {
        $openingGoodwill = 1_000_000_000.0;
        // Fixed by the harness: synergy 1.10 on a target ROIC draw that clamps to the 0.25 ceiling.
        $effectiveTargetRoic = MergerAndAcquisitionEngine::MA_TARGET_ROIC_CEILING * 1.10;
        $hurdleRate = 0.06;
        $netAssetShare = min(1.0, $hurdleRate / $effectiveTargetRoic);

        $this->primeHealthyDeal(operatingBase: 15_000_000_000.0);
        $this->bookIssuedDebt();

        $disciplined = $this->buildLedgeredAcquirer('DSCP', 40_000_000_000.0, 1_000_000_000.0, 100_000_000.0);
        $disciplined->setManagementStyle(ManagementStyle::Steward);
        $disciplinedResult = $this->engine->evaluatePrivateAcquisition($disciplined, $this->healthyMacro(), 1.0);
        $this->assertIsArray($disciplinedResult, 'The control deal must actually execute for the comparison to mean anything.');

        $hubristic = $this->buildLedgeredAcquirer('EMPR', 40_000_000_000.0, 1_000_000_000.0, 100_000_000.0);
        $hubristic->setManagementStyle(ManagementStyle::EmpireBuilder);
        $hubristicResult = $this->engine->evaluatePrivateAcquisition($hubristic, $this->healthyMacro(), 1.0);
        $this->assertIsArray($hubristicResult);

        $disciplinedGoodwillRate = ((float) $disciplined->getGoodwill() - $openingGoodwill) / (float) $disciplinedResult['spent'];
        $hubristicGoodwillRate = ((float) $hubristic->getGoodwill() - $openingGoodwill) / (float) $hubristicResult['spent'];

        // A disciplined acquirer pays standalone value, so goodwill is purely the residual-income premium.
        $this->assertEqualsWithDelta(1.0 - $netAssetShare, $disciplinedGoodwillRate, 1e-9);

        // The empire builder's net assets scale with value/(1+premium); the premium itself is all goodwill.
        $premium = ManagementStyle::EmpireBuilder->hubrisPremium();
        $this->assertEqualsWithDelta(1.0 - ($netAssetShare / (1.0 + $premium)), $hubristicGoodwillRate, 1e-9);

        $this->assertGreaterThan(
            $disciplinedGoodwillRate,
            $hubristicGoodwillRate,
            'Overpaying must show up as goodwill per dollar spent, or the hubris is free.'
        );

        // The cheque still equals the assets that came in, premium and all.
        $this->assertBalanced($disciplined, 'a disciplined acquisition');
        $this->assertBalanced($hubristic, 'an acquisition carrying a hubris premium');
    }

    /**
     * The other half of the same transaction: overpaying does not change what the target earns, so the same
     * money has to buy strictly less business. Without this the empire builder simply got a larger firm at
     * an unchanged return — growth with no agency cost attached to it.
     *
     * Both runs are driven off one seed, so the target drawn is the same target and the only difference
     * between them is the cheque written for it.
     */
    public function testHubrisPremiumBuysStrictlyLessBusinessForTheSameMoney(): void
    {
        $this->primeHealthyDeal(operatingBase: 15_000_000_000.0);
        $this->bookIssuedDebt();

        $openingRevenue = 20_000_000_000.0;

        mt_srand(20260915);
        $disciplined = $this->buildLedgeredAcquirer('DSC2', 40_000_000_000.0, 1_000_000_000.0, 100_000_000.0);
        $disciplined->setManagementStyle(ManagementStyle::Steward);
        $disciplinedResult = $this->engine->evaluatePrivateAcquisition($disciplined, $this->healthyMacro(), 1.0);
        $this->assertIsArray($disciplinedResult);

        mt_srand(20260915);
        $hubristic = $this->buildLedgeredAcquirer('EMP2', 40_000_000_000.0, 1_000_000_000.0, 100_000_000.0);
        $hubristic->setManagementStyle(ManagementStyle::EmpireBuilder);
        $hubristicResult = $this->engine->evaluatePrivateAcquisition($hubristic, $this->healthyMacro(), 1.0);
        $this->assertIsArray($hubristicResult);

        // Revenue bought per dollar spent is the target's turnover on the price paid, and the premium is the
        // only thing separating the two.
        $disciplinedYield = ((float) $disciplined->getTotalRevenue() - $openingRevenue) / (float) $disciplinedResult['spent'];
        $hubristicYield = ((float) $hubristic->getTotalRevenue() - $openingRevenue) / (float) $hubristicResult['spent'];

        $this->assertGreaterThan(0.0, $disciplinedYield);
        $this->assertEqualsWithDelta(
            $disciplinedYield / (1.0 + ManagementStyle::EmpireBuilder->hubrisPremium()),
            $hubristicYield,
            1e-9,
            'The premium buys no revenue at all, so the yield on the price paid falls by exactly (1 + premium).'
        );

        // And the capital that bought nothing drags the acquirer's own structural return down with it.
        $this->assertLessThan(
            (float) $disciplined->getBaselineRoic(),
            (float) $hubristic->getBaselineRoic(),
            'Goodwill sits in invested capital and earns nothing; the blended return has to reflect that.'
        );
    }

    /**
     * The empire builder's branch funds with leverage, so the stub has to actually book the borrowing or the
     * sheet is short by whatever the treasury could not cover.
     */
    private function bookIssuedDebt(): void
    {
        $this->debtEngineMock->method('issueDebt')->willReturnCallback(
            static function (Stock $borrower, float $amount): void {
                $borrower->setWholesaleDebt((string) ((float) $borrower->getWholesaleDebt() + $amount));
            }
        );
    }

    private function healthyMacro(): MacroStateDTO
    {
        return new MacroStateDTO(policyRateEma: 0.03, corporateTaxRate: 0.21, yield5yEma: 0.035, nominalGdpIndex: 1.0);
    }

    /**
     * The empire builder's branch is read ahead of every other one and funds itself with leverage, but it
     * had no lender test on it at all: hubris is a reason to overpay for a target, not a reason a bank lends
     * to a firm that cannot service what it already owes. Failing either test does not stop it acquiring —
     * it drops through to the cash-funded branches — so what must fall is the size of the cheque.
     */
    public function testEmpireBuilderCannotFundADealOnCreditItDoesNotHave(): void
    {
        $treasury = 4_000_000_000.0;

        $this->primeHealthyDeal(operatingBase: 15_000_000_000.0, canIssueDebt: true, hasLeverageHeadroom: true);
        $banked = $this->buildLedgeredAcquirer('EMPA', $treasury, 1_000_000_000.0, 100_000_000.0);
        $banked->setManagementStyle(\App\Data\ManagementStyle::EmpireBuilder);
        $levered = $this->engine->evaluatePrivateAcquisition($banked, $this->healthyMacro(), 1.0);
        $this->assertIsArray($levered, 'The control deal must execute, or the comparison proves nothing.');
        $this->assertGreaterThan($treasury, (float) $levered['spent'], 'The control must actually be drawing on borrowing capacity.');

        $this->setUp();
        $this->primeHealthyDeal(operatingBase: 15_000_000_000.0, canIssueDebt: false, hasLeverageHeadroom: true);
        $shutOut = $this->buildLedgeredAcquirer('EMPB', $treasury, 1_000_000_000.0, 100_000_000.0);
        $shutOut->setManagementStyle(\App\Data\ManagementStyle::EmpireBuilder);
        $cashOnly = $this->engine->evaluatePrivateAcquisition($shutOut, $this->healthyMacro(), 1.0);

        $spent = is_array($cashOnly) ? (float) $cashOnly['spent'] : 0.0;
        $this->assertLessThanOrEqual($treasury, $spent, 'A firm the lenders have refused cannot spend borrowed money.');
        $this->assertLessThan((float) $levered['spent'], $spent, 'Losing access to credit must shrink the cheque.');
    }

    /**
     * Acquisition debt is sized by the same lender test as any other borrowing: the balance sheet AND the
     * interest the firm can cover. A firm whose EBIT only just covers the interest it already pays has no
     * coverage left to borrow against, however much book equity it carries, so the empire builder's cheque
     * falls to what its own cash can write.
     */
    public function testAcquisitionDebtIsLimitedByInterestCoverageNotBookLeverageAlone(): void
    {
        $treasury = 4_000_000_000.0;

        $this->primeHealthyDeal(operatingBase: 15_000_000_000.0);
        $this->bookIssuedDebt();
        $covered = $this->buildLedgeredAcquirer('COVA', $treasury, 1_000_000_000.0, 100_000_000.0);
        $covered->setManagementStyle(ManagementStyle::EmpireBuilder);
        $levered = $this->engine->evaluatePrivateAcquisition($covered, $this->healthyMacro(), 1.0);
        $this->assertIsArray($levered, 'The control deal must execute, or the comparison proves nothing.');
        $this->assertGreaterThan($treasury, (float) $levered['spent'], 'A well-covered firm borrows for the deal.');

        $this->setUp();
        // EBIT equal to the interest bill: coverage of 1.0, below any minimum a lender would accept.
        $this->primeHealthyDeal(operatingBase: 15_000_000_000.0, ebit: 50_000_000.0);
        $this->bookIssuedDebt();
        $stretched = $this->buildLedgeredAcquirer('COVB', $treasury, 1_000_000_000.0, 100_000_000.0);
        $stretched->setManagementStyle(ManagementStyle::EmpireBuilder);
        $cashOnly = $this->engine->evaluatePrivateAcquisition($stretched, $this->healthyMacro(), 1.0);

        $spent = is_array($cashOnly) ? (float) $cashOnly['spent'] : 0.0;
        $this->assertLessThanOrEqual($treasury, $spent, 'No coverage left means no acquisition debt.');
        $this->assertSame(1_000_000_000.0, (float) $stretched->getWholesaleDebt(), 'The stretched acquirer must not have borrowed.');
    }

    /**
     * A financial buys with regulatory capital, and the goodwill a deal books is deducted from it. The cap on
     * deal size is therefore a rule the firm is held to, not a preference: an empire builder and a cash
     * hoarder are bound by it exactly as a steward is. Exempting them let a bank write a multi-trillion
     * cheque for a single private company.
     */
    public function testEveryFinancialAcquirerIsBoundByTheEquityCapWhateverItsStyle(): void
    {
        foreach ([ManagementStyle::EmpireBuilder, ManagementStyle::Fortress, ManagementStyle::Steward] as $style) {
            $this->setUp();
            $this->primeHealthyDeal(operatingBase: 15_000_000_000.0);
            $this->bookIssuedDebt();

            $bank = new Stock();
            $bank->setTicker('BNK' . substr($style->value, 0, 2));
            $bank->setName('Acquisitive Bank');
            $bank->setIndustry('Banks - Diversified');
            $bank->setPrice('50.00');
            $bank->setSharesOutstanding('1000000000');
            $bank->setCorporateTreasury('60000000000');
            $bank->setTotalEquity('100000000000');
            $bank->setWholesaleDebt('10000000000');
            $bank->setTotalRevenue('20000000000');
            $bank->setOperatingMargin('0.30');
            $bank->setRetainedEarnings('40000000000');
            $bank->setEarningsPerShare('4.00');
            $bank->setBaselineRoe('0.12');
            $bank->setManagementStyle($style);

            $result = $this->engine->evaluatePrivateAcquisition($bank, $this->healthyMacro(), 1.0);
            if ($result === null) {
                continue; // This style's branch did not trade; the cap is only asserted on deals that happen.
            }

            $this->assertLessThanOrEqual(
                100_000_000_000.0 * MergerAndAcquisitionEngine::MA_FINANCIAL_EQUITY_CAP + 1.0,
                (float) $result['spent'],
                "A {$style->value} bank must not spend more than the capital cap allows."
            );
        }
    }

    /**
     * Goodwill is not capital, so the 15% cap is struck on tangible equity: a bank carrying $60B of goodwill
     * on $100B of book equity buys with $40B of capital.
     */
    public function testTheFinancialDealCapIsStruckOnTangibleEquity(): void
    {
        $this->primeHealthyDeal(operatingBase: 15_000_000_000.0);
        $bank = $this->cashRichBank(wholesaleDebt: 10_000_000_000.0);
        $bank->setGoodwill('60000000000');

        $deal = $this->engine->evaluatePrivateAcquisition($bank, $this->healthyMacro(), 1.0);

        $this->assertIsArray($deal);
        $this->assertEqualsWithDelta(40_000_000_000.0 * MergerAndAcquisitionEngine::MA_FINANCIAL_EQUITY_CAP, (float) $deal['spent'], 1.0);
    }

    /**
     * A regulator approves a bank's acquisition only while the bank stays well capitalized, and the goodwill
     * it pays is deducted from its capital. The deal is sized to the largest one that leaves the tangible
     * capital ratio at the model's own warning threshold, the edge of the zone its rating and closure read,
     * with the leases the acquired revenue brings counted on the asset side.
     */
    public function testAFinancialDealIsSizedToKeepTheBankOutOfTheGreyZone(): void
    {
        $this->primeHealthyDeal(operatingBase: 15_000_000_000.0);
        $this->corporateMetricsMock->method('calculateLeaseLiability')->willReturnCallback(
            static fn (float $revenue, float $intensity): float => max(0.0, $revenue) * max(0.0, $intensity)
        );
        $bank = $this->cashRichBank(wholesaleDebt: 1_500_000_000_000.0);
        $capitalBase = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);
        $ratio = static function (Stock $stock) use ($capitalBase): float {
            $base = $capitalBase->resolveTangibleCapitalBase($stock, (float) $stock->getTotalRevenue());

            return 100.0 * $base['capital'] / $base['assets'];
        };
        $warning = (new \App\Service\Model\Sector\CommercialBankBusinessModel())->getWarningEquityThreshold();
        $this->assertGreaterThan($warning, $ratio($bank), 'the fixture must open with headroom');

        $deal = $this->engine->evaluatePrivateAcquisition($bank, $this->healthyMacro(), 1.0);

        $this->assertIsArray($deal);
        $this->assertLessThan(40_000_000_000.0 * MergerAndAcquisitionEngine::MA_FINANCIAL_EQUITY_CAP, (float) $deal['spent'], 'capital, not the 15% cap, sized the deal');
        $this->assertEqualsWithDelta($warning, $ratio($bank), 1e-9);
        $this->assertStringEndsWith('Sized to ' . MergerAndAcquisitionEngine::LIMIT_CAPITAL_TEST . '.', $deal['event']['description'], 'the announcement names the rule that sized it');
        $this->assertStringContainsString('booked as goodwill', $deal['event']['description']);
    }

    /** A bank already in the grey zone has no capital to spend on goodwill: no deal and no cash spent. */
    public function testABankAlreadyInTheGreyZoneBuysNothing(): void
    {
        $this->primeHealthyDeal(operatingBase: 15_000_000_000.0);
        $bank = $this->cashRichBank(wholesaleDebt: 1_700_000_000_000.0);

        $this->assertNull($this->engine->evaluatePrivateAcquisition($bank, $this->healthyMacro(), 1.0));
        $this->assertSame('200000000000', $bank->getCorporateTreasury());
    }

    /**
     * A bank is under the same merger review as everyone else; its market is measured in revenue because a
     * lender has no plant to count. At 40% of its market it has only the 100-point safe harbour left, 1.25%
     * of the market, however much capital it could spend, and the deal says so.
     */
    public function testABankIsSizedByMergerReviewOnARevenueMeasuredMarket(): void
    {
        $this->primeHealthyDeal(operatingBase: 15_000_000_000.0);
        $bank = $this->cashRichBank(wholesaleDebt: 100_000_000_000.0);
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $ledger->recordIdiosyncraticGain($bank, 400_000_000_000.0, 0.0, 0.40, 290);
        $engine = new MergerAndAcquisitionEngine($this->marketEventPublisherMock, $this->debtEngineMock, $this->mathUtilityMock, $this->corporateMetricsMock, $ledger);

        $deal = $engine->evaluatePrivateAcquisition($bank, $this->healthyMacro(), 1.0, 300, 252);

        $this->assertIsArray($deal);
        $this->assertLessThan(100_000_000_000.0 * MergerAndAcquisitionEngine::MA_FINANCIAL_EQUITY_CAP, (float) $deal['spent'], 'review, not the capital cap, sized it');
        $this->assertStringEndsWith('Sized to ' . MergerAndAcquisitionEngine::LIMIT_MERGER_REVIEW . '.', $deal['event']['description']);
        $cleared = MergerAndAcquisitionEngine::maxClearedTargetShare(0.40, 0.16);
        $this->assertEqualsWithDelta(0.40 + $cleared, $ledger->describeMergerMarket($bank, $this->healthyMacro(), 0.0, 300, 252)['acquirer_share'], 1e-9);
    }

    /** A steward bank with cash well past its target, so the cash-funded strategic acquisition trades. */
    private function cashRichBank(float $wholesaleDebt): Stock
    {
        $bank = new Stock();
        $bank->setTicker('CAPB');
        $bank->setName('Capital Bank');
        $bank->setIndustry('Banks - Diversified');
        $bank->setPrice('50.00');
        $bank->setSharesOutstanding('1000000000');
        $bank->setCorporateTreasury('200000000000');
        $bank->setTotalEquity('100000000000');
        $bank->setWholesaleDebt((string) $wholesaleDebt);
        $bank->setTotalRevenue('20000000000');
        $bank->setOperatingMargin('0.30');
        $bank->setRetainedEarnings('40000000000');
        $bank->setEarningsPerShare('4.00');
        $bank->setBaselineRoe('0.12');
        $bank->setManagementStyle(ManagementStyle::Steward);

        return $bank;
    }

    /**
     * The target was an off-board company already selling into the acquirer's market, so the acquirer's trend
     * plant rises by the capacity it bought and the industry balance reads the deal as a change of owner,
     * not a build. The acquirer reporting its old plant plus the acquisition finds its industry where it was.
     */
    public function testAnAcquisitionIsBookedAsPlantBoughtNotPlantBuilt(): void
    {
        $this->primeHealthyDeal(operatingBase: 15_000_000_000.0);
        $this->bookIssuedDebt();
        $ledger = new \App\Service\Corporate\Industry\IndustryShareLedger(new \App\Service\Corporate\Industry\InMemoryIndustryShareStore());
        $engine = new MergerAndAcquisitionEngine($this->marketEventPublisherMock, $this->debtEngineMock, $this->mathUtilityMock, $this->corporateMetricsMock, $ledger);

        $stock = $this->buildLedgeredAcquirer('PLNT', 4_000_000_000.0, 1_000_000_000.0, 100_000_000.0);
        $stock->setManagementStyle(ManagementStyle::Steward);
        // At 5% of its market the deal clears merger review whole.
        $ledger->resolveIndustryCapacityRatio($stock, 20_000_000_000.0, 0.05, 1.0, 0.0, 0.0, 10, 252);

        $result = $engine->evaluatePrivateAcquisition($stock, $this->healthyMacro(), 1.0);
        $this->assertIsArray($result);
        $acquiredRevenue = (float) $stock->getTotalRevenue() - 20_000_000_000.0;
        $this->assertGreaterThan(0.0, $acquiredRevenue);

        $this->assertEqualsWithDelta(
            1.0,
            $ledger->resolveIndustryCapacityRatio($stock, 20_000_000_000.0 + $acquiredRevenue, 0.05, 1.0, 0.0, 0.0, 73, 252),
            1e-9
        );
    }

    /**
     * The 2023 Merger Guidelines presume a deal illegal when it raises the HHI by more than 100 points and
     * either leaves the market above 1,800 or creates a firm with more than 30% of it. The cleared share is
     * the largest deal those screens pass, checked here against the screens as the guidelines state them.
     */
    public function testTheClearedTargetShareIsTheLargestDealTheMergerScreensPass(): void
    {
        $presumedIllegal = static function (float $share, float $herfindahl, float $target): bool {
            $delta = 2.0 * $share * $target;

            return $delta > 0.0100 && (($herfindahl + ($target * $target) + $delta) > 0.1800 || ($share + $target) > 0.30);
        };

        foreach ([0.0, 0.02, 0.05, 0.10, 0.20, 0.28, 0.30, 0.35, 0.50, 0.80] as $share) {
            foreach ([0.0, 0.02, 0.08, 0.15, 0.18, 0.25] as $rivals) {
                $herfindahl = ($share * $share) + $rivals;
                $cleared = MergerAndAcquisitionEngine::maxClearedTargetShare($share, $herfindahl);
                $case = "share {$share}, HHI {$herfindahl}, cleared {$cleared}";

                foreach ([0.25, 0.5, 0.999999] as $fraction) {
                    $this->assertFalse($presumedIllegal($share, $herfindahl, $cleared * $fraction), "every smaller deal clears: {$case}");
                }
                if ($cleared < 1.0) {
                    $this->assertTrue($presumedIllegal($share, $herfindahl, $cleared + 1e-6), "a larger deal is presumed illegal: {$case}");
                }
            }
        }

        // A leader at 30% alone in its market is held to the delta safe harbour: 100 points over 2 x 30%.
        $this->assertEqualsWithDelta(0.0100 / 0.60, MergerAndAcquisitionEngine::maxClearedTargetShare(0.30, 0.09), 1e-12);
        // A 10% firm in an unconcentrated market may buy up to the 30% share ceiling.
        $this->assertEqualsWithDelta(0.20, MergerAndAcquisitionEngine::maxClearedTargetShare(0.10, 0.01), 1e-12);
        // In a highly concentrated market even a small firm only gets the safe harbour.
        $this->assertEqualsWithDelta(0.10, MergerAndAcquisitionEngine::maxClearedTargetShare(0.05, 0.20), 1e-12);
    }

    /**
     * The Diet sets review between the 2023 guidelines and the 2010 ones: 2,500 points, a 200-point delta and no share
     * presumption let the 30% leader that 2023 holds to its safe harbour buy until the market reaches 2,500.
     */
    public function testLenientReviewAppliesTheTwentyTenGuidelines(): void
    {
        $this->assertSame(['delta' => 0.0100, 'concentrated' => 0.1800, 'shareCeiling' => 0.30], MergerAndAcquisitionEngine::reviewScreens(0.0));
        $this->assertEqualsWithDelta(0.0200, MergerAndAcquisitionEngine::reviewScreens(1.0)['delta'], 1e-12);
        $this->assertEqualsWithDelta(0.2500, MergerAndAcquisitionEngine::reviewScreens(1.0)['concentrated'], 1e-12);
        $this->assertEqualsWithDelta(1.0, MergerAndAcquisitionEngine::reviewScreens(1.0)['shareCeiling'], 1e-12);
        $this->assertSame(MergerAndAcquisitionEngine::reviewScreens(1.0), MergerAndAcquisitionEngine::reviewScreens(1.5), 'Nothing on record is more lenient.');

        // √(0.09 + 0.25 − 0.09) − 0.30: the purchase that takes the market to 2,500.
        $this->assertEqualsWithDelta(0.20, MergerAndAcquisitionEngine::maxClearedTargetShare(0.30, 0.09, 1.0), 1e-12);
        $this->assertEqualsWithDelta(0.0100 / 0.60, MergerAndAcquisitionEngine::maxClearedTargetShare(0.30, 0.09, 0.0), 1e-12);
        $this->assertGreaterThan(MergerAndAcquisitionEngine::maxClearedTargetShare(0.30, 0.09, 0.4), MergerAndAcquisitionEngine::maxClearedTargetShare(0.30, 0.09, 0.6));
    }

    /**
     * A leader at 30% of its market buys no more of it than the delta safe harbour clears, 1.67% of the
     * market, however much its balance sheet could pay. Its share after the deal is its share before plus
     * exactly that, which is an HHI increase of exactly 100 points.
     */
    public function testADominantAcquirerBuysNoMoreThanMergerReviewClears(): void
    {
        $this->primeHealthyDeal(operatingBase: 15_000_000_000.0);
        $this->bookIssuedDebt();

        $unreviewed = $this->buildLedgeredAcquirer('FREE', 4_000_000_000.0, 1_000_000_000.0, 100_000_000.0);
        $unreviewed->setManagementStyle(ManagementStyle::Steward);
        $unreviewedDeal = $this->engine->evaluatePrivateAcquisition($unreviewed, $this->healthyMacro(), 1.0);
        $this->assertIsArray($unreviewedDeal);
        $unreviewedRevenue = (float) $unreviewed->getTotalRevenue() - 20_000_000_000.0;

        // The market is sized so the cleared target is half of what the same balance sheet buys unreviewed.
        $cleared = MergerAndAcquisitionEngine::maxClearedTargetShare(0.30, 0.09);
        $marketRevenue = 0.5 * $unreviewedRevenue / $cleared;
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $engine = new MergerAndAcquisitionEngine($this->marketEventPublisherMock, $this->debtEngineMock, $this->mathUtilityMock, $this->corporateMetricsMock, $ledger);
        $leader = $this->buildLedgeredAcquirer('LEAD', 4_000_000_000.0, 1_000_000_000.0, 100_000_000.0);
        $leader->setManagementStyle(ManagementStyle::Steward);
        $ledger->resolveIndustryCapacityRatio($leader, 0.30 * $marketRevenue, 0.30, 1.0, 0.0, 0.0, 10, 252);

        $deal = $engine->evaluatePrivateAcquisition($leader, $this->healthyMacro(), 1.0, 20, 252);
        $this->assertIsArray($deal);
        $acquiredRevenue = (float) $leader->getTotalRevenue() - 20_000_000_000.0;

        $this->assertEqualsWithDelta($cleared * $marketRevenue, $acquiredRevenue, 1.0, 'the review, not the balance sheet, sized the deal');
        $this->assertStringEndsWith('Sized to ' . MergerAndAcquisitionEngine::LIMIT_MERGER_REVIEW . '.', $deal['event']['description']);
        $this->assertStringNotContainsString('Sized to', $unreviewedDeal['event']['description'], 'a deal its buyer sized states no rule');
        $this->assertLessThan((float) $unreviewedDeal['spent'], (float) $deal['spent'], 'and the price follows the target');
        $market = $ledger->describeMergerMarket($leader, $this->healthyMacro(), 0.0, 30, 252);
        $this->assertEqualsWithDelta(0.30 + $cleared, $market['acquirer_share'], 1e-9);
        $this->assertEqualsWithDelta(0.0100, $market['herfindahl'] - 0.09 - ($cleared * $cleared), 1e-9);
    }

    /**
     * The announcement return is the deal's NPV to the acquirer over its market value (Moeller, Schlingemann &
     * Stulz 2004): the target's standalone value with its synergies, less the price. A steward paying no
     * premium for a 10% synergy gains 10% of the price, with no 2% floor under a small deal; an empire
     * builder paying a premium above the synergy destroys value and falls by exactly what it destroyed.
     */
    public function testTheAnnouncementReturnIsTheDealsNpvToTheAcquirer(): void
    {
        $this->primeHealthyDeal(operatingBase: 15_000_000_000.0);
        $this->bookIssuedDebt();

        foreach ([ManagementStyle::Steward, ManagementStyle::EmpireBuilder] as $style) {
            $acquirer = $this->buildLedgeredAcquirer('NPV' . strtoupper(substr($style->value, 0, 1)), 4_000_000_000.0, 1_000_000_000.0, 100_000_000.0);
            $acquirer->setManagementStyle($style);
            $marketCap = 100.0 * 100_000_000.0;
            $hubris = $acquirer->getManagementProfile()->hubrisPremium();

            $deal = $this->engine->evaluatePrivateAcquisition($acquirer, $this->healthyMacro(), 1.0);

            $this->assertIsArray($deal, $style->value);
            $npv = ((float) $deal['spent'] * 1.10 / (1.0 + $hubris)) - (float) $deal['spent'];
            $this->assertEqualsWithDelta($npv / $marketCap, $deal['shock'], 1e-12, "{$style->value}: the deal's NPV over market value");
            if ($style === ManagementStyle::Steward) {
                $this->assertGreaterThan(0.0, $deal['shock']);
            } else {
                $this->assertGreaterThan(0.10, $hubris, 'the fixture needs a premium above the synergy');
                $this->assertLessThan(0.0, $deal['shock'], 'a premium above the synergy is value destroyed');
            }
        }
    }

    /** A market the roster already owns has nothing left off the board to buy: no deal and no cash spent. */
    public function testAnAcquirerWhoseMarketHasNoFringeLeftBuysNothing(): void
    {
        $this->primeHealthyDeal(operatingBase: 15_000_000_000.0);
        $this->bookIssuedDebt();
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $engine = new MergerAndAcquisitionEngine($this->marketEventPublisherMock, $this->debtEngineMock, $this->mathUtilityMock, $this->corporateMetricsMock, $ledger);

        // At 5% the review would clear a target of a tenth of the market; the rival holds the other 95%.
        $acquirer = $this->buildLedgeredAcquirer('SMAL', 4_000_000_000.0, 1_000_000_000.0, 100_000_000.0);
        $acquirer->setManagementStyle(ManagementStyle::Steward);
        $ledger->resolveIndustryCapacityRatio($acquirer, 20_000_000_000.0, 0.05, 1.0, 0.0, 0.0, 10, 252);
        $rival = (new Stock())->setTicker('HOLD')->setIndustry('Technology');
        $ledger->resolveIndustryCapacityRatio($rival, 380_000_000_000.0, 0.95, 1.0, 0.0, 0.0, 12, 252);
        $this->assertEqualsWithDelta(0.0, $ledger->describeMergerMarket($acquirer, $this->healthyMacro(), 0.0, 20, 252)['fringe_share'], 1e-12);

        $this->assertNull($engine->evaluatePrivateAcquisition($acquirer, $this->healthyMacro(), 1.0, 20, 252));
        $this->assertSame('4000000000', $acquirer->getCorporateTreasury());
        $this->assertSame('20000000000', $acquirer->getTotalRevenue());
    }

    /**
     * The lender prices the next deal on what the firm actually earned over the last twelve months, as the
     * treasury's own lender test does. Reading the structural margin kept a firm that was losing money
     * creditworthy; one annualized quarter made its credit flip with the seasons.
     */
    public function testAcquisitionCreditIsUnderwrittenOnTheTrailingYear(): void
    {
        $this->primeHealthyDeal(operatingBase: 15_000_000_000.0);
        $health = $this->debtEngineMock->analyzeDebtHealth(new Stock(), $this->healthyMacro());

        $stock = $this->buildLedgeredAcquirer('RPTM', 4_000_000_000.0, 1_000_000_000.0, 100_000_000.0);

        $debtEngine = $this->createMock(DebtEngine::class);
        $debtEngine->expects($this->once())
            ->method('analyzeTrailingDebtHealth')
            ->with($stock, $this->anything())
            ->willReturn($health);
        $debtEngine->expects($this->never())->method('analyzeDebtHealth');
        $engine = new MergerAndAcquisitionEngine($this->marketEventPublisherMock, $debtEngine, $this->mathUtilityMock, $this->corporateMetricsMock);

        $engine->evaluatePrivateAcquisition($stock, $this->healthyMacro(), 1.0);
    }

    /**
     * Credit agreements measure a buyer pro forma, as if it had owned the target all year: every trailing
     * quarter gains the same quarter-share of the acquired business, so the window's revenue grows by exactly
     * what the deal added to the run-rate.
     */
    public function testAnAcquisitionRestatesTheTrailingYearProForma(): void
    {
        $stock = $this->buildLedgeredAcquirer('PROF', treasury: 4_000_000_000.0, debt: 1_000_000_000.0, shares: 100_000_000.0);
        $history = [
            ['revenue' => 4_000_000_000.0, 'ebit' => 900_000_000.0],
            ['revenue' => 5_000_000_000.0, 'ebit' => 1_300_000_000.0],
            ['revenue' => 4_500_000_000.0, 'ebit' => 1_000_000_000.0],
            ['revenue' => 6_500_000_000.0, 'ebit' => 1_800_000_000.0],
        ];
        $stock->setQuarterlyOperatingHistory($history);
        $this->primeHealthyDeal(operatingBase: 15_000_000_000.0);
        $this->bookIssuedDebt();
        $revenueBefore = (float) $stock->getTotalRevenue();

        $this->assertIsArray($this->engine->evaluatePrivateAcquisition($stock, $this->healthyMacro(), 1.0));

        $acquiredRevenue = (float) $stock->getTotalRevenue() - $revenueBefore;
        $this->assertGreaterThan(0.0, $acquiredRevenue);
        $restated = $stock->getQuarterlyOperatingHistory();
        $this->assertCount(4, $restated);
        $ebitAdded = $restated[0]['ebit'] - $history[0]['ebit'];
        $this->assertGreaterThan(0.0, $ebitAdded);
        foreach ($restated as $i => $quarter) {
            $this->assertEqualsWithDelta($history[$i]['revenue'] + ($acquiredRevenue / 4.0), $quarter['revenue'], 1.0);
            $this->assertEqualsWithDelta($history[$i]['ebit'] + $ebitAdded, $quarter['ebit'], 1.0);
        }
    }

    /**
     * ...and a seller as if the business it sold had never been its own.
     */
    public function testADivestitureRestatesTheTrailingYearProForma(): void
    {
        $stock = $this->buildLedgeredAcquirer('PROD', treasury: 1_000_000_000.0, debt: 4_000_000_000.0, shares: 10_000_000.0);
        $stock->setOperatingMargin('-0.05');
        $stock->setEarningsPerShare('-0.10');
        $stock->setTotalNetIncome('-1000000000');
        $stock->setRoicTtm('0.00');
        $history = [
            ['revenue' => 5_000_000_000.0, 'ebit' => -100_000_000.0],
            ['revenue' => 4_000_000_000.0, 'ebit' => -300_000_000.0],
            ['revenue' => 5_500_000_000.0, 'ebit' => 200_000_000.0],
            ['revenue' => 5_500_000_000.0, 'ebit' => -200_000_000.0],
        ];
        $stock->setQuarterlyOperatingHistory($history);
        $this->primeDistressedDivestiture(operatingBase: 10_000_000_000.0, fraction: 0.25);

        $this->assertIsArray($this->engine->evaluateCorporateDivestiture($stock, new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, nominalGdpIndex: 1.0), 1.0));

        foreach ($stock->getQuarterlyOperatingHistory() as $i => $quarter) {
            $this->assertEqualsWithDelta($history[$i]['revenue'] * 0.75, $quarter['revenue'], 1.0);
            $this->assertEqualsWithDelta($history[$i]['ebit'] * 0.75, $quarter['ebit'], 1.0);
        }
    }

    /**
     * The division sold is a pro rata slice of the firm, so the business kept earns the margin and return it
     * did before; a distressed seller is not made more efficient by selling. The sale fetches less than the
     * book value it gives up, and the headline says it booked a loss rather than a negative gain.
     */
    public function testADistressSaleLeavesTheKeptBusinessAsItWasAndReportsItsLoss(): void
    {
        $stock = $this->buildLedgeredAcquirer('SHED', treasury: 1_000_000_000.0, debt: 4_000_000_000.0, shares: 10_000_000.0);
        $stock->setOperatingMargin('-0.05');
        $stock->setEarningsPerShare('-0.10');
        $stock->setTotalNetIncome('-1000000000');
        $stock->setRoicTtm('0.00');
        $this->primeDistressedDivestiture(operatingBase: 10_000_000_000.0, fraction: 0.25);

        $published = null;
        $publisher = $this->createStub(MarketEventPublisher::class);
        $publisher->method('publish')->willReturnCallback(static function (Stock $seller, string $type, string $description) use (&$published): array {
            $published = $description;

            return [];
        });
        $engine = new MergerAndAcquisitionEngine($publisher, $this->debtEngineMock, $this->mathUtilityMock, $this->corporateMetricsMock);

        $marginBefore = $stock->getOperatingMargin();
        $roicBefore = $stock->getBaselineRoic();
        $retainedBefore = (float) $stock->getRetainedEarnings();

        $this->assertIsArray($engine->evaluateCorporateDivestiture($stock, new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, nominalGdpIndex: 1.0), 1.0));

        $this->assertSame($marginBefore, $stock->getOperatingMargin());
        $this->assertSame($roicBefore, $stock->getBaselineRoic());

        $loss = $retainedBefore - (float) $stock->getRetainedEarnings();
        $this->assertGreaterThan(0.0, $loss, 'a fire sale below book is a loss');
        $this->assertStringContainsString('booking a $' . number_format($loss / 1_000_000_000, 1) . 'B loss on sale', (string) $published);
        $this->assertStringNotContainsString('$-', (string) $published);
    }

    /**
     * The seller's announcement return is the sale's worth to its shareholders over their stake: the price
     * received less the pro-rata market value of the division given up, struck at the price before the sale.
     */
    public function testTheSellersAnnouncementReturnIsTheSalesGainOverItsMarketValue(): void
    {
        $this->primeDistressedDivestiture(operatingBase: 10_000_000_000.0, fraction: 0.25);
        $stock = $this->buildLedgeredAcquirer('SELL', treasury: 1_000_000_000.0, debt: 4_000_000_000.0, shares: 10_000_000.0);
        $stock->setPrice('5.0');
        $stock->setOperatingMargin('-0.05');
        $stock->setEarningsPerShare('-0.10');
        $stock->setTotalNetIncome('-1000000000');
        $stock->setRoicTtm('0.00');
        $cashBefore = (float) $stock->getCorporateTreasury();
        $marketCap = 5.0 * 10_000_000.0;

        $sale = $this->engine->evaluateCorporateDivestiture($stock, new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, nominalGdpIndex: 1.0), 1.0);

        $this->assertIsArray($sale);
        $proceeds = (float) $stock->getCorporateTreasury() - $cashBefore;
        $this->assertGreaterThan(0.0, $proceeds);
        $this->assertEqualsWithDelta(($proceeds - (0.25 * $marketCap)) / $marketCap, $sale['shock'], 1e-9);
    }

    /**
     * A forced seller with nothing to sell on earnings fetches the market's value of the slice less the fire-sale
     * discount, whatever its book says: a firm written down far below book gains nothing from the sale, and its
     * shareholders lose the discount on the slice sold.
     */
    public function testAFireSaleLosesTheDiscountOnTheSliceWhateverTheSellersBook(): void
    {
        $this->primeDistressedDivestiture(operatingBase: 10_000_000_000.0, fraction: 0.25);
        $shock = function (float $price): float {
            $stock = $this->buildLedgeredAcquirer('SELL', treasury: 1_000_000_000.0, debt: 4_000_000_000.0, shares: 10_000_000.0);
            $stock->setPrice((string) $price);
            $stock->setOperatingMargin('-0.05');
            $stock->setEarningsPerShare('-0.10');
            $stock->setTotalNetIncome('-1000000000');
            $stock->setRoicTtm('0.00');

            return $this->engine->evaluateCorporateDivestiture($stock, new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, nominalGdpIndex: 1.0), 1.0)['shock'];
        };

        $expected = -0.25 * MergerAndAcquisitionEngine::DIV_FIRE_SALE_DISCOUNT;
        $this->assertEqualsWithDelta($expected, $shock(5.0), 1e-9, 'written down far below book');
        $this->assertEqualsWithDelta($expected, $shock(2_000.0), 1e-9, 'valued far above book');
    }

    private function buildLedgeredAcquirer(string $ticker, float $treasury, float $debt, float $shares): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setName("{$ticker} Corp");
        $stock->setIndustry('Technology');
        $stock->setPrice('100.00');
        $stock->setSharesOutstanding((string) $shares);
        $stock->setCorporateTreasury((string) $treasury);
        $stock->setWholesaleDebt((string) $debt);
        $stock->setTotalRevenue('20000000000');
        $stock->setOperatingMargin('0.25');
        $stock->setRetainedEarnings('5000000000');
        $stock->setEarningsPerShare('1.00');
        $stock->setBaselineRoic('0.15');
        $stock->setRoicTtm('0.15');

        $stock->setReceivables('3000000000');
        $stock->setReceivablesAllowance('50000000');
        $stock->setInventory('2000000000');
        $stock->setPayables('1500000000');
        $stock->setGrossPpe('12000000000');
        $stock->setAccumulatedDepreciation('4000000000');
        $stock->setPpeTaxBasis('6000000000');
        $stock->setCipBalance('500000000');
        $stock->setGoodwill('1000000000');
        $stock->setDeferredTaxLiability('400000000');

        $stock->setTotalEquity((string) ($stock->getTotalAssets() - $stock->getTotalLiabilities()));

        return $stock;
    }

    private function primeHealthyDeal(float $operatingBase, bool $canIssueDebt = true, bool $hasLeverageHeadroom = true, float $ebit = 5_000_000_000.0): void
    {
        $debtMetrics = new DebtMetricsDTO(
            interestExpense: 50000000.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.015,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: $ebit,
            revenue: 20000000000.0,
            depreciation: 500000000.0,
            ebitda: $ebit + 500000000.0
        );
        $health = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.035,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 100.0,
            wantsToPaydownDebt: false,
            canIssueDebt: $canIssueDebt,
            debtTolerance: 1.5,
            wacc: 0.06,
            costOfEquity: 0.08,
            leveredBeta: 1.0,
            rawMetrics: $debtMetrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false,
            hasLeverageHeadroom: $hasLeverageHeadroom,
            netDebtToEbitda: $hasLeverageHeadroom ? 0.2 : 4.0,
            ebitdaCovenantLimit: 2.0
        );

        $this->debtEngineMock->method('analyzeDebtHealth')->willReturn($health);
        $this->debtEngineMock->method('analyzeTrailingDebtHealth')->willReturn($health);
        // The capital a financial acquirer is held to is balance-sheet arithmetic, read off the real engine.
        $capitalBase = new DebtEngine(new MathUtility(), new CorporateMetrics(), null, null);
        $this->debtEngineMock->method('resolveTangibleCapitalBase')->willReturnCallback(
            static fn (Stock $stock, float $revenue): array => $capitalBase->resolveTangibleCapitalBase($stock, $revenue)
        );
        $this->corporateMetricsMock->method('calculateOperatingBase')->willReturn($operatingBase);
        $this->mathUtilityMock->method('calculateManagementFairValuePE')->willReturn(15.0);
        $this->mathUtilityMock->method('calculateLogNormalSynergy')->willReturn(1.10);
        $this->mathUtilityMock->method('checkProbability')->willReturn(true);
        $this->mathUtilityMock->method('generateUniformBetween')->willReturn(0.80);
        // The announcement comes back as published, so a test can read what the deal said about itself.
        $this->marketEventPublisherMock->method('publish')->willReturnCallback(
            static fn (mixed $asset, string $type, string $description, float $changePercent): array => ['type' => $type, 'description' => $description, 'change_percent' => $changePercent]
        );
    }

    private function primeDistressedDivestiture(float $operatingBase, float $fraction): void
    {
        $debtMetrics = new DebtMetricsDTO(
            interestExpense: 1000000000.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.02,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: -1500000000.0,
            revenue: 20000000000.0,
            depreciation: 1000000000.0,
            ebitda: -500000000.0
        );
        $health = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.02,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: -1.5,
            wantsToPaydownDebt: true,
            canIssueDebt: false,
            debtTolerance: 1.0,
            wacc: 0.12,
            costOfEquity: 0.14,
            leveredBeta: 1.5,
            rawMetrics: $debtMetrics,
            isLiquidityCrisis: true,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        );

        $this->debtEngineMock->method('analyzeDebtHealth')->willReturn($health);
        $this->debtEngineMock->method('analyzeTrailingDebtHealth')->willReturn($health);
        $this->corporateMetricsMock->method('calculateOperatingBase')->willReturn($operatingBase);
        $this->corporateMetricsMock->method('calculateScaleRatio')->willReturn(0.10);
        $this->mathUtilityMock->method('checkProbability')->willReturn(true);
        // One draw serves the divested fraction and every other uniform the deal takes.
        $this->mathUtilityMock->method('generateUniformBetween')->willReturn($fraction);
        $this->marketEventPublisherMock->method('publish')->willReturn(['event_type' => 'DIVESTITURE']);
    }

    /** Assets equal liabilities plus equity. The lease is the same figure on both sides, so it is left out. */
    private function assertBalanced(Stock $stock, string $context): void
    {
        $assets = $stock->getTotalAssets();
        $claims = $stock->getTotalLiabilities() + (float) $stock->getTotalEquity();
        $this->assertEqualsWithDelta($assets, $claims, max(1.0, abs($assets) * 1e-9), "Balance sheet failed to balance after {$context}");
    }

    /**
     * A divested banking division takes its loans, its share of the allowance, its deposits and its cash
     * reserves with it. The seller's equity moves by the gain or loss against that book value, and the sheet
     * that remains still balances.
     */
    public function testLenderDivestitureShedsBookDepositsAndCashProRataAndBalances(): void
    {
        $stock = new Stock();
        $stock->setTicker('DIVB');
        $stock->setName('Divesting Bank');
        $stock->setIndustry('Banks - Diversified');
        $stock->setPrice('20.00');
        $stock->setSharesOutstanding('1000000000');
        $stock->setTotalRevenue('4000000000');
        $stock->setOperatingMargin('-0.05');
        $stock->setEarningsPerShare('-0.10');
        $stock->setTotalNetIncome('-1000000000');
        $stock->setRetainedEarnings('2000000000');
        $stock->setRoeTtm('0.00');
        $stock->setBaselineRoe('0.08');
        $stock->setCorporateTreasury('3000000000');
        $stock->setCustomerDeposits('40000000000');
        $stock->setWholesaleDebt('5000000000');
        $stock->setEarningAssets('50000000000');
        $stock->setCreditLossAllowance('1000000000');
        $stock->setTotalEquity((string) ($stock->getTotalAssets() - $stock->getTotalLiabilities()));
        $this->assertBalanced($stock, 'the fixture must open balanced');

        $macroState = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.20, yield5yEma: 0.04, nominalGdpIndex: 1.0);
        // A large operating base keeps $3B of reserves from reading as a cash fortress that would call off the sale.
        $this->primeDistressedDivestiture(operatingBase: 40_000_000_000.0, fraction: 0.25);

        $equityBefore = (float) $stock->getTotalEquity();
        $treasuryBefore = (float) $stock->getCorporateTreasury();
        $bookValueDisposed = 0.25 * ($stock->getNetEarningAssets() + $treasuryBefore - 40_000_000_000.0);
        $debtShed = 5_000_000_000.0 * 0.25;

        $result = $this->engine->evaluateCorporateDivestiture($stock, $macroState, 1.0);
        $this->assertIsArray($result);

        $this->assertEqualsWithDelta(50_000_000_000.0 * 0.75, (float) $stock->getEarningAssets(), 1.0, 'the book leaves pro rata');
        $this->assertEqualsWithDelta(1_000_000_000.0 * 0.75, (float) $stock->getCreditLossAllowance(), 1.0, 'and so does its allowance');
        $this->assertEqualsWithDelta(40_000_000_000.0 * 0.75, (float) $stock->getCustomerDeposits(), 1.0, 'the deposits that funded it go to the buyer');
        $this->assertEqualsWithDelta(5_000_000_000.0 * 0.75, (float) $stock->getWholesaleDebt(), 1.0);

        // Cash: the division's reserves left, the proceeds arrived.
        $proceeds = (float) $stock->getCorporateTreasury() - ($treasuryBefore * 0.75);
        $this->assertGreaterThan(0.0, $proceeds);
        $expectedGain = $proceeds - ($bookValueDisposed - $debtShed);
        $this->assertEqualsWithDelta($equityBefore + $expectedGain, (float) $stock->getTotalEquity(), 1.0, 'equity moves by the gain or loss on the book value sold');

        $this->assertBalanced($stock, 'a lender divestiture');
    }
}
