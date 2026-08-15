<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\RetailInsuranceBusinessModel;
use PHPUnit\Framework\TestCase;

class RetailInsuranceBusinessModelTest extends TestCase
{
    private RetailInsuranceBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new RetailInsuranceBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testDistinctPncAndLifeStreams(): void
    {
        $stock = new Stock();
        $stock->setTicker('DOVE');
        $stock->setBeta('0.8');
        $stock->setTotalEquity('50000000000');

        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            yield10yEma: 0.045,
            policyRateEma: 0.025
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 10_000_000_000.0,
            realizedVariableMargin: 0.70,
            fixedCosts: 1_000_000_000.0,
            baselineVol: 0.08,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('property_casualty_premiums', $result->streamRevenue);
        $this->assertArrayHasKey('life_insurance_premiums', $result->streamRevenue);
        $this->assertArrayHasKey('property_casualty_premiums', $result->streamZ);
        $this->assertArrayHasKey('life_insurance_premiums', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->streamRevenue['property_casualty_premiums']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['life_insurance_premiums']);
        $this->assertEqualsWithDelta(
            $result->actualRevenue,
            $result->streamRevenue['property_casualty_premiums'] + $result->streamRevenue['life_insurance_premiums'],
            1.0
        );
    }
}
