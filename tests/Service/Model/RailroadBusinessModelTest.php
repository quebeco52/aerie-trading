<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\RailroadBusinessModel;
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
}
