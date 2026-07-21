<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\InsuranceBusinessModel;
use PHPUnit\Framework\TestCase;

class InsuranceBusinessModelTest extends TestCase
{
    public function testCalculateMinOperatingCashAndTargetCash(): void
    {
        $model = new InsuranceBusinessModel();

        $operatingBase = 100_000_000_000.0; // $100 Billion operating base
        $customerDeposits = 4_000_000_000_000.0; // $4 Trillion float (customer deposits)
        $wholesaleDebt = 200_000_000_000.0;

        $minCash = $model->calculateMinOperatingCash($operatingBase, $customerDeposits, $wholesaleDebt);
        $expectedMin = ($operatingBase * InsuranceBusinessModel::MIN_OPERATING_BUFFER)
            + ($customerDeposits * InsuranceBusinessModel::MIN_FLOAT_BUFFER);

        // $100B * 0.03 + $4T * 0.15 = $3B + $600B = $603B
        $this->assertEquals($expectedMin, $minCash);
        $this->assertEquals(603_000_000_000.0, $minCash);

        $targetCash = $model->calculateTargetOperatingCash($operatingBase, $customerDeposits, $wholesaleDebt);
        $expectedTarget = max(
            $operatingBase * InsuranceBusinessModel::TARGET_OPERATING_BUFFER,
            $customerDeposits * InsuranceBusinessModel::TARGET_FLOAT_BUFFER
        );

        // max($100B * 0.05, $4T * 1.00) = $4 Trillion
        $this->assertEquals($expectedTarget, $targetCash);
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
        $this->assertEquals(($ebit + $depreciation) / $interestExpense, $icrWithDebt);
        $this->assertEquals(2.2, $icrWithDebt);

        // 3. Negative EBIT with high emergency interest expense -> returns low/negative ICR
        $icrDistressed = $model->getInterestCoverage(-200_000_000.0, 800_000_000.0, 0.0);
        $this->assertEquals(-0.25, $icrDistressed);
    }

    public function testCatastropheZScoreImpactsClaims(): void
    {
        $model = new InsuranceBusinessModel();
        $stock = new Stock();
        $stock->setBeta('0.6');

        $mathUtilityMock = $this->createMock(MathUtility::class);
        $mathUtilityMock->expects($this->once())
            ->method('generateStandardNormal')
            ->willReturn(-2.5); // Severe catastrophe z-score below threshold

        $macroState = [
            'inflation_ema' => 0.02,
            'policy_rate' => 0.05,
            'gdp_growth' => 0.02,
            'credit_spread' => 0.015,
        ];

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
}
