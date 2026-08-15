<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\ModelParam;
use App\Data\StockModelTuning;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\ConstructionBusinessModel;
use PHPUnit\Framework\TestCase;

class ConstructionBusinessModelTest extends TestCase
{
    private ConstructionBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new ConstructionBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testTriStreamEmissionAndSumConsistency(): void
    {
        $stock = new Stock();
        $stock->setTicker('GEN_CONST');
        $stock->setBeta('1.1');

        $macro = new MacroStateDTO(outputGapEma: 0.01, policyRateEma: 0.03, inflationEma: 0.02, energyPriceIndexEma: 100.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.25,
            fixedCosts: 10_000_000.0,
            baselineVol: 0.12,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('civil_infrastructure', $result->streamRevenue);
        $this->assertArrayHasKey('commercial_epc', $result->streamRevenue);
        $this->assertArrayHasKey('facilities_maintenance', $result->streamRevenue);

        $this->assertArrayHasKey('civil_infrastructure', $result->streamZ);
        $this->assertArrayHasKey('commercial_epc', $result->streamZ);
        $this->assertArrayHasKey('facilities_maintenance', $result->streamZ);
        $this->assertArrayHasKey('event', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->streamRevenue['civil_infrastructure']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['commercial_epc']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['facilities_maintenance']);

        $sumStreams = $result->streamRevenue['civil_infrastructure']
            + $result->streamRevenue['commercial_epc']
            + $result->streamRevenue['facilities_maintenance'];

        $this->assertEqualsWithDelta($result->actualRevenue, $sumStreams, 1.0);
    }

    public function testIbhiTickerParameterResolution(): void
    {
        $stock = new Stock();
        $stock->setTicker('IBHI');
        $stock->setBeta('1.0');

        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            policyRateEma: \App\Service\Macro\MacroEngine::NATURAL_RATE,
            inflationEma: 0.02,
            energyPriceIndexEma: 100.0
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 10_000_000.0,
            baselineVol: 0.00, // zero vol to test baseline weights
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        // IBHI tuned weights: 60% Civil, 25% Commercial, 15% Maintenance
        $this->assertEqualsWithDelta(60_000_000.0, $result->streamRevenue['civil_infrastructure'], 1.0);
        $this->assertEqualsWithDelta(25_000_000.0, $result->streamRevenue['commercial_epc'], 1.0);
        $this->assertEqualsWithDelta(15_000_000.0, $result->streamRevenue['facilities_maintenance'], 1.0);
    }

    public function testPricingPowerMitigatesMaterialCostInflation(): void
    {
        $stockDefault = new Stock();
        $stockDefault->setTicker('GEN_CONST');
        $stockDefault->setBeta('1.0');

        // High inflation + energy spike
        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            policyRateEma: 0.03,
            inflationEma: 0.06, // 400bps above 2% target
            energyPriceIndexEma: 140.0 // 40% energy spike
        );

        $resultDefault = $this->model->computeActualFinancials(
            $stockDefault,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 10_000_000.0,
            baselineVol: 0.0,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        // Under high material cost inflation, variable cost margin expands (realizedVariableMargin increases)
        $this->assertGreaterThan(0.30, $resultDefault->clampedMargin);
        $this->assertLessThan(1.50, $resultDefault->clampedMargin);
    }
}
