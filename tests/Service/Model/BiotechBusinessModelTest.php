<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\BiotechBusinessModel;
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
        $this->assertLessThan(1000.0 * 0.30, $result->actualVariableCosts);
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
}
