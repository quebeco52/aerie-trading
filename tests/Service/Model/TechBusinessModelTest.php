<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\TechBusinessModel;
use PHPUnit\Framework\TestCase;

class TechBusinessModelTest extends TestCase
{
    public function testSaaSARROperatingLeverageAndContinuousWageInflation(): void
    {
        $model = new TechBusinessModel();
        $stock = new Stock();
        $stock->setBeta('1.5');

        $mathUtilityMock = $this->createMock(MathUtility::class);
        // sequence: subscriptionZ=2.0 (strong cloud ARR expansion), adZ=0, eventZ=0, analystError=0
        $mathUtilityMock->method('generateStandardNormal')
            ->willReturnOnConsecutiveCalls(2.0, 0.0, 0.0, 0.0);

        $macroState = ['inflation_ema' => 0.02];
        $result = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.30,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock
        );

        // SaaS ARR expansion lowers variable cost percentage below 30%
        $this->assertLessThan(1000.0 * 0.30, $result->actualVariableCosts);
    }

    public function testSoftwareTechDebtAndCloudPlatformModernization(): void
    {
        $model = new TechBusinessModel();

        // R = 0.5 -> Software tech debt decay
        $stock = new Stock();
        $stock->setOperatingMargin('0.35');
        $model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $decayed = (float) $stock->getOperatingMargin();
        $this->assertLessThan(0.35, $decayed);
        $this->assertGreaterThanOrEqual(TechBusinessModel::MIN_OPERATING_MARGIN_FLOOR, $decayed);

        // R = 1.5 -> Cloud ARR platform modernization
        $stock->setOperatingMargin('0.35');
        $model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $expanded = (float) $stock->getOperatingMargin();
        $this->assertGreaterThan(0.35, $expanded);
        $this->assertLessThanOrEqual(TechBusinessModel::MAX_OPERATING_MARGIN_CEILING, $expanded);
    }
}
