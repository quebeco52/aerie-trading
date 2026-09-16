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
        $stock->setTotalEquity('10000000000.0');

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


