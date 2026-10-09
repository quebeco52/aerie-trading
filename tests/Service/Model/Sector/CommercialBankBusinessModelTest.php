<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\Service\Model\ModelParam;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\CreditRisk;
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
     * against EBIT, so the operating target must fund it: revenue per unit of book rises by exactly the loss rate
     * (the firm's own, CreditRiskAppetite-scaled) over a loss-free control, since the cost base is struck on the book
     * and does not grow with it.
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
        $grossYield = static fn (array $metrics): float => $metrics['baseline_roic'] / (0.40 * (1.0 - $macro->corporateTaxRate));
        $this->assertEqualsWithDelta($lossRate, $grossYield($withLosses) - $grossYield($without), 1e-9);
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
     * to each segment's charge-off rate, and a neutral H.8 loan book to 0.92% a year (US banks: 0.89%, FRED CORALACBS).
     * The rates are on loans, so the earning-asset book, a fifth of it securities, loses them on its loan share alone.
     */
    public function testOverTheCycleTheBookLosesItsThroughTheCycleRate(): void
    {
        $stock = $this->creditTestBank('NEUTRAL');
        $math = new MathUtility();
        $step = 0.05;
        $meanLoss = 0.0;
        for ($z = -7.0; $z <= 7.0 + 1e-9; $z += $step) {
            $macro = new MacroStateDTO(
                retailDefaultRateEma: CreditRisk::calculateVasicekExpectedLoss($z, MacroEngine::RETAIL_DEFAULT_BASELINE, CreditFiscalSubsystem::RETAIL_ASRF_RHO, 1.0),
                corporateDefaultRateEma: CreditRisk::calculateVasicekExpectedLoss($z, MacroEngine::CORPORATE_DEFAULT_BASELINE, CreditFiscalSubsystem::CORPORATE_DEFAULT_RHO, 1.0),
            );
            $stock->setEarningsMomentumZ([]);
            $result = $this->model->computeActualFinancials($stock, 1_000_000_000.0, 0.50, 200_000_000.0, 0.10, $macro, $this->mathUtility);
            $meanLoss += $step * exp(-0.5 * $z * $z) / sqrt(2.0 * M_PI) * $result->netChargeOffs * FinancialConstants::QUARTERS_PER_YEAR / $this->model->resolveEarningAssets($stock);
        }

        $ttc = $this->model->getThroughTheCycleCreditLossRate($stock);
        $this->assertEqualsWithDelta($ttc, $meanLoss, 0.002 * $ttc);
        $loanShare = 1.0 - FinancialConstants::SECURITIES_SHARE_OF_EARNING_ASSETS;
        $this->assertEqualsWithDelta($loanShare, $this->model->getLoanShareOfEarningAssets(), 1e-12);
        $this->assertEqualsWithDelta(
            $loanShare * (CommercialBankBusinessModel::RESIDENTIAL_MORTGAGE_SHARE * CommercialBankBusinessModel::RESIDENTIAL_CHARGE_OFF_RATE
                + CommercialBankBusinessModel::CONSUMER_LOAN_SHARE * CommercialBankBusinessModel::CONSUMER_CHARGE_OFF_RATE
                + CommercialBankBusinessModel::COMMERCIAL_REAL_ESTATE_SHARE * CommercialBankBusinessModel::COMMERCIAL_REAL_ESTATE_CHARGE_OFF_RATE
                + (1.0 - CommercialBankBusinessModel::RESIDENTIAL_MORTGAGE_SHARE - CommercialBankBusinessModel::CONSUMER_LOAN_SHARE - CommercialBankBusinessModel::COMMERCIAL_REAL_ESTATE_SHARE) * CommercialBankBusinessModel::BUSINESS_CHARGE_OFF_RATE),
            $ttc,
            1e-12,
            'securities are marked, never charged off'
        );
        $this->assertEqualsWithDelta(0.0092, $ttc / $loanShare, 0.0002, 'a neutral H.8 loan book loses ~0.92% a year');
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
        $swing = static fn (float $rate, float $rho): float => CreditRisk::calculateVasicekExpectedLoss($z, $rate / CommercialBankBusinessModel::LGD_BASELINE, $rho, 1.0) / ($rate / CommercialBankBusinessModel::LGD_BASELINE);

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
        $householdZ = CreditRisk::calculateVasicekSystematicFactor(MacroEngine::RETAIL_DEFAULT_BASELINE, MacroEngine::RETAIL_DEFAULT_BASELINE, CreditFiscalSubsystem::RETAIL_ASRF_RHO);
        $corporateZ = CreditRisk::calculateVasicekSystematicFactor(MacroEngine::CORPORATE_DEFAULT_BASELINE, MacroEngine::CORPORATE_DEFAULT_BASELINE, CreditFiscalSubsystem::CORPORATE_DEFAULT_RHO);
        $mortgageLoss = 0.80 * CreditRisk::calculateVasicekExpectedLoss($householdZ, CommercialBankBusinessModel::RESIDENTIAL_CHARGE_OFF_RATE / 0.45, CreditFiscalSubsystem::RETAIL_ASRF_RHO, 1.0);
        $businessLoss = 0.20 * CreditRisk::calculateVasicekExpectedLoss($corporateZ, CommercialBankBusinessModel::BUSINESS_CHARGE_OFF_RATE / 0.45, CreditFiscalSubsystem::CORPORATE_DEFAULT_RHO, 1.0) * 0.45;
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

    /** Every tenor of the opening curve moved by the same amount: a parallel shift. */
    private function shiftedMacro(float $shift): MacroStateDTO
    {
        $opening = new MacroStateDTO();

        return new MacroStateDTO(
            policyRateEma: $opening->policyRateEma + $shift,
            yield2yEma: $opening->yield2yEma + $shift,
            yield5yEma: $opening->yield5yEma + $shift,
            yield10yEma: $opening->yield10yEma + $shift,
            yield30yEma: $opening->yield30yEma + $shift,
        );
    }

    /** Runs the bank's physics for a number of quarters at one macro state, carrying the book forward as the engine does. */
    private function carryBook(CommercialBankBusinessModel $model, Stock $stock, MacroStateDTO $macro, int $quarters): void
    {
        for ($quarter = 0; $quarter < $quarters; $quarter++) {
            $result = $model->computeActualFinancials($stock, 1_000_000_000.0, 0.50, 200_000_000.0, 0.0, $macro, $this->mathUtility);
            $stock->setEarningsMomentumZ($result->streamZ);
        }
    }

    /**
     * The franchise is priced once, at the opening macro, so the revenue its structural return requires is exactly
     * what its book and fees earn there: the opening board opens where it did, and the rate cycle moves it afterwards.
     */
    public function testTheFranchiseReproducesTheOpeningRevenue(): void
    {
        $model = new class extends CommercialBankBusinessModel {
            public function calibrationYield(Stock $stock): float
            {
                return $this->resolveCalibrationYield($stock, new MacroStateDTO());
            }
        };
        $stock = $this->creditTestBank('LAKE');
        $stock->setBaselineRoe('0.15');
        $stock->setOperatingMargin('0.40');
        $opening = new MacroStateDTO();

        $metrics = $model->getTargetMetrics($stock, $opening, $this->mathUtility);
        $grossYield = $metrics['baseline_roic'] / (0.40 * (1.0 - $opening->corporateTaxRate));

        $this->assertEqualsWithDelta($model->calibrationYield($stock), $grossYield, 1e-9);
        $this->carryBook($model, $stock, $opening, 4);
        $this->assertEqualsWithDelta($grossYield, $model->getTargetMetrics($stock, $opening, $this->mathUtility)['baseline_roic'] / (0.40 * (1.0 - $opening->corporateTaxRate)), 1e-9, 'a book at its steady state stays there');
    }

    /**
     * Each part of the book reprices toward the market at the hazard its measured repricing maturity implies
     * (Drechsler, Savov & Schnabl 2021, Table A.2), and the bank floats as much of its other loans as puts its
     * four-quarter interest income beta on DSS's matching line through its own expense beta: after a parallel point
     * the rise in interest yield is that line's value, and the bank's ROA barely moves.
     */
    public function testAParallelPointReachesInterestYieldAtTheBooksRepricingSpeed(): void
    {
        // The US funding mix: deposits ~80% of assets and wholesale ~10%, the wholesale short-term and repricing in full.
        $stock = $this->creditTestBank('NEUTRAL');
        $stock->setFloatingDebtRatio('1.0');
        $this->carryBook($this->model, $stock, new MacroStateDTO(), 1);
        $before = $this->model->resolveInterestYield($stock, new MacroStateDTO());

        $shifted = $this->shiftedMacro(0.01);
        $this->carryBook($this->model, $stock, $shifted, 4);
        $rise = ($this->model->resolveInterestYield($stock, $shifted) - $before) / 0.01;

        $expenseBeta = $this->model->resolveExpenseBeta($stock, new MacroStateDTO());
        $matched = CommercialBankBusinessModel::MEAN_INCOME_BETA
            + (CommercialBankBusinessModel::INCOME_EXPENSE_BETA_MATCHING_SLOPE * ($expenseBeta - CommercialBankBusinessModel::MEAN_EXPENSE_BETA));

        // DSS strike both betas on total assets, so the cash earning the policy rate beside the book counts as income.
        $earningAssets = $this->model->resolveEarningAssets($stock);
        $cash = (float) $stock->getCorporateTreasury();
        $earningCash = $cash - ((float) $stock->getTotalEquity() * CommercialBankBusinessModel::INTEREST_INCOME_CASH_BUFFER);
        $assetIncomeBeta = (($rise * $earningAssets) + $earningCash) / ($earningAssets + $cash);

        $this->assertEqualsWithDelta($matched, $assetIncomeBeta, 1e-6, 'income beta on the DSS matching line');
        $this->assertEqualsWithDelta(0.360, $expenseBeta, 0.03, 'US banks: interest expense beta 0.360 (DSS 2021)');
        $this->assertLessThan(0.05, abs($assetIncomeBeta - $expenseBeta), 'a matched bank\'s net interest income barely moves with rates');

        $this->carryBook($this->model, $stock, $shifted, 160);
        $this->assertEqualsWithDelta(1.0, ($this->model->resolveInterestYield($stock, $shifted) - $before) / 0.01, 0.01, 'in the long run the whole book reprices');
    }

    /**
     * Non-interest income is the bank's FDIC peer group's per unit of book, and the loan spread takes what is left of
     * the revenue its structural return needs: a lender earning more from fees prices its loans thinner.
     */
    public function testFeesAreThePeerGroupsAndTheLoanSpreadTakesTheRest(): void
    {
        $model = new class extends CommercialBankBusinessModel {
            /** @return array{floating_share: float, loan_spread: float, fee_yield: float} */
            public function pricing(Stock $stock): array
            {
                return $this->resolveFranchisePricing($stock);
            }
        };
        $untuned = $this->creditTestBank('PLAIN');
        $international = $this->creditTestBank('LAKE');

        $this->assertEqualsWithDelta(CommercialBankBusinessModel::NON_INTEREST_INCOME_TO_EARNING_ASSETS, $model->pricing($untuned)['fee_yield'], 1e-12);
        $this->assertEqualsWithDelta(0.0207, $model->pricing($international)['fee_yield'], 1e-12, 'US international banks, 2019 (FDIC QBP)');
        $this->assertLessThan($model->pricing($untuned)['loan_spread'], $model->pricing($international)['loan_spread']);
    }

    /** A mortgage book reprices over nearly a decade, a business book within a couple of years. */
    public function testAMortgageBookRepricesSlowerThanABusinessBook(): void
    {
        $mortgageLender = new class extends CommercialBankBusinessModel {
            protected function resolveLoanBookMix(Stock $stock): array
            {
                return ['residential' => 1.0, 'consumer' => 0.0, 'commercial_real_estate' => 0.0, 'business' => 0.0];
            }
        };
        $businessLender = new class extends CommercialBankBusinessModel {
            protected function resolveLoanBookMix(Stock $stock): array
            {
                return ['residential' => 0.0, 'consumer' => 0.0, 'commercial_real_estate' => 0.0, 'business' => 1.0];
            }
        };

        $rise = function (CommercialBankBusinessModel $model): float {
            $stock = $this->creditTestBank('MIX');
            $this->carryBook($model, $stock, new MacroStateDTO(), 1);
            $before = $model->resolveInterestYield($stock, new MacroStateDTO());
            $this->carryBook($model, $stock, $this->shiftedMacro(0.01), 4);

            return $model->resolveInterestYield($stock, $this->shiftedMacro(0.01)) - $before;
        };

        $this->assertGreaterThan(3.0 * $rise($mortgageLender), $rise($businessLender));
    }

    /**
     * A mortgage reprices off the long end of the curve: a point on the 10-year alone, with the short end held, lifts a
     * fully repriced residential book by the curve's move at its 9.5-year repricing tenor.
     */
    public function testAMortgageBookIsPricedOffTheLongEnd(): void
    {
        $mortgageLender = new class extends CommercialBankBusinessModel {
            protected function resolveLoanBookMix(Stock $stock): array
            {
                return ['residential' => 1.0, 'consumer' => 0.0, 'commercial_real_estate' => 0.0, 'business' => 0.0];
            }
        };
        $opening = new MacroStateDTO();
        $steeper = new MacroStateDTO(yield10yEma: $opening->yield10yEma + 0.01);
        $stock = $this->creditTestBank('MORT');

        $tenorMove = (CommercialBankBusinessModel::RESIDENTIAL_REPRICING_YEARS - 5.0) / 5.0;
        $securitiesMove = (CommercialBankBusinessModel::SECURITIES_REPRICING_YEARS - 5.0) / 5.0;
        $loanShare = 1.0 - FinancialConstants::SECURITIES_SHARE_OF_EARNING_ASSETS;
        $this->assertEqualsWithDelta(
            0.01 * (($loanShare * $tenorMove) + ((1.0 - $loanShare) * $securitiesMove)),
            $mortgageLender->resolveInterestYield($stock, $steeper) - $mortgageLender->resolveInterestYield($stock, $opening),
            1e-9
        );
    }

    /** The rate cycle reaches interest income alone: a repriced book earns more interest and the same fees. */
    public function testARateRiseLandsInInterestIncomeAlone(): void
    {
        $stock = $this->creditTestBank('LAKE');
        $streams = function (MacroStateDTO $macro) use ($stock): array {
            $metrics = $this->model->getTargetMetrics($stock, $macro, $this->mathUtility);
            $expectedRevenue = $metrics['invested_capital'] * $metrics['baseline_roic'] / (max(0.01, (float) $stock->getOperatingMargin()) * (1.0 - $macro->corporateTaxRate)) / 4.0;
            $result = $this->model->computeActualFinancials($stock, $expectedRevenue, 0.50, 200_000_000.0, 0.0, $macro, $this->createMathUtilityMock());

            return $result->streamRevenue;
        };

        $base = $streams(new MacroStateDTO());
        $raised = $streams($this->shiftedMacro(0.01));

        $this->assertGreaterThan($base['net_interest_income'], $raised['net_interest_income']);
        $this->assertEqualsWithDelta($base['fee_income'], $raised['fee_income'], $base['fee_income'] * 1e-9, 'fees do not reprice');
        $this->assertEqualsWithDelta($base['proprietary_dividend'] ?? 0.0, $raised['proprietary_dividend'] ?? 0.0, 1.0);
    }

    public function testBaselThreeRwaAndCet1Calculations(): void
    {
        $bank = new Stock();
        $bank->setTicker('HEALTHY_BANK');
        $bank->setTotalEquity('10000000000'); // $10B
        $bank->setCustomerDeposits('80000000000'); // $80B
        $bank->setWholesaleDebt('10000000000'); // $10B
        $bank->setCorporateTreasury('5000000000'); // $5B

        // Earning Assets = 10B + 90B - 5B = 95B, risk-weighted at the H.8 book's density; cash weighs 0%.
        $density = $this->model->calculateRiskWeightDensity($bank);
        $rwa = $this->model->calculateRiskWeightedAssets($bank);
        $this->assertEqualsWithDelta(95_000_000_000.0 * $density, $rwa, 1.0);

        // CET1 = 10B / (95B * 0.734) = ~14.3%
        $cet1 = $this->model->calculateCet1Ratio($bank);
        $this->assertEqualsWithDelta(10.0 / (95.0 * $density), $cet1, 0.0001);
        $this->assertGreaterThan(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT, $cet1);

        // Dividend cap for healthy bank is 1.0
        $divCap = $this->model->getRegulatoryDividendCap($bank, 5_000_000_000.0);
        $this->assertSame(1.0, $divCap);
    }

    /**
     * Standardized risk weights follow the book (12 CFR 217.32): mortgages at 50%, other loans at 100%, the securities
     * sleeve at the agency 20%. The H.8 book lands on the insured system's 0.72 of non-cash assets; a mortgage lender
     * needs less capital per dollar lent and a business lender more.
     */
    public function testRiskWeightsFollowTheLoanBook(): void
    {
        $lender = static fn (float $residential): CommercialBankBusinessModel => new class ($residential) extends CommercialBankBusinessModel {
            public function __construct(private readonly float $residential) {}

            protected function resolveLoanBookMix(Stock $stock): array
            {
                return ['residential' => $this->residential, 'consumer' => 0.0, 'commercial_real_estate' => 0.0, 'business' => 1.0 - $this->residential];
            }
        };
        $stock = $this->creditTestBank('RWA');
        $loanShare = 1.0 - FinancialConstants::SECURITIES_SHARE_OF_EARNING_ASSETS;
        $securities = FinancialConstants::SECURITIES_SHARE_OF_EARNING_ASSETS * CommercialBankBusinessModel::BASEL_RISK_WEIGHT_SECURITIES;

        $this->assertEqualsWithDelta(0.72, $this->model->calculateRiskWeightDensity($stock), 0.02, 'US insured banks, end-2024: $14.90T of RWA on $20.69T of non-cash assets');
        $this->assertEqualsWithDelta(($loanShare * 0.50) + $securities, $lender(1.0)->calculateRiskWeightDensity($stock), 1e-12);
        $this->assertEqualsWithDelta($loanShare + $securities, $lender(0.0)->calculateRiskWeightDensity($stock), 1e-12);

        $mortgageCet1 = $lender(0.65)->calculateCet1Ratio($stock);
        $this->assertGreaterThan($this->model->calculateCet1Ratio($stock), $mortgageCet1, 'the same equity covers a mortgage book with room to spare');
        $this->assertGreaterThan($lender(0.0)->calculateCet1Ratio($stock) * 1.3, $mortgageCet1);
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
        // CET1 = 5B / (90B * 0.734) = ~7.6% (between the 4.5% minimum and the 9.4% requirement)
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

        // CET1 = 7.5B / (92.5B * 0.734) = ~11.0% (> 9.4% requirement, but < 9.4% + 2.0% CCyB = 11.4%)
        $cet1 = $this->model->calculateCet1Ratio($bank);
        $this->assertGreaterThan(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT, $cet1);
        $this->assertLessThan(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT + 0.020, $cet1);

        $neutralMacro = new \App\DTO\MacroStateDTO(countercyclicalBufferRate: 0.0);
        $ccybMacro = new \App\DTO\MacroStateDTO(countercyclicalBufferRate: 0.020);

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

        // CET1 = 7B / (92B * 0.734) = ~10.4%: inside a light requirement, short of a strict one.
        $cet1 = $this->model->calculateCet1Ratio($bank);
        $this->assertEqualsWithDelta(7.0 / (92.0 * $this->model->calculateRiskWeightDensity($bank)), $cet1, 1e-9);

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

    public function testABankBelowTheCapitalMinimumIsFlagged(): void
    {
        $bank = new Stock();
        $bank->setTicker('INSOLVENT_BANK');
        $bank->setBeta('1.0');
        $bank->setTotalEquity('2000000000'); // $2B
        $bank->setCustomerDeposits('80000000000'); // $80B
        $bank->setWholesaleDebt('10000000000'); // $10B
        $bank->setCorporateTreasury('5000000000'); // $5B

        // Earning Assets = 2B + 90B - 5B = 87B
        // CET1 = 2B / (87B * 0.734) = ~3.1% (< 4.5% Pillar 1 minimum)
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

        $this->assertSame(ShockEvent::CAPITAL_BELOW_MINIMUM, $result->eventType);
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
        $this->assertEqualsWithDelta(44_100_000_000.0 * $this->model->calculateRiskWeightDensity($stock), $this->model->calculateRiskWeightedAssets($stock), 1.0, 'risk weights apply to the book, not the proxy');

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
        $tightSystem = MacroStateDTO::fromArray(['system_deposit_beta_ema' => 1.5 * MacroEngine::SYSTEM_DEPOSIT_BETA_BASE]);

        $withoutMacro = $this->model->calculateInterestExpenseAndWholesaleRate($stock, 0.05, 0.05, 0.05, 0.05, 15.0, 100.0, 1000.0);
        $neutral = $this->model->calculateInterestExpenseAndWholesaleRate($stock, 0.05, 0.05, 0.05, 0.05, 15.0, 100.0, 1000.0, $neutralSystem);
        $tight = $this->model->calculateInterestExpenseAndWholesaleRate($stock, 0.05, 0.05, 0.05, 0.05, 15.0, 100.0, 1000.0, $tightSystem);

        $this->assertEqualsWithDelta($withoutMacro->interestExpense, $neutral->interestExpense, 1e-9, 'At the normalisation level the system scale is one, so a caller without a macro reading gets the same answer.');
        $this->assertEqualsWithDelta(1.5 * $neutral->interestExpense, $tight->interestExpense, 1e-9, 'A system passing half as much again through raises by half the deposit interest on the same franchise.');
    }

    /**
     * Deposits reprice toward 0.46 of the policy rate over three quarters (Drechsler, Savov & Schnabl 2017, 2021): a
     * point on the policy rate lifts what the bank pays by 1 - exp(-0.25/0.75) of 46bp in the first quarter, and by
     * the whole 46bp once the deposits have repriced.
     */
    public function testDepositsRepriceTowardTheLongRunPassThroughOverQuarters(): void
    {
        $stock = $this->creditTestBank('LAKE');
        $opening = new MacroStateDTO();
        $raised = new MacroStateDTO(policyRateEma: $opening->policyRateEma + 0.01);
        $paid = fn (): float => $this->model->calculateInterestExpenseAndWholesaleRate($stock, 0.0, 0.0, 0.0, $raised->policyRateEma, 15.0, 10e9, 90e9, $raised)->interestExpense;
        $stock->setWholesaleDebt('0');

        $this->carryBook($this->model, $stock, $opening, 1);
        $before = $paid();
        $this->carryBook($this->model, $stock, $raised, 1);
        $this->assertEqualsWithDelta(80e9 * 0.0046 * (1.0 - exp(-0.25 / 0.75)), $paid() - $before, 1e3);

        $this->carryBook($this->model, $stock, $raised, 80);
        $this->assertEqualsWithDelta(80e9 * 0.0046, $paid() - $before, 1e3);
    }

    /** The cash beside the book earns its own interest below the line, so the franchise's revenue target leaves it out. */
    public function testTheFranchiseCalibrationCountsTheCashBesideTheBook(): void
    {
        $calibration = static fn (CommercialBankBusinessModel $model, Stock $stock): float => (new \ReflectionMethod($model, 'resolveCalibrationYield'))->invoke($model, $stock, new MacroStateDTO());
        $cashless = new class extends CommercialBankBusinessModel {
            protected function resolveTreasuryIncome(Stock $stock, MacroStateDTO $macroState): float
            {
                return 0.0;
            }
        };
        $stock = $this->creditTestBank('LAKE');
        $treasuryIncome = (5e9 - (10e9 * CommercialBankBusinessModel::INTEREST_INCOME_CASH_BUFFER)) * $this->model->calculateCashYield(new MacroStateDTO());

        $this->assertGreaterThan(0.0, $treasuryIncome);
        $this->assertEqualsWithDelta(
            $treasuryIncome,
            ($calibration($cashless, $stock) - $calibration($this->model, $stock)) * $this->model->resolveEarningAssets($stock),
            1e3
        );
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
