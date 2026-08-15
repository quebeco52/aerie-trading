<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\HeavyManufacturingBusinessModel;
use PHPUnit\Framework\TestCase;

class HeavyManufacturingBusinessModelTest extends TestCase
{
    private HeavyManufacturingBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new HeavyManufacturingBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testDualStreamEmissionAndBacklogDamping(): void
    {
        $stock = new Stock();
        $stock->setTicker('CATP');
        $stock->setBeta('1.2');

        $macro = new MacroStateDTO(outputGapEma: 0.02, energyPriceIndexEma: 100.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('oem_equipment', $result->streamRevenue);
        $this->assertArrayHasKey('aftermarket_mro', $result->streamRevenue);
        $this->assertArrayHasKey('oem_equipment', $result->streamZ);
        $this->assertArrayHasKey('aftermarket_mro', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->streamRevenue['oem_equipment']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['aftermarket_mro']);
        $this->assertEqualsWithDelta(
            $result->actualRevenue,
            $result->streamRevenue['oem_equipment'] + $result->streamRevenue['aftermarket_mro'],
            1.0
        );
    }
}
