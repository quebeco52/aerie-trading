<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\DTO\EarningsSimulationContext;
use App\DTO\MacroStateDTO;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\EventSubscriber\EarningsReportSubscriber;
use App\Service\Event\EarningsReportedEvent;
use App\Service\Event\ShockEvent;
use App\Service\Model\BusinessModelInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class EarningsReportSubscriberTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private EntityRepository&MockObject $reportRepository;
    private EarningsReportSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->reportRepository = $this->createMock(EntityRepository::class);

        $this->entityManager
            ->method('getRepository')
            ->willReturn($this->reportRepository);

        $this->subscriber = new EarningsReportSubscriber($this->entityManager);
    }

    public function testBuildsStreamDetailsForFirstQuarterReport(): void
    {
        $stock = new Stock();
        $stock->setTicker('SAFE');
        $stock->setIndustry('Security & Protection Services');
        $stock->setBeta('1.2');
        $stock->setVolatility('0.20');
        $stock->setTotalEquity('50000000.0000');
        $stock->setWholesaleDebt('10000000.0000');
        $stock->setCorporateTreasury('5000000.0000');
        $stock->setSharesOutstanding('10000000');
        $stock->setEarningsMomentumZ([
            'government_contracts' => 1.45,
            'corporate_retainers'  => -0.85,
            'expeditionary_ops'    => 2.10,
        ]);

        $macro = new MacroStateDTO(
            outputGapEma: 0.03,
            governmentSpendingIndexEma: 110.0,
            inflationEma: 0.035,
            marketVolatilityEma: 0.22,
            macroCreditSpreadEma: 0.03
        );

        $strategy = $this->createMock(BusinessModelInterface::class);

        $ctx = new EarningsSimulationContext(
            stock: $stock,
            macroState: $macro,
            strategy: $strategy,
            businessModel: 'security_protection'
        );
        $ctx->isFinancial = false;

        $ctx->actualRevenue = 10000000.0;
        $ctx->streamRevenue = [
            'government_contracts' => 5000000.0,
            'corporate_retainers'  => 3000000.0,
            'expeditionary_ops'    => 2000000.0,
        ];
        $ctx->reportedActualNetIncome = 1500000.0;
        $ctx->trueOperatingMargin = 0.20;
        $ctx->operatingCosts = 8000000.0;
        $ctx->ebit = 2000000.0;
        $ctx->preTaxIncome = 1900000.0;
        $ctx->taxPaid = 400000.0;
        $ctx->debtMetrics = new \App\DTO\DebtMetricsDTO(
            interestExpense: 400000.0,
            blendedRate: 0.04,
            historicalFixedRate: 0.04,
            dynamicSpread: 0.01,
            currentMarketRate: 0.04,
            wholesaleRate: 0.04,
            ebit: 2000000.0,
            revenue: 40000000.0,
            depreciation: 200000.0,
            ebitda: 2200000.0
        );

        // No previous report exists in DB
        $this->reportRepository
            ->method('findOneBy')
            ->willReturn(null);

        $persistedReport = null;
        $this->entityManager
            ->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function ($report) use (&$persistedReport) {
                $persistedReport = $report;
            });

        $this->subscriber->onEarningsReported(new EarningsReportedEvent($ctx));

        $this->assertInstanceOf(CorporateReport::class, $persistedReport);
        $streamDetails = $persistedReport->getStreamDetails();
        $this->assertIsArray($streamDetails);

        // Check government_contracts
        $this->assertArrayHasKey('government_contracts', $streamDetails);
        $gov = $streamDetails['government_contracts'];
        $this->assertEquals(5000000.0, $gov['revenue']);
        $this->assertEquals(0.5, $gov['share']);
        $this->assertEquals(0.0, $gov['qoq_delta']); // No previous report -> 0 QoQ delta

        // Check drivers were identified
        $driverLabels = array_column($gov['drivers'], 'label');
        $this->assertContains('Strong Operational Execution', $driverLabels);
        $this->assertContains('Fiscal & Defense Appropriations', $driverLabels);
        $this->assertContains('Cost-Plus Inflation Escalation', $driverLabels);

        // Check expeditionary_ops VIX fear premium
        $exp = $streamDetails['expeditionary_ops'];
        $expLabels = array_column($exp['drivers'], 'label');
        $this->assertContains('VIX Geopolitical Fear Premium', $expLabels);
        $this->assertContains('Credit Distress Demand', $expLabels);
    }

    /**
     * The quarterly report is a full three-statement filing, not just an income statement. Every balance
     * sheet and cash flow line the engine now tracks has to reach the persisted report, or the screener and
     * the fundamentals page are reading a statement with holes in it.
     */
    public function testPersistsTheFullBalanceSheetAndCashFlowStatement(): void
    {
        $stock = new Stock();
        $stock->setTicker('FULL');
        $stock->setIndustry('Auto Manufacturers');
        $stock->setBeta('1.0');
        $stock->setVolatility('0.20');
        $stock->setTotalEquity('50000000.0000');
        $stock->setWholesaleDebt('10000000.0000');
        $stock->setCorporateTreasury('5000000.0000');
        $stock->setSharesOutstanding('10000000');
        $stock->setTotalRevenue('40000000.0000');

        // A complete balance sheet: trade cycle, plant, construction, intangibles and deferred tax.
        $stock->setReceivables('6000000.0000');
        $stock->setReceivablesAllowance('500000.0000');
        $stock->setInventory('4000000.0000');
        $stock->setPayables('3000000.0000');
        $stock->setGrossPpe('30000000.0000');
        $stock->setAccumulatedDepreciation('12000000.0000');
        $stock->setCipBalance('2000000.0000');
        $stock->setGoodwill('1500000.0000');
        $stock->setDeferredTaxLiability('900000.0000');

        $strategy = $this->createMock(BusinessModelInterface::class);
        $strategy->method('getLeaseIntensity')->willReturn(0.05);

        $ctx = new EarningsSimulationContext(
            stock: $stock,
            macroState: new MacroStateDTO(),
            strategy: $strategy,
            businessModel: 'auto_manufacturer'
        );

        $ctx->actualRevenue = 10000000.0;
        $ctx->streamRevenue = ['core_business' => 10000000.0];
        $ctx->reportedActualNetIncome = 1500000.0;
        $ctx->actualQuarterlyNetIncome = 1500000.0;
        $ctx->trueOperatingMargin = 0.20;
        $ctx->operatingCosts = 7500000.0;
        $ctx->ebitda = 2500000.0;
        $ctx->ebit = 2000000.0;
        $ctx->quarterlyDepreciation = 500000.0;
        $ctx->preTaxIncome = 1900000.0;
        $ctx->taxPaid = 400000.0;
        $ctx->cashTaxPaid = 300000.0;
        $ctx->deferredTaxExpense = 100000.0;
        $ctx->inventoryWriteDown = 120000.0;
        $ctx->receivablesProvision = 80000.0;
        $ctx->stockCompensation = 60000.0;
        $ctx->goodwillImpairment = 40000.0;
        $ctx->operatingCashFlow = 2100000.0;
        $ctx->investingCashFlow = -900000.0;
        $ctx->financingCashFlow = -400000.0;
        $ctx->lifecycleStage = \App\Data\LifecycleStage::Mature;
        $ctx->debtMetrics = new \App\DTO\DebtMetricsDTO(
            interestExpense: 400000.0,
            blendedRate: 0.04,
            historicalFixedRate: 0.04,
            dynamicSpread: 0.01,
            currentMarketRate: 0.04,
            wholesaleRate: 0.04,
            ebit: 2000000.0,
            revenue: 40000000.0,
            depreciation: 500000.0,
            ebitda: 2500000.0
        );

        $this->reportRepository->method('findOneBy')->willReturn(null);

        $persisted = null;
        $this->entityManager->expects($this->once())->method('persist')
            ->willReturnCallback(function ($report) use (&$persisted) {
                $persisted = $report;
            });

        $this->subscriber->onEarningsReported(new EarningsReportedEvent($ctx));

        $this->assertInstanceOf(CorporateReport::class, $persisted);

        // Income statement below the depreciation line.
        $this->assertEqualsWithDelta(500000.0, (float) $persisted->getDepreciation(), 0.01);
        $this->assertEqualsWithDelta(2500000.0, (float) $persisted->getEbitda(), 0.01);

        // Balance sheet.
        $this->assertEqualsWithDelta(30000000.0, (float) $persisted->getGrossPpe(), 0.01);
        $this->assertEqualsWithDelta(18000000.0, (float) $persisted->getNetPpe(), 0.01);
        $this->assertEqualsWithDelta(5500000.0, (float) $persisted->getReceivables(), 0.01, 'receivables are reported net of the allowance');
        $this->assertEqualsWithDelta(4000000.0, (float) $persisted->getInventory(), 0.01);
        $this->assertEqualsWithDelta(3000000.0, (float) $persisted->getPayables(), 0.01);
        $this->assertEqualsWithDelta(2000000.0, (float) $persisted->getCip(), 0.01);
        $this->assertEqualsWithDelta(1500000.0, (float) $persisted->getGoodwill(), 0.01);
        $this->assertEqualsWithDelta(900000.0, (float) $persisted->getDeferredTaxLiability(), 0.01);
        // 5% of $40M annual revenue.
        $this->assertEqualsWithDelta(2000000.0, (float) $persisted->getLeaseLiability(), 0.01);

        // Cash flow statement and the life-cycle stage its signs imply.
        $this->assertEqualsWithDelta(2100000.0, (float) $persisted->getOperatingCashFlow(), 0.01);
        $this->assertEqualsWithDelta(-900000.0, (float) $persisted->getInvestingCashFlow(), 0.01);
        $this->assertEqualsWithDelta(-400000.0, (float) $persisted->getFinancingCashFlow(), 0.01);
        $this->assertEqualsWithDelta(60000.0, (float) $persisted->getStockCompensation(), 0.01);
        $this->assertEqualsWithDelta(40000.0, (float) $persisted->getGoodwillImpairment(), 0.01);
        $this->assertEqualsWithDelta(120000.0, (float) $persisted->getInventoryWriteDown(), 0.01);
        $this->assertEqualsWithDelta(80000.0, (float) $persisted->getReceivablesProvision(), 0.01);
        $this->assertEqualsWithDelta(300000.0, (float) $persisted->getCashTaxPaid(), 0.01);
        $this->assertEqualsWithDelta(100000.0, (float) $persisted->getDeferredTaxExpense(), 0.01);
        $this->assertSame('mature', $persisted->getLifecycleStage());
    }

    /**
     * The balance sheet has to balance. Assets are the things the firm owns; liabilities are the claims
     * against them; the difference is what the shareholders have. Anything left over is a plug, and a plug
     * is how a statement quietly stops meaning anything.
     */
    public function testAssetsEqualLiabilitiesPlusEquityOnThePersistedStatement(): void
    {
        $stock = new Stock();
        $stock->setTicker('BLNC');
        $stock->setIndustry('Auto Manufacturers');
        $stock->setSharesOutstanding('10000000');
        $stock->setTotalRevenue('40000000.0000');
        $stock->setCorporateTreasury('5000000.0000');
        $stock->setWholesaleDebt('10000000.0000');
        $stock->setReceivables('6000000.0000');
        $stock->setReceivablesAllowance('500000.0000');
        $stock->setInventory('4000000.0000');
        $stock->setPayables('3000000.0000');
        $stock->setGrossPpe('30000000.0000');
        $stock->setAccumulatedDepreciation('12000000.0000');
        $stock->setCipBalance('2000000.0000');
        $stock->setGoodwill('1500000.0000');
        $stock->setDeferredTaxLiability('900000.0000');

        $lease = 2000000.0; // 5% of annual revenue
        $assets = $stock->getTotalAssets($lease);
        $liabilities = $stock->getTotalLiabilities($lease);

        // Equity is whatever the asset side leaves over once every claim on it is met.
        $impliedEquity = $assets - $liabilities;
        $stock->setTotalEquity((string) $impliedEquity);

        $this->assertEqualsWithDelta(
            $assets,
            $liabilities + (float) $stock->getTotalEquity(),
            0.01,
            'assets must equal liabilities plus equity'
        );

        // And the asset side is genuinely the sum of its parts, not a single stored number.
        $this->assertEqualsWithDelta(
            5000000.0 + 5500000.0 + 4000000.0 + 18000000.0 + 2000000.0 + 1500000.0 + $lease,
            $assets,
            0.01
        );
    }

    /**
     * Before its first report opens the earning-asset ledger, a bank has no asset side to state, so its report
     * must not claim a total-assets figure: publishing a proxy would show a sheet out by the whole deposit
     * base. Liabilities are real either way.
     */
    public function testBalanceSheetBusinessReportsNoAssetSideBeforeItsLedgerIsOpen(): void
    {
        $stock = new Stock();
        $stock->setTicker('BNKR');
        $stock->setIndustry('Banks - Diversified');
        $stock->setSharesOutstanding('10000000');
        $stock->setTotalRevenue('40000000.0000');
        $stock->setTotalEquity('50000000.0000');
        $stock->setWholesaleDebt('10000000.0000');
        $stock->setCustomerDeposits('300000000.00');
        $stock->setCorporateTreasury('5000000.0000');
        // No plant ledger: gross PP&E stays null for a financial model.

        $strategy = $this->createMock(BusinessModelInterface::class);
        $strategy->method('getLeaseIntensity')->willReturn(0.0);

        $ctx = new EarningsSimulationContext(stock: $stock, macroState: new MacroStateDTO(), strategy: $strategy, businessModel: 'commercial_bank');
        $ctx->actualRevenue = 10000000.0;
        $ctx->streamRevenue = ['net_interest_income' => 10000000.0];
        $ctx->operatingCashFlow = 1000000.0;
        $ctx->debtMetrics = new \App\DTO\DebtMetricsDTO(1.0, 0.04, 0.04, 0.01, 0.04, 0.04, 1.0, 1.0, 0.0, 1.0);

        $this->reportRepository->method('findOneBy')->willReturn(null);
        $persisted = null;
        $this->entityManager->expects($this->once())->method('persist')->willReturnCallback(function ($r) use (&$persisted) { $persisted = $r; });

        $this->subscriber->onEarningsReported(new EarningsReportedEvent($ctx));

        $this->assertNull($persisted->getTotalAssets(), 'no asset side exists until the ledger is seeded');
        $this->assertNull($persisted->getAssetAge());
        $this->assertNull($persisted->getEarningAssets());
        $this->assertGreaterThan(300000000.0, (float) $persisted->getTotalLiabilities(), 'deposits are a real liability');
        $this->assertNotNull($persisted->getOperatingCashFlow(), 'the cash flow statement is still reported');
    }

    /**
     * Once the ledger is open a lender's report carries its own sheet: the book net of the allowance is the
     * asset side, deposits are stated on their own, and the margin the book earned is struck on it.
     */
    public function testLenderReportsItsBookAsTheAssetSide(): void
    {
        $stock = new Stock();
        $stock->setTicker('BNKL');
        $stock->setIndustry('Banks - Diversified');
        $stock->setSharesOutstanding('10000000');
        $stock->setTotalRevenue('40000000.0000');
        $stock->setWholesaleDebt('10000000.0000');
        $stock->setCustomerDeposits('300000000.00');
        $stock->setCorporateTreasury('5000000.0000');
        $stock->setEarningAssets('360000000.0000');
        $stock->setCreditLossAllowance('6000000.0000');
        $stock->setTotalEquity((string) ($stock->getTotalAssets() - $stock->getTotalLiabilities()));

        $strategy = $this->createMock(BusinessModelInterface::class);
        $strategy->method('getLeaseIntensity')->willReturn(0.0);
        $strategy->method('isFinancial')->willReturn(true);

        $ctx = new EarningsSimulationContext(stock: $stock, macroState: new MacroStateDTO(), strategy: $strategy, businessModel: 'commercial_bank');
        $ctx->actualRevenue = 10000000.0;
        $ctx->streamRevenue = ['net_interest_income' => 8000000.0, 'fee_income' => 2000000.0];
        $ctx->operatingCashFlow = 1000000.0;
        $ctx->creditLossProvision = 700000.0;
        $ctx->netChargeOffs = 500000.0;
        $ctx->netLoanOriginations = 3000000.0;
        $ctx->debtMetrics = new \App\DTO\DebtMetricsDTO(4000000.0, 0.04, 0.04, 0.01, 0.04, 0.04, 1.0, 1.0, 0.0, 1.0);

        $this->reportRepository->method('findOneBy')->willReturn(null);
        $persisted = null;
        $this->entityManager->expects($this->once())->method('persist')->willReturnCallback(function ($r) use (&$persisted) { $persisted = $r; });

        $this->subscriber->onEarningsReported(new EarningsReportedEvent($ctx));

        $this->assertEqualsWithDelta(5000000.0 + 360000000.0 - 6000000.0, (float) $persisted->getTotalAssets(), 0.01, 'cash plus the book net of the allowance');
        $this->assertNull($persisted->getAssetAge(), 'no plant, no plant age');
        $this->assertEqualsWithDelta(360000000.0, (float) $persisted->getEarningAssets(), 0.01);
        $this->assertEqualsWithDelta(6000000.0, (float) $persisted->getCreditLossAllowance(), 0.01);
        $this->assertEqualsWithDelta(700000.0, (float) $persisted->getCreditLossProvision(), 0.01);
        $this->assertEqualsWithDelta(500000.0, (float) $persisted->getNetChargeOffs(), 0.01);
        $this->assertEqualsWithDelta(3000000.0, (float) $persisted->getNetLoanOriginations(), 0.01);
        $this->assertEqualsWithDelta(300000000.0, (float) $persisted->getCustomerDeposits(), 0.01);
        $this->assertNull($persisted->getCet1Ratio(), 'only a real bank model can state a capital ratio');
        // (8.0M of interest earned - 1.0M of interest paid this quarter) / 354M net book, annualized.
        $this->assertEqualsWithDelta(((8000000.0 - 1000000.0) / 354000000.0) * 4.0, (float) $persisted->getNetInterestMargin(), 1e-4);
        $this->assertEqualsWithDelta((float) $persisted->getTotalAssets(), (float) $persisted->getTotalLiabilities() + (float) $persisted->getEquity(), 0.01, 'the statement balances');
    }

    public function testBuildsCommercialBankStreamDetails(): void
    {
        $stock = new Stock();
        $stock->setTicker('LAKE');
        $stock->setIndustry('Commercial Banking');
        $stock->setBeta('1.0');
        $stock->setVolatility('0.15');
        $stock->setTotalEquity('50000000000.0000');
        $stock->setWholesaleDebt('100000000000.0000');
        $stock->setCorporateTreasury('20000000000.0000');
        $stock->setSharesOutstanding('1000000000');
        $stock->setEarningsMomentumZ([
            'net_interest_income' => -1.77,
            'fee_income'          => 0.50,
            'proprietary_dividend'=> 1.20,
        ]);

        $macro = new MacroStateDTO(
            outputGapEma: 0.031,
            yield10yEma: 0.045,
            yield2yEma: 0.035,
            interbankLiquiditySpreadEma: 0.001,
            macroCreditSpreadEma: 0.024
        );

        $strategy = $this->createMock(BusinessModelInterface::class);

        $ctx = new EarningsSimulationContext(
            stock: $stock,
            macroState: $macro,
            strategy: $strategy,
            businessModel: 'commercial_bank'
        );
        $ctx->isFinancial = true;

        $ctx->actualRevenue = 300000000.0;
        $ctx->streamRevenue = [
            'net_interest_income' => 200000000.0,
            'fee_income'          => 60000000.0,
            'proprietary_dividend'=> 40000000.0,
        ];
        $ctx->reportedActualNetIncome = 80000000.0;
        $ctx->trueOperatingMargin = 0.35;
        $ctx->operatingCosts = 195000000.0;
        $ctx->ebit = 105000000.0;
        $ctx->preTaxIncome = 100000000.0;
        $ctx->taxPaid = 20000000.0;
        $ctx->debtMetrics = new \App\DTO\DebtMetricsDTO(
            interestExpense: 5000000.0,
            blendedRate: 0.03,
            historicalFixedRate: 0.03,
            dynamicSpread: 0.01,
            currentMarketRate: 0.03,
            wholesaleRate: 0.03,
            ebit: 105000000.0,
            revenue: 1200000000.0,
            depreciation: 0.0,
            ebitda: 105000000.0
        );

        $this->reportRepository
            ->method('findOneBy')
            ->willReturn(null);

        $persistedReport = null;
        $this->entityManager
            ->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function ($report) use (&$persistedReport) {
                $persistedReport = $report;
            });

        $this->subscriber->onEarningsReported(new EarningsReportedEvent($ctx));

        $this->assertInstanceOf(CorporateReport::class, $persistedReport);
        $streamDetails = $persistedReport->getStreamDetails();
        $this->assertArrayHasKey('net_interest_income', $streamDetails);
        $nii = $streamDetails['net_interest_income'];
        
        $driverLabels = array_column($nii['drivers'], 'label');
        // The spread is no longer baked into the label — it ships as a resolved reading instead,
        // so the same variable prints in the same unit here and everywhere else it appears.
        $this->assertContains('Yield Curve & NIM Spread', $driverLabels);
        $this->assertContains('Commercial Loan Demand', $driverLabels);
        $this->assertContains('Credit Spread & CECL Reserves', $driverLabels);
        $this->assertContains('Operational Headwinds', $driverLabels);

        $nimDriver = array_values(array_filter(
            $nii['drivers'],
            static fn (array $driver): bool => $driver['label'] === 'Yield Curve & NIM Spread',
        ))[0];
        $readingFields = array_column($nimDriver['readings'], 'field');
        $this->assertSame(['interbank_liquidity_spread_ema', 'yield_10y_ema', 'yield_2y_ema'], $readingFields);
        $this->assertContains($nimDriver['direction'], [-1, 0, 1]);
        $this->assertGreaterThanOrEqual(1, $nimDriver['strength']);
        $this->assertLessThanOrEqual(3, $nimDriver['strength']);
        // `impact` is an unpriced coefficient, so the field the panel may print must never be it.
        $this->assertArrayNotHasKey('fields', $nimDriver);

        // Strongest first — the panel prints the head of this list, so the ranking must be the
        // mix's, not the order the business-model switch happened to append drivers in.
        $impacts = array_map(static fn (array $d): float => abs((float) $d['impact']), $nii['drivers']);
        $sorted = $impacts;
        rsort($sorted);
        $this->assertSame($sorted, $impacts);
    }

    public function testCalculatesQoQDeltaAgainstPreviousReport(): void
    {
        $stock = new Stock();
        $stock->setTicker('AMZN');
        $stock->setIndustry('Internet Retail');
        $stock->setBeta('1.1');
        $stock->setVolatility('0.25');
        $stock->setTotalEquity('100000000.0000');
        $stock->setWholesaleDebt('20000000.0000');
        $stock->setCorporateTreasury('15000000.0000');
        $stock->setSharesOutstanding('50000000');

        $macro = new MacroStateDTO(
            outputGapEma: 0.02,
            consumerSentimentIndexEma: 108.0
        );

        $strategy = $this->createMock(BusinessModelInterface::class);

        $ctx = new EarningsSimulationContext(
            stock: $stock,
            macroState: $macro,
            strategy: $strategy,
            businessModel: 'internet_retail'
        );
        $ctx->isFinancial = false;

        $ctx->actualRevenue = 12000000.0;
        $ctx->streamRevenue = [
            'first_party_retail' => 6000000.0,
            'third_party_seller' => 4000000.0,
            'digital_ads_cloud'  => 2000000.0,
        ];
        $ctx->reportedActualNetIncome = 2000000.0;
        $ctx->trueOperatingMargin = 0.18;
        $ctx->operatingCosts = 9800000.0;
        $ctx->ebit = 2200000.0;
        $ctx->preTaxIncome = 2100000.0;
        $ctx->taxPaid = 100000.0;
        $ctx->debtMetrics = new \App\DTO\DebtMetricsDTO(
            interestExpense: 100000.0,
            blendedRate: 0.03,
            historicalFixedRate: 0.03,
            dynamicSpread: 0.01,
            currentMarketRate: 0.03,
            wholesaleRate: 0.03,
            ebit: 2200000.0,
            revenue: 48000000.0,
            depreciation: 300000.0,
            ebitda: 2500000.0
        );
        $ctx->eventType = ShockEvent::VIRAL_GROWTH;

        // Previous report with prior quarter streams
        $prevReport = new CorporateReport();
        $prevReport->setRevenueStreams([
            'first_party_retail' => 5000000.0, // 5M -> 6M (+20%)
            'third_party_seller' => 5000000.0, // 5M -> 4M (-20%)
            'digital_ads_cloud'  => 1600000.0, // 1.6M -> 2M (+25%)
        ]);

        $this->reportRepository
            ->method('findOneBy')
            ->willReturn($prevReport);

        $persistedReport = null;
        $this->entityManager
            ->expects($this->once())
            ->method('persist')
            ->willReturnCallback(function ($report) use (&$persistedReport) {
                $persistedReport = $report;
            });

        $this->subscriber->onEarningsReported(new EarningsReportedEvent($ctx));

        $this->assertInstanceOf(CorporateReport::class, $persistedReport);
        $streamDetails = $persistedReport->getStreamDetails();

        // 1P Retail: 5M to 6M is +20% QoQ
        $this->assertEquals(0.20, $streamDetails['first_party_retail']['qoq_delta']);
        // 3P Retail: 5M to 4M is -20% QoQ
        $this->assertEquals(-0.20, $streamDetails['third_party_seller']['qoq_delta']);
        // Ads: 1.6M to 2M is +25% QoQ
        $this->assertEquals(0.25, $streamDetails['digital_ads_cloud']['qoq_delta']);

        // Check shock event is attached
        $this->assertEquals('Viral Growth', $streamDetails['first_party_retail']['event']);
    }
}
