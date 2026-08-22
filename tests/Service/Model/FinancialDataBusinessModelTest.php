<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\FinancialDataBusinessModel;
use PHPUnit\Framework\TestCase;

class FinancialDataBusinessModelTest extends TestCase
{
    public function testRatingIssuanceOperatingLeverageImpactsVariableCosts(): void
    {
        $model = new FinancialDataBusinessModel();
        $stock = new Stock();
        $stock->setTicker('FINDATA');
        $stock->setBeta('1.0');

        $mathUtilityMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
        // Sequence of generateStandardNormal calls:
        // 1. subscriptionZ = 0.0
        // 2. transactionZ = 2.0 (Boom in debt issuance & credit rating mandates)
        $mathUtilityMock->expects($this->exactly(2))
            ->method('generateStandardNormal')
            ->willReturnOnConsecutiveCalls(0.0, 2.0);

        $macroState = \App\DTO\MacroStateDTO::fromArray([]);
        $result = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.35,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock
        );

        // Operating leverage shift = -0.020 * 2.0 * 0.15 = -0.006
        // Variable cost realized = 1000 * (0.35 - 0.006) = 344.0
        $this->assertLessThan(1000.0 * 0.35, $result->actualVariableCosts);
    }

    public function testDataPlatformReinvestmentAndMonopolyMoat(): void
    {
        $model = new FinancialDataBusinessModel();

        // Underinvestment (R = 0.5): Margin erodes toward software floor (0.20)
        $stock = new Stock();
        $stock->setOperatingMargin('0.50');
        $model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $decayed = (float) $stock->getOperatingMargin();
        $this->assertLessThan(0.50, $decayed);
        $this->assertGreaterThanOrEqual(FinancialDataBusinessModel::MIN_OPERATING_MARGIN_FLOOR, $decayed);

        // Modernization Overinvestment (R = 1.5): Margin expands toward data monopoly ceiling (0.65)
        $stock->setOperatingMargin('0.50');
        $model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $expanded = (float) $stock->getOperatingMargin();
        $this->assertGreaterThan(0.50, $expanded);
        $this->assertLessThanOrEqual(FinancialDataBusinessModel::MAX_OPERATING_MARGIN_CEILING, $expanded);
    }

    public function testSubscriptionMonopolyIsUnderLeveraged(): void
    {
        $model = new FinancialDataBusinessModel();

        // Ke > Kd + 0.02, ICR >= 6.0, debtRatio < tolerance * 0.60 -> True
        $this->assertTrue(
            $model->isUnderLeveraged(
                0.20,
                0.50,
                8.0,
                3.0,
                0.09,
                0.05
            )
        );

        // ICR < 6.0 -> False
        $this->assertFalse(
            $model->isUnderLeveraged(
                0.20,
                0.50,
                5.0,
                3.0,
                0.09,
                0.05
            )
        );
    }

    public function testDcmIssuanceAndVixVolExpandsTransactionRevenue(): void
    {
        $model = new FinancialDataBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHRK');
        $stock->setBeta('1.0');

        $mathUtility = new MathUtility();

        // Baseline macro: normal spreads, neutral output gap, calm VIX (0.15)
        $normalMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpreadEma: 0.020,
            marketVolatilityEma: 0.15
        );

        // Boom & Volatility macro: tight credit spreads (0.010 = +100bps DCM syndication boom), positive output gap (+2%), and high VIX (0.35)
        $boomMacro = new MacroStateDTO(
            outputGapEma: 0.02,
            macroCreditSpreadEma: 0.010,
            marketVolatilityEma: 0.35
        );

        $normalResult = $model->computeActualFinancials($stock, 1000.0, 0.35, 50.0, 0.0, $normalMacro, $mathUtility);
        $boomResult = $model->computeActualFinancials($stock, 1000.0, 0.35, 50.0, 0.0, $boomMacro, $mathUtility);

        $this->assertGreaterThan(
            $normalResult->streamRevenue['transaction'],
            $boomResult->streamRevenue['transaction'],
            'Tight credit spreads and elevated VIX volatility must expand transaction/rating revenue.'
        );
    }
}
