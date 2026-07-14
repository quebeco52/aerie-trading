<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\DefenseContractorBusinessModel;
use PHPUnit\Framework\TestCase;

class DefenseContractorBusinessModelTest extends TestCase
{
    public function testProgramExecutionElasticityImprovesVariableMargin(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setBeta('0.7');

        $mathUtilityMock = $this->createMock(MathUtility::class);
        // sequence: contractZ=2.0 (strong sovereign execution), commercialZ=0, eventZ=0, analystError=0
        $mathUtilityMock->method('generateStandardNormal')
            ->willReturnOnConsecutiveCalls(2.0, 0.0, 0.0, 0.0);

        $macroState = ['inflation_ema' => 0.02];
        $result = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.65,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock
        );

        // Strong contract performance reduces cost overruns -> actual variable costs lower than baseline 65%
        $this->assertLessThan(1000.0 * 0.65, $result->actualVariableCosts);
    }

    public function testClassifiedToolingAndNextGenPlatformReinvestment(): void
    {
        $model = new DefenseContractorBusinessModel();

        // R = 0.5 -> Classified tooling tech debt
        $stock = new Stock();
        $stock->setOperatingMargin('0.14');
        $model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $decayed = (float) $stock->getOperatingMargin();
        $this->assertLessThan(0.14, $decayed);
        $this->assertGreaterThanOrEqual(DefenseContractorBusinessModel::MIN_OPERATING_MARGIN_FLOOR, $decayed);

        // R = 1.5 -> Next-gen defense platform modernization
        $stock->setOperatingMargin('0.14');
        $model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $expanded = (float) $stock->getOperatingMargin();
        $this->assertGreaterThan(0.14, $expanded);
        $this->assertLessThanOrEqual(DefenseContractorBusinessModel::MAX_OPERATING_MARGIN_CEILING, $expanded);
    }
}
