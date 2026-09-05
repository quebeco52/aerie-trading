<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\CommercialBankBusinessModel;
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

    public function testInterbankLiquiditySpreadCompressesNIM(): void
    {
        $bank = new Stock();
        $bank->setTicker('BANK');
        $bank->setBeta('1.0');
        $bank->setTotalEquity('10000000000');
        $bank->setCustomerDeposits('50000000000');
        $bank->setWholesaleDebt('20000000000');
        $bank->setCorporateTreasury('2000000000');
        $bank->setFloatingDebtRatio('0.50');

        $calmMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            policyRateEma: 0.03,
            yield2yEma: 0.03,
            yield10yEma: 0.05,
            macroCreditSpreadEma: 0.02,
            interbankLiquiditySpreadEma: 0.0010
        );

        $tedBlowoutMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            policyRateEma: 0.03,
            yield2yEma: 0.03,
            yield10yEma: 0.05,
            macroCreditSpreadEma: 0.02,
            interbankLiquiditySpreadEma: 0.0150
        );

        $mathMock = $this->createMathUtilityMock([0.0, 0.0, 0.0]);

        $calmResult = $this->model->computeActualFinancials(
            $bank,
            expectedRevenue: 1_000_000_000.0,
            realizedVariableMargin: 0.50,
            fixedCosts: 200_000_000.0,
            baselineVol: 0.0,
            macroState: $calmMacro,
            mathUtility: $mathMock
        );

        $tedResult = $this->model->computeActualFinancials(
            $bank,
            expectedRevenue: 1_000_000_000.0,
            realizedVariableMargin: 0.50,
            fixedCosts: 200_000_000.0,
            baselineVol: 0.0,
            macroState: $tedBlowoutMacro,
            mathUtility: $mathMock
        );

        $this->assertGreaterThan(
            $calmResult->clampedMargin,
            $tedResult->clampedMargin,
            'TED spread spike must increase wholesale borrowing costs, compress NIM, and raise the variable cost ratio.'
        );
        $this->assertLessThan($calmResult->ebit, $tedResult->ebit);
    }

    public function testBaselThreeRwaAndCet1Calculations(): void
    {
        $bank = new Stock();
        $bank->setTicker('HEALTHY_BANK');
        $bank->setTotalEquity('10000000000'); // $10B
        $bank->setCustomerDeposits('80000000000'); // $80B
        $bank->setWholesaleDebt('10000000000'); // $10B
        $bank->setCorporateTreasury('5000000000'); // $5B

        // Earning Assets = 10B + 90B - 5B = 95B
        // RWA = 95B * 1.0 + 5B * 0.0 = 95B
        $rwa = $this->model->calculateRiskWeightedAssets($bank);
        $this->assertEqualsWithDelta(95_000_000_000.0, $rwa, 1.0);

        // CET1 = 10B / 95B = ~10.53%
        $cet1 = $this->model->calculateCet1Ratio($bank);
        $this->assertEqualsWithDelta(10.0 / 95.0, $cet1, 0.0001);
        $this->assertGreaterThan(CommercialBankBusinessModel::BASEL_CCB_CET1_RATIO, $cet1);

        // Dividend cap for healthy bank is 1.0
        $divCap = $this->model->getRegulatoryDividendCap($bank, 5_000_000_000.0);
        $this->assertSame(1.0, $divCap);
    }

    public function testCapitalConservationBufferHaltsDividends(): void
    {
        $bank = new Stock();
        $bank->setTicker('BUFFER_BANK');
        $bank->setTotalEquity('5000000000'); // $5B
        $bank->setCustomerDeposits('80000000000'); // $80B
        $bank->setWholesaleDebt('10000000000'); // $10B
        $bank->setCorporateTreasury('5000000000'); // $5B

        // Earning Assets = 5B + 90B - 5B = 90B
        // CET1 = 5B / 90B = ~5.56% (Between 4.0% and 6.5%)
        $cet1 = $this->model->calculateCet1Ratio($bank);
        $this->assertGreaterThan(CommercialBankBusinessModel::BASEL_MIN_CET1_RATIO, $cet1);
        $this->assertLessThan(CommercialBankBusinessModel::BASEL_CCB_CET1_RATIO, $cet1);

        // CCB forces dividend cap strictly to 0.0
        $divCap = $this->model->getRegulatoryDividendCap($bank, 5_000_000_000.0);
        $this->assertSame(0.0, $divCap);
    }

    public function testInsolventBankTriggersBankSeizureShockEvent(): void
    {
        $bank = new Stock();
        $bank->setTicker('INSOLVENT_BANK');
        $bank->setBeta('1.0');
        $bank->setTotalEquity('3000000000'); // $3B
        $bank->setCustomerDeposits('80000000000'); // $80B
        $bank->setWholesaleDebt('10000000000'); // $10B
        $bank->setCorporateTreasury('5000000000'); // $5B

        // Earning Assets = 3B + 90B - 5B = 88B
        // CET1 = 3B / 88B = ~3.41% (< 4.0% statutory minimum)
        $cet1 = $this->model->calculateCet1Ratio($bank);
        $this->assertLessThan(CommercialBankBusinessModel::BASEL_MIN_CET1_RATIO, $cet1);

        $mathMock = $this->createMathUtilityMock([0.0, 0.0, 0.0]);
        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            policyRateEma: 0.03,
            yield2yEma: 0.03,
            yield10yEma: 0.05,
            macroCreditSpreadEma: 0.02
        );

        $result = $this->model->computeActualFinancials(
            $bank,
            expectedRevenue: 1_000_000_000.0,
            realizedVariableMargin: 0.50,
            fixedCosts: 200_000_000.0,
            baselineVol: 0.0,
            macroState: $macro,
            mathUtility: $mathMock
        );

        $this->assertSame(ShockEvent::BANK_SEIZURE, $result->eventType);
    }

    public function testCorporateDefaultRateSpikeIncreasesLossProvisions(): void
    {
        $bank = new Stock();
        $bank->setTicker('COMM_BANK');
        $bank->setTotalEquity('5000000000');
        $bank->setCustomerDeposits('40000000000');
        $bank->setWholesaleDebt('5000000000');
        $bank->setCorporateTreasury('2000000000');

        $mathMock = $this->createMathUtilityMock([0.0, 0.0, 0.0]);
        $macroNormal = new MacroStateDTO(
            outputGapEma: 0.0,
            corporateDefaultRateEma: 0.018
        );

        $resultNormal = $this->model->computeActualFinancials(
            $bank,
            expectedRevenue: 1_000_000_000.0,
            realizedVariableMargin: 0.50,
            fixedCosts: 200_000_000.0,
            baselineVol: 0.0,
            macroState: $macroNormal,
            mathUtility: $mathMock
        );

        $macroSpike = new MacroStateDTO(
            outputGapEma: 0.0,
            corporateDefaultRateEma: 0.060 // Speculative corporate default spike
        );

        $resultSpike = $this->model->computeActualFinancials(
            $bank,
            expectedRevenue: 1_000_000_000.0,
            realizedVariableMargin: 0.50,
            fixedCosts: 200_000_000.0,
            baselineVol: 0.0,
            macroState: $macroSpike,
            mathUtility: $mathMock
        );

        $this->assertGreaterThan($resultNormal->clampedMargin, $resultSpike->clampedMargin, 'Corporate default spike must increase loan provision cost ratio.');
        $this->assertLessThan($resultNormal->ebit, $resultSpike->ebit);
    }

    public function testSloosCreditTighteningDampensLoanOrigination(): void
    {
        $bank = new Stock();
        $bank->setTicker('LEND_BANK');
        $bank->setTotalEquity('5000000000');
        $bank->setCustomerDeposits('40000000000');

        $mathMock = $this->createMathUtilityMock([0.0, 0.0, 0.0]);
        $macroEasy = new MacroStateDTO(
            outputGapEma: 0.0,
            sloosTighteningIndexEma: 0.0
        );

        $resultEasy = $this->model->computeActualFinancials(
            $bank,
            expectedRevenue: 1_000_000_000.0,
            realizedVariableMargin: 0.50,
            fixedCosts: 200_000_000.0,
            baselineVol: 0.0,
            macroState: $macroEasy,
            mathUtility: $mathMock
        );

        $macroTight = new MacroStateDTO(
            outputGapEma: 0.0,
            sloosTighteningIndexEma: 0.40 // 40% net banks tightening standards
        );

        $resultTight = $this->model->computeActualFinancials(
            $bank,
            expectedRevenue: 1_000_000_000.0,
            realizedVariableMargin: 0.50,
            fixedCosts: 200_000_000.0,
            baselineVol: 0.0,
            macroState: $macroTight,
            mathUtility: $mathMock
        );

        $this->assertLessThan($resultEasy->streamRevenue['net_interest_income'], $resultTight->streamRevenue['net_interest_income'], 'SLOOS tightening must dampen NII loan origination revenue.');
    }

    public function testRecessionProbabilitySpikeIncreasesCeclProvisioning(): void
    {
        $bank = new Stock();
        $bank->setTicker('CECL_BANK');
        $bank->setTotalEquity('5000000000');
        $bank->setCustomerDeposits('40000000000');

        $mathMock = $this->createMathUtilityMock([0.0, 0.0, 0.0]);
        $macroLowRisk = new MacroStateDTO(
            outputGapEma: 0.0,
            recessionProbabilityEma: 0.10
        );

        $resultLowRisk = $this->model->computeActualFinancials(
            $bank,
            expectedRevenue: 1_000_000_000.0,
            realizedVariableMargin: 0.50,
            fixedCosts: 200_000_000.0,
            baselineVol: 0.0,
            macroState: $macroLowRisk,
            mathUtility: $mathMock
        );

        $macroHighRisk = new MacroStateDTO(
            outputGapEma: 0.0,
            recessionProbabilityEma: 0.70 // High forward recession probability
        );

        $resultHighRisk = $this->model->computeActualFinancials(
            $bank,
            expectedRevenue: 1_000_000_000.0,
            realizedVariableMargin: 0.50,
            fixedCosts: 200_000_000.0,
            baselineVol: 0.0,
            macroState: $macroHighRisk,
            mathUtility: $mathMock
        );

        $this->assertGreaterThan($resultLowRisk->clampedMargin, $resultHighRisk->clampedMargin, 'High 12M forward recession probability must build forward CECL reserves.');
    }
}
