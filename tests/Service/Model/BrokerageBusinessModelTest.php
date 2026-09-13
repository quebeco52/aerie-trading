<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use PHPUnit\Framework\TestCase;
use App\Service\Model\Sector\BrokerageBusinessModel;
use App\Service\Math\MathUtility;
use App\Entity\Stock;
use App\Data\InitialMarket;
use App\Data\Sectors;
use App\DTO\MacroStateDTO;

class BrokerageBusinessModelTest extends TestCase
{
    private BrokerageBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new BrokerageBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testRookIsRegisteredInInitialMarketAndBrokeragesIndustry(): void
    {
        $rookConfig = null;
        foreach (InitialMarket::STOCKS as $stock) {
            if ($stock['ticker'] === 'ROOK') {
                $rookConfig = $stock;
                break;
            }
        }

        $this->assertNotNull($rookConfig, 'ROOK must be registered in InitialMarket::STOCKS');
        $this->assertSame('Rook Proprietary Trading', $rookConfig['name']);
        $this->assertSame('Financials', $rookConfig['sector']);
        $this->assertSame('Brokerages', $rookConfig['industry']);

        $industryConfig = Sectors::INDUSTRY_METRICS['Brokerages'] ?? null;
        $this->assertNotNull($industryConfig);
        $this->assertSame('brokerage', $industryConfig['business_model']);
        $this->assertTrue(Sectors::isFinancial($industryConfig['business_model']));
    }

    public function testTradingVolumeBonusScalesWithVix(): void
    {
        $stock = new Stock();
        $stock->setTicker('ROOK');
        $stock->setOperatingMargin('0.30');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        // Macro state with elevated VIX (30% vs 20% baseline)
        $macroState = MacroStateDTO::fromArray([
            'output_gap_ema' => 0.0,
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
            'corporate_tax_rate' => 0.21,
            'market_volatility_ema' => 0.30,
        ]);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 1000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 100.0,
            baselineVol: 0.20,
            macroState: $macroState,
            mathUtility: $mathMock
        );

        // ROOK custom tuning overrides: trading_weight = 0.85, advisory_weight = 0.15
        // VIX bonus = (0.30 - 0.20) * 0.50 = 0.05
        // Trading revenue = 1000 * 0.85 * (1.0 + 0.05) = 892.5
        // Advisory revenue = 1000 * 0.15 * 1.0 = 150.0
        // Total expected revenue = 892.5 + 150.0 = 1042.5
        $this->assertEqualsWithDelta(1042.5, $result->actualRevenue, 0.01);
        $this->assertEqualsWithDelta(892.5, $result->streamRevenue['trading'], 0.01);
        $this->assertEqualsWithDelta(150.0, $result->streamRevenue['advisory'], 0.01);
    }

    public function testClearinghouseCashBackingRequirements(): void
    {
        $operatingBase = 1_000_000.0;
        $currentLiability = 500_000.0;
        $wholesaleDebt = 10_000_000.0;

        // Requires 15% cash backing on wholesale debt
        $targetCash = $this->model->calculateTargetOperatingCash($operatingBase, $currentLiability, $wholesaleDebt);
        $this->assertSame(1_500_000.0, $targetCash);

        // Requires 10% min cash backing floor
        $minCash = $this->model->calculateMinOperatingCash($operatingBase, $currentLiability, $wholesaleDebt);
        $this->assertSame(1_000_000.0, $minCash);
    }

    public function testInterestIncomeIncludesMarginLoanAndClientSweep(): void
    {
        $stock = new Stock();
        $stock->setWholesaleDebt('10000000');
        $stock->setCorporateTreasury('2000000');
        $stock->setTotalEquity('10000000');
        $stock->setTotalRevenue('5000000');

        $macroState = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.05,
            'corporate_tax_rate' => 0.21,
        ]);

        $interestIncome = $this->model->calculateInterestIncome($stock, $macroState, $this->mathUtility);
        $this->assertGreaterThan(0.0, $interestIncome);

        // Margin loan yield = policyRate (0.05) + MARGIN_LOAN_SPREAD (0.025) = 0.075
        // Margin loan interest on $10M debt = $750,000
        // Total interest income must exceed margin interest alone due to sweep/cash yields
        $this->assertGreaterThan(750_000.0, $interestIncome);
    }

    public function testTargetMetricsCalculatedOnEarningAssetsAndRoe(): void
    {
        $stock = new Stock();
        $stock->setTicker('ROOK');
        $stock->setIndustry('Brokerages');
        $stock->setTotalEquity('50000000');
        $stock->setWholesaleDebt('100000000');
        $stock->setCorporateTreasury('15000000');
        $stock->setBaselineRoe('0.20');
        $stock->setOperatingMargin('0.30');
        $stock->setCreditSpread('0.015');
        $stock->setFloatingDebtRatio('0.50');

        $macroState = MacroStateDTO::fromArray([
            'policy_rate' => 0.04,
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
            'corporate_tax_rate' => 0.21,
            'equity_risk_premium' => 0.05,
            'market_saturation_limit' => 500_000_000.0,
        ]);

        $target = $this->model->getTargetMetrics($stock, $macroState, $this->mathUtility);

        $this->assertIsArray($target);
        $this->assertArrayHasKey('invested_capital', $target);
        $this->assertArrayHasKey('baseline_roic', $target);
        $this->assertGreaterThan(0.0, $target['invested_capital']);
        $this->assertGreaterThan(0.0, $target['baseline_roic']);
    }

    public function testMacroPhysicsProducesExpectedDemandShift(): void
    {
        $stock = new Stock();
        $stock->setTicker('BRK');
        $stock->setBeta('1.40');

        $macroState = MacroStateDTO::fromArray([
            'output_gap_ema' => 0.02,
        ]);

        $macroPhysics = $this->model->getMacroPhysics($stock, $macroState);

        // Demand shift = 0.02 * operating cyclicality * 0.50; equity beta is not an operating input.
        $this->assertEqualsWithDelta(0.02 * BrokerageBusinessModel::OPERATING_CYCLICALITY * 0.50, $macroPhysics['macro_demand_shift'], 0.0001);
        $this->assertSame(1.0, $macroPhysics['pricing_power_multiplier']);
    }

    public function testWholesaleLeverageLimitMatchesOperatingCapacity(): void
    {
        $this->assertSame(8.0, $this->model->getWholesaleLeverageLimit());
    }

    public function testDealActivityExpandsAdvisoryRevenue(): void
    {
        $stock = new Stock();
        $stock->setTicker('ROOK');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baselineMacro = new MacroStateDTO(
            dealActivityIndexEma: 100.0,
            marketVolatilityEma: 0.20
        );

        $boomMacro = new MacroStateDTO(
            dealActivityIndexEma: 140.0, // +40% surge in M&A/capital markets deal flow
            marketVolatilityEma: 0.20
        );

        $baseResult = $this->model->computeActualFinancials($stock, 1000.0, 0.35, 100.0, 0.0, $baselineMacro, $mathMock);
        $boomResult = $this->model->computeActualFinancials($stock, 1000.0, 0.35, 100.0, 0.0, $boomMacro, $mathMock);

        $this->assertGreaterThan(
            $baseResult->streamRevenue['advisory'],
            $boomResult->streamRevenue['advisory'],
            'Elevated capital markets deal activity must boost brokerage advisory and underwriting revenue.'
        );
    }
}
