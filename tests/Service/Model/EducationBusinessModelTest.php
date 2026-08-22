<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\EducationBusinessModel;
use PHPUnit\Framework\TestCase;

class EducationBusinessModelTest extends TestCase
{
    private EducationBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new EducationBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testCounterCyclicalTuitionAndLmsStreams(): void
    {
        $stock = new Stock();
        $stock->setTicker('STRA');
        $stock->setBeta('0.9');

        $recessionMacro = new MacroStateDTO(outputGapEma: -0.03);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 200_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 40_000_000.0,
            baselineVol: 0.08,
            macroState: $recessionMacro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('degree_tuition_enrollment', $result->streamRevenue);
        $this->assertArrayHasKey('enterprise_b2b_training', $result->streamRevenue);
        $this->assertArrayHasKey('digital_lms_licensing', $result->streamRevenue);

        $this->assertArrayHasKey('degree_tuition_enrollment', $result->streamZ);
        $this->assertArrayHasKey('enterprise_b2b_training', $result->streamZ);
        $this->assertArrayHasKey('digital_lms_licensing', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->streamRevenue['degree_tuition_enrollment']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['enterprise_b2b_training']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['digital_lms_licensing']);
        $this->assertEqualsWithDelta(
            $result->actualRevenue,
            $result->streamRevenue['degree_tuition_enrollment'] + $result->streamRevenue['enterprise_b2b_training'] + $result->streamRevenue['digital_lms_licensing'],
            1.0
        );
    }

    public function testGovernmentSpendingGrantsBoostEnrollment(): void
    {
        $stock = new Stock();
        $stock->setTicker('STRA');
        $stock->setBeta('1.0');

        $baseMacro = new MacroStateDTO(governmentSpendingIndexEma: 100.0);
        $expansionMacro = new MacroStateDTO(governmentSpendingIndexEma: 130.0);

        $mathMock = $this->createMock(MathUtility::class);
        $baseResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 20_000_000.0, 0.0, $baseMacro, $mathMock);
        $expansionResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 20_000_000.0, 0.0, $expansionMacro, $mathMock);

        $this->assertGreaterThan($baseResult->streamRevenue['degree_tuition_enrollment'], $expansionResult->streamRevenue['degree_tuition_enrollment']);
    }

    public function testUnemploymentSpikeBoostsDegreeEnrollment(): void
    {
        $stock = new Stock();
        $stock->setTicker('STRA');
        $stock->setBeta('1.0');

        $lowUnemploymentMacro = new MacroStateDTO(unemploymentRateEma: 0.040);
        $highUnemploymentMacro = new MacroStateDTO(unemploymentRateEma: 0.080);

        $mathMock = $this->createMock(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baseResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 20_000_000.0, 0.0, $lowUnemploymentMacro, $mathMock);
        $surgeResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.30, 20_000_000.0, 0.0, $highUnemploymentMacro, $mathMock);

        $this->assertGreaterThan(
            $baseResult->streamRevenue['degree_tuition_enrollment'],
            $surgeResult->streamRevenue['degree_tuition_enrollment'],
            'Elevated unemployment must drive countercyclical workforce retraining and boost degree tuition enrollment.'
        );
    }
}
