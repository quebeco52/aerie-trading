<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ShadowBankBusinessModel;
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

    /**
     * The ROE target is earned after the through-the-cycle credit charge the allowance roll-forward books
     * against EBIT, so the operating target must fund it: the after-tax return target rises by exactly the
     * loss rate (the firm's own, CreditRiskAppetite-scaled) over a loss-free control.
     */
    public function testTargetOperatingProfitFundsTheThroughTheCycleCreditCharge(): void
    {
        $stock = new Stock();
        $stock->setTicker('LAKE');
        $stock->setIndustry('Banks - Diversified');
        $stock->setTotalEquity('200000000000');
        $stock->setCustomerDeposits('1600000000000');
        $stock->setWholesaleDebt('200000000000');
        $stock->setCorporateTreasury('150000000000');
        $stock->setBaselineRoe('0.14');
        $stock->setOperatingMargin('0.40');
        $stock->setCreditSpread('0.012');
        $stock->setFloatingDebtRatio('0.40');

        $macro = new MacroStateDTO(
            policyRate: 0.04, policyRateEma: 0.04, yield5yEma: 0.045, corporateTaxRate: 0.21, equityRiskPremium: 0.045
        );

        $lossFree = new class extends CommercialBankBusinessModel {
            public function getThroughTheCycleCreditLossRate(?Stock $stock = null): float
            {
                return 0.0;
            }
        };

        $lossRate = $this->model->getThroughTheCycleCreditLossRate($stock);
        $this->assertGreaterThan(0.0, $lossRate);

        $withLosses = $this->model->getTargetMetrics($stock, $macro, $this->mathUtility);
        $without = $lossFree->getTargetMetrics($stock, $macro, $this->mathUtility);

        $this->assertSame($without['invested_capital'], $withLosses['invested_capital']);
        $this->assertEqualsWithDelta(
            $lossRate * (1.0 - $macro->corporateTaxRate),
            $withLosses['baseline_roic'] - $without['baseline_roic'],
            1e-9
        );
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

    /** A 2009-scale cycle (Moody's all-rated 5.4%, retail defaults more than doubled) is a massive provision quarter, at the multiple of normal losses US banks took. */
    public function testCreditStressFromTheMacroCycleSurgesChargeOffsAndTriggersTheEvent(): void
    {
        $stock = $this->creditTestBank('LAKE');
        $macro = new MacroStateDTO(outputGapEma: -0.03, policyRateEma: 0.03, yield2yEma: 0.03, yield10yEma: 0.035, macroCreditSpreadEma: 0.035, retailDefaultRateEma: 0.06, corporateDefaultRateEma: 0.054);

        $result = $this->model->computeActualFinancials($stock, 2_000_000_000.0, 0.50, 400_000_000.0, 0.10, $macro, $this->mathUtility);

        $this->assertSame(ShockEvent::MASSIVE_CREDIT_PROVISION, $result->eventType);
        $annualLossRate = $result->netChargeOffs * FinancialConstants::QUARTERS_PER_YEAR / $this->model->resolveEarningAssets($stock);
        $this->assertGreaterThan(2.5 * $this->model->getThroughTheCycleCreditLossRate($stock), $annualLossRate, 'US banks charged off 2.85x their long-run mean in 2009-10');
    }

    public function testBenignCreditCycleTriggersReserveRelease(): void
    {
        $stock = $this->creditTestBank('LAKE');
        $macro = new MacroStateDTO(outputGapEma: 0.02, policyRateEma: 0.03, yield2yEma: 0.03, yield10yEma: 0.05, macroCreditSpreadEma: 0.015, retailDefaultRateEma: 0.012, corporateDefaultRateEma: 0.005);

        $result = $this->model->computeActualFinancials($stock, 2_000_000_000.0, 0.60, 400_000_000.0, 0.05, $macro, $this->mathUtility);

        $this->assertSame(ShockEvent::RESERVE_RELEASE, $result->eventType);
        $annualLossRate = $result->netChargeOffs * FinancialConstants::QUARTERS_PER_YEAR / $this->model->resolveEarningAssets($stock);
        $this->assertLessThan($this->model->getThroughTheCycleCreditLossRate($stock), $annualLossRate);
    }

    /**
     * Credit losses are charged off, not overlaid on the margin: a default spike raises this quarter's charge-offs
     * (which the allowance roll-forward replaces through EBIT) and leaves the operating margin alone, where it used
     * to be provisioned into the allowance and released back as income by the convergence step.
     */
    public function testCorporateDefaultSpikeIsChargedOffNotOverlaidOnTheMargin(): void
    {
        $bank = $this->creditTestBank('COMM_BANK');
        $normal = $this->model->computeActualFinancials($bank, 1_000_000_000.0, 0.50, 200_000_000.0, 0.0, new MacroStateDTO(outputGapEma: 0.0, corporateDefaultRateEma: 0.018), $this->mathUtility);
        $spike = $this->model->computeActualFinancials($bank, 1_000_000_000.0, 0.50, 200_000_000.0, 0.0, new MacroStateDTO(outputGapEma: 0.0, corporateDefaultRateEma: 0.060), $this->mathUtility);

        $this->assertGreaterThan(2.0 * $normal->netChargeOffs, $spike->netChargeOffs);
        $this->assertEqualsWithDelta($normal->clampedMargin, $spike->clampedMargin, 1e-12, 'credit losses do not touch the operating margin');
        $this->assertSame(0.0, $spike->creditLossProvision, 'nothing is provisioned outside the roll-forward');
    }

    /**
     * Basel ASRF: on a granular book the idiosyncratic part of default diversifies away, so two banks with the same
     * underwriting and the same macro state charge off the same fraction of their books whatever their own draws.
     */
    public function testChargeOffsAreSystematicNotIdiosyncratic(): void
    {
        $macro = new MacroStateDTO(outputGapEma: -0.02, retailDefaultRateEma: 0.04, corporateDefaultRateEma: 0.035);
        $rates = [];
        foreach ([[0.0, 0.0, 2.5], [0.0, 0.0, -2.5], [1.0, -1.0, 0.0]] as $i => $draws) { // the third draw was the old idiosyncratic default Z
            $stock = $this->creditTestBank('SYS' . $i);
            $result = $this->model->computeActualFinancials($stock, 1_000_000_000.0, 0.50, 200_000_000.0, 0.10, $macro, $this->createMathUtilityMock($draws));
            $rates[] = $result->netChargeOffs / $this->model->resolveEarningAssets($stock);
        }

        $this->assertEqualsWithDelta($rates[0], $rates[1], 1e-15);
        $this->assertEqualsWithDelta($rates[0], $rates[2], 1e-15);
    }

    /**
     * The through-the-cycle rate is the book's mean loss over the credit cycle: with the systematic factor standard
     * normal (so both macro default rates average at their baselines) and flat collateral, the segment losses average
     * to each segment's charge-off rate, and a neutral H.8 book to 0.92% a year (US banks: 0.89%, FRED CORALACBS).
     */
    public function testOverTheCycleTheBookLosesItsThroughTheCycleRate(): void
    {
        $stock = $this->creditTestBank('NEUTRAL');
        $math = new MathUtility();
        $step = 0.05;
        $meanLoss = 0.0;
        for ($z = -7.0; $z <= 7.0 + 1e-9; $z += $step) {
            $macro = new MacroStateDTO(
                retailDefaultRateEma: $math->calculateVasicekExpectedLoss($z, MacroEngine::RETAIL_DEFAULT_BASELINE, CreditFiscalSubsystem::RETAIL_ASRF_RHO, 1.0),
                corporateDefaultRateEma: $math->calculateVasicekExpectedLoss($z, MacroEngine::CORPORATE_DEFAULT_BASELINE, CreditFiscalSubsystem::CORPORATE_DEFAULT_RHO, 1.0),
            );
            $stock->setEarningsMomentumZ([]);
            $result = $this->model->computeActualFinancials($stock, 1_000_000_000.0, 0.50, 200_000_000.0, 0.10, $macro, $this->mathUtility);
            $meanLoss += $step * exp(-0.5 * $z * $z) / sqrt(2.0 * M_PI) * $result->netChargeOffs * FinancialConstants::QUARTERS_PER_YEAR / $this->model->resolveEarningAssets($stock);
        }

        $ttc = $this->model->getThroughTheCycleCreditLossRate($stock);
        $this->assertEqualsWithDelta($ttc, $meanLoss, 0.002 * $ttc);
        $this->assertEqualsWithDelta(
            CommercialBankBusinessModel::RESIDENTIAL_MORTGAGE_SHARE * CommercialBankBusinessModel::RESIDENTIAL_CHARGE_OFF_RATE
                + CommercialBankBusinessModel::CONSUMER_LOAN_SHARE * CommercialBankBusinessModel::CONSUMER_CHARGE_OFF_RATE
                + CommercialBankBusinessModel::COMMERCIAL_REAL_ESTATE_SHARE * CommercialBankBusinessModel::COMMERCIAL_REAL_ESTATE_CHARGE_OFF_RATE
                + (1.0 - CommercialBankBusinessModel::RESIDENTIAL_MORTGAGE_SHARE - CommercialBankBusinessModel::CONSUMER_LOAN_SHARE - CommercialBankBusinessModel::COMMERCIAL_REAL_ESTATE_SHARE) * CommercialBankBusinessModel::BUSINESS_CHARGE_OFF_RATE,
            $ttc,
            1e-12
        );
        $this->assertEqualsWithDelta(0.0092, $ttc, 0.0002, 'a neutral H.8 book loses ~0.92% a year');
    }

    /**
     * Calibrating each segment to its own charge-off mean at a common LGD puts the low-PD segments furthest out in a
     * bust, the order US banks' charge-offs peaked in in 2009-10 relative to their means: mortgages 6.5x, CRE 5.3x,
     * business 3.5x, consumer 2.5x (FRED).
     */
    public function testInABustTheLowPdSegmentsSwingFurthest(): void
    {
        $math = new MathUtility();
        $z = -2.0;
        $swing = static fn (float $rate, float $rho): float => $math->calculateVasicekExpectedLoss($z, $rate / CommercialBankBusinessModel::LGD_BASELINE, $rho, 1.0) / ($rate / CommercialBankBusinessModel::LGD_BASELINE);

        $residential = $swing(CommercialBankBusinessModel::RESIDENTIAL_CHARGE_OFF_RATE, CreditFiscalSubsystem::RETAIL_ASRF_RHO);
        $consumer = $swing(CommercialBankBusinessModel::CONSUMER_CHARGE_OFF_RATE, CreditFiscalSubsystem::RETAIL_ASRF_RHO);
        $commercialRealEstate = $swing(CommercialBankBusinessModel::COMMERCIAL_REAL_ESTATE_CHARGE_OFF_RATE, CreditFiscalSubsystem::CORPORATE_DEFAULT_RHO);
        $business = $swing(CommercialBankBusinessModel::BUSINESS_CHARGE_OFF_RATE, CreditFiscalSubsystem::CORPORATE_DEFAULT_RHO);

        $this->assertGreaterThan($consumer, $residential);
        $this->assertGreaterThan($business, $commercialRealEstate);
    }

    /**
     * Frye (2000) collateral damage: a house-price fall since the loans were written raises loss given default on the
     * mortgage book. A mortgage lender takes it; a business lender with no residential book does not.
     */
    public function testAHousePriceFallSinceOriginationHitsTheMortgageBook(): void
    {
        $mortgageLender = new class extends CommercialBankBusinessModel {
            protected function resolveLoanBookMix(Stock $stock): array
            {
                return ['residential' => 0.80, 'consumer' => 0.0, 'commercial_real_estate' => 0.0, 'business' => 0.20];
            }
        };
        $businessLender = new class extends CommercialBankBusinessModel {
            protected function resolveLoanBookMix(Stock $stock): array
            {
                return ['residential' => 0.0, 'consumer' => 0.0, 'commercial_real_estate' => 0.0, 'business' => 1.0];
            }
        };

        $lossRate = function (CommercialBankBusinessModel $model, float $housePrice): float {
            $stock = $this->creditTestBank('MIX');
            $stock->setEarningsMomentumZ([CommercialBankBusinessModel::STATE_RESIDENTIAL_ORIGINATION_PRICE => 100.0]);
            $result = $model->computeActualFinancials($stock, 1_000_000_000.0, 0.50, 200_000_000.0, 0.0, new MacroStateDTO(residentialPropertyIndexEma: $housePrice), $this->mathUtility);

            return $result->netChargeOffs / $model->resolveEarningAssets($stock);
        };

        // At long-run default rates each segment defaults at its conditional PD for the factor those rates imply.
        $math = new MathUtility();
        $householdZ = $math->calculateVasicekSystematicFactor(MacroEngine::RETAIL_DEFAULT_BASELINE, MacroEngine::RETAIL_DEFAULT_BASELINE, CreditFiscalSubsystem::RETAIL_ASRF_RHO);
        $corporateZ = $math->calculateVasicekSystematicFactor(MacroEngine::CORPORATE_DEFAULT_BASELINE, MacroEngine::CORPORATE_DEFAULT_BASELINE, CreditFiscalSubsystem::CORPORATE_DEFAULT_RHO);
        $mortgageLoss = 0.80 * $math->calculateVasicekExpectedLoss($householdZ, CommercialBankBusinessModel::RESIDENTIAL_CHARGE_OFF_RATE / 0.45, CreditFiscalSubsystem::RETAIL_ASRF_RHO, 1.0);
        $businessLoss = 0.20 * $math->calculateVasicekExpectedLoss($corporateZ, CommercialBankBusinessModel::BUSINESS_CHARGE_OFF_RATE / 0.45, CreditFiscalSubsystem::CORPORATE_DEFAULT_RHO, 1.0) * 0.45;
        $this->assertEqualsWithDelta(
            ($mortgageLoss * (1.0 - 0.55 * 0.70) + $businessLoss) / ($mortgageLoss * 0.45 + $businessLoss),
            $lossRate($mortgageLender, 70.0) / $lossRate($mortgageLender, 100.0),
            1e-6,
            'a 30% fall lifts mortgage LGD from 45% to 61.5%'
        );
        $this->assertEqualsWithDelta($lossRate($businessLender, 100.0), $lossRate($businessLender, 70.0), 1e-15);

        // The reference walks toward today's price over the book's age, so a fall that persists is slowly written into new loans.
        $stock = $this->creditTestBank('MIX');
        $stock->setEarningsMomentumZ([CommercialBankBusinessModel::STATE_RESIDENTIAL_ORIGINATION_PRICE => 100.0]);
        $result = $mortgageLender->computeActualFinancials($stock, 1_000_000_000.0, 0.50, 200_000_000.0, 0.0, new MacroStateDTO(residentialPropertyIndexEma: 70.0), $this->mathUtility);
        $reference = $result->streamZ[CommercialBankBusinessModel::STATE_RESIDENTIAL_ORIGINATION_PRICE];
        $this->assertEqualsWithDelta(100.0 - 30.0 * (1.0 - exp(-0.25 / CommercialBankBusinessModel::COLLATERAL_ORIGINATION_YEARS)), $reference, 1e-9);
    }

    private function creditTestBank(string $ticker): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setBeta('1.0');
        $stock->setTotalEquity('10000000000');
        $stock->setCustomerDeposits('80000000000');
        $stock->setWholesaleDebt('10000000000');
        $stock->setCorporateTreasury('5000000000');

        return $stock;
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

        // The squeeze is disclosed in the net interest margin: the dollars it moved the cost ratio by.
        $this->assertLessThan(0.0, $steepResult->netInterestSqueeze, 'a steep curve widens the margin');
        $this->assertGreaterThan(0.0, $invertedResult->netInterestSqueeze, 'an inverted curve narrows it');
        $this->assertEqualsWithDelta(
            ($invertedResult->clampedMargin - $steepResult->clampedMargin) * $invertedResult->actualRevenue,
            $invertedResult->netInterestSqueeze - $steepResult->netInterestSqueeze,
            1.0
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
        // RWA = 95B * 0.72 + 5B * 0.0 = 68.4B
        $rwa = $this->model->calculateRiskWeightedAssets($bank);
        $this->assertEqualsWithDelta(68_400_000_000.0, $rwa, 1.0);

        // CET1 = 10B / 68.4B = ~14.6%
        $cet1 = $this->model->calculateCet1Ratio($bank);
        $this->assertEqualsWithDelta(10.0 / 68.4, $cet1, 0.0001);
        $this->assertGreaterThan(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT, $cet1);

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
        // CET1 = 5B / (90B * 0.72) = ~7.7% (between the 4.5% minimum and the 9.4% requirement)
        $cet1 = $this->model->calculateCet1Ratio($bank);
        $this->assertGreaterThan(CommercialBankBusinessModel::BASEL_MIN_CET1_RATIO, $cet1);
        $this->assertLessThan(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT, $cet1);

        // Below the requirement the dividend cap is strictly 0.0
        $divCap = $this->model->getRegulatoryDividendCap($bank, 5_000_000_000.0);
        $this->assertSame(0.0, $divCap);
    }

    public function testCountercyclicalCapitalBufferHaltsDividendsWhenActivated(): void
    {
        $bank = new Stock();
        $bank->setTicker('CCYB_BANK');
        $bank->setTotalEquity('7500000000'); // $7.5B
        $bank->setCustomerDeposits('80000000000'); // $80B
        $bank->setWholesaleDebt('10000000000'); // $10B
        $bank->setCorporateTreasury('5000000000'); // $5B

        // CET1 = 7.5B / (92.5B * 0.72) = ~11.3% (> 9.4% requirement, but < 9.4% + 2.0% CCyB = 11.4%)
        $cet1 = $this->model->calculateCet1Ratio($bank);
        $this->assertGreaterThan(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT, $cet1);
        $this->assertLessThan(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT + 0.020, $cet1);

        $neutralMacro = new \App\DTO\MacroStateDTO(countercyclicalBufferRateEma: 0.0);
        $ccybMacro = new \App\DTO\MacroStateDTO(countercyclicalBufferRateEma: 0.020);

        // Under neutral macro, bank meets CCB and distributions are permitted
        $this->assertSame(1.0, $this->model->getRegulatoryDividendCap($bank, 5_000_000_000.0, $neutralMacro));

        // When macro countercyclical buffer activates (+200bps), required CET1 rises to 11.4% and halts dividends
        $this->assertSame(0.0, $this->model->getRegulatoryDividendCap($bank, 5_000_000_000.0, $ccybMacro));
    }

    public function testTheRequirementInForceSetsThePayoutStop(): void
    {
        $bank = new Stock();
        $bank->setTicker('REQ_BANK');
        $bank->setTotalEquity('7000000000');
        $bank->setCustomerDeposits('80000000000');
        $bank->setWholesaleDebt('10000000000');
        $bank->setCorporateTreasury('5000000000');

        // CET1 = 7B / (92B * 0.72) = ~10.6%: inside a light requirement, short of a strict one.
        $cet1 = $this->model->calculateCet1Ratio($bank);
        $this->assertEqualsWithDelta(7.0 / (92.0 * 0.72), $cet1, 1e-9);

        $light = new MacroStateDTO(bankCapitalRequirement: 0.0843);
        $strict = new MacroStateDTO(bankCapitalRequirement: 0.1315);
        $this->assertSame(1.0, $this->model->getRegulatoryDividendCap($bank, 5_000_000_000.0, $light));
        $this->assertSame(0.0, $this->model->getRegulatoryDividendCap($bank, 5_000_000_000.0, $strict));
        $this->assertTrue($this->model->checkBuybackRegulatoryLockout($bank, 5_000_000_000.0, $strict));

        // Without a macro reading, the requirement in force at Year 1 applies.
        $this->assertEqualsWithDelta(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT, $this->model->capitalRequirement(null), 1e-15);
        $this->assertEqualsWithDelta(0.1315, $this->model->capitalRequirement($strict), 1e-15);
    }

    public function testAShadowBankHoldsTheBaselFloorNotTheDistrictRequirement(): void
    {
        $shadow = new ShadowBankBusinessModel();
        $strict = new MacroStateDTO(bankCapitalRequirement: 0.1315);

        $this->assertEqualsWithDelta(ShadowBankBusinessModel::BASEL_CCB_CET1_RATIO, $shadow->capitalRequirement($strict), 1e-15);
        $this->assertEqualsWithDelta(0.070, CommercialBankBusinessModel::BASEL_CCB_CET1_RATIO, 1e-15, 'Basel III: 4.5% Pillar 1 plus the 2.5% conservation buffer');
    }

    public function testACapitalTargetMovesWithTheRequirementOnRiskWeightedAssets(): void
    {
        $bank = new Stock();
        $bank->setTicker('LAKE');
        $bank->setIndustry('Banks - Diversified');
        $bank->setTotalEquity('1797687500000');
        $bank->setCustomerDeposits('19250000000000');
        $bank->setWholesaleDebt('350000000000');
        $bank->setCorporateTreasury('1925000000000');
        $bank->setEarningAssets('19472687500000'); // equity + deposits + wholesale - cash, as the seed books it

        $seedTarget = $this->model->getTargetCapitalRatio($bank);
        $this->assertNotNull($seedTarget);
        $this->assertEqualsWithDelta($seedTarget, $this->model->getTargetCapitalRatio($bank, new MacroStateDTO()), 1e-12, 'at the opening requirement the target is the seeded ratio');

        $density = $this->model->calculateRiskWeightedAssets($bank) / $bank->getTotalAssets();
        $raised = $this->model->getTargetCapitalRatio($bank, new MacroStateDTO(bankCapitalRequirement: FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT + 0.01));
        $this->assertEqualsWithDelta($seedTarget + (0.01 * $density), $raised, 1e-12, 'a point of requirement is a point of equity on risk-weighted assets');
        $this->assertGreaterThan(0.6, $density);
        $this->assertLessThan(0.7, $density, 'Lakebird runs at about the insured system\'s 64% density');
    }

    public function testInsolventBankTriggersBankSeizureShockEvent(): void
    {
        $bank = new Stock();
        $bank->setTicker('INSOLVENT_BANK');
        $bank->setBeta('1.0');
        $bank->setTotalEquity('2000000000'); // $2B
        $bank->setCustomerDeposits('80000000000'); // $80B
        $bank->setWholesaleDebt('10000000000'); // $10B
        $bank->setCorporateTreasury('5000000000'); // $5B

        // Earning Assets = 2B + 90B - 5B = 87B
        // CET1 = 2B / (87B * 0.72) = ~3.19% (< 4.5% Pillar 1 minimum)
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

    /**
     * A forward reserve is a balance, not a flow (ASC 326): a worse outlook raises the lifetime loss
     * TARGET the allowance converges to, and the ledger roll-forward books that build once. It must not
     * reach the variable margin, where it used to be charged again every quarter the outlook stayed bad.
     */
    public function testRecessionProbabilitySpikeRaisesTheReserveTargetNotTheRunningMargin(): void
    {
        $bank = new Stock();
        $bank->setTicker('CECL_BANK');
        $bank->setTotalEquity('5000000000');
        $bank->setCustomerDeposits('40000000000');

        $mathMock = $this->createMathUtilityMock([0.0, 0.0, 0.0]);
        $macroLowRisk = new MacroStateDTO(outputGapEma: 0.0, recessionProbabilityEma: 0.10);
        $macroHighRisk = new MacroStateDTO(outputGapEma: 0.0, recessionProbabilityEma: 0.70);

        $resultLowRisk = $this->model->computeActualFinancials($bank, 1_000_000_000.0, 0.50, 200_000_000.0, 0.0, $macroLowRisk, $mathMock);
        $resultHighRisk = $this->model->computeActualFinancials($bank, 1_000_000_000.0, 0.50, 200_000_000.0, 0.0, $macroHighRisk, $mathMock);

        $this->assertEqualsWithDelta($resultLowRisk->clampedMargin, $resultHighRisk->clampedMargin, 1e-12, 'the forecast reserve is not a running cost');

        $calm = $this->model->getForwardCreditLossMultiplier($bank, $macroLowRisk);
        $stressed = $this->model->getForwardCreditLossMultiplier($bank, $macroHighRisk);
        $this->assertEqualsWithDelta(1.0, $calm, 1e-9, 'at or below the baseline outlook the estimate is the through-the-cycle one');
        $this->assertEqualsWithDelta(
            1.0 + (0.70 - CommercialBankBusinessModel::CECL_BASELINE_RECESSION_PROB) * CommercialBankBusinessModel::CECL_RESERVE_RECESSION_SENSITIVITY,
            $stressed,
            1e-9
        );

        // Spreads: widening lifts the target, a benign market releases part of it, never past the floor.
        $wide = $this->model->getForwardCreditLossMultiplier($bank, new MacroStateDTO(macroCreditSpreadEma: CommercialBankBusinessModel::CECL_BASELINE_CREDIT_SPREAD + 0.03));
        $this->assertEqualsWithDelta(1.0 + 0.03 * CommercialBankBusinessModel::CECL_RESERVE_SPREAD_SENSITIVITY, $wide, 1e-9);
        $benign = $this->model->getForwardCreditLossMultiplier($bank, new MacroStateDTO(macroCreditSpreadEma: 0.0));
        $this->assertEqualsWithDelta(1.0 - CommercialBankBusinessModel::CECL_BASELINE_CREDIT_SPREAD * CommercialBankBusinessModel::CECL_RESERVE_SPREAD_SENSITIVITY, $benign, 1e-9);
        $this->assertGreaterThanOrEqual(CommercialBankBusinessModel::CECL_RESERVE_MULTIPLIER_FLOOR, $benign);
    }

    public function testDepositThrottleSuppressesWholesaleDebtExpansionWhenAdequatelyFunded(): void
    {
        // Bank funded 80% by deposits with adequate treasury cash
        $totalDebt = 100_000_000_000.0;
        $customerDeposits = 80_000_000_000.0; // 80% deposit ratio >= 50% upper bound
        $targetOperatingCash = 10_000_000_000.0;
        $currentTreasury = 15_000_000_000.0; // Sufficient treasury

        $result = $this->model->getDebtExpansionAggressiveness(
            spreadMultiplier: 1.0,
            totalDebt: $totalDebt,
            customerDeposits: $customerDeposits,
            targetOperatingCash: $targetOperatingCash,
            currentTreasury: $currentTreasury
        );

        $this->assertSame(0.0, $result->probability, 'Deposit-funded bank with adequate cash must not borrow wholesale debt.');
        $this->assertSame(0.0, $result->aggressiveness, 'Aggressiveness must be 0 for deposit-funded bank.');
    }

    public function testLiquidityShortfallTriggersUrgentWholesaleBorrowing(): void
    {
        // Bank suffering liquidity shortfall (treasury < targetOperatingCash)
        $totalDebt = 100_000_000_000.0;
        $customerDeposits = 80_000_000_000.0;
        $targetOperatingCash = 10_000_000_000.0;
        $currentTreasury = 2_000_000_000.0; // 80% shortfall

        $result = $this->model->getDebtExpansionAggressiveness(
            spreadMultiplier: 0.5,
            totalDebt: $totalDebt,
            customerDeposits: $customerDeposits,
            targetOperatingCash: $targetOperatingCash,
            currentTreasury: $currentTreasury
        );

        $this->assertGreaterThan(0.50, $result->probability, 'Liquidity shortfall must boost borrowing probability.');
        $this->assertGreaterThan(0.20, $result->aggressiveness, 'Liquidity shortfall must boost borrowing aggressiveness.');
    }

    public function testUnfundedExpansionCapacityDeductsExcessCash(): void
    {
        $baseCapacity = 10_000_000_000.0;
        $excessCash = 4_000_000_000.0;

        $unfunded = $this->model->getUnfundedExpansionCapacity($baseCapacity, $excessCash);
        $this->assertEqualsWithDelta(6_000_000_000.0, $unfunded, 1.0, 'Unfunded expansion capacity must deduct excess treasury cash.');

        // When excess cash exceeds base capacity, unfunded is zero
        $unfundedZero = $this->model->getUnfundedExpansionCapacity($baseCapacity, 15_000_000_000.0);
        $this->assertSame(0.0, $unfundedZero);
    }

    public function testBuybacksStrictlyProhibitedDuringNegativeEarnings(): void
    {
        $excessCash = 50_000_000_000.0;
        $negativeEarnings = -5_000_000_000.0;

        // Even for a mega-hoarder, negative earnings must yield zero buyback budget
        $spendMega = $this->model->calculateMaxBuybackSpend($excessCash, $negativeEarnings, true);
        $this->assertSame(0.0, $spendMega, 'Mega-hoarder bank cannot spend depositor cash on buybacks during net losses.');

        $spendNormal = $this->model->calculateMaxBuybackSpend($excessCash, $negativeEarnings, false);
        $this->assertSame(0.0, $spendNormal, 'Bank cannot buy back shares during net losses.');

        // Positive earnings: buybacks are capped by positive retained earnings
        $positiveEarnings = 2_000_000_000.0;
        $spendPositive = $this->model->calculateMaxBuybackSpend($excessCash, $positiveEarnings, true);
        $this->assertLessThanOrEqual($positiveEarnings, $spendPositive, 'Buyback spend must never exceed retained earnings.');
    }

    public function testUpdateDynamicRoicClampsExtremeReturns(): void
    {
        $stock = new Stock();
        $stock->setTotalEquity('1000.0'); // Near-zero equity
        $hugeIncome = 50_000_000_000.0; // Trillions in return if unclamped

        $roic = $this->model->updateDynamicRoic(
            $stock,
            actualTotalNetIncome: $hugeIncome,
            investedCapital: 1_000_000.0,
            ebit: 60_000_000_000.0,
            corporateTaxRate: 0.21
        );

        $this->assertLessThanOrEqual(\App\Service\Math\FinancialConstants::MAX_REPORTED_RETURN, $roic);
        $this->assertGreaterThanOrEqual(\App\Service\Math\FinancialConstants::MIN_REPORTED_RETURN, $roic);
        $this->assertEqualsWithDelta(\App\Service\Math\FinancialConstants::MAX_REPORTED_RETURN, (float) $stock->getCurrentRoe(), 0.0001);
    }

    /**
     * Underwriting posture has to reach the loss physics, or two banks funded identically are priced as
     * though they lend identically. CreditRiskAppetite scales the Vasicek long-run default probability
     * around a neutral 0.50, so the tuned regional lender carries a materially heavier through-the-cycle
     * charge and a heavier lifetime allowance than the tuned universal bank.
     */
    public function testCreditRiskAppetiteScalesTheThroughTheCycleLossRate(): void
    {
        // The H.8 mix for every ticker, so only the appetite differs.
        $model = new class extends CommercialBankBusinessModel {
            protected function resolveLoanBookMix(Stock $stock): array
            {
                return ['residential' => self::RESIDENTIAL_MORTGAGE_SHARE, 'consumer' => self::CONSUMER_LOAN_SHARE, 'commercial_real_estate' => self::COMMERCIAL_REAL_ESTATE_SHARE, 'business' => 1.0 - self::RESIDENTIAL_MORTGAGE_SHARE - self::CONSUMER_LOAN_SHARE - self::COMMERCIAL_REAL_ESTATE_SHARE];
            }
        };
        $conservative = (new Stock())->setTicker('LAKE'); // appetite 0.40
        $aggressive   = (new Stock())->setTicker('RIVR'); // appetite 0.60
        $untuned      = (new Stock())->setTicker('NO_OVERRIDES_FOR_THIS_TICKER');

        $sectorLoss = $model->getThroughTheCycleCreditLossRate();
        $this->assertEqualsWithDelta($sectorLoss, $model->getThroughTheCycleCreditLossRate($untuned), 1e-12, 'an absent firm falls back to the sector rate');
        $this->assertEqualsWithDelta(0.80 * $sectorLoss, $model->getThroughTheCycleCreditLossRate($conservative), 1e-12);
        $this->assertEqualsWithDelta(1.20 * $sectorLoss, $model->getThroughTheCycleCreditLossRate($aggressive), 1e-12);
    }

    /** An appetite outside the viable commercial envelope is clamped, not passed through to the loss model. */
    public function testCreditRiskAppetiteIsClampedToTheViableUnderwritingEnvelope(): void
    {
        $sectorLoss = $this->model->getThroughTheCycleCreditLossRate();
        $floorLoss = $sectorLoss * CommercialBankBusinessModel::MIN_CREDIT_RISK_APPETITE / CommercialBankBusinessModel::NEUTRAL_CREDIT_RISK_APPETITE;
        $ceilingLoss = $sectorLoss * CommercialBankBusinessModel::MAX_CREDIT_RISK_APPETITE / CommercialBankBusinessModel::NEUTRAL_CREDIT_RISK_APPETITE;

        $this->assertGreaterThan(0.0, $floorLoss);
        foreach (['LAKE', 'RIVR', 'CLAMP_TEST'] as $ticker) {
            $loss = $this->model->getThroughTheCycleCreditLossRate((new Stock())->setTicker($ticker));
            $this->assertGreaterThanOrEqual($floorLoss * 0.5, $loss);
            $this->assertLessThanOrEqual($ceilingLoss * 1.5, $loss);
        }
    }

    /**
     * Once the earning-asset ledger is open the physics is struck on the book it carries, net of the losses
     * already reserved, and the quarter's credit entries come back in dollars: what went bad, and what was
     * charged beyond the through-the-cycle loss the cost base already holds.
     */
    public function testCreditLossHooksAndLedgerFeedThePhysics(): void
    {
        $this->assertGreaterThan(0.0, $this->model->getThroughTheCycleCreditLossRate());
        $this->assertEqualsWithDelta(CommercialBankBusinessModel::CECL_LIFETIME_HORIZON_YEARS, $this->model->getCreditLossHorizonYears(), 1e-9);

        $stock = new Stock();
        $stock->setTicker('LDGR');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('5000000000');
        $stock->setCustomerDeposits('40000000000');
        $stock->setWholesaleDebt('5000000000');
        $stock->setCorporateTreasury('2000000000');

        // Before the ledger: the funding proxy. After: the book net of its allowance, whatever cash does.
        $this->assertEqualsWithDelta(48_000_000_000.0, $this->model->resolveEarningAssets($stock), 1.0);
        $stock->setEarningAssets('45000000000');
        $stock->setCreditLossAllowance('900000000');
        $this->assertEqualsWithDelta(44_100_000_000.0, $this->model->resolveEarningAssets($stock), 1.0);
        $this->assertEqualsWithDelta(44_100_000_000.0 * CommercialBankBusinessModel::BASEL_RISK_WEIGHT_EARNING_ASSETS, $this->model->calculateRiskWeightedAssets($stock), 1.0, 'risk weights apply to the book, not the proxy');

        $macro = new MacroStateDTO(outputGapEma: 0.0, policyRateEma: 0.04, yield2yEma: 0.04, yield10yEma: 0.045, macroCreditSpreadEma: 0.02);
        $result = $this->model->computeActualFinancials($stock, expectedRevenue: 1_000_000_000.0, realizedVariableMargin: 0.50, fixedCosts: 200_000_000.0, baselineVol: 0.10, macroState: $macro, mathUtility: $this->mathUtility);

        $this->assertGreaterThanOrEqual(0.0, $result->netChargeOffs);
        $this->assertLessThan(44_100_000_000.0 * 0.05, $result->netChargeOffs, 'a quarter of charge-offs is a small fraction of the book');
        $this->assertTrue(is_finite($result->creditLossProvision));
    }


    // --- Deposits Channel ---

    /** Drechsler, Savov & Schnabl (2017): what the bank pays depositors is its own franchise beta scaled by how far the system is passing rates through. */
    public function testDepositInterestFollowsTheSystemDepositBeta(): void
    {
        $stock = new Stock();
        $stock->setTicker('LAKE');
        $stock->setCustomerDeposits('1000.0');
        $stock->setWholesaleDebt('0.0');
        $stock->setFloatingDebtRatio('0.0');

        $neutralSystem = MacroStateDTO::fromArray(['system_deposit_beta_ema' => MacroEngine::SYSTEM_DEPOSIT_BETA_BASE]);
        $tightSystem = MacroStateDTO::fromArray(['system_deposit_beta_ema' => 2.0 * MacroEngine::SYSTEM_DEPOSIT_BETA_BASE]);

        $withoutMacro = $this->model->calculateInterestExpenseAndWholesaleRate($stock, 0.05, 0.05, 0.05, 0.05, 15.0, 100.0, 1000.0);
        $neutral = $this->model->calculateInterestExpenseAndWholesaleRate($stock, 0.05, 0.05, 0.05, 0.05, 15.0, 100.0, 1000.0, $neutralSystem);
        $tight = $this->model->calculateInterestExpenseAndWholesaleRate($stock, 0.05, 0.05, 0.05, 0.05, 15.0, 100.0, 1000.0, $tightSystem);

        $this->assertEqualsWithDelta($withoutMacro->interestExpense, $neutral->interestExpense, 1e-9, 'At the normalisation level the system scale is one, so a caller without a macro reading gets the same answer.');
        $this->assertEqualsWithDelta(2.0 * $neutral->interestExpense, $tight->interestExpense, 1e-9, 'A system passing twice as much through doubles the deposit interest on the same franchise.');
    }

    /**
     * A bank's deposit rate must never exceed the policy rate, even under extreme system deposit beta multipliers.
     */
    public function testEffectiveDepositBetaIsCappedAtMaximum(): void
    {
        $stock = new Stock();
        $stock->setTicker('LAKE');
        $stock->setCustomerDeposits('1000.0');
        $stock->setWholesaleDebt('0.0');
        $stock->setFloatingDebtRatio('0.0');

        // System beta elevated to maximum (3.0x normalisation scale)
        $extremeSystem = MacroStateDTO::fromArray(['system_deposit_beta_ema' => 0.60]);
        $policyRate = 0.06;

        // Utilization = 0 (debt = 0) with 0 customer deposits in ratio gives depositBeta = MAX_DEPOSIT_BETA (0.70)
        $result = $this->model->calculateInterestExpenseAndWholesaleRate(
            $stock, 0.05, 0.05, 0.05, $policyRate, 15.0, 1000.0, 0.0, $extremeSystem
        );

        // Max deposit rate is 0.70 * 0.06 = 0.042; deposit interest on $1000 is $42.0
        $maxAllowedInterest = 1000.0 * $policyRate * CommercialBankBusinessModel::MAX_DEPOSIT_BETA;
        $this->assertLessThanOrEqual($maxAllowedInterest, $result->interestExpense);
        $this->assertLessThan($policyRate * 1000.0, $result->interestExpense, 'Deposit rate must never exceed the policy rate.');
    }

    public function testMoneyFundMigrationDrainsTheDepositBase(): void
    {
        $stock = new Stock();
        $stock->setTicker('LAKE');
        $stock->setTotalEquity('100.0');
        $stock->setIndustry('Banks - Regional');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);

        $run = function (float $share, float $shareEma) use ($stock, $mathMock): float {
            $macro = MacroStateDTO::fromArray([
                'inflation_ema' => 0.02,
                'output_gap_ema' => 0.0,
                'policy_rate_ema' => 0.05,
                'money_market_fund_share' => $share,
                'money_market_fund_share_ema' => $shareEma,
            ]);
            $state = ['customerDeposits' => 1000.0, 'wholesaleDebt' => 200.0, 'treasury' => 50.0];
            $this->model->processPassiveLiabilityGrowth($stock, $macro, $state, $mathMock);

            return $state['customerDeposits'];
        };

        $steady = $run(MacroEngine::MMF_SHARE_BASE, MacroEngine::MMF_SHARE_BASE);
        $migrating = $run(MacroEngine::MMF_SHARE_BASE + 0.0125, MacroEngine::MMF_SHARE_BASE);

        $this->assertLessThan($steady, $migrating, 'A quarter in which money funds gain share is a quarter deposits leave.');
        // Exactly the migrating share of the base, times the drag.
        $this->assertEqualsWithDelta(1000.0 * 0.0125 * CommercialBankBusinessModel::MMF_MIGRATION_DEPOSIT_DRAG, $steady - $migrating, 1e-9);
    }

    public function testMoneyFundOutflowsReturnWhenTheShareFallsBack(): void
    {
        $stock = new Stock();
        $stock->setTicker('LAKE');
        $stock->setTotalEquity('100.0');
        $stock->setIndustry('Banks - Regional');
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);

        $deposits = 1000.0;
        foreach ([[0.0125, 0.0], [0.0, 0.0125]] as [$shareMove, $emaMove]) {
            $macro = MacroStateDTO::fromArray([
                'inflation_ema' => -(MacroEngine::TFP_DRIFT + MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE),
                'policy_rate_ema' => 0.05,
                'money_market_fund_share' => MacroEngine::MMF_SHARE_BASE + $shareMove,
                'money_market_fund_share_ema' => MacroEngine::MMF_SHARE_BASE + $emaMove,
            ]);
            $state = ['customerDeposits' => $deposits, 'wholesaleDebt' => 200.0, 'treasury' => 50.0, 'events' => []];
            $this->model->processPassiveLiabilityGrowth($stock, $macro, $state, $mathMock);
            $deposits = $state['customerDeposits'];
        }

        // Out as the rate cycle opens the deposit spread, back in as it closes: a round trip in the share is a
        // round trip in the base. Counting only the outflow drained a fifth of every bank's deposits in 20 years.
        $this->assertEqualsWithDelta(1000.0, $deposits, 1000.0 * 0.0125 * 0.0125 * 2.0);
    }

    public function testDepositBaseGrowsWithNominalIncomeWhateverTheBanksLeverage(): void
    {
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $macro = MacroStateDTO::fromArray([
            'inflation_ema' => 0.03,
            'policy_rate_ema' => 0.05,
            'output_gap_ema' => -0.03,
            'money_market_fund_share' => MacroEngine::MMF_SHARE_BASE,
            'money_market_fund_share_ema' => MacroEngine::MMF_SHARE_BASE,
        ]);

        $grow = function (float $equity, float $wholesaleDebt) use ($macro, $mathMock): float {
            $stock = new Stock();
            $stock->setTicker('LAKE');
            $stock->setTotalEquity((string) $equity);
            $stock->setIndustry('Credit Services');
            $state = ['customerDeposits' => 1000.0, 'wholesaleDebt' => $wholesaleDebt, 'treasury' => 50.0, 'events' => []];
            $this->model->processPassiveLiabilityGrowth($stock, $macro, $state, $mathMock);

            return $state['customerDeposits'] - 1000.0;
        };

        // Money demand has unit income elasticity: the base compounds at inflation plus potential growth, a
        // quarter at a time, through a recession-sized gap and at a high policy rate alike.
        $trend = 0.03 + MacroEngine::TFP_DRIFT + MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE;
        $this->assertEqualsWithDelta(1000.0 * $trend / 4.0, $grow(1000.0, 0.0), 1e-9);
        // A lender at its leverage limit pays the floor beta; that prices its funding, it does not shrink the
        // deposit base it is handed. Scaling growth by the beta froze card lenders' books for twenty years.
        $this->assertEqualsWithDelta($grow(1000.0, 0.0), $grow(150.0, 50.0), 1e-9);
    }

    public function testDepositGrowthAtTrendIsNotNews(): void
    {
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $stock = new Stock();
        $stock->setTicker('LAKE');
        $stock->setTotalEquity('100.0');
        $stock->setIndustry('Banks - Regional');
        $macro = MacroStateDTO::fromArray([
            'inflation_ema' => 0.06,
            'policy_rate_ema' => 0.03,
            'money_market_fund_share' => MacroEngine::MMF_SHARE_BASE,
            'money_market_fund_share_ema' => MacroEngine::MMF_SHARE_BASE,
        ]);
        $state = ['customerDeposits' => 1000.0, 'wholesaleDebt' => 200.0, 'treasury' => 50.0, 'events' => []];

        $this->model->processPassiveLiabilityGrowth($stock, $macro, $state, $mathMock);

        // 2% in the quarter clears the 0.5% capture threshold, but it is the economy's growth, not share won.
        $this->assertEqualsWithDelta(20.0, $state['customerDeposits'] - 1000.0, 1e-9);
        $this->assertSame([], $state['events']);
    }

    /**
     * The bank levy: the full rate on wholesale funding that reprices within a year and on the revolver, half on the
     * rest and on the deposits deposit insurance leaves uncovered; equity and insured deposits are left out. A non-bank
     * lender owes none.
     */
    public function testTheBankLevyIsChargedOnFundingAndUninsuredDeposits(): void
    {
        $bank = new Stock();
        $bank->setTicker('LEVY');
        $bank->setWholesaleDebt('100000000000');
        $bank->setFloatingDebtRatio('0.4');
        $bank->setRevolverDrawn('10000000000');
        $bank->setCustomerDeposits('1000000000000');
        $macro = new MacroStateDTO(bankLevyRate: 0.0021);

        $short = (100.0e9 * 0.4) + 10.0e9;
        $long = (100.0e9 * 0.6) + (1000.0e9 * (1.0 - 0.604));
        $this->assertEqualsWithDelta((0.0021 * $short) + (0.00105 * $long), (new CommercialBankBusinessModel())->calculateAnnualBankLevy($bank, $macro), 1.0);
        $this->assertSame(0.0, (new CommercialBankBusinessModel())->calculateAnnualBankLevy($bank, new MacroStateDTO()), 'No levy at the founding.');
        $this->assertSame(0.0, (new \App\Service\Model\Sector\ShadowBankBusinessModel())->calculateAnnualBankLevy($bank, $macro));
        $this->assertSame(0.0, (new \App\Service\Model\Sector\BrokerageBusinessModel())->calculateAnnualBankLevy($bank, $macro));
        $this->assertSame(0.0, (new \App\Service\Model\Sector\ClearingHouseBusinessModel())->calculateAnnualBankLevy($bank, $macro));
        $this->assertGreaterThan(0.0, (new \App\Service\Model\Sector\InvestmentBankBusinessModel())->calculateAnnualBankLevy($bank, $macro));
    }
}
