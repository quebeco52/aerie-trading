<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\BiotechBusinessModel;
use PHPUnit\Framework\TestCase;

class BiotechBusinessModelTest extends TestCase
{
    public function testPatentCliffAndBlockbusterAssetDepreciationDecay(): void
    {
        $model = new BiotechBusinessModel();

        // Exact replacement R&D reinvestment (R = 1.0): Zero drift
        $stock = new Stock();
        $stock->setOperatingMargin('0.30');
        $model->applyAssetDepreciationDecay($stock, 1.0, 0.25);
        $this->assertEquals('0.30', $stock->getOperatingMargin());

        // Patent Cliff Underinvestment (R = 0.5): Margin erodes toward generic floor (0.08)
        $stock->setOperatingMargin('0.30');
        $model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $decayed = (float) $stock->getOperatingMargin();
        $this->assertLessThan(0.30, $decayed);
        $this->assertGreaterThanOrEqual(BiotechBusinessModel::MIN_OPERATING_MARGIN_FLOOR, $decayed);

        // Blockbuster Super-Cycle Overinvestment (R = 1.5): Margin expands toward biologic ceiling (0.50)
        $stock->setOperatingMargin('0.30');
        $model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $expanded = (float) $stock->getOperatingMargin();
        $this->assertGreaterThan(0.30, $expanded);
        $this->assertLessThanOrEqual(BiotechBusinessModel::MAX_OPERATING_MARGIN_CEILING, $expanded);
    }

    public function testContinuousPipelineProgressImpactsVariableCosts(): void
    {
        $model = new BiotechBusinessModel();
        $stock = new Stock();
        $stock->setTicker('BIO');
        $stock->setBeta('1.0');

        $mathUtilityMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
        // Sequence of generateStandardNormal calls:
        // 1. establishedZ = 0.0
        // 2. pipelineZ = 1.5 (Positive clinical pipeline progress)
        // 3. trialZ = 0.0 (No binary landmark tail event)
        $mathUtilityMock->expects($this->exactly(3))
            ->method('generateStandardNormal')
            ->willReturnOnConsecutiveCalls(0.0, 1.5, 0.0);

        $macroState = \App\DTO\MacroStateDTO::fromArray(['output_gap_ema' => 0.0, 'inflation_ema' => 0.02]);
        $result = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.30,
            100.0,
            0.20,
            $macroState,
            $mathUtilityMock
        );

        // Continuous pipeline shift = -0.015 * 1.5 * 0.30 = -0.00675
        // Realized variable margin = 0.30 - 0.00675 = 0.29325
        $this->assertLessThan(0.30, $result->clampedMargin);
        $this->assertEqualsWithDelta(0.29325, $result->clampedMargin, 0.0001);
        $this->assertNull($result->eventType);
    }

    public function testBiotechResearchBurnValuation(): void
    {
        $model = new BiotechBusinessModel();

        $revenueFloorValue = 50.0;
        $peFairValue = 100.0;
        $fcfPerShare = -1.50; // Negative FCF due to R&D clinical trial burn
        $liveWacc = 0.09;
        $mathUtility = new MathUtility();

        $fairValue = $model->calculateEarningsValue(
            $revenueFloorValue,
            $peFairValue,
            $fcfPerShare,
            $liveWacc,
            $mathUtility
        );

        $expected = max(50.0, 100.0 * BiotechBusinessModel::BIOTECH_RESEARCH_BURN_DISCOUNT);
        $this->assertEquals($expected, $fairValue);
    }

    public function testCoverageProfileAndModelTraits(): void
    {
        $model = new BiotechBusinessModel();
        $stock = new Stock();
        $stock->setTicker('BIO');

        $this->assertEquals(0.15, $model->getReversionSpeed());
        $this->assertEquals(0.015, $model->getMoatSpread());
        $this->assertEquals(0.125, $model->getCapExCompletionRate($stock));
        $this->assertEquals(0.04, $model->getSecularGrowthRate($stock));

        $weights = $model->getSurpriseBlendWeights();
        $this->assertEquals(0.20, $weights['eps_weight']);
        $this->assertEquals(0.80, $weights['revenue_weight']);

        $coverage = $model->getCoverageProfile($stock);
        $this->assertEquals(BiotechBusinessModel::BASE_COVERAGE_VISIBILITY, $coverage->baseVisibility);
        $this->assertEquals(BiotechBusinessModel::BASE_COVERAGE_ERROR, $coverage->errorStdDev);
        $this->assertEquals(BiotechBusinessModel::BASE_COVERAGE_MIN_VISIBILITY, $coverage->minVisibility);
        $this->assertEquals(BiotechBusinessModel::EVENT_BASE_VISIBILITY, $coverage->eventBaseVisibility);
        $this->assertEquals(BiotechBusinessModel::EVENT_MIN_VISIBILITY, $coverage->eventMinVisibility);
    }

    public function testPartitionedStreamVariance(): void
    {
        $model = new BiotechBusinessModel();
        $stock = new Stock();
        $stock->setTicker('BIO');
        $stock->setBeta('1.0');

        $mathUtilityMock = $this->createStub(MathUtility::class);
        $mathUtilityMock->method('generatePersistentZ')->willReturnOnConsecutiveCalls(1.0, 1.0, 0.0);

        $macro = new \App\DTO\MacroStateDTO();
        $result = $model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $mathUtilityMock
        );

        // Commercial shock = 1.0 * (0.10 * 0.15) = +0.015 (+1.5%)
        // Pipeline shock = 1.0 * (0.10 * 0.45) = +0.045 (+4.5%)
        // Established revenue = 70M * 1.015 = 71.05M
        // Pipeline revenue = 30M * 1.045 = 31.35M
        $this->assertEqualsWithDelta(71_050_000.0, $result->streamRevenue['commercial_therapeutics'], 1.0);
        $this->assertEqualsWithDelta(31_350_000.0, $result->streamRevenue['pipeline_licensing_milestones'], 1.0);
    }
}
