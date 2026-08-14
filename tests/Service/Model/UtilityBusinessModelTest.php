<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\UtilityBusinessModel;
use PHPUnit\Framework\TestCase;

class UtilityBusinessModelTest extends TestCase
{
    public function testMerchantSparkSpreadImpactsVariableCosts(): void
    {
        $model = new UtilityBusinessModel();
        $stock = new Stock();
        $stock->setTicker('UTIL');
        $stock->setBeta('0.5');

        $mathUtilityMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
        // Sequence of generateStandardNormal calls:
        // 1. regulatedZ = 0.0
        // 2. unregulatedZ = 2.0 (Positive merchant wholesale spark spread readout)
        // 3. eventZ = 0.0
        $mathUtilityMock->expects($this->exactly(3))
            ->method('generateStandardNormal')
            ->willReturnOnConsecutiveCalls(0.0, 2.0, 0.0);

        $macroState = \App\DTO\MacroStateDTO::fromArray(['inflation_ema' => 0.02]);
        $result = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.40,
            100.0,
            0.20,
            $macroState,
            $mathUtilityMock
        );

        // Positive unregulated trading Z increases merchant revenue
        $this->assertGreaterThan(1000.0, $result->actualRevenue);
    }

    public function testRateBaseCapexBurnValuation(): void
    {
        $model = new UtilityBusinessModel();

        $revenueFloorValue = 80.0;
        $peFairValue = 100.0;
        $fcfPerShare = -0.50; // Negative FCF due to rate-base T&D expansion CapEx
        $liveWacc = 0.06;
        $mathUtility = new MathUtility();

        $fairValue = $model->calculateEarningsValue(
            $revenueFloorValue,
            $peFairValue,
            $fcfPerShare,
            $liveWacc,
            $mathUtility
        );

        $expected = max(80.0, 100.0 * UtilityBusinessModel::UTILITY_CAPEX_BURN_DISCOUNT);
        $this->assertEquals($expected, $fairValue);
    }

    public function testUtilityIsUnderLeveraged(): void
    {
        $model = new UtilityBusinessModel();

        // Ke > Kd + 0.01, ICR >= 2.5, debtRatio < tolerance * 0.70 -> True
        $this->assertTrue(
            $model->isUnderLeveraged(
                0.30,
                0.60,
                3.0,
                2.0,
                0.08,
                0.05
            )
        );

        // ICR < 2.5 -> False
        $this->assertFalse(
            $model->isUnderLeveraged(
                0.30,
                0.60,
                2.0,
                2.0,
                0.08,
                0.05
            )
        );
    }
}
