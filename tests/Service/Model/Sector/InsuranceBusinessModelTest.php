<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Math\Distributions;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Model\Sector\InsuranceBusinessModel;
use App\Service\Model\Sector\RetailInsuranceBusinessModel;
use App\Service\Model\StreamContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class InsuranceBusinessModelTest extends TestCase
{
    public function testCalculateMinOperatingCashAndTargetCash(): void
    {
        $model = new InsuranceBusinessModel();

        $operatingBase = 100_000_000_000.0; // $100 Billion operating base
        $customerDeposits = 4_000_000_000_000.0; // $4 Trillion float (customer deposits)
        $wholesaleDebt = 200_000_000_000.0;

        $minCash = $model->calculateMinOperatingCash($operatingBase, $customerDeposits, $wholesaleDebt);
        $this->assertEquals(600_000_000_000.0, $minCash);

        $targetCash = $model->calculateTargetOperatingCash($operatingBase, $customerDeposits, $wholesaleDebt);
        $this->assertEquals(4_005_000_000_000.0, $targetCash, 'The float backed in full plus the 5% operating buffer above it.');
        $this->assertEquals(4_000_000_000_000.0, $model->calculateLiabilityBackingCash($customerDeposits, $wholesaleDebt));
    }

    /**
     * A fortress manager runs a bigger buffer, not a bigger float backing: the backing is what policyholders are
     * owed. Scaling it too had a fortress reinsurer hold 175% of its float in cash and never return a dollar.
     */
    public function testAFortressInsurerBiasesItsBufferNotItsFloatBacking(): void
    {
        $model = new InsuranceBusinessModel();
        $float = 3_000_000_000_000.0;
        $target = $model->calculateTargetOperatingCash(2_000_000_000_000.0, $float, 0.0);
        $backing = $model->calculateLiabilityBackingCash($float, 0.0);

        $fortress = \App\Data\Company\ManagementStyle::Fortress;
        $this->assertEqualsWithDelta($backing + ($fortress->cashTargetBias() * ($target - $backing)), $fortress->appliedTargetCash($target, $backing), 1.0);
        $this->assertLessThan(1.1 * $float, $fortress->appliedTargetCash($target, $backing));
        $this->assertSame($target, \App\Data\Company\ManagementStyle::Operator->appliedTargetCash($target, $backing));
    }

    /**
     * NAIC Model Act #440: twelve months' distributions without approval are the greater of a tenth of surplus and the
     * year's income.
     */
    public function testTheNaicLimitIsTheGreaterOfATenthOfSurplusAndTheYearsIncome(): void
    {
        $model = new InsuranceBusinessModel();
        $stock = new Stock();
        $stock->setTotalEquity('100000000000');

        $this->assertEqualsWithDelta(0.10 * $stock->getStatutorySurplus(), $model->getDistributionCeiling($stock, 1_000_000_000.0), 1.0, 'A thin year leaves a tenth of surplus.');
        $this->assertSame(25_000_000_000.0, $model->getDistributionCeiling($stock, 25_000_000_000.0), 'A strong year may pay out its income.');
        $this->assertEqualsWithDelta(0.10 * $stock->getStatutorySurplus(), $model->getDistributionCeiling($stock, -5_000_000_000.0), 1.0, 'A loss year still allows the tenth.');
    }

    /**
     * An insurer's distributions answer to its solvency, not to a bank's leverage: a composite owing its policyholders
     * ten times its capital in reserves buys back while its surplus covers the Kenney target its premium needs, and is
     * barred once it does not.
     */
    public function testBuybacksAnswerToSurplusNotToTheFloatOwedToPolicyholders(): void
    {
        $model = new RetailInsuranceBusinessModel();
        $macro = new MacroStateDTO();
        $stock = (new Stock())->setTicker('LIFE')->setIndustry('Insurance - Diversified')->setTotalEquity('85000000000')
            ->setCustomerDeposits('850000000000')->setCorporateTreasury('850000000000')->setTotalRevenue('120000000000');

        $this->assertNull($model->getRegulatoryDividendCap($stock, 850_000_000_000.0, $macro));
        $this->assertFalse($model->checkBuybackRegulatoryLockout($stock, 850_000_000_000.0, $macro), 'Surplus 85 against a target of 80.');

        $stock->setTotalRevenue('140000000000');
        $this->assertTrue($model->checkBuybackRegulatoryLockout($stock, 850_000_000_000.0, $macro), 'Surplus 85 against a target of 93.');
        $this->assertNull($model->getRegulatoryDividendCap($stock, 850_000_000_000.0, $macro), 'The dividend halts only below half the target.');
    }

    public function testGetInterestCoverage(): void
    {
        $model = new InsuranceBusinessModel();

        // 1. No wholesale interest expense -> returns fallback infinite coverage (999.0)
        $icrZeroDebt = $model->getInterestCoverage(500_000_000.0, 0.0, 50_000_000.0);
        $this->assertEquals(InsuranceBusinessModel::INFINITE_ICR_FALLBACK, $icrZeroDebt);

        // 2. Wholesale interest expense present -> returns ($ebit + $depreciation) / $interestExpense
        $ebit = 1_000_000_000.0;
        $depreciation = 100_000_000.0;
        $interestExpense = 500_000_000.0;
        $icrWithDebt = $model->getInterestCoverage($ebit, $interestExpense, $depreciation);
        $this->assertEquals(2.2, $icrWithDebt);

        // 3. Negative EBIT with high emergency interest expense -> evaluates serviceable income
        $icrDistressed = $model->getInterestCoverage(-200_000_000.0, 800_000_000.0, 0.0);
        $this->assertEquals(0.75, $icrDistressed);
    }

    public function testCatastropheZScoreImpactsClaims(): void
    {
        $model = new InsuranceBusinessModel();
        $stock = new Stock();
        $stock->setTicker('TEST');
        $stock->setBeta('0.6');

        $mathUtilityMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
        $mathUtilityMock->method('generateStandardNormal')
            // Firm factor = 0.0, Revenue Z = 0.0, Claim Z = -2.5 (Severe catastrophe)
            ->willReturnOnConsecutiveCalls(0.0, 0.0, -2.5);

        $macroState = MacroStateDTO::fromArray([
            'inflation_ema' => 0.02,
            'policy_rate' => 0.05,
            'gdp_growth' => 0.02,
            'credit_spread' => 0.015,
        ]);

        $result = $model->computeActualFinancials(
            $stock,
            50_000_000_000.0,
            0.60,
            2_000_000_000.0,
            0.15,
            $macroState,
            $mathUtilityMock,
        );

        // Variable costs (claims) should be significantly elevated due to catastrophe loss
        $this->assertGreaterThan(50_000_000_000.0 * 0.60, $result->actualVariableCosts);
    }

    public function testCalculateEarningsValueWithFranchiseFloor(): void
    {
        $model = new InsuranceBusinessModel();

        // 1. When PE fair value is healthy ($100), returns PE fair value
        $earningsValNormal = $model->calculateEarningsValue(50.0, 100.0);
        $this->assertEquals(100.0, $earningsValNormal);

        // 2. When PE fair value collapses to $0 (e.g. catastrophe claims), franchise floor cushions value (0.70 * $50 =
        //    $35)
        $earningsValLoss = $model->calculateEarningsValue(50.0, 0.0);
        $this->assertEquals(35.0, $earningsValLoss);
    }

    public function testCalculateFairValueProfitableVsLossRegimes(): void
    {
        $model = new InsuranceBusinessModel();

        // 1. Profitable regime (normalized EPS > 0): half book ($100), half earnings ($120) = 110. The dividend
        // discount value is no input: an underwriter's payout does not change what it is worth.
        $this->assertEquals(110.0, $model->calculateFairValue(120.0, 100.0, 5.0, 80.0));
        $this->assertEquals(110.0, $model->calculateFairValue(120.0, 100.0, 5.0));

        // 2. Catastrophe loss regime (normalized EPS <= 0): all book.
        $this->assertEquals(100.0, $model->calculateFairValue(0.0, 100.0, -2.0, 80.0));
    }

    public function testUpdateDynamicRoicStandardCalculation(): void
    {
        $model = new InsuranceBusinessModel();
        $stock = new Stock();
        $stock->setTotalEquity('1000000000.0'); // $1B Equity
        $stock->setRoeTtm('0.10');

        // $25M quarterly net income -> Annualized ROE = ($25M / $1000M) * 4.0 = 0.10 (10%)
        $return = $model->updateDynamicRoic($stock, 25_000_000.0, 1_000_000_000.0, 30_000_000.0, 0.21, 0.08, 0.10);

        $this->assertEquals(0.10, $return);
        $this->assertEquals('0.1', $stock->getCurrentRoe());
        $this->assertNotNull($stock->getRoeTtm());
    }

    public function testQuietDistrictYearLowersLossRatioAndLeavesExpenseRatio(): void
    {
        $model = new InsuranceBusinessModel();
        $stock = new Stock();
        $stock->setTicker('TEST_BENIGN');
        $stock->setBeta('1.0');
        // Surplus covering the ANNUAL book at the Kenney ratio ($40B written / 1.5), so the capacity
        // channel stays out of a test about the claim channel.
        $stock->setTotalEquity('26666666667.0');

        $mathUtilityMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
        // Firm factor = 0.0, Revenue Z = 0.0, Claim Z = 2.0: nothing in the firm's own large-loss layer.
        $mathUtilityMock->method('generateStandardNormal')
            ->willReturnOnConsecutiveCalls(0.0, 0.0, 2.0);

        $quietBurden = 0.5;
        $window = DistrictCatastropheWindow::prime($stock, $quietBurden);
        $macroState = MacroStateDTO::fromArray([
            'inflation_ema' => 0.02,
            'policy_rate' => 0.05,
            'gdp_growth' => 0.02,
            'credit_spread' => 0.015,
            'catastrophe_loss_index' => $window['catastropheLossIndex'],
            'total_time' => $window['totalTime'],
        ]);

        $baseVariableMargin = 0.60;
        $expectedRevenue = 10_000_000_000.0;

        $result = $model->computeActualFinancials(
            $stock,
            $expectedRevenue,
            $baseVariableMargin,
            1_000_000_000.0,
            0.10,
            $macroState,
            $mathUtilityMock,
        );

        // Claims 0.60 x 0.834 = 0.5004, less half an average quarter's catastrophe load, less the large-loss
        // layer's expected payout that the structural margin prices in and this quarter did not use.
        // Commissions and taxes 0.60 x 0.166 = 0.0996 are untouched by claims.
        $expectedLayer = InsuranceBusinessModel::CATASTROPHE_LOSS_SCALAR
            * Distributions::calculateNormalLowerPartialMoment(InsuranceBusinessModel::CATASTROPHE_Z_THRESHOLD);
        // The catastrophe cover's premium is a reinsurance cost of the quarter, filed with the losses.
        $coverPremium = InsuranceBusinessModel::catastropheLayer(InsuranceBusinessModel::CATASTROPHE_Z_THRESHOLD, InsuranceBusinessModel::CATASTROPHE_LOSS_SCALAR, InsuranceBusinessModel::DISTRICT_CATASTROPHE_LOAD)['expected_recovery']
            * (1.0 + InsuranceBusinessModel::CATASTROPHE_COVER_RATE_LOADING);
        // The catastrophe terms are per unit of premium at the base rate, so they land on the book's volume: premium
        // over the rate level it was written at (a little under par here, the float yield above neutral).
        $volume = 1.0 / $model->getMacroPhysics($stock, $macroState)['pricing_power_multiplier'];
        $claimsRatio = 0.5004 + ((-(InsuranceBusinessModel::DISTRICT_CATASTROPHE_LOAD * (1.0 - $quietBurden)) - $expectedLayer + $coverPremium) * $volume);
        $this->assertEqualsWithDelta($claimsRatio + 0.0996, $result->clampedMargin, 1e-9);
        $this->assertEqualsWithDelta(($claimsRatio + 0.0996) * $expectedRevenue, $result->actualVariableCosts, 1_000.0);

        // The report files the split over the $1B of fixed costs on $10B of premium: the claims department's 30.9% of
        // it is loss adjustment expense, the rest underwriting expense.
        $this->assertEqualsWithDelta($claimsRatio + 0.0309, $result->kpis['loss_ratio'], 1e-9);
        $this->assertEqualsWithDelta(0.0996 + 0.0691, $result->kpis['expense_ratio'], 1e-9);
    }

    /**
     * Each underwriter files its loss and expense ratios, and the two add to its cost base over premium: the variable
     * cost ratio plus the fixed costs, which is the combined ratio the page shows.
     */
    public function testEveryUnderwriterFilesALossAndExpenseRatioThatAddToItsCostBase(): void
    {
        $fixedCosts = 800_000_000.0;
        foreach ([new InsuranceBusinessModel(), new RetailInsuranceBusinessModel(), new \App\Service\Model\Sector\ReinsuranceBusinessModel()] as $model) {
            $result = $model->computeActualFinancials($this->underwriter(26_666_666_667.0), 10_000_000_000.0, 0.60, $fixedCosts, 0.10, new MacroStateDTO(), $this->scriptedMath());

            $label = $model::class;
            $this->assertGreaterThan(0.0, $result->kpis['loss_ratio'], $label);
            $this->assertGreaterThan((1.0 - InsuranceBusinessModel::FIXED_COST_LAE_SHARE) * $fixedCosts / $result->actualRevenue, $result->kpis['expense_ratio'], $label);
            $this->assertEqualsWithDelta(
                $result->clampedMargin + $fixedCosts / $result->actualRevenue,
                $result->kpis['loss_ratio'] + $result->kpis['expense_ratio'],
                1e-9,
                $label,
            );
        }
    }

    /**
     * The district term is linear in the losses incurred, so a storm quarter and an equally quiet one cancel: the
     * structural margin is the average year's, catastrophe load included, and the season only spreads it.
     */
    public function testDistrictCatastropheClaimsAreCentredOnTheAverageYear(): void
    {
        $model = new InsuranceBusinessModel();
        $rateLevel = 1.0;
        $run = function (float $burden) use ($model, &$rateLevel): float {
            $stock = new Stock();
            $stock->setTicker('CENT');
            $stock->setBeta('1.0');
            $stock->setTotalEquity('200000000000');
            $macro = new MacroStateDTO(...['inflationEma' => 0.02, 'policyRateEma' => 0.03, 'yield10yEma' => 0.04, ...DistrictCatastropheWindow::prime($stock, $burden)]);
            $rateLevel = $model->getMacroPhysics($stock, $macro)['pricing_power_multiplier'];

            return $model->computeActualFinancials($stock, 10_000_000_000.0, 0.60, 1.0e9, 0.10, $macro, $this->scriptedMath())->clampedMargin;
        };

        $this->assertEqualsWithDelta(2.0 * $run(1.0), $run(1.6) + $run(0.4), 1e-12);
        // Per unit of premium at the base rate: on revenue at the rate it was written at, the volume share of it.
        $this->assertEqualsWithDelta(InsuranceBusinessModel::DISTRICT_CATASTROPHE_LOAD * 0.6 / $rateLevel, $run(1.6) - $run(1.0), 1e-12);
    }

    /**
     * A storm is an incurred loss of the quarter it lands in: the report after it books no more of it. Read off the
     * smoothed stock of open claims, a storm reached the loss ratio over six quarters and more.
     */
    public function testAStormIsBookedInTheQuarterItLandsAndNotTheNext(): void
    {
        $model = new InsuranceBusinessModel();
        $k = \App\Service\Macro\Subsystem\CommodityLogisticsSubsystem::CATASTROPHE_LOSS_DECAY;
        $twoQuarters = function (float $lossMultiple) use ($model, $k): array {
            $stock = $this->underwriter(200_000_000_000.0);
            $window = DistrictCatastropheWindow::prime($stock, $lossMultiple);
            $first = $model->computeActualFinancials($stock, 10_000_000_000.0, 0.60, 1.0e9, 0.10, new MacroStateDTO(...[
                'inflationEma' => 0.02, 'policyRateEma' => 0.03, 'yield10yEma' => 0.04, ...$window,
            ]), $this->scriptedMath());
            $stock->setEarningsMomentumZ($first->streamZ);
            // Nothing lands in the next quarter: the burden only settles.
            $second = $model->computeActualFinancials($stock, 10_000_000_000.0, 0.60, 1.0e9, 0.10, new MacroStateDTO(
                inflationEma: 0.02,
                policyRateEma: 0.03,
                yield10yEma: 0.04,
                catastropheLossIndex: $window['catastropheLossIndex'] * exp(-$k * 0.25),
                totalTime: $window['totalTime'] + 0.25,
            ), $this->scriptedMath());

            return [$first->clampedMargin, $second->clampedMargin];
        };

        [$calmFirst, $calmSecond] = $twoQuarters(1.0);
        [$stormFirst, $stormSecond] = $twoQuarters(3.0);
        // Per unit of premium at the base rate, so on revenue at the rate the book was written at.
        $rateLevel = $model->getMacroPhysics($this->underwriter(200_000_000_000.0), new MacroStateDTO(inflationEma: 0.02, policyRateEma: 0.03, yield10yEma: 0.04))['pricing_power_multiplier'];

        $this->assertEqualsWithDelta(InsuranceBusinessModel::DISTRICT_CATASTROPHE_LOAD * 2.0 / $rateLevel, $stormFirst - $calmFirst, 1e-9, 'The storm is booked in full the quarter it lands.');
        $this->assertEqualsWithDelta($calmSecond, $stormSecond, 1e-9, 'The quarter after books none of it.');
    }

    /**
     * A storm costs the same dollars whatever rate the book was renewed at: on a book written 25% harder the same
     * draws charge the same claims. Struck on revenue, the hard market's uplift raised them by the whole uplift.
     */
    public function testHardMarketDoesNotRaiseClaimDollars(): void
    {
        $model = new InsuranceBusinessModel();
        $costs = function (float $rateLevel) use ($model): float {
            $stock = $this->underwriter(200_000_000_000.0);
            $window = DistrictCatastropheWindow::prime($stock, 3.0);
            $stock->setEarningsMomentumZ([
                ...($stock->getEarningsMomentumZ() ?? []),
                StreamContext::REGIME_STATE_PREFIX . InsuranceBusinessModel::REGIME_HARD_MARKET => $rateLevel > 1.0 ? 1.0 : 0.0,
                InsuranceBusinessModel::STATE_EARNED_RATE_LEVEL => $rateLevel,
                InsuranceBusinessModel::STATE_OBSERVED_CAPACITY => 1.0,
            ]);
            $macro = new MacroStateDTO(...['inflationEma' => 0.02, 'policyRateEma' => MacroStateDTO::fromArray([])->perceivedNeutralRate, 'yield10yEma' => 0.04, ...$window]);
            $this->assertEqualsWithDelta($rateLevel, $model->getMacroPhysics($stock, $macro)['pricing_power_multiplier'], 1e-9);

            // As the engine hands it the quarter: revenue at the rate, the structural cost ratio deflated by it.
            return $model->computeActualFinancials($stock, 10_000_000_000.0 * $rateLevel, 0.60 / $rateLevel, 1.0e9, 0.10, $macro, $this->scriptedMath())->actualVariableCosts;
        };

        $this->assertEqualsWithDelta($costs(1.0), $costs(1.0 + InsuranceBusinessModel::HARD_MARKET_PRICING_UPLIFT), 1.0);
    }

    /** Each book's cover attaches at its own one-in-five-year quarter, on the district's own loss distribution. */
    public function testRetentionAttachesAtItsReturnPeriod(): void
    {
        $distribution = \App\Service\Macro\Subsystem\CommodityLogisticsSubsystem::catastropheQuarterDistribution();
        foreach ([[-1.50, 0.15, 0.13], [-1.80, 0.08, 0.13], [-1.55, 0.20, 0.08]] as [$threshold, $scale, $load]) {
            $layer = InsuranceBusinessModel::catastropheLayer($threshold, $scale, $load);
            $attaches = 0.0;
            foreach ($distribution['ratios'] as $index => $ratio) {
                $attaches += $distribution['weights'][$index] * Distributions::normalHingeLayerExceedance($scale, $threshold, ($load * ($ratio - 1.0)) - $layer['retention']);
            }

            // To the lattice's resolution: each lattice point's mass steps in as the retention passes it.
            $this->assertEqualsWithDelta(1.0 / (4.0 * InsuranceBusinessModel::CATASTROPHE_RETENTION_RETURN_YEARS), $attaches, 5e-4);
            $this->assertGreaterThan(0.0, $layer['limit']);
        }
    }

    /**
     * A reinsurer's cover is priced on its own layer: lighter district load and a heavier own tail than a primary
     * book's, so it attaches lower and pays less. Struck on the primary's layer, it paid 3.3 times what it recovered.
     */
    public function testCededPremiumIsLoadedOnOwnRecovery(): void
    {
        $primary = InsuranceBusinessModel::catastropheLayer(-1.50, 0.15, 0.13);
        $reinsurer = InsuranceBusinessModel::catastropheLayer(-1.55, 0.20, 0.08);

        $this->assertLessThan($primary['retention'], $reinsurer['retention']);
        $this->assertLessThan($primary['expected_recovery'], $reinsurer['expected_recovery']);
        $this->assertEqualsWithDelta(0.0225, $primary['expected_recovery'], 0.0225 * 0.05, 'the engine measured 2.25% a quarter on the primary layer');
    }

    /** The cover pays the claims above its retention up to its limit, and the carrier keeps what lies beyond. */
    public function testTheCatastropheCoverStopsAtItsLimit(): void
    {
        $cover = new class extends InsuranceBusinessModel {
            /** @param array{retention: float, limit: float, expected_recovery: float} $layer */
            public function recovery(float $gross, array $layer): float
            {
                return $this->resolveCatastropheRecovery($gross, $layer);
            }
        };
        $layer = InsuranceBusinessModel::catastropheLayer(InsuranceBusinessModel::CATASTROPHE_Z_THRESHOLD, InsuranceBusinessModel::CATASTROPHE_LOSS_SCALAR, InsuranceBusinessModel::DISTRICT_CATASTROPHE_LOAD);

        $this->assertSame(0.0, $cover->recovery($layer['retention'] * 0.5, $layer), 'Inside the retention the carrier pays it all.');
        $this->assertEqualsWithDelta(0.5, $cover->recovery($layer['retention'] + 0.5, $layer), 1e-12, 'Above it the cover pays.');
        $this->assertEqualsWithDelta($layer['limit'], $cover->recovery($layer['retention'] + $layer['limit'] + 5.0, $layer), 1e-12, 'Past its limit it pays no more.');
    }

    public function testExpenseRatioScalesWithRevenueFluctuations(): void
    {
        $model = new InsuranceBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SCALE');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('10000000000.0');

        $macroState = MacroStateDTO::fromArray([
            'inflation_ema' => 0.02,
            'policy_rate' => 0.05,
            'gdp_growth' => 0.02,
            'credit_spread' => 0.015,
            'commercial_property_index_ema' => 100.0,
            'residential_property_index_ema' => 100.0,
        ]);

        $baseVariableMargin = 0.60;
        $expectedRevenue = 10_000_000_000.0;
        $baselineVol = 0.20; // with REVENUE_VARIANCE_SCALAR = 0.05, shock magnitude is vol * 0.05

        // 1. Negative Revenue Shock (Revenue drops, expense ratio as % of revenue rises due to operating overhead)
        $mockNegRevenue = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
        $mockNegRevenue->method('generateStandardNormal')
            // Firm factor = 0.0, composite Revenue Z = -2.0 (financial loading 0.40), Claim Z = 0.0
            ->willReturnOnConsecutiveCalls(0.0, -2.0 / sqrt(1.0 - 0.16), 0.0);

        $resultNeg = $model->computeActualFinancials(
            $stock,
            $expectedRevenue,
            $baseVariableMargin,
            1_000_000_000.0,
            $baselineVol,
            $macroState,
            $mockNegRevenue,
        );

        // 2. Positive Revenue Shock (Revenue expands, expense ratio as % of revenue declines)
        $mockPosRevenue = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
        $mockPosRevenue->method('generateStandardNormal')
            // Firm factor = 0.0, composite Revenue Z = 2.0 (financial loading 0.40), Claim Z = 0.0
            ->willReturnOnConsecutiveCalls(0.0, 2.0 / sqrt(1.0 - 0.16), 0.0);

        $resultPos = $model->computeActualFinancials(
            $stock,
            $expectedRevenue,
            $baseVariableMargin,
            1_000_000_000.0,
            $baselineVol,
            $macroState,
            $mockPosRevenue,
        );

        $this->assertGreaterThan($expectedRevenue, $resultPos->actualRevenue);
        $this->assertLessThan($expectedRevenue, $resultNeg->actualRevenue);

        // Combined ratio with depressed revenue should exceed combined ratio with expanded revenue due to expense ratio
        // dynamics
        $this->assertGreaterThan($resultPos->clampedMargin, $resultNeg->clampedMargin);
    }

    public function testCalculateStructuralRoicWithCatastropheCollapse(): void
    {
        $model = new InsuranceBusinessModel();

        $revenuePerShare = 150.0;
        $bookValuePerShare = 100.0; // Actual turnover = 1.50 (Kenney capacity)
        $baselineMargin = 0.12; // 12% sustainable operating margin (float yield + benign underwriting)
        $baselineRoic = 0.10;

        // The trailing ROE's gap to capacity (1.50 x 0.12 = 0.18) is kept at its persistent-equivalent share at a 10%
        // cost of equity (Ohlson 1995; Fama & French 2000).
        $rate = 0.10;
        $kept = TimeSeries::persistentEquivalentReturn(1.0, 0.0, $rate);
        $this->assertGreaterThan(0.0, $kept);
        $this->assertLessThan(0.30, $kept, 'A good or bad year is not capitalized as permanent.');

        // 1. Normal profitable regime (TTM ROE = 15%)
        $normalStructuralRoic = $model->calculateStructuralRoic(0.15, $baselineRoic, $revenuePerShare, $bookValuePerShare, $baselineMargin, $rate);
        $this->assertEqualsWithDelta(0.18 + ((0.15 - 0.18) * $kept), $normalStructuralRoic, 1e-12);

        // 2. Severe catastrophe collapse (TTM ROE = -50% due to major disaster): still above the floor, only a
        // fading share of the loss is capitalized.
        $catastropheStructuralRoic = $model->calculateStructuralRoic(-0.50, $baselineRoic, $revenuePerShare, $bookValuePerShare, $baselineMargin, $rate);
        $this->assertEqualsWithDelta(max(InsuranceBusinessModel::MIN_STRUCTURAL_ROE_FLOOR, 0.18 + ((-0.50 - 0.18) * $kept)), $catastropheStructuralRoic, 1e-12);
        $this->assertGreaterThan(0.0, $catastropheStructuralRoic);

        // 3. A loss deep enough to cross the floor is held at it.
        $this->assertSame(InsuranceBusinessModel::MIN_STRUCTURAL_ROE_FLOOR, $model->calculateStructuralRoic(-5.0, $baselineRoic, $revenuePerShare, $bookValuePerShare, $baselineMargin, $rate));
    }

    /**
     * The premium book an insurer writes is capped by its surplus (Kenney), whatever it happens to be
     * earning. Everything getTargetMetrics() returns is divided by the after-tax underwriting margin
     * downstream to recover a premium turnover, so any term that reaches the result in units of a
     * return on EQUITY writes premium against float investment income.
     */
    public function testPremiumTurnoverNeverExceedsKenneyCapacity(): void
    {
        $model = new InsuranceBusinessModel();
        $mathUtility = new MathUtility();
        // Rates at the level the book was priced at, so neither the float-yield discount nor the hurdle moves the book.
        $macroState = new MacroStateDTO(
            policyRate: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
            policyRateEma: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
            perceivedNeutralRate: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
        );
        $afterTaxMargin = static fn(float $margin): float => $margin * (1.0 - $macroState->corporateTaxRate);

        // A float-levered underwriter: a 4.5% underwriting margin, and a TTM ROE of 20% that the float,
        // not the premium book, is earning. The blend must not turn that ROE back into premium.
        $stock = $this->kenneyStock(0.045, 0.20);
        $turnover = $model->getTargetMetrics($stock, $macroState, $mathUtility)['baseline_roic'] / $afterTaxMargin(0.045);
        $this->assertEqualsWithDelta(InsuranceBusinessModel::KENNEY_CAPACITY_RATIO, $turnover, 0.001);

        // Thin margins put the capacity return below the cost of capital. The WACC floor under the
        // RETURN must not become a floor under the BOOK: an underwriter that cannot earn its hurdle on
        // the premium its surplus supports does not answer by writing more of it.
        $thin = $this->kenneyStock(0.02, 0.20);
        $thinCapacityRoic = InsuranceBusinessModel::KENNEY_CAPACITY_RATIO * $afterTaxMargin(0.02);
        $this->assertLessThan($macroState->policyRate + $macroState->equityRiskPremium, $thinCapacityRoic);
        $thinTurnover = $model->getTargetMetrics($thin, $macroState, $mathUtility)['baseline_roic'] / $afterTaxMargin(0.02);
        $this->assertEqualsWithDelta(InsuranceBusinessModel::KENNEY_CAPACITY_RATIO, $thinTurnover, 0.001);

        // The blend still bites downward: a book earning far below its structural capacity return pulls
        // the target under the cap, which is the pullback after a loss year the blend exists to model.
        $impaired = $this->kenneyStock(0.20, 0.03);
        $capacityRoic = InsuranceBusinessModel::KENNEY_CAPACITY_RATIO * $afterTaxMargin(0.20);
        $blended = ($capacityRoic * InsuranceBusinessModel::BASELINE_ROIC_WEIGHT)
            + (InsuranceBusinessModel::MIN_STRUCTURAL_ROE_FLOOR * InsuranceBusinessModel::TTM_ROIC_WEIGHT);
        $impairedRoic = $model->getTargetMetrics($impaired, $macroState, $mathUtility)['baseline_roic'];
        $this->assertEqualsWithDelta($blended, $impairedRoic, 0.0001);
        $this->assertLessThan(InsuranceBusinessModel::KENNEY_CAPACITY_RATIO, $impairedRoic / $afterTaxMargin(0.20));
    }

    /**
     * The soft half of the capacity cycle (Winter 1994 / Gron 1994). Capital accumulated beyond what the
     * market can absorb competes for the same premium and rates fall; without it an underwriter that
     * retains its earnings faces nothing that prices the glut it is building.
     */
    public function testSoftMarketDiscountsRatesOnceCapitalOutgrowsItsMarket(): void
    {
        $model = new InsuranceBusinessModel();
        // Policy rate at the neutral rate so the Cummins-Danzon float-yield discount is zero and
        // the capacity term is the only thing moving the multiplier.
        $macroState = new MacroStateDTO(
            policyRate: 0.035,
            policyRateEma: 0.035,
            perceivedNeutralRate: 0.035,
        );
        $pricingPower = static fn(Stock $s): float => (float) $model->getMacroPhysics($s, $macroState)['pricing_power_multiplier'];

        // Capacity short of demand: no glut, no discount.
        $this->assertEqualsWithDelta(1.0, $pricingPower($this->capacityStock(0.80)), 0.0001);

        // Capacity half again its demand: rates soften in proportion to the glut, 1.5·S/D − 1.
        $this->assertEqualsWithDelta(
            1.0 - (0.50 * InsuranceBusinessModel::SOFT_MARKET_CAPACITY_BETA),
            $pricingPower($this->capacityStock(1.50)),
            0.0001,
        );

        // Price competition has a floor: however overcapitalized, the market does not give cover away.
        $this->assertEqualsWithDelta(
            1.0 - InsuranceBusinessModel::SOFT_MARKET_MAX_DISCOUNT,
            $pricingPower($this->capacityStock(8.00)),
            0.0001,
        );

        // A rate cut has to reach the combined ratio, which it only does if the cost base is declared
        // separately: the engine deflates costs by input_cost_multiplier / pricing_power_multiplier, so a
        // model leaving the cost level to default holds its margin whatever it charges.
        $physics = $model->getMacroPhysics($this->capacityStock(1.50), $macroState);
        $this->assertSame(1.0, $physics['input_cost_multiplier']);
        $this->assertLessThan($physics['input_cost_multiplier'], $physics['pricing_power_multiplier']);
    }

    /**
     * Underwriting discipline: capacity is a ceiling, not a plan. An underwriter offered a rate its capital
     * cannot earn a return on writes less of the book (Winter 1994 / Gron 1994), and it does so along a
     * supply curve: the old rule switched from the whole book to the floor the moment the book's own return
     * crossed zero, and the written premium of the live game flipped between the two.
     */
    public function testDisciplinedUnderwriterWritesLessThanCapacityAtInadequateRates(): void
    {
        $model = new InsuranceBusinessModel();
        $mathUtility = new MathUtility();
        // Policy rate at neutral: the float-yield discount is zero and the capacity glut alone softens rates.
        $macroState = new MacroStateDTO(
            policyRate: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
            policyRateEma: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
            perceivedNeutralRate: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
        );
        $target = static fn(Stock $s): float => $model->resolveTargetWrittenShare($s, $macroState, $mathUtility, (float) $s->getTotalEquity());

        // Rates at the level the book was priced at, with a float that covers the hurdle: nearly the whole book.
        $this->assertGreaterThan(0.99, $target($this->underwriterAtCapacity(0.80, floatToEquity: 10.0)));

        // Rates softening across the point where the book's own return, float included, turns negative: the
        // written share falls with them and never jumps. The old switch moved 0.65 of capacity in one step here.
        $previous = null;
        $largestStep = 0.0;
        for ($ratio = 0.80; $ratio <= 1.80; $ratio += 0.004) {
            $written = $target($this->underwriterAtCapacity($ratio));
            if ($previous !== null) {
                $this->assertLessThanOrEqual($previous + 1e-12, $written, sprintf('Softer rates never buy more book (capacity/demand %.3f).', $ratio));
                $largestStep = max($largestStep, $previous - $written);
            }
            $previous = $written;
        }
        $this->assertLessThan(0.02, $largestStep);
        $this->assertGreaterThan(0.85, $target($this->underwriterAtCapacity(0.80)));
        $this->assertEqualsWithDelta(InsuranceBusinessModel::MIN_WRITTEN_CAPACITY, $previous, 0.001);

        // However inadequate the rate, a going concern still writes its obligatory renewals.
        $collapsed = $target($this->underwriterAtCapacity(3.00));
        $this->assertGreaterThanOrEqual(InsuranceBusinessModel::MIN_WRITTEN_CAPACITY, $collapsed);
        $this->assertEqualsWithDelta(InsuranceBusinessModel::MIN_WRITTEN_CAPACITY, $collapsed, 0.001);

        // The manager's own hurdle decides where that line falls (Jensen's agency cost, in its underwriting
        // form): an empire builder keeps writing a market a fortress has already withdrawn from.
        $fortress = $this->underwriterAtCapacity(1.30, floatToEquity: 10.0);
        $fortress->setManagementStyle(\App\Data\Company\ManagementStyle::Fortress);
        $empireBuilder = $this->underwriterAtCapacity(1.30, floatToEquity: 10.0);
        $empireBuilder->setManagementStyle(\App\Data\Company\ManagementStyle::EmpireBuilder);
        $this->assertGreaterThan($target($fortress), $target($empireBuilder));
    }

    /**
     * Float now follows the book, so writing a unit of it brings reserves that earn the portfolio yield (Myers &
     * Cohn 1987). Treating the float as already invested — what it was while it grew with GDP — makes a firm in a
     * soft market shed a book whose float would have carried it, and then lose the float too.
     */
    public function testTheFloatABookBringsCountsInTheDecisionToWriteIt(): void
    {
        $model = new InsuranceBusinessModel();
        $mathUtility = new MathUtility();
        $macroState = new MacroStateDTO(
            policyRate: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
            policyRateEma: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
            perceivedNeutralRate: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
        );
        $target = static fn(Stock $s, MacroStateDTO $m): float => $model->resolveTargetWrittenShare($s, $m, $mathUtility, (float) $s->getTotalEquity());

        // At a rate 4.8% soft the combined ratio is over 100. A firm whose float carries it keeps writing; one
        // with little float sheds more of the same book.
        $floatRich = $target($this->underwriterAtCapacity(1.12, floatToEquity: 10.0), $macroState);
        $floatPoor = $target($this->underwriterAtCapacity(1.12, floatToEquity: 1.2), $macroState);
        $this->assertGreaterThan(0.95, $floatRich);
        $this->assertGreaterThan($floatPoor, $floatRich);

        // A bond book struck at no yield makes the same rate an outright loss against the same hurdle, and more
        // of the book is shed.
        $yielding = $this->underwriterAtCapacity(1.12);
        $yielding->setSecuritiesCarryingYield(0.04);
        $noYield = $this->underwriterAtCapacity(1.12);
        $noYield->setSecuritiesCarryingYield(0.0);
        $this->assertLessThan($target($yielding, $macroState), $target($noYield, $macroState));
    }

    /**
     * Capital behind a book the firm is declining to write is redundant, and so is capital its demand has
     * outgrown: the life-cycle payout expansion reads both as a reason to hand it back. Without the return leg
     * the capacity cycle has no way down, and the firm grinds into a fund with an insurance licence.
     */
    public function testSurplusBehindUnwrittenBusinessIsReportedAsUndeployable(): void
    {
        $model = new InsuranceBusinessModel();
        $mathUtility = new MathUtility();
        $macroState = new MacroStateDTO(
            policyRate: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
            policyRateEma: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
        );

        // Capacity short of demand and nearly the whole book written: every dollar of surplus is writing.
        $this->assertLessThan(0.01, $model->getUndeployableCapitalShare($this->underwriterAtCapacity(0.80, floatToEquity: 10.0), $macroState, $mathUtility));

        // Capital a third past its demand: idle behind the lines it declines AND behind demand it has outgrown.
        $soft = $this->underwriterAtCapacity(1.30);
        $undeployable = $model->getUndeployableCapitalShare($soft, $macroState, $mathUtility);
        $targetShare = $model->resolveTargetWrittenShare($soft, $macroState, $mathUtility, (float) $soft->getTotalEquity());
        $this->assertGreaterThan(1.0 - $targetShare, $undeployable);

        // It is the exact complement of the book getTargetMetrics() writes, so the two cannot disagree.
        $written = $model->getTargetMetrics($soft, $macroState, $mathUtility)['baseline_roic']
            / (InsuranceBusinessModel::KENNEY_CAPACITY_RATIO * (float) $soft->getOperatingMargin() * (1.0 - $macroState->corporateTaxRate));
        $this->assertEqualsWithDelta(1.0 - $written, $undeployable, 0.0001);

        // And what it writes is the book in force, not the target it is still renewing toward.
        $this->mergeState($soft, [InsuranceBusinessModel::STATE_WRITTEN_SHARE => 0.80]);
        $this->assertEqualsWithDelta(1.0 - (0.80 / 1.30), $model->getUndeployableCapitalShare($soft, $macroState, $mathUtility), 1e-9);
    }

    /**
     * Rates are quoted against the capital the market has SEEN. The lag between a firm's capital changing
     * and its renewal rates moving is what makes the capacity cycle a cycle rather than a level.
     */
    public function testRatesFollowObservedCapitalRatherThanTodaysBalanceSheet(): void
    {
        $model = new InsuranceBusinessModel();
        $macroState = new MacroStateDTO(
            policyRate: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
            policyRateEma: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
            perceivedNeutralRate: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
        );

        // Capacity already twice its demand, but nothing has been filed or rated yet.
        $stock = $this->underwriterAtCapacity(2.00);
        $this->mergeState($stock, [InsuranceBusinessModel::STATE_OBSERVED_CAPACITY => 0.80]);
        $this->assertEqualsWithDelta(1.0, (float) $model->getMacroPhysics($stock, $macroState)['pricing_power_multiplier'], 0.0001);

        // Each quarter of physics moves the observed position toward the real one, and once the market has
        // caught up with the capital that was built, the rate follows it down.
        $observed = 0.80;
        foreach (range(1, 2) as $quarter) {
            $result = $model->computeActualFinancials($stock, 50_000_000_000.0, 0.90, 1.0e9, 0.10, $macroState, $this->scriptedMath());
            $next = $result->streamZ[InsuranceBusinessModel::STATE_OBSERVED_CAPACITY] ?? 0.0;

            $this->assertGreaterThan($observed, $next);
            $this->assertLessThan(2.00, $next);

            $observed = $next;
            $this->mergeState($stock, [InsuranceBusinessModel::STATE_OBSERVED_CAPACITY => $observed]);
        }

        $this->assertLessThan(1.0, (float) $model->getMacroPhysics($stock, $macroState)['pricing_power_multiplier']);
    }

    /**
     * An insurer earns on premium AND on float, and only the premium leg moves with the book it writes.
     */
    public function testStructuralReturnKeepsTheFloatLegWhenTheBookShrinks(): void
    {
        $model = new InsuranceBusinessModel();
        $baselineMargin = 0.045;
        $baselineReturn = 0.14; // What the firm is built to earn: underwriting plus float.
        $bookValuePerShare = 100.0;
        $fullCapacityRevenue = $bookValuePerShare * InsuranceBusinessModel::KENNEY_CAPACITY_RATIO;

        // The float leg is whatever the anchor carries beyond full-capacity underwriting (0.14 - 0.0675).
        $atCapacity = $model->calculateStructuralRoic(0.14, $baselineReturn, $fullCapacityRevenue, $bookValuePerShare, $baselineMargin, 0.10);
        $this->assertEqualsWithDelta(0.14, $atCapacity, 0.0001);

        // Writing two thirds of the book costs the underwriting third of it, and nothing else: the float is
        // invested either way. Measured on underwriting alone this firm re-rated on every rate cycle.
        $withdrawn = $model->calculateStructuralRoic(0.14, $baselineReturn, $fullCapacityRevenue * (2.0 / 3.0), $bookValuePerShare, $baselineMargin, 0.10);
        $underwritingLost = (InsuranceBusinessModel::KENNEY_CAPACITY_RATIO * (1.0 / 3.0)) * $baselineMargin;
        $this->assertEqualsWithDelta(0.14 - ($underwritingLost * (1.0 - TimeSeries::persistentEquivalentReturn(1.0, 0.0, 0.10))), $withdrawn, 1e-12);
    }

    /**
     * A capital shortage is measured against the Kenney capital for the book the firm wants to write, demand times
     * its target share over 1.5. Measured against the firm's own written book it could never open, because that
     * book is sized from the same surplus.
     */
    public function testHardMarketCapitalTriggerIsMeasuredAgainstTheDesiredBook(): void
    {
        $model = new InsuranceBusinessModel();
        $regimeKey = StreamContext::REGIME_STATE_PREFIX . InsuranceBusinessModel::REGIME_HARD_MARKET;
        $quarterlyPremium = 100_000_000_000.0;
        $macro = new MacroStateDTO();

        // Surplus 30% short of what demand needs, and its book sized from that surplus all the same.
        $impaired = $this->underwriterAtCapacity(0.70);
        $adequate = $this->underwriterAtCapacity(1.00);
        $impaired->setTotalRevenue((string) ((float) $impaired->getTotalEquity() * InsuranceBusinessModel::KENNEY_CAPACITY_RATIO));
        $desiredShare = $model->resolveTargetWrittenShare($impaired, $macro, new MathUtility(), (float) $impaired->getTotalEquity());
        $this->assertGreaterThan(InsuranceBusinessModel::HARD_MARKET_ONSET_SURPLUS_DEFICIT, 1.0 - (0.70 / $desiredShare));

        $impairedResult = $model->computeActualFinancials($impaired, $quarterlyPremium, 0.90, 1.0e9, 0.10, $macro, $this->scriptedMath());
        $adequateResult = $model->computeActualFinancials($adequate, $quarterlyPremium, 0.90, 1.0e9, 0.10, $macro, $this->scriptedMath());

        $this->assertSame(1.0, $impairedResult->streamZ[$regimeKey] ?? 0.0);
        $this->assertSame(0.0, $adequateResult->streamZ[$regimeKey] ?? 0.0);

        // The harder rate reaches the combined ratio through the pricing-power multiplier at renewal; the
        // shortfall itself must not discount this quarter's claims a second time.
        $this->assertEqualsWithDelta($adequateResult->clampedMargin, $impairedResult->clampedMargin, 1e-12);
    }

    /**
     * A firm that writes less because the rate does not pay, and holds only the capital that smaller book needs, is
     * not short of capital: Winter's (1994) constraint binds on the quantity it would supply at the price, not on
     * the whole of demand. Measured against all of demand, every capital return in a soft market opened a hard one.
     */
    public function testASoftMarketWithCapitalReturnedIsNotAShortage(): void
    {
        $model = new InsuranceBusinessModel();
        $regimeKey = StreamContext::REGIME_STATE_PREFIX . InsuranceBusinessModel::REGIME_HARD_MARKET;
        $macro = new MacroStateDTO();

        // A thin float and a demanding hurdle: the firm wants well under the whole book at par rates, and holds 80%
        // of the capital all of demand would need, more than its desired book needs.
        $withdrawn = $this->underwriterAtCapacity(0.80, floatToEquity: 0.5);
        $withdrawn->setManagementStyle(\App\Data\Company\ManagementStyle::Steward);
        $desiredShare = $model->resolveTargetWrittenShare($withdrawn, $macro, new MathUtility(), (float) $withdrawn->getTotalEquity());
        $this->assertLessThan(0.80, $desiredShare);
        $this->assertGreaterThanOrEqual(InsuranceBusinessModel::HARD_MARKET_ONSET_SURPLUS_DEFICIT, 1.0 - 0.80, 'Against all of demand this would be a shortage.');

        $result = $model->computeActualFinancials($withdrawn, 100_000_000_000.0, 0.90, 1.0e9, 0.10, $macro, $this->scriptedMath());
        $this->assertSame(0.0, $result->streamZ[$regimeKey] ?? 0.0);
    }

    /**
     * An underwriter steers to its own premium-to-surplus (Gropp & Heider 2010; Cheng & Weiss 2012), struck on its
     * opening book, and the engine returns the surplus above it. The ratio handed to the engine is the book capital
     * ratio at which the statutory surplus carries the premium at that target; marks and goodwill pass through.
     */
    public function testAnUnderwriterSteersToItsOpeningPremiumToSurplus(): void
    {
        $model = new InsuranceBusinessModel();
        $stock = $this->underwriter(1_500_000_000_000.0);
        $stock->setCorporateTreasury('4500000000000');
        $stock->setGoodwill('100000000000');
        $stock->setTotalEquity('1600000000000');
        $stock->setTotalRevenue('1200000000000');

        // Before the first report has struck a target there is none, and the engine runs no capital target.
        $unstruck = clone $stock;
        $this->assertNull($model->getTargetCapitalRatio($unstruck));

        $stock->setEarningsMomentumZ([InsuranceBusinessModel::STATE_TARGET_PREMIUM_TO_SURPLUS => 1.20]);
        $target = $model->getTargetCapitalRatio($stock);
        $this->assertNotNull($target);

        // 1.2T of premium at 1.2x needs 1.0T of surplus; goodwill rides on top. Retiring the excess E - E* takes
        // equity and assets down together and lands exactly on the target ratio.
        $equity = 1_600_000_000_000.0;
        $targetEquity = 1_000_000_000_000.0 + 100_000_000_000.0;
        $assets = $stock->getTotalAssets();
        $this->assertEqualsWithDelta($targetEquity / ($assets - ($equity - $targetEquity)), $target, 1e-12);

        // The engine's partial adjustment hands back a share of that excess, and nothing once the firm is at target.
        $repurchase = \App\Service\Corporate\CapitalAllocationEngine::capitalTargetRepurchase($equity, $assets, $target, $equity / $assets);
        $this->assertGreaterThan(0.0, $repurchase);
        $this->assertLessThan($equity - $targetEquity, $repurchase);
        $this->assertSame(0.0, \App\Service\Corporate\CapitalAllocationEngine::capitalTargetRepurchase($targetEquity, $assets - ($equity - $targetEquity), $target, $target));
    }

    /**
     * The first report strikes the target premium-to-surplus on the opening book, 1.5 x its written share, and carries
     * it.
     */
    public function testTheFirstReportStrikesTheTargetPremiumToSurplus(): void
    {
        $model = new InsuranceBusinessModel();
        $macro = new MacroStateDTO();
        $stock = $this->underwriter(1_000_000_000_000.0);
        $stock->setEarningsMomentumZ([InsuranceBusinessModel::STATE_WRITTEN_SHARE => 0.90]);

        $result = $model->computeActualFinancials($stock, 250_000_000_000.0, 0.90, 1.0e9, 0.10, $macro, $this->scriptedMath());
        $struck = $result->streamZ[InsuranceBusinessModel::STATE_TARGET_PREMIUM_TO_SURPLUS] ?? null;
        $this->assertEqualsWithDelta(InsuranceBusinessModel::KENNEY_CAPACITY_RATIO * 0.90, $struck, 1e-12);

        $stock->setEarningsMomentumZ(array_merge($result->streamZ, [InsuranceBusinessModel::STATE_WRITTEN_SHARE => 0.50]));
        $next = $model->computeActualFinancials($stock, 250_000_000_000.0, 0.90, 1.0e9, 0.10, $macro, $this->scriptedMath());
        $this->assertSame($struck, $next->streamZ[InsuranceBusinessModel::STATE_TARGET_PREMIUM_TO_SURPLUS] ?? null);
    }

    /**
     * Winter (1994): premium is min(demand x written share, Kenney capacity). Capital beyond demand writes
     * nothing; capital short of it rations the book at the Kenney ratio.
     */
    public function testPremiumIsDemandTimesWrittenShareCappedByKenneyCapacity(): void
    {
        $model = new InsuranceBusinessModel();
        $math = new MathUtility();
        $macro = new MacroStateDTO(
            policyRate: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
            policyRateEma: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
            perceivedNeutralRate: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK,
        );
        $premium = static function (Stock $s) use ($model, $macro, $math): float {
            $metrics = $model->getTargetMetrics($s, $macro, $math);

            return $metrics['invested_capital'] * $metrics['baseline_roic'] / ((float) $s->getOperatingMargin() * (1.0 - $macro->corporateTaxRate));
        };

        // Twice the capital its demand can use, writing every line: the book is demand, half its capacity.
        $glutted = $this->underwriterAtCapacity(2.00);
        $this->mergeState($glutted, [InsuranceBusinessModel::STATE_WRITTEN_SHARE => 1.0]);
        $demand = $model->resolvePremiumDemand($glutted, $macro);
        $this->assertEqualsWithDelta($demand, $premium($glutted), $demand * 1e-9);
        $this->assertEqualsWithDelta(0.5 * InsuranceBusinessModel::KENNEY_CAPACITY_RATIO * (float) $glutted->getTotalEquity(), $premium($glutted), $demand * 1e-9);

        // Half the capital its demand needs: the book is rationed at the Kenney ratio to surplus.
        $short = $this->underwriterAtCapacity(0.50);
        $this->mergeState($short, [InsuranceBusinessModel::STATE_WRITTEN_SHARE => 1.0]);
        $capacity = InsuranceBusinessModel::KENNEY_CAPACITY_RATIO * (float) $short->getTotalEquity();
        $this->assertEqualsWithDelta($capacity, $premium($short), $capacity * 1e-9);
    }

    /**
     * Demand rides trend nominal GDP and the franchise struck at the first report, never the capital the firm
     * retains; the output gap reaches volume through the lagged demand shift, so it is not in the anchor too.
     */
    public function testDemandGrowsWithTrendNominalGdpNotWithRetainedCapital(): void
    {
        $model = new InsuranceBusinessModel();
        $base = new MacroStateDTO();
        $stock = $this->underwriterAtCapacity(1.00);
        $demand = $model->resolvePremiumDemand($stock, $base);

        $retained = $this->underwriterAtCapacity(1.00);
        $retained->setTotalEquity((string) ((float) $retained->getTotalEquity() * 2.0));
        $this->assertEqualsWithDelta($demand, $model->resolvePremiumDemand($retained, $base), $demand * 1e-12);
        $this->assertEqualsWithDelta(2.0, $model->resolveCapacityToDemandRatio($retained, $base), 1e-9);

        $grown = new MacroStateDTO(potentialGdpIndex: 1.5, gdpDeflator: 1.2);
        $this->assertEqualsWithDelta($demand * 1.8, $model->resolvePremiumDemand($stock, $grown), $demand * 1e-9);

        $boom = new MacroStateDTO(outputGap: 0.04);
        $this->assertEqualsWithDelta($demand, $model->resolvePremiumDemand($stock, $boom), $demand * 1e-12);
    }

    /**
     * A fresh book has no franchise yet: it opens with demand at its capacity, so the seeded premium is the one
     * its surplus wrote before, and its first quarter records the franchise that keeps it there.
     */
    public function testAFreshBookOpensInBalanceAndRecordsItsFranchise(): void
    {
        $model = new InsuranceBusinessModel();
        $macro = new MacroStateDTO();
        $stock = $this->underwriter(1_000_000_000_000.0);
        $capacity = InsuranceBusinessModel::KENNEY_CAPACITY_RATIO * 1_000_000_000_000.0;

        $this->assertEqualsWithDelta($capacity, $model->resolvePremiumDemand($stock, $macro), 1.0);
        $this->assertEqualsWithDelta(1.0, $model->resolveCapacityToDemandRatio($stock, $macro), 1e-12);

        $result = $model->computeActualFinancials($stock, 250_000_000_000.0, 0.90, 1.0e9, 0.10, $macro, $this->scriptedMath());
        $franchise = $result->streamZ[InsuranceBusinessModel::STATE_PREMIUM_FRANCHISE_SHARE] ?? null;
        $this->assertNotNull($franchise);
        $this->assertEqualsWithDelta($capacity / (CorporateMetrics::BASELINE_SECTOR_TAM * (float) $stock->getSamRatio()), $franchise, 1e-12);

        // Carried, not re-struck: a quarter later on doubled capital the franchise is the one recorded.
        $stock->setEarningsMomentumZ($result->streamZ);
        $stock->setTotalEquity('2000000000000');
        $next = $model->computeActualFinancials($stock, 250_000_000_000.0, 0.90, 1.0e9, 0.10, $macro, $this->scriptedMath());
        $this->assertSame($franchise, $next->streamZ[InsuranceBusinessModel::STATE_PREMIUM_FRANCHISE_SHARE] ?? null);
    }

    /** Draws pinned so the claim Z carries no catastrophe: the capital channel is what is under test. */
    private function scriptedMath(): MathUtility
    {
        $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateStandardNormal', 'checkProbability'])->getMock();
        $math->method('generateStandardNormal')->willReturn(0.0);
        $math->method('checkProbability')->willReturn(false);

        return $math;
    }

    /** A solvent underwriter with the given surplus, carrying no float large enough to imply runoff equity. */
    private function underwriter(float $equity): Stock
    {
        $stock = new Stock();
        $stock->setTicker('TEST');
        $stock->setIndustry('Insurance - Specialty');
        $stock->setSystemicImportance('base');
        $stock->setTotalEquity((string) $equity);
        $stock->setCustomerDeposits((string) ($equity * 2.0));
        $stock->setOperatingMargin('0.10');
        $stock->setTotalRevenue((string) ($equity * InsuranceBusinessModel::KENNEY_CAPACITY_RATIO));
        $stock->setRoeTtm('0.10');
        $stock->setSamRatio('10.00');

        return $stock;
    }

    /**
     * An underwriter carrying a real float, whose Kenney capacity is the given multiple of its premium demand,
     * and whose prior book is small enough that the hard-market revenue floor does not hold its premium up.
     */
    private function underwriterAtCapacity(float $capacityToDemand, float $floatToEquity = 3.0): Stock
    {
        $stock = $this->capacityStock($capacityToDemand);
        $equity = (float) $stock->getTotalEquity();
        $stock->setOperatingMargin('0.045');
        $stock->setCustomerDeposits((string) ($equity * $floatToEquity));
        $stock->setCorporateTreasury((string) ($equity * $floatToEquity));
        $stock->setTotalRevenue((string) ($equity * 0.50));

        return $stock;
    }

    /**
     * An underwriter whose Kenney capacity is the given multiple of its premium demand, at trend nominal GDP of one.
     */
    private function capacityStock(float $capacityToDemand): Stock
    {
        $samRatio = 2.0;
        $equity = 1_000_000_000_000.0;

        $stock = new Stock();
        $stock->setTicker('TEST');
        $stock->setIndustry('Insurance - Specialty');
        $stock->setSystemicImportance('base');
        $stock->setSamRatio((string) $samRatio);
        $stock->setTotalEquity((string) $equity);
        $stock->setCustomerDeposits((string) $equity);
        $stock->setWholesaleDebt('0');
        $stock->setCorporateTreasury((string) $equity);
        $stock->setOperatingMargin('0.10');
        $stock->setTotalRevenue((string) ($equity * InsuranceBusinessModel::KENNEY_CAPACITY_RATIO));
        $stock->setRoeTtm('0.10');
        $demand = InsuranceBusinessModel::KENNEY_CAPACITY_RATIO * $equity / $capacityToDemand;
        $stock->setEarningsMomentumZ([
            InsuranceBusinessModel::STATE_PREMIUM_FRANCHISE_SHARE => $demand / (CorporateMetrics::BASELINE_SECTOR_TAM * $samRatio),
        ]);

        return $stock;
    }

    /** @param array<string, float> $state */
    private function mergeState(Stock $stock, array $state): void
    {
        $stock->setEarningsMomentumZ(array_merge($stock->getEarningsMomentumZ() ?? [], $state));
    }

    /** An unsaturated insurer carrying a float too small to imply runoff equity, opened at exactly Kenney capacity. */
    private function kenneyStock(float $operatingMargin, float $roeTtm): Stock
    {
        $equity = 1_500_000_000_000.0;

        $stock = new Stock();
        $stock->setTicker('TEST');
        $stock->setIndustry('Insurance - Reinsurance');
        $stock->setSystemicImportance('titan');
        $stock->setTotalEquity((string) $equity);
        $stock->setCustomerDeposits((string) ($equity * 2.0));
        $stock->setOperatingMargin((string) $operatingMargin);
        $stock->setTotalRevenue((string) ($equity * InsuranceBusinessModel::KENNEY_CAPACITY_RATIO));
        $stock->setRoeTtm((string) $roeTtm);
        // Serviceable market wide enough that the Penrose saturation penalty is zero here: this test is
        // about the capacity cap, and tests/Financial covers the saturation channel on its own.
        $stock->setSamRatio('10.00');
        // The in-force book is written at full capacity, so the cap and the blend are all that is under test.
        $stock->setEarningsMomentumZ([InsuranceBusinessModel::STATE_WRITTEN_SHARE => 1.0]);

        return $stock;
    }

    // --- District Catastrophes ---

    /**
     * One district storm season is the same claim for every carrier: the burden shifts each firm's claim draw, so all
     * three books lose together.
     */
    public function testADistrictStormSeasonRaisesClaimsForEveryCarrierAtOnce(): void
    {
        $carriers = [
            new InsuranceBusinessModel(),
            new \App\Service\Model\Sector\ReinsuranceBusinessModel(),
            new RetailInsuranceBusinessModel(),
        ];

        foreach ($carriers as $carrier) {
            $run = function (float $burden) use ($carrier): float {
                $stock = new Stock();
                $stock->setTicker('CARR');
                $stock->setBeta('0.6');
                $stock->setTotalEquity('60000000000');
                $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateStandardNormal', 'generatePersistentZ', 'checkProbability'])->getMock();
                $math->method('generateStandardNormal')->willReturn(0.0);
                $math->method('generatePersistentZ')->willReturn(0.0);
                $math->method('checkProbability')->willReturn(false);
                $macro = new MacroStateDTO(...['inflationEma' => 0.02, 'policyRateEma' => 0.03, 'yield10yEma' => 0.04, ...DistrictCatastropheWindow::prime($stock, $burden)]);

                return $carrier->computeActualFinancials($stock, 50_000_000_000.0, 0.60, 2_000_000_000.0, 0.15, $macro, $math)->actualVariableCosts;
            };

            $averageYear = $run(1.0);
            $stormSeason = $run(1.0 + 3.0 * \App\Service\Macro\Subsystem\CommodityLogisticsSubsystem::CATASTROPHE_LOSS_INDEX_SD);
            $this->assertGreaterThan($averageYear, $stormSeason, sprintf('%s must pay more claims in a district storm season with its own draw flat.', $carrier::class));
        }
    }

    public function testADistrictStormSeasonHardensTheMarketForAWellCapitalisedCarrier(): void
    {
        $model = new InsuranceBusinessModel();
        $regimeKey = StreamContext::REGIME_STATE_PREFIX . InsuranceBusinessModel::REGIME_HARD_MARKET;

        $run = function (float $burden) use ($model): array {
            $stock = new Stock();
            $stock->setTicker('CARR');
            $stock->setBeta('0.6');
            $stock->setTotalEquity('200000000000'); // far above the Kenney target: no capital trigger
            $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateStandardNormal', 'generatePersistentZ', 'checkProbability'])->getMock();
            $math->method('generateStandardNormal')->willReturn(0.0);
            $math->method('generatePersistentZ')->willReturn(0.0);
            $math->method('checkProbability')->willReturn(false);
            $macro = new MacroStateDTO(inflationEma: 0.02, policyRateEma: 0.03, yield10yEma: 0.04, catastropheLossIndexEma: $burden);

            return $model->computeActualFinancials($stock, 50_000_000_000.0, 0.60, 2_000_000_000.0, 0.15, $macro, $math)->streamZ;
        };

        $this->assertArrayNotHasKey($regimeKey, array_filter($run(1.0)), 'An average year with ample capital starts no hard market.');
        $this->assertGreaterThan(0.0, $run(InsuranceBusinessModel::CAT_HARD_MARKET_THRESHOLD)[$regimeKey] ?? 0.0, 'A district storm season hardens the market whatever the carrier\'s own capital.');
    }

    public function testTheBondTrancheEarnsItsBookYieldAndLeavesTheRateMoveToTheMark(): void
    {
        // A book struck at 3% earns 3% whatever the 10y does since; the move is the AOCI mark on the same tranche.
        $model = new InsuranceBusinessModel();
        $stock = new Stock();
        $stock->setTicker('XXXX');
        $stock->setCorporateTreasury('1000000000');
        $stock->setCustomerDeposits('1000000000');
        $stock->setSecuritiesCarryingYield(0.03);

        $atBook = $model->calculateInterestIncome($stock, new MacroStateDTO(yield10yEma: 0.03));
        $afterSelloff = $model->calculateInterestIncome($stock, new MacroStateDTO(yield10yEma: 0.06));
        $this->assertEqualsWithDelta($atBook, $afterSelloff, 1e-6);

        // A book with no history was bought at today's curve.
        $stock->setSecuritiesCarryingYield(null);
        $this->assertGreaterThan($atBook, $model->calculateInterestIncome($stock, new MacroStateDTO(yield10yEma: 0.06)));
    }

    /**
     * An insurer's through-the-cycle capacity blend is its persistence model, so the trailing return reaches it
     * unfaded.
     */
    public function testAnInsurerIsValuedOnItsOwnCapacityBlendNotTheFade(): void
    {
        $insurer = \App\Data\Company\Sectors::getBusinessModelStrategy('insurance');

        $this->assertSame(0.20, $insurer->getValuationReturn(0.20, 0.10, 0.09));
        $this->assertNotSame(0.20, \App\Data\Company\Sectors::getBusinessModelStrategy('none')->getValuationReturn(0.20, 0.10, 0.09));
    }
}
