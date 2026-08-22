<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\MedicalCareFacilityBusinessModel;
use PHPUnit\Framework\TestCase;

class MedicalCareFacilityBusinessModelTest extends TestCase
{
    private MedicalCareFacilityBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new MedicalCareFacilityBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testTriStreamEmissionAndSumConsistency(): void
    {
        $stock = new Stock();
        $stock->setTicker('GEN_HOSPITAL');
        $stock->setBeta('1.0');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, energyPriceIndexEma: 100.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.25,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.08,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('inpatient_care', $result->streamRevenue);
        $this->assertArrayHasKey('elective_outpatient', $result->streamRevenue);
        $this->assertArrayHasKey('insurance_arbitrage', $result->streamRevenue);

        $this->assertArrayHasKey('inpatient_care', $result->streamZ);
        $this->assertArrayHasKey('elective_outpatient', $result->streamZ);
        $this->assertArrayHasKey('insurance_arbitrage', $result->streamZ);
        $this->assertArrayHasKey('event', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->streamRevenue['inpatient_care']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['elective_outpatient']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['insurance_arbitrage']);

        $sumStreams = $result->streamRevenue['inpatient_care']
            + $result->streamRevenue['elective_outpatient']
            + $result->streamRevenue['insurance_arbitrage'];

        $this->assertEqualsWithDelta($result->actualRevenue, $sumStreams, 1.0);
    }

    public function testCranTickerParameterResolution(): void
    {
        $stock = new Stock();
        $stock->setTicker('CRAN');
        $stock->setBeta('1.0');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, energyPriceIndexEma: 100.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 15_000_000.0,
            baselineVol: 0.00, // zero volatility to verify baseline weights
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        // CRAN tuned weights: 50% Inpatient, 30% Outpatient, 20% Insurance Arbitrage
        $this->assertEqualsWithDelta(50_000_000.0, $result->streamRevenue['inpatient_care'], 1.0);
        $this->assertEqualsWithDelta(30_000_000.0, $result->streamRevenue['elective_outpatient'], 1.0);
        $this->assertEqualsWithDelta(20_000_000.0, $result->streamRevenue['insurance_arbitrage'], 1.0);
    }

    public function testInsuranceArbitrageExpandsWithInflation(): void
    {
        $stock = new Stock();
        $stock->setTicker('CRAN');
        $stock->setBeta('1.0');

        $normalMacro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02);
        $highInflationMacro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.06); // 400bps above target

        $normalResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 15_000_000.0,
            baselineVol: 0.00,
            macroState: $normalMacro,
            mathUtility: $this->mathUtility
        );

        $inflatedResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 15_000_000.0,
            baselineVol: 0.00,
            macroState: $highInflationMacro,
            mathUtility: $this->mathUtility
        );

        // Insurance arbitrage stream revenue expands under high inflation
        $this->assertGreaterThan(
            $normalResult->streamRevenue['insurance_arbitrage'],
            $inflatedResult->streamRevenue['insurance_arbitrage']
        );
    }
}
