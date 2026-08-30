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
        $this->assertContains('Yield Curve & NIM Spread (90 bps)', $driverLabels);
        $this->assertContains('Commercial Loan Demand', $driverLabels);
        $this->assertContains('Credit Spread & CECL Reserves', $driverLabels);
        $this->assertContains('Operational Headwinds', $driverLabels);
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
