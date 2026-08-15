<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\AutoManufacturerBusinessModel;
use PHPUnit\Framework\TestCase;

class AutoManufacturerBusinessModelTest extends TestCase
{
    private AutoManufacturerBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new AutoManufacturerBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testTriStreamEmissionAndSumConsistency(): void
    {
        $stock = new Stock();
        $stock->setTicker('GEN_AUTO');
        $stock->setBeta('1.5');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, policyRateEma: 0.03, yield10yEma: 0.04, yield2yEma: 0.03);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 30_000_000.0,
            baselineVol: 0.15,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('mass_market_sales', $result->streamRevenue);
        $this->assertArrayHasKey('apex_luxury', $result->streamRevenue);
        $this->assertArrayHasKey('software_telematics', $result->streamRevenue);

        $this->assertArrayHasKey('mass_market_sales', $result->streamZ);
        $this->assertArrayHasKey('apex_luxury', $result->streamZ);
        $this->assertArrayHasKey('software_telematics', $result->streamZ);
        $this->assertArrayHasKey('event', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->streamRevenue['mass_market_sales']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['apex_luxury']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['software_telematics']);

        $sumStreams = $result->streamRevenue['mass_market_sales']
            + $result->streamRevenue['apex_luxury']
            + $result->streamRevenue['software_telematics'];

        $this->assertEqualsWithDelta($result->actualRevenue, $sumStreams, 1.0);
    }

    public function testFalcTickerParameterResolution(): void
    {
        $stock = new Stock();
        $stock->setTicker('FALC');
        $stock->setBeta('1.75');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, policyRateEma: 0.03, yield10yEma: 0.04, yield2yEma: 0.03);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 25_000_000.0,
            baselineVol: 0.0,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        // FALC tuned: 55% Mass Market Fleet, 25% Apex Luxury, 20% Software Telematics
        $this->assertEqualsWithDelta(55_000_000.0, $result->streamRevenue['mass_market_sales'], 1.0);
        $this->assertEqualsWithDelta(25_000_000.0, $result->streamRevenue['apex_luxury'], 1.0);
        $this->assertEqualsWithDelta(20_000_000.0, $result->streamRevenue['software_telematics'], 1.0);
    }

    public function testApexLuxurySurgesDuringBoom(): void
    {
        $stock = new Stock();
        $stock->setTicker('FALC');
        $stock->setBeta('1.75');

        $flatMacro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, policyRateEma: 0.03, yield10yEma: 0.04, yield2yEma: 0.03);
        $boomMacro = new MacroStateDTO(outputGapEma: 0.03, inflationEma: 0.02, policyRateEma: 0.03, yield10yEma: 0.04, yield2yEma: 0.03);

        $flatResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.20, 25_000_000.0, 0.0, $flatMacro, $this->mathUtility);
        $boomResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.20, 25_000_000.0, 0.0, $boomMacro, $this->mathUtility);

        // Apex luxury revenue expands in economic booms
        $this->assertGreaterThan(
            $flatResult->streamRevenue['apex_luxury'],
            $boomResult->streamRevenue['apex_luxury']
        );
    }

    public function testLaborStrikeObservableShockBounded(): void
    {
        $mathMock = $this->createMock(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(-3.0);

        $stock = new Stock();
        $stock->setTicker('GEN_AUTO');
        $stock->setBeta('1.0');
        $stock->setEarningsMomentumZ([
            'event' => -3.0,
        ]);

        $macro = new MacroStateDTO();

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 25_000_000.0,
            baselineVol: 0.15,
            macroState: $macro,
            mathUtility: $mathMock
        );

        $this->assertSame(\App\Service\Event\ShockEvent::LABOR_STRIKE, $result->eventType);
        // Strike reduces mass-market sales by 20% (on 60% of revenue -> -12% overall hit).
        // Observable shock is properly scaled between -0.25 and 0.0, rather than blowing up to -2.0 (-200%).
        $this->assertGreaterThan(-0.25, $result->observableShockZ);
        $this->assertLessThan(0.0, $result->observableShockZ);
    }
}
