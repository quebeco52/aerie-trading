<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\RailroadBusinessModel;
use PHPUnit\Framework\TestCase;

class RailroadBusinessModelTest extends TestCase
{
    private RailroadBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new RailroadBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testFreightRailTriStreamPhysics(): void
    {
        $stock = new Stock();
        $stock->setTicker('UNP');
        $stock->setBeta('1.1');

        $macro = new MacroStateDTO(
            outputGapEma: 0.02,
            energyPriceIndexEma: 110.0
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 5_000_000_000.0,
            realizedVariableMargin: 0.40,
            fixedCosts: 1_500_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('intermodal_freight', $result->streamRevenue);
        $this->assertArrayHasKey('bulk_commodities', $result->streamRevenue);
        $this->assertArrayHasKey('industrial_carloads', $result->streamRevenue);

        $this->assertArrayHasKey('intermodal_freight', $result->streamZ);
        $this->assertArrayHasKey('bulk_commodities', $result->streamZ);
        $this->assertArrayHasKey('industrial_carloads', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->streamRevenue['intermodal_freight']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['bulk_commodities']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['industrial_carloads']);
        $this->assertEqualsWithDelta(
            $result->actualRevenue,
            $result->streamRevenue['intermodal_freight'] + $result->streamRevenue['bulk_commodities'] + $result->streamRevenue['industrial_carloads'],
            1.0
        );
    }

    public function testFreightAndAgriCommodityIndexShifts(): void
    {
        $stock = new Stock();
        $stock->setTicker('UNP');
        $stock->setBeta('1.0');

        $baseMacro = new MacroStateDTO(
            freightRateIndexEma: 100.0,
            agriculturalCommodityIndexEma: 100.0
        );

        $surgeMacro = new MacroStateDTO(
            freightRateIndexEma: 140.0,
            agriculturalCommodityIndexEma: 130.0
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baseResult = $this->model->computeActualFinancials($stock, 1_000_000.0, 0.40, 200_000.0, 0.0, $baseMacro, $mathMock);
        $surgeResult = $this->model->computeActualFinancials($stock, 1_000_000.0, 0.40, 200_000.0, 0.0, $surgeMacro, $mathMock);

        $this->assertGreaterThan($baseResult->streamRevenue['intermodal_freight'], $surgeResult->streamRevenue['intermodal_freight']);
        $this->assertGreaterThan($baseResult->streamRevenue['bulk_commodities'], $surgeResult->streamRevenue['bulk_commodities']);
    }

    public function testTrackAgingAndPsrModernization(): void
    {
        // Underinvestment (R = 0.5) -> Track slow orders & rail line decay
        $stock = new Stock();
        $stock->setOperatingMargin('0.35');
        $this->model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $decayed = (float) $stock->getOperatingMargin();
        $this->assertLessThan(0.35, $decayed);
        $this->assertGreaterThanOrEqual(RailroadBusinessModel::MIN_OPERATING_MARGIN_FLOOR, $decayed);

        // Modernization (R = 1.5) -> Precision Scheduled Railroading efficiency
        $stock->setOperatingMargin('0.35');
        $this->model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $expanded = (float) $stock->getOperatingMargin();
        $this->assertGreaterThan(0.35, $expanded);
        $this->assertLessThanOrEqual(RailroadBusinessModel::MAX_OPERATING_MARGIN_CEILING, $expanded);
    }
}
