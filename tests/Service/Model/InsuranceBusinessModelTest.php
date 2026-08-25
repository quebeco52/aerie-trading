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
            ->willReturnOnConsecutiveCalls(0.0, -2.5); // Revenue Z = 0.0, Claim Z = -2.5 (Severe catastrophe)

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
}
