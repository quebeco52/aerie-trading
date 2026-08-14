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
}
