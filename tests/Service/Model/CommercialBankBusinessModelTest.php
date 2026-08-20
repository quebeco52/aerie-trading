<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\CommercialBankBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class CommercialBankBusinessModelTest extends TestCase
{
    private CommercialBankBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new CommercialBankBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testDualStreamsAndRevenueAccounting(): void
    {
        $stock = new Stock();
        $stock->setTicker('TEST_BANK');
        $stock->setBeta('1.1');
        $stock->setTotalEquity('5000000000');
        $stock->setCustomerDeposits('40000000000');
        $stock->setWholesaleDebt('5000000000');
        $stock->setCorporateTreasury('2000000000');

        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            policyRateEma: 0.04,
            yield2yEma: 0.04,
            yield10yEma: 0.045,
            macroCreditSpreadEma: 0.02
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 1_000_000_000.0,
            realizedVariableMargin: 0.50,
            fixedCosts: 200_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('net_interest_income', $result->streamRevenue);
        $this->assertArrayHasKey('fee_income', $result->streamRevenue);
        $this->assertGreaterThan(0.0, $result->actualRevenue);
        $this->assertEqualsWithDelta(
            $result->actualRevenue,
            $result->streamRevenue['net_interest_income'] + $result->streamRevenue['fee_income'],
            1.0
        );
    }

    private function createMathUtilityMock(array $persistentZCalls = []): MathUtility
    {
        $mock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ'])
            ->getMock();

        $callIndex = 0;
        $mock->method('generatePersistentZ')
            ->willReturnCallback(function ($prevZ, $phi) use (&$callIndex, $persistentZCalls) {
                if (isset($persistentZCalls[$callIndex])) {
                    $val = $persistentZCalls[$callIndex];
                    $callIndex++;
                    return $val;
                }
                $callIndex++;
                return 0.0;
            });

        return $mock;
    }

    public function testCreditStressVasicekProvisionSurgeAndEventTrigger(): void
    {
        $stock = new Stock();
        $stock->setTicker('LAKE');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('10000000000');
        $stock->setCustomerDeposits('80000000000');
        $stock->setWholesaleDebt('10000000000');
        $stock->setCorporateTreasury('5000000000');

        // revenueZ = 0.0, feeZ = 0.0, defaultZ = -2.5 (severe credit stress)
        $mathMock = $this->createMathUtilityMock([0.0, 0.0, -2.5]);

        $macro = new MacroStateDTO(
            outputGapEma: -0.03,
            policyRateEma: 0.03,
            yield2yEma: 0.03,
            yield10yEma: 0.035,
            macroCreditSpreadEma: 0.035
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 2_000_000_000.0,
            realizedVariableMargin: 0.50,
            fixedCosts: 400_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $mathMock
        );

        $this->assertSame(ShockEvent::MASSIVE_CREDIT_PROVISION, $result->eventType);
        $this->assertGreaterThan(0.50, $result->clampedMargin, 'Credit stress and CECL drag must increase variable cost margin.');
    }

    public function testBenignCreditEnvironmentTriggersReserveRelease(): void
    {
        $stock = new Stock();
        $stock->setTicker('LAKE');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('10000000000');
        $stock->setCustomerDeposits('80000000000');
        $stock->setWholesaleDebt('10000000000');
        $stock->setCorporateTreasury('5000000000');

        // revenueZ = 0.0, feeZ = 0.0, defaultZ = +2.2 (benign boom)
        $mathMock = $this->createMathUtilityMock([0.0, 0.0, 2.2]);

        $macro = new MacroStateDTO(
            outputGapEma: 0.02,
            policyRateEma: 0.03,
            yield2yEma: 0.03,
            yield10yEma: 0.05,
            macroCreditSpreadEma: 0.015
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 2_000_000_000.0,
            realizedVariableMargin: 0.60,
            fixedCosts: 400_000_000.0,
            baselineVol: 0.05,
            macroState: $macro,
            mathUtility: $mathMock
        );

        $this->assertSame(ShockEvent::RESERVE_RELEASE, $result->eventType);
    }

    public function testMacaulayDurationGapNIMSqueezeUnderInversion(): void
    {
        $stock = new Stock();
        $stock->setTicker('RIVR');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('2000000000');
        $stock->setCustomerDeposits('15000000000');
        $stock->setWholesaleDebt('3000000000');
        $stock->setCorporateTreasury('1000000000');
        $stock->setFloatingDebtRatio('0.10');

        // Steep curve: 10Y = 5%, 2Y = 3% (Spread = +200 bps)
        $steepMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            policyRateEma: 0.03,
            yield2yEma: 0.03,
            yield10yEma: 0.05,
            macroCreditSpreadEma: 0.02
        );

        // Inverted curve: 10Y = 3%, 2Y = 5% (Spread = -200 bps)
        $invertedMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            policyRateEma: 0.05,
            yield2yEma: 0.05,
            yield10yEma: 0.03,
            macroCreditSpreadEma: 0.02
        );

        $steepMath = $this->createMathUtilityMock([0.0, 0.0, 0.0]);
        $invertedMath = $this->createMathUtilityMock([0.0, 0.0, 0.0]);

        $steepResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 500_000_000.0,
            realizedVariableMargin: 0.50,
            fixedCosts: 100_000_000.0,
            baselineVol: 0.05,
            macroState: $steepMacro,
            mathUtility: $steepMath
        );

        $invertedResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 500_000_000.0,
            realizedVariableMargin: 0.50,
            fixedCosts: 100_000_000.0,
            baselineVol: 0.05,
            macroState: $invertedMacro,
            mathUtility: $invertedMath
        );

        // Inversion must result in a higher cost ratio (NIM squeeze) than a steep yield curve
        $this->assertGreaterThan(
            $steepResult->clampedMargin,
            $invertedResult->clampedMargin,
            'Yield curve inversion must compress NIM and increase variable cost margin relative to steep curve.'
        );
    }

    public function testHedgeRatioBluntingProtectsHedgedBank(): void
    {
        // Hedged titan bank: high floating ratio, lower sensitivity
        $hedgedBank = new Stock();
        $hedgedBank->setTicker('LAKE');
        $hedgedBank->setBeta('1.0');
        $hedgedBank->setTotalEquity('10000000000');
        $hedgedBank->setCustomerDeposits('80000000000');
        $hedgedBank->setWholesaleDebt('10000000000');
        $hedgedBank->setCorporateTreasury('5000000000');
        $hedgedBank->setFloatingDebtRatio('0.60');

        // Unhedged regional lender: low floating ratio, higher sensitivity
        $unhedgedBank = new Stock();
        $unhedgedBank->setTicker('RIVR');
        $unhedgedBank->setBeta('1.0');
        $unhedgedBank->setTotalEquity('10000000000');
        $unhedgedBank->setCustomerDeposits('80000000000');
        $unhedgedBank->setWholesaleDebt('10000000000');
        $unhedgedBank->setCorporateTreasury('5000000000');
        $unhedgedBank->setFloatingDebtRatio('0.10');

        // Inverted curve: 10Y = 3.5%, 2Y = 5.5% (Spread = -200 bps)
        $invertedMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            policyRateEma: 0.055,
            yield2yEma: 0.055,
            yield10yEma: 0.035,
            macroCreditSpreadEma: 0.02
        );

        $mathMockHedged = $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0]);
        $hedgedResult = $this->model->computeActualFinancials(
            $hedgedBank,
            expectedRevenue: 2_000_000_000.0,
            realizedVariableMargin: 0.50,
            fixedCosts: 400_000_000.0,
            baselineVol: 0.0,
            macroState: $invertedMacro,
            mathUtility: $mathMockHedged
        );

        $mathMockUnhedged = $this->createMathUtilityMock([0.0, 0.0, 0.0, 0.0]);
        $unhedgedResult = $this->model->computeActualFinancials(
            $unhedgedBank,
            expectedRevenue: 2_000_000_000.0,
            realizedVariableMargin: 0.50,
            fixedCosts: 400_000_000.0,
            baselineVol: 0.0,
            macroState: $invertedMacro,
            mathUtility: $mathMockUnhedged
        );

        $this->assertLessThan(
            $unhedgedResult->clampedMargin,
            $hedgedResult->clampedMargin,
            'Hedged bank with higher floating ratio and ALM tuning must suffer less margin compression during inversion.'
        );
    }
}
