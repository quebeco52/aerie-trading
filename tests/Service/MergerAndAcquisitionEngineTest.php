<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\DTO\MacroStateDTO;
use App\Data\ManagementStyle;
use App\Entity\Stock;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\MergerAndAcquisitionEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class MergerAndAcquisitionEngineTest extends TestCase
{
    private EntityManagerInterface&Stub $entityManagerMock;
    private MarketEventPublisher&Stub $marketEventPublisherMock;
    private DebtEngine&Stub $debtEngineMock;
    private MathUtility&Stub $mathUtilityMock;
    private CorporateMetrics&Stub $corporateMetricsMock;
    private MergerAndAcquisitionEngine $engine;

    protected function setUp(): void
    {
        $this->entityManagerMock = $this->createStub(EntityManagerInterface::class);
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

    public function testPrivateAcquisitionBlendsStructuralTurnoverCapitalWeighted(): void
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
        $this->corporateMetricsMock->method('calculateOperatingBase')->willReturn(15000000000.0);
        $this->mathUtilityMock->method('calculateManagementFairValuePE')->willReturn(15.0);
        $this->mathUtilityMock->method('calculateLogNormalSynergy')->willReturn(1.10);
        $this->mathUtilityMock->method('checkProbability')->willReturn(true);
        $this->mathUtilityMock->method('generateUniformBetween')->willReturn(0.80);
        $this->marketEventPublisherMock->method('publish')->willReturn([]);

        $result = $this->engine->evaluatePrivateAcquisition($stock, $macroState, 1.0);
        $this->assertIsArray($result);

        $spent = (float) $result['spent'];
        $this->assertGreaterThan(0.0, $spent);

        // The target's revenue per dollar of purchase price is what the engine booked into total revenue.
        $acquiredRevenue = (float) $stock->getTotalRevenue() - 20_000_000_000.0;
        $targetTurnover = $acquiredRevenue / $spent;
        $blended = (float) $stock->getAssetTurnover();

        // A capital-weighted average lies strictly between the two turnovers and moves off the acquirer's own.
        $this->assertNotEqualsWithDelta(2.0, $blended, 1e-6);
        $this->assertGreaterThanOrEqual(min(2.0, $targetTurnover) - 1e-6, $blended);
        $this->assertLessThanOrEqual(max(2.0, $targetTurnover) + 1e-6, $blended);
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

    private function primeHealthyDeal(float $operatingBase, bool $canIssueDebt = true, bool $hasLeverageHeadroom = true): void
    {
        $debtMetrics = new DebtMetricsDTO(
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
        $this->corporateMetricsMock->method('calculateOperatingBase')->willReturn($operatingBase);
        $this->mathUtilityMock->method('calculateManagementFairValuePE')->willReturn(15.0);
        $this->mathUtilityMock->method('calculateLogNormalSynergy')->willReturn(1.10);
        $this->mathUtilityMock->method('checkProbability')->willReturn(true);
        $this->mathUtilityMock->method('generateUniformBetween')->willReturn(0.80);
        $this->marketEventPublisherMock->method('publish')->willReturn([]);
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
        $this->corporateMetricsMock->method('calculateOperatingBase')->willReturn($operatingBase);
        $this->corporateMetricsMock->method('calculateScaleRatio')->willReturn(0.10);
        $this->mathUtilityMock->method('checkProbability')->willReturn(true);
        // One draw serves both the divested fraction and the fire-sale cents on the dollar.
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
