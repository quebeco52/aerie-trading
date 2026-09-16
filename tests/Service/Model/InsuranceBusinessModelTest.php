<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\InsuranceBusinessModel;
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
        $this->assertEquals(4_000_000_000_000.0, $targetCash);
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
            ->willReturnOnConsecutiveCalls(0.0, 0.0, -2.5); // Firm factor = 0.0, Revenue Z = 0.0, Claim Z = -2.5 (Severe catastrophe)

        $macroState = \App\DTO\MacroStateDTO::fromArray([
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
            $mathUtilityMock
        );

        // Variable costs (claims) should be significantly elevated due to catastrophe loss
        $this->assertGreaterThan(50_000_000_000.0 * 0.60, $result->actualVariableCosts);
    }

    public function testCalculateEarningsValueWithFranchiseFloor(): void
    {
        $model = new InsuranceBusinessModel();
        $mathUtility = new MathUtility();

        // 1. When PE fair value is healthy ($100), returns PE fair value
        $earningsValNormal = $model->calculateEarningsValue(50.0, 100.0, 5.0, 0.08, $mathUtility);
        $this->assertEquals(100.0, $earningsValNormal);

        // 2. When PE fair value collapses to $0 (e.g. catastrophe claims), franchise floor cushions value (0.70 * $50 = $35)
        $earningsValLoss = $model->calculateEarningsValue(50.0, 0.0, -10.0, 0.08, $mathUtility);
        $this->assertEquals(35.0, $earningsValLoss);
    }

    public function testCalculateFairValueProfitableVsLossRegimes(): void
    {
        $model = new InsuranceBusinessModel();

        // 1. Profitable regime (normalized EPS > 0): 50% Book ($100) / 35% Earnings ($120) / 15% DDM ($80)
        // Base consensus = (120 * 0.50) + (100 * 0.50) = 60 + 50 = 110
        // Blended with DDM = (110 * 0.85) + (80 * 0.15) = 93.5 + 12 = 105.5
        $fairValProfit = $model->calculateFairValue(120.0, 100.0, 5.0, 80.0);
        $this->assertEquals(105.5, $fairValProfit);

        // 2. Catastrophe loss regime (normalized EPS <= 0): 100% Book ($100) blended with DDM ($80)
        // Base consensus = 100
        // Blended with DDM = (100 * 0.85) + (80 * 0.15) = 85 + 12 = 97.0
        $fairValLoss = $model->calculateFairValue(0.0, 100.0, -2.0, 80.0);
        $this->assertEquals(97.0, $fairValLoss);
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

    public function testBenignClaimEnvironmentReducesLossRatioWhilePreservingExpenseRatio(): void
    {
        $model = new InsuranceBusinessModel();
        $stock = new Stock();
        $stock->setTicker('TEST_BENIGN');
        $stock->setBeta('1.0');
        // Surplus covering the ANNUAL book at the Kenney ratio ($40B written / 1.5), so the capacity
        // channel stays out of a test about the claim channel: an underwriter short of capital hardens
        // its renewal rates, and the combined ratio below would carry that discount too.
        $stock->setTotalEquity('26666666667.0');

        $mathUtilityMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
        // Revenue Z = 0.0 (no revenue shock), Claim Z = 2.0 (benign environment)
        $mathUtilityMock->method('generateStandardNormal')
            ->willReturnOnConsecutiveCalls(0.0, 0.0, 2.0); // Firm factor = 0.0, Revenue Z = 0.0, Claim Z = 2.0

        $macroState = \App\DTO\MacroStateDTO::fromArray([
            'inflation_ema' => 0.02,
            'policy_rate' => 0.05,
            'gdp_growth' => 0.02,
            'credit_spread' => 0.015,
            'commercial_property_index_ema' => 100.0,
            'residential_property_index_ema' => 100.0,
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
            $mathUtilityMock
        );

        // Baseline Loss Ratio = 0.60 * 0.65 = 0.39
        // Baseline Expense Ratio = 0.60 * 0.35 = 0.21
        // Benign claim bonus = -0.08 * (1.0) = -0.08 applied only to loss ratio -> realized loss ratio = 0.31
        // Realized expense ratio remains 0.21
        // Realized combined ratio = 0.31 + 0.21 = 0.52
        $this->assertEqualsWithDelta(0.52, $result->clampedMargin, 0.001);
        $this->assertEqualsWithDelta(5_200_000_000.0, $result->actualVariableCosts, 1_000.0);
    }

    public function testExpenseRatioScalesWithRevenueFluctuations(): void
    {
        $model = new InsuranceBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SCALE');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('10000000000.0');

        $macroState = \App\DTO\MacroStateDTO::fromArray([
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
            ->willReturnOnConsecutiveCalls(0.0, -2.0 / sqrt(1.0 - 0.16), 0.0); // Firm factor = 0.0, composite Revenue Z = -2.0 (financial loading 0.40), Claim Z = 0.0

        $resultNeg = $model->computeActualFinancials(
            $stock,
            $expectedRevenue,
            $baseVariableMargin,
            1_000_000_000.0,
            $baselineVol,
            $macroState,
            $mockNegRevenue
        );

        // 2. Positive Revenue Shock (Revenue expands, expense ratio as % of revenue declines)
        $mockPosRevenue = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
        $mockPosRevenue->method('generateStandardNormal')
            ->willReturnOnConsecutiveCalls(0.0, 2.0 / sqrt(1.0 - 0.16), 0.0); // Firm factor = 0.0, composite Revenue Z = 2.0 (financial loading 0.40), Claim Z = 0.0

        $resultPos = $model->computeActualFinancials(
            $stock,
            $expectedRevenue,
            $baseVariableMargin,
            1_000_000_000.0,
            $baselineVol,
            $macroState,
            $mockPosRevenue
        );

        $this->assertGreaterThan($expectedRevenue, $resultPos->actualRevenue);
        $this->assertLessThan($expectedRevenue, $resultNeg->actualRevenue);

        // Combined ratio with depressed revenue should exceed combined ratio with expanded revenue due to expense ratio dynamics
        $this->assertGreaterThan($resultPos->clampedMargin, $resultNeg->clampedMargin);
    }

    public function testCalculateStructuralRoicWithCatastropheCollapse(): void
    {
        $model = new InsuranceBusinessModel();

        $revenuePerShare = 150.0;
        $bookValuePerShare = 100.0; // Actual turnover = 1.50 (Kenney capacity)
        $baselineMargin = 0.12; // 12% sustainable operating margin (float yield + benign underwriting)
        $baselineRoic = 0.10;

        // 1. Normal profitable regime (TTM ROE = 15%)
        // Structural ROE = 1.50 * 0.12 = 0.18
        // Blended ROE = (0.18 * 0.70) + (0.15 * 0.30) = 0.126 + 0.045 = 0.171
        $normalStructuralRoic = $model->calculateStructuralRoic(0.15, $baselineRoic, $revenuePerShare, $bookValuePerShare, $baselineMargin);
        $this->assertEqualsWithDelta(0.171, $normalStructuralRoic, 0.001);

        // 2. Severe catastrophe collapse (TTM ROE = -50% due to major disaster)
        // Structural capacity ROE = 1.50 * 0.12 = 0.18
        // Blended ROE = (0.18 * 0.70) + (-0.50 * 0.30) = 0.126 - 0.150 = -0.024 -> clamped to MIN_STRUCTURAL_ROE_FLOOR (0.03)
        $catastropheStructuralRoic = $model->calculateStructuralRoic(-0.50, $baselineRoic, $revenuePerShare, $bookValuePerShare, $baselineMargin);
        $this->assertEqualsWithDelta(InsuranceBusinessModel::MIN_STRUCTURAL_ROE_FLOOR, $catastropheStructuralRoic, 0.001);
        $this->assertGreaterThan(0.0, $catastropheStructuralRoic);

        // 3. Moderate catastrophe shock (TTM ROE = -10%)
        // Blended ROE = (0.18 * 0.70) + (-0.10 * 0.30) = 0.126 - 0.030 = 0.096
        $moderateCatastropheRoic = $model->calculateStructuralRoic(-0.10, $baselineRoic, $revenuePerShare, $bookValuePerShare, $baselineMargin);
        $this->assertEqualsWithDelta(0.096, $moderateCatastropheRoic, 0.001);
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
        $macroState = new \App\DTO\MacroStateDTO();
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
        // Policy rate at the model's own fallback so the Cummins-Danzon float-yield discount is zero and
        // the capacity term is the only thing moving the multiplier.
        $macroState = new \App\DTO\MacroStateDTO(policyRateEma: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK);
        $pricingPower = static fn(Stock $s): float => (float) $model->getMacroPhysics($s, $macroState)['pricing_power_multiplier'];

        // Capital inside the market's optimal scale: no glut, no discount.
        $this->assertEqualsWithDelta(1.0, $pricingPower($this->shareStock(0.40)), 0.0001);

        // Half again the optimal share: rates soften in proportion to the excess capital.
        $excess = (0.75 - \App\Service\Math\FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD)
            / (1.0 - \App\Service\Math\FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD);
        $this->assertEqualsWithDelta(
            1.0 - ($excess * InsuranceBusinessModel::SOFT_MARKET_CAPACITY_BETA),
            $pricingPower($this->shareStock(0.75)),
            0.0001
        );

        // Price competition has a floor: however overcapitalized, the market does not give cover away.
        $this->assertEqualsWithDelta(
            1.0 - InsuranceBusinessModel::SOFT_MARKET_MAX_DISCOUNT,
            $pricingPower($this->shareStock(4.00)),
            0.0001
        );

        // A rate cut has to reach the combined ratio, which it only does if the cost base is declared
        // separately: the engine deflates costs by input_cost_multiplier / pricing_power_multiplier, so a
        // model leaving the cost level to default holds its margin whatever it charges.
        $physics = $model->getMacroPhysics($this->shareStock(0.75), $macroState);
        $this->assertSame(1.0, $physics['input_cost_multiplier']);
        $this->assertLessThan($physics['input_cost_multiplier'], $physics['pricing_power_multiplier']);
    }

    /**
     * Underwriting discipline: capacity is a ceiling, not a plan. An underwriter offered a rate its capital
     * cannot earn a return on writes less of the book rather than all of it, which is the volume half of
     * the same capacity cycle and the reason industry premium-to-surplus is procyclical with rates.
     */
    public function testDisciplinedUnderwriterWritesLessThanCapacityAtInadequateRates(): void
    {
        $model = new InsuranceBusinessModel();
        $mathUtility = new MathUtility();
        $macroState = new \App\DTO\MacroStateDTO(policyRateEma: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK);
        $writtenCapacity = static function (Stock $s) use ($model, $macroState, $mathUtility): float {
            $roic = $model->getTargetMetrics($s, $macroState, $mathUtility)['baseline_roic'];

            return $roic / (InsuranceBusinessModel::KENNEY_CAPACITY_RATIO
                * (float) $s->getOperatingMargin() * (1.0 - $macroState->corporateTaxRate));
        };

        // Rates at the level the book was priced at: the whole capacity is worth writing.
        $this->assertEqualsWithDelta(1.0, $writtenCapacity($this->underwriterAtShare(0.40)), 0.001);

        // Capital a third past the market's optimal scale: rates soften far enough that the marginal treaty costs
        // more than it brings in, and the book is cut back rather than written at a loss.
        $soft = $writtenCapacity($this->underwriterAtShare(0.65));
        $this->assertLessThan(1.0, $soft);
        $this->assertGreaterThan(InsuranceBusinessModel::MIN_WRITTEN_CAPACITY, $soft);

        // However inadequate the rate, a going concern still writes its obligatory renewals.
        $collapsed = $writtenCapacity($this->underwriterAtShare(1.50));
        $this->assertSame(InsuranceBusinessModel::MIN_WRITTEN_CAPACITY, $collapsed);

        // The manager's own hurdle decides where that line falls (Jensen's agency cost, in its underwriting
        // form): an empire builder keeps writing a market a fortress has already withdrawn from.
        $fortress = $this->underwriterAtShare(0.65);
        $fortress->setManagementStyle(\App\Data\ManagementStyle::Fortress);
        $empireBuilder = $this->underwriterAtShare(0.65);
        $empireBuilder->setManagementStyle(\App\Data\ManagementStyle::EmpireBuilder);
        $this->assertGreaterThan($writtenCapacity($fortress), $writtenCapacity($empireBuilder));
    }

    /**
     * Capital behind a book the firm is declining to write is redundant, and the life-cycle payout
     * expansion reads that as a reason to hand it back. Without the return leg the capacity cycle has no
     * way down: capital only ever accumulates, and the firm grinds into a fund with an insurance licence.
     */
    public function testSurplusBehindUnwrittenBusinessIsReportedAsUndeployable(): void
    {
        $model = new InsuranceBusinessModel();
        $mathUtility = new MathUtility();
        $macroState = new \App\DTO\MacroStateDTO(policyRateEma: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK);

        // Writing the whole book: every dollar of surplus is doing something.
        $this->assertSame(0.0, $model->getUndeployableCapitalShare($this->underwriterAtShare(0.40), $macroState, $mathUtility));

        // Withdrawing from a soft market: the surplus behind the business it declines is not.
        $soft = $this->underwriterAtShare(0.65);
        $undeployable = $model->getUndeployableCapitalShare($soft, $macroState, $mathUtility);
        $this->assertGreaterThan(0.0, $undeployable);
        $this->assertLessThanOrEqual(1.0 - InsuranceBusinessModel::MIN_WRITTEN_CAPACITY, $undeployable);

        // It is the exact complement of what the firm chose to write, so the two decisions cannot disagree.
        $written = $model->getTargetMetrics($soft, $macroState, $mathUtility)['baseline_roic']
            / (InsuranceBusinessModel::KENNEY_CAPACITY_RATIO * (float) $soft->getOperatingMargin() * (1.0 - $macroState->corporateTaxRate));
        $this->assertEqualsWithDelta(1.0 - $written, $undeployable, 0.0001);
    }

    /**
     * Rates are quoted against the capital the market has SEEN. The lag between a firm's capital changing
     * and its renewal rates moving is what makes the capacity cycle a cycle rather than a level.
     */
    public function testRatesFollowObservedCapitalRatherThanTodaysBalanceSheet(): void
    {
        $model = new InsuranceBusinessModel();
        $macroState = new \App\DTO\MacroStateDTO(policyRateEma: InsuranceBusinessModel::DEFAULT_POLICY_RATE_FALLBACK);

        // Capital already well past the market's optimal scale, but nothing has been filed or rated yet.
        $stock = $this->underwriterAtShare(1.00);
        $stock->setEarningsMomentumZ([InsuranceBusinessModel::STATE_OBSERVED_CAPACITY => 0.40]);
        $this->assertEqualsWithDelta(1.0, (float) $model->getMacroPhysics($stock, $macroState)['pricing_power_multiplier'], 0.0001);

        // Each quarter of physics moves the observed position toward the real one, and once the market has
        // caught up with the capital that was built, the rate follows it down.
        $observed = 0.40;
        foreach (range(1, 2) as $quarter) {
            $result = $model->computeActualFinancials($stock, 50_000_000_000.0, 0.90, 1.0e9, 0.10, $macroState, $this->scriptedMath());
            $next = $result->streamZ[InsuranceBusinessModel::STATE_OBSERVED_CAPACITY] ?? 0.0;

            $this->assertGreaterThan($observed, $next);
            $this->assertLessThan(1.00, $next);

            $observed = $next;
            $stock->setEarningsMomentumZ([InsuranceBusinessModel::STATE_OBSERVED_CAPACITY => $observed]);
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
        $atCapacity = $model->calculateStructuralRoic(0.14, $baselineReturn, $fullCapacityRevenue, $bookValuePerShare, $baselineMargin);
        $this->assertEqualsWithDelta(0.14, $atCapacity, 0.0001);

        // Writing two thirds of the book costs the underwriting third of it, and nothing else: the float is
        // invested either way. Measured on underwriting alone this firm re-rated on every rate cycle.
        $withdrawn = $model->calculateStructuralRoic(0.14, $baselineReturn, $fullCapacityRevenue * (2.0 / 3.0), $bookValuePerShare, $baselineMargin);
        $underwritingLost = (InsuranceBusinessModel::KENNEY_CAPACITY_RATIO * (1.0 / 3.0)) * $baselineMargin;
        $this->assertEqualsWithDelta(0.14 - ($underwritingLost * 0.70), $withdrawn, 0.0001);
    }

    /**
     * The Kenney ratio is annual premium to surplus, and sector physics is handed one quarter of it: the
     * capacity trigger used to ask whether three quarters of the firm's capital had gone.
     */
    public function testHardMarketCapitalTriggerIsMeasuredOnTheAnnualBook(): void
    {
        $model = new InsuranceBusinessModel();
        $quarterlyPremium = 100_000_000_000.0;
        $annualTargetSurplus = ($quarterlyPremium * InsuranceBusinessModel::KENNEY_PREMIUM_QUARTERS)
            / InsuranceBusinessModel::KENNEY_CAPACITY_RATIO;

        // Surplus 20% short of the capital the annual book requires — a real capital shock, and under the
        // old quarterly base it did not register as a deficit at all.
        $impaired = $this->underwriter($annualTargetSurplus * 0.80);
        $adequate = $this->underwriter($annualTargetSurplus);

        $impairedMargin = $model->computeActualFinancials($impaired, $quarterlyPremium, 0.90, 1.0e9, 0.10, new \App\DTO\MacroStateDTO(), $this->scriptedMath())->clampedMargin;
        $adequateMargin = $model->computeActualFinancials($adequate, $quarterlyPremium, 0.90, 1.0e9, 0.10, new \App\DTO\MacroStateDTO(), $this->scriptedMath())->clampedMargin;

        // Hard-market rate increases on renewal pull the combined ratio down for the impaired underwriter.
        $this->assertLessThan($adequateMargin, $impairedMargin);

        // And the capital shock puts it into the regime, which persists past the quarter that caused it.
        $regimeKey = \App\DTO\StreamContext::REGIME_STATE_PREFIX . InsuranceBusinessModel::REGIME_HARD_MARKET;
        $impairedResult = $model->computeActualFinancials($impaired, $quarterlyPremium, 0.90, 1.0e9, 0.10, new \App\DTO\MacroStateDTO(), $this->scriptedMath());
        $this->assertSame(1.0, $impairedResult->streamZ[$regimeKey] ?? 0.0);
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
     * An underwriter carrying a real float, at the given multiple of the market it serves, whose prior book
     * is small enough that the hard-market revenue floor does not hold its written premium up.
     */
    private function underwriterAtShare(float $capitalShareOfMarket): Stock
    {
        $stock = $this->shareStock($capitalShareOfMarket);
        $equity = (float) $stock->getTotalEquity();
        $stock->setOperatingMargin('0.045');
        $stock->setCustomerDeposits((string) ($equity * 3.0));
        $stock->setCorporateTreasury((string) ($equity * 3.0));
        $stock->setTotalRevenue((string) ($equity * 0.50));

        return $stock;
    }

    /** An underwriter whose capital is the given multiple of the market it serves. */
    private function shareStock(float $capitalShareOfMarket): Stock
    {
        $samRatio = 2.0;
        $equity = $capitalShareOfMarket * \App\Service\Math\FinancialConstants::BASELINE_SECTOR_TAM * $samRatio;

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

        return $stock;
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

        return $stock;
    }
}


