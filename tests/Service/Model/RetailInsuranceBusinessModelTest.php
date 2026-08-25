<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\RetailInsuranceBusinessModel;
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

    public function testObservableShockZCalculation(): void
    {
        $stock = new Stock();
        $stock->setTicker('DOVE');
        $stock->setBeta('0.8');
        $stock->setTotalEquity('50000000000');

        $mathMock = $this->createStub(MathUtility::class);
        // pcZ = 1.0, lifeZ = 0.5, claimZ = 0.0
        $mathMock->method('generatePersistentZ')->willReturnOnConsecutiveCalls(1.0, 0.5, 0.0);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);

        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            yield10yEma: 0.045,
            policyRateEma: 0.025
        );

        $expectedRevenue = 10_000_000_000.0;
        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: $expectedRevenue,
            realizedVariableMargin: 0.70,
            fixedCosts: 1_000_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $mathMock
        );

        // Observable shock should match the revenue percentage deviation
        $pcBase = $expectedRevenue * RetailInsuranceBusinessModel::PROPERTY_CASUALTY_WEIGHT;
        $pcShock = ($result->streamRevenue['property_casualty_premiums'] - $pcBase) / $pcBase;

        $lifeBase = $expectedRevenue * RetailInsuranceBusinessModel::LIFE_INSURANCE_WEIGHT;
        $lifeShock = ($result->streamRevenue['life_insurance_premiums'] - $lifeBase) / $lifeBase;

        $expectedObservableShock = ($pcShock * RetailInsuranceBusinessModel::PROPERTY_CASUALTY_WEIGHT)
            + ($lifeShock * RetailInsuranceBusinessModel::LIFE_INSURANCE_WEIGHT);

        $this->assertEqualsWithDelta($expectedObservableShock, $result->observableShockZ, 0.0001);
    }
}
