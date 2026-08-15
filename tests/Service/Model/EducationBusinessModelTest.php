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
}
