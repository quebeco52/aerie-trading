<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use PHPUnit\Framework\TestCase;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
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

    public function testCalculateInterestIncomeOnlyComputesOnOwnCash(): void
    {
        $stockMock = $this->createStub(\App\Entity\Stock::class);
        $stockMock->method('getCorporateTreasury')->willReturn('110000000000.0'); // 110B ($10B own cash + $100B margin)
        $stockMock->method('getCustomerDeposits')->willReturn('100000000000.0'); // 100B margin pool

        $mathMock = $this->createStub(MathUtility::class);

        // At 5% policy rate:
        // Own cash yield = $10B * 0.05 = $500M
        $macroState = new \App\DTO\MacroStateDTO(
            policyRateEma: 0.05,
            yield2yEma: 0.06
        );
        $income = $this->model->calculateInterestIncome($stockMock, $macroState, $mathMock);

        $this->assertSame(500000000.0, $income);
    }

    public function testCalculateEffectiveCustodySpreadScalesWithRate(): void
    {
        // 5% rate -> 15 bps base + (5% * 15% retention = 75 bps) = 90 bps (0.0090)
        $macroState = new \App\DTO\MacroStateDTO(
            policyRateEma: 0.05,
            yield2yEma: 0.06
        );
        $spread = $this->model->calculateEffectiveCustodySpread($macroState);
        $this->assertEqualsWithDelta(0.0090, $spread, 0.0001);
    }

    public function testGetTargetMetricsUsesEffectiveEquityAsInvestedCapital(): void
    {
        $stockMock = $this->createStub(\App\Entity\Stock::class);
        $stockMock->method('getTotalEquity')->willReturn('35000000000.0');
        $stockMock->method('getCustomerDeposits')->willReturn('1300000000000.0');

        $macroState = new \App\DTO\MacroStateDTO(
            policyRate: 0.02,
            equityRiskPremium: 0.05,
            corporateTaxRate: 0.20,
            policyRateEma: 0.02,
            yield5yEma: 0.03
        );
        $mathMock = $this->createStub(MathUtility::class);

        $metrics = $this->model->getTargetMetrics($stockMock, $macroState, $mathMock);

        // Invested capital must be just $35B, not $1.335T
        $this->assertSame(35000000000.0, $metrics['invested_capital']);
    }

    public function testProcessPassiveLiabilityGrowthCapacityClamping(): void
    {
        $stockMock = $this->createStub(\App\Entity\Stock::class);
        $stockMock->method('getTotalEquity')->willReturn('35000000000.0'); // 35B

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);

        $macroState = new \App\DTO\MacroStateDTO(
            inflationEma: 0.02,
            outputGapEma: 0.0,
            marketVolatilityEma: 0.20 // Neutral VIX
        );

        // Exceeding capacity limits (50x equity = 1.75T). Let's say deposits are 2T.
        $stateOver = ['customerDeposits' => 2000000000000.0, 'treasury' => 2000000000000.0, 'events' => []];
        $this->model->processPassiveLiabilityGrowth($stockMock, $macroState, $stateOver, $mathMock);
        
        // Growth should be negative because of capacity clamping
        $this->assertLessThan(2000000000000.0, $stateOver['customerDeposits']);
        
        // Under capacity limit
        $stateUnder = ['customerDeposits' => 500000000000.0, 'treasury' => 500000000000.0, 'events' => []];
        $this->model->processPassiveLiabilityGrowth($stockMock, $macroState, $stateUnder, $mathMock);
        
        // Growth should be positive
        $this->assertGreaterThan(500000000000.0, $stateUnder['customerDeposits']);
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

    public function testCorporateDefaultRateSurgeIncreasesClearingHouseDefaultStress(): void
    {
        $stock = new Stock();
        $stock->setTicker('ACC');
        $stock->setBeta('0.8');

        $calmMacro = new MacroStateDTO(
            corporateDefaultRateEma: 0.020, // Baseline 2.0%
            marketVolatilityEma: 0.18
        );

        $distressMacro = new MacroStateDTO(
            corporateDefaultRateEma: 0.080, // Default wave (8.0%)
            marketVolatilityEma: 0.18
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $calmResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.0, $calmMacro, $mathMock);
        $distressResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.0, $distressMacro, $mathMock);

        $this->assertGreaterThan(
            $calmResult->clampedMargin,
            $distressResult->clampedMargin,
            'Systemic corporate default waves must increase member insolvency risk and default waterfall provisioning.'
        );
    }
}

