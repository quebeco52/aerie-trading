<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use PHPUnit\Framework\TestCase;
use App\Service\Model\Sector\ClearingHouseBusinessModel;
use App\Service\Model\BusinessModelInterface;
use App\Service\Math\MathUtility;

class ClearingHouseBusinessModelTest extends TestCase
{
    private ClearingHouseBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new ClearingHouseBusinessModel();
    }

    public function testImplementsBusinessModelInterface(): void
    {
        $this->assertInstanceOf(BusinessModelInterface::class, $this->model);
    }

    public function testCalculateEarningsValue(): void
    {
        $mathMock = $this->createStub(MathUtility::class);

        $val1 = $this->model->calculateEarningsValue(100.0, 150.0, 5.0, 0.08, $mathMock);
        $this->assertSame(150.0, $val1);

        $val2 = $this->model->calculateEarningsValue(200.0, 150.0, 5.0, 0.08, $mathMock);
        $this->assertSame(200.0, $val2);
    }

    public function testCalculateInterestIncomeScalesWithPolicyRateAndZirpTrap(): void
    {
        $stockMock = $this->createStub(\App\Entity\Stock::class);
        $stockMock->method('getCorporateTreasury')->willReturn('100000000000.0');
        $stockMock->method('getCustomerDeposits')->willReturn('100000000000.0'); // 100% margin pool

        $mathMock = $this->createStub(MathUtility::class);

        // Low interest rate ZIRP regime (< 1%)
        $lowRateState = new \App\DTO\MacroStateDTO(
            policyRateEma: 0.005,
            yield2yEma: 0.005
        );
        $incomeLow = $this->model->calculateInterestIncome($stockMock, $lowRateState, $mathMock);

        // High interest rate regime
        $highRateState = new \App\DTO\MacroStateDTO(
            policyRateEma: 0.05,
            yield2yEma: 0.06
        );
        $incomeHigh = $this->model->calculateInterestIncome($stockMock, $highRateState, $mathMock);

        $this->assertGreaterThan($incomeLow, $incomeHigh, 'Clearinghouse margin pool yield must scale upward during high-rate regimes.');
    }

    public function testCalculateCashYieldUsesPolicyRate(): void
    {
        $macroState = new \App\DTO\MacroStateDTO(
            policyRateEma: 0.0525,
            yield2yEma: 0.0400
        );
        $yield = $this->model->calculateCashYield($macroState);

        $this->assertSame(0.0525, $yield);
    }

    public function testAccIsRegisteredInInitialMarketWithLeanEquityAndExtremeFloatLeverage(): void
    {
        $accConfig = null;
        foreach (\App\Data\InitialMarket::STOCKS as $stock) {
            if ($stock['ticker'] === 'ACC') {
                $accConfig = $stock;
                break;
            }
        }

        $this->assertNotNull($accConfig, 'ACC must be registered in InitialMarket::STOCKS');
        $this->assertSame('Aerie Central Clearing', $accConfig['name']);
        $this->assertSame('Financials', $accConfig['sector']);
        $this->assertSame('Financial Clearinghouses', $accConfig['industry']);
        $this->assertSame('titan', $accConfig['systemic_importance']);

        $industryConfig = \App\Data\Sectors::INDUSTRY_METRICS['Financial Clearinghouses'] ?? null;
        $this->assertNotNull($industryConfig);
        $this->assertSame('clearing_house', $industryConfig['business_model']);
        $this->assertTrue(\App\Data\Sectors::isFinancial($industryConfig['business_model']));

        // Lean equity calibration (CME / ICE model)
        $this->assertSame(35_000_000_000.00, (float) $accConfig['total_equity'], 'ACC total equity must be $35B (lean equity)');
        $this->assertSame(1_300_000_000_000.00, (float) $accConfig['customer_deposits'], 'ACC customer deposits must be $1.3T (collateral float)');
        $this->assertSame(1_315_000_000_000.00, (float) $accConfig['corporate_treasury'], 'ACC corporate treasury must cover 100% margin float + $15B operating buffer');
        $this->assertSame(15_000_000_000.00, (float) $accConfig['wholesale_debt'], 'ACC wholesale debt must be $15B');
        $this->assertSame(20_000_000_000.00, (float) $accConfig['retained_earnings'], 'ACC retained earnings must be $20B');
        $this->assertSame(0.22, (float) $accConfig['baseline_roe'], 'ACC baseline ROE must be 22%');

        // Extreme collateral leverage (> 30x customer deposits to equity)
        $collateralLeverage = $accConfig['customer_deposits'] / $accConfig['total_equity'];
        $this->assertGreaterThan(30.0, $collateralLeverage, 'Clearinghouses operate on extreme collateral float leverage relative to lean equity');
    }

    public function testClearingHouseCashBackingInvariants(): void
    {
        $operatingBase = 5_000_000_000.0;
        $marginLiabilities = 1_000_000_000_000.0;
        $debt = 10_000_000_000.0;

        $targetCash = $this->model->calculateTargetOperatingCash($operatingBase, $marginLiabilities, $debt);
        $minCash = $this->model->calculateMinOperatingCash($operatingBase, $marginLiabilities, $debt);

        // 100% margin backing + 5% operating buffer
        $this->assertSame(1_000_000_000_000.0 + ($operatingBase * 0.05), $targetCash);
        $this->assertSame(1_000_000_000_000.0 + ($operatingBase * 0.02), $minCash);
        $this->assertGreaterThan($minCash, $targetCash);
    }
}

