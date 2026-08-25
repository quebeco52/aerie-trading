<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\DTO\MacroStateDTO;
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
            $this->entityManagerMock,
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
        $this->mathUtilityMock->method('calculateIntrinsicFairValuePE')->willReturn(15.0);
        $this->mathUtilityMock->method('calculateLogNormalSynergy')->willReturn(1.10);
        $this->mathUtilityMock->method('checkProbability')->willReturn(true);
        $this->mathUtilityMock->method('generateUniformBetween')->willReturn(0.80);
        $this->marketEventPublisherMock->method('publish')->willReturn([]);

        $result = $this->engine->evaluatePrivateAcquisition($stock, $macroState, 1.0);

        $this->assertIsArray($result);
        $this->assertGreaterThan(0.0, (float) $stock->getTotalEquity());
        $this->assertGreaterThan(0.0, (float) $stock->getOperatingMargin());
        $this->assertGreaterThan(0.0, (float) $stock->getTotalRevenue());
    }
}
