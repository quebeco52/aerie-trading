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
        $this->mathUtilityMock->method('calculateIntrinsicFairValuePE')->willReturn(15.0);
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
        $this->mathUtilityMock->method('calculateIntrinsicFairValuePE')->willReturn(15.0);
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
        $this->corporateMetricsMock->method('calculateMarketShare')->willReturn(0.10);
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
}
