<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\ConsumerStaplesBusinessModel;
use PHPUnit\Framework\TestCase;

class ConsumerStaplesBusinessModelTest extends TestCase
{
    public function testCommodityInputElasticityShiftsVariableCosts(): void
    {
        $model = new ConsumerStaplesBusinessModel();
        $stock = new Stock();
        $stock->setTicker('STAPLE');
        $stock->setBeta('0.6');

        $mathUtilityMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
        // sequence: brandedZ=0, volumeZ=2.0 (commodity spike), eventZ=0, revenueError=0, recallError=0
        $mathUtilityMock->method('generateStandardNormal')
            ->willReturnOnConsecutiveCalls(0.0, 2.0, 0.0, 0.0, 0.0);

        $macroState = \App\DTO\MacroStateDTO::fromArray([]);
        $result = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.60,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock
        );

        // Commodity input cost shift increases realized variable cost percentage
        $this->assertGreaterThan(1000.0 * 0.60, $result->actualVariableCosts);
    }

    public function testBrandEquityAmortizationAndMarketingSuperCycle(): void
    {
        $model = new ConsumerStaplesBusinessModel();

        // R = 0.5 -> Brand equity erosion
        $stock = new Stock();
        $stock->setOperatingMargin('0.22');
        $model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $decayed = (float) $stock->getOperatingMargin();
        $this->assertLessThan(0.22, $decayed);
        $this->assertGreaterThanOrEqual(ConsumerStaplesBusinessModel::MIN_OPERATING_MARGIN_FLOOR, $decayed);

        // R = 1.5 -> Marketing super-cycle expands pricing power
        $stock->setOperatingMargin('0.22');
        $model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $expanded = (float) $stock->getOperatingMargin();
        $this->assertGreaterThan(0.22, $expanded);
        $this->assertLessThanOrEqual(ConsumerStaplesBusinessModel::MAX_OPERATING_MARGIN_CEILING, $expanded);
    }

    public function testWeaponizedProofDeskCommodityArbitrage(): void
    {
        $model = new ConsumerStaplesBusinessModel();
        $mathUtility = new MathUtility();

        $stock = new Stock();
        $stock->setTicker('PINT');
        $stock->setBeta('0.8');

        $normalMacro = new \App\DTO\MacroStateDTO(inflationEma: 0.02, energyPriceIndexEma: 100.0);
        $spikeMacro = new \App\DTO\MacroStateDTO(inflationEma: 0.06, energyPriceIndexEma: 150.0);

        $normalResult = $model->computeActualFinancials(
            $stock,
            100_000_000.0,
            0.40,
            10_000_000.0,
            0.0,
            $normalMacro,
            $mathUtility
        );

        $spikeResult = $model->computeActualFinancials(
            $stock,
            100_000_000.0,
            0.40,
            10_000_000.0,
            0.0,
            $spikeMacro,
            $mathUtility
        );

        $this->assertArrayHasKey('commodity_trading', $normalResult->streamRevenue);
        $this->assertArrayHasKey('commodity_trading', $spikeResult->streamRevenue);

        // Under inflation/energy spike, the Proof Desk generates windfall commodity trading profits
        $this->assertGreaterThan(
            $normalResult->streamRevenue['commodity_trading'],
            $spikeResult->streamRevenue['commodity_trading']
        );
    }
}
