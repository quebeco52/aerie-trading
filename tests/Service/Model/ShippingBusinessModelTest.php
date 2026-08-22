<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ShippingBusinessModel;
use PHPUnit\Framework\TestCase;

class ShippingBusinessModelTest extends TestCase
{
    public function testContinuousSpotRateElasticity(): void
    {
        $model = new ShippingBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHIP');
        $stock->setBeta('1.0');

        $mathUtilityMock = $this->createMock(MathUtility::class);
        $mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        // Positive output gap -> continuous positive spot rate multiplier
        $macroStatePositive = \App\DTO\MacroStateDTO::fromArray(['output_gap_ema' => 0.010, 'inflation_ema' => 0.02]);
        $resultPositive = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.40,
            50.0,
            0.15,
            $macroStatePositive,
            $mathUtilityMock
        );

        // Negative output gap -> continuous negative spot rate multiplier
        $macroStateNegative = \App\DTO\MacroStateDTO::fromArray(['output_gap_ema' => -0.010, 'inflation_ema' => 0.02]);
        $resultNegative = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.40,
            50.0,
            0.15,
            $macroStateNegative,
            $mathUtilityMock
        );

        $this->assertGreaterThan($resultNegative->actualRevenue, $resultPositive->actualRevenue);
    }

    public function testVesselAgingAndEcoFleetModernization(): void
    {
        $model = new ShippingBusinessModel();

        // R = 0.5 (Underinvestment -> Vessel aging drag)
        $stock = new Stock();
        $stock->setOperatingMargin('0.25');
        $model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $decayed = (float) $stock->getOperatingMargin();
        $this->assertLessThan(0.25, $decayed);
        $this->assertGreaterThanOrEqual(ShippingBusinessModel::MIN_OPERATING_MARGIN_FLOOR, $decayed);

        // R = 1.5 (Modernization -> Eco fleet fuel savings)
        $stock->setOperatingMargin('0.25');
        $model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $expanded = (float) $stock->getOperatingMargin();
        $this->assertGreaterThan(0.25, $expanded);
        $this->assertLessThanOrEqual(ShippingBusinessModel::MAX_OPERATING_MARGIN_CEILING, $expanded);
    }

    public function testFreightRateIndexSurgeBoostsShippingRevenue(): void
    {
        $model = new ShippingBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHIP');
        $stock->setBeta('1.0');

        $mathUtilityMock = $this->createMock(MathUtility::class);
        $mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $baseMacro = new \App\DTO\MacroStateDTO(freightRateIndexEma: 100.0);
        $surgeMacro = new \App\DTO\MacroStateDTO(freightRateIndexEma: 140.0);

        $baseResult = $model->computeActualFinancials($stock, 1000.0, 0.40, 50.0, 0.15, $baseMacro, $mathUtilityMock);
        $surgeResult = $model->computeActualFinancials($stock, 1000.0, 0.40, 50.0, 0.15, $surgeMacro, $mathUtilityMock);

        $this->assertGreaterThan($baseResult->streamRevenue['spot'], $surgeResult->streamRevenue['spot']);
    }

    public function testFxShiftOnMacroPhysics(): void
    {
        $model = new ShippingBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHIP');
        $stock->setBeta('1.0');

        $baseMacro = new \App\DTO\MacroStateDTO(exchangeRateIndexEma: 100.0);
        $strongDollarMacro = new \App\DTO\MacroStateDTO(exchangeRateIndexEma: 120.0);

        $basePhysics = $model->getMacroPhysics($stock, $baseMacro);
        $strongDollarPhysics = $model->getMacroPhysics($stock, $strongDollarMacro);

        $this->assertLessThan($basePhysics['macro_demand_shift'], $strongDollarPhysics['macro_demand_shift']);
    }
}
