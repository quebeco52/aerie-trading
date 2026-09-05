<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\InitialMarket;
use App\Data\StockInfo;
use App\Data\StockModelTuning;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\InvestmentBankBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class InvestmentBankBusinessModelTest extends TestCase
{
    private InvestmentBankBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new InvestmentBankBusinessModel();
    }

    public function testCorvIsRegisteredInInitialMarketAndStockInfo(): void
    {
        $corvConfig = null;
        foreach (InitialMarket::STOCKS as $stock) {
            if ($stock['ticker'] === 'CORV') {
                $corvConfig = $stock;
                break;
            }
        }

        $this->assertNotNull($corvConfig, 'CORV must be registered in InitialMarket::STOCKS');
        $this->assertSame('Corvid Capital', $corvConfig['name']);
        $this->assertSame('Financials', $corvConfig['sector']);
        $this->assertSame('Investment Banking', $corvConfig['industry']);
        $this->assertArrayHasKey('CORV', StockInfo::DESCRIPTIONS);
        $this->assertArrayHasKey('CORV', StockModelTuning::OVERRIDES);
    }

    public function testCorvUsesCustomTuningOverridesInIdiosyncraticShock(): void
    {
        $stock = new Stock();
        $stock->setTicker('CORV');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('generateUniform')->willReturn(0.50); // No regulatory fine

        // Neutral macro baseline with VIX = 0.28 (10% above VIX_ARBITRAGE_FLOOR 0.18, within BASEL_VAR_VOL_TARGET 0.30)
        $macroState = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04, // Neutral curve slope (0.0)
            'market_volatility_ema'   => 0.28,
            'macro_credit_spread_ema' => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD, // Neutral spread (0.02)
            'equity_risk_premium'     => MacroEngine::BASE_EQUITY_RISK_PREMIUM, // Neutral ERP (0.05)
        ]);

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.14,
            $macroState,
            $mathMock
        );

        // CORV overrides: advisory_weight = 0.25, trading_weight = 0.75, vix_arbitrage_scalar = 2.00
        // advisoryRevenue = 1000.0 * 0.25 * 1.0 = 250.0
        // volatilityArbitrage = (0.28 - 0.18) * 2.00 * 1.0 (varDeleverageFactor = 1.0) = 0.20
        // tradingRevenue  = 1000.0 * 0.75 * (1.0 + 0.20) = 900.0
        // actualRevenue   = 250.0 + 900.0 = 1150.0
        $this->assertEqualsWithDelta(1150.0, $result->actualRevenue, 0.001);
    }

    public function testExtremeVixSpikeIsCappedByBaselFrtbVarLimits(): void
    {
        $stock = new Stock();
        $stock->setTicker('GS');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('generateUniform')->willReturn(0.50);

        // Extreme 2008-level VIX panic (0.80) -> vixGap = 0.62
        // Basel FRTB volatility targeting deleveraging: varDeleverageFactor = 0.30 / 0.80 = 0.375
        // volatilityArbitrage = (0.62 * 1.20) * 0.375 = 0.279
        $macroState = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04,
            'market_volatility_ema'   => 0.80,
            'macro_credit_spread_ema' => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD,
            'equity_risk_premium'     => MacroEngine::BASE_EQUITY_RISK_PREMIUM,
        ]);

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.14,
            $macroState,
            $mathMock
        );

        // Default weights: advisory = 0.40, trading = 0.60
        // advisoryRevenue = 1000.0 * 0.40 * 1.0 = 400.0
        // tradingRevenue  = 1000.0 * 0.60 * (1.0 + 0.279) = 767.4
        // total           = 400.0 + 767.4 = 1167.4
        // Without VaR deleveraging, tradingRevenue would be 1000 * 0.60 * (1 + 0.744) = 1046.4, total = 1446.4
        $this->assertEqualsWithDelta(1167.4, $result->actualRevenue, 0.01);
    }

    public function testOptionsDeskVegaAndIsolatedGammaPhysics(): void
    {
        $stock = new Stock();
        $stock->setTicker('PERE'); // PERE: advisory = 0.0, trading = 0.40, options_premium_income = 0.60, vix_scalar = 1.80

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('generateUniform')->willReturn(0.50);

        $macroState = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04,
            'market_volatility_ema'   => 0.28, // vixGap = 0.10
            'macro_credit_spread_ema' => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD,
            'equity_risk_premium'     => MacroEngine::BASE_EQUITY_RISK_PREMIUM,
        ]);

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.14,
            $macroState,
            $mathMock
        );

        // optionsVegaBonus = 0.10 * 1.50 = 0.15 -> optionsRevenue = 1000 * 0.60 * 1.15 = 690.0
        // tradingRevenue  = 1000 * 0.40 * (1 + 0.10 * 1.80) = 472.0
        // actualRevenue   = 690.0 + 472.0 = 1162.0
        $this->assertEqualsWithDelta(1162.0, $result->actualRevenue, 0.01);

        // gammaHedgingCost = (0.10 * 0.60) * 0.60 (optionsWeight) = 0.036
        // rawMargin = 0.50 + 0.036 = 0.536 (variable cost ratio)
        // Check clamped margin in result
        $this->assertEqualsWithDelta(0.536, $result->clampedMargin, 0.001);
        $this->assertEqualsWithDelta(1162.0 * 0.536, $result->actualVariableCosts, 0.01);
    }

    public function testCostOfCapitalAndMacroElasticityStimulatesAdvisoryRevenue(): void
    {
        $stock = new Stock();
        $stock->setTicker('KING'); // KING: advisory = 0.75, trading = 0.25

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('generateUniform')->willReturn(0.50);

        // Boom conditions: +2% output gap, ERP down 100bps, Credit spreads tight by 50bps, curve steep by 200bps
        $macroState = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.02,  // mnaOutputGap = 0.02 * 3.00 = 0.06
            'equity_risk_premium'     => 0.04,  // erpGap = (0.05 - 0.04) * 5.00 = 0.05
            'macro_credit_spread_ema' => 0.015, // creditSpreadGap = (0.020 - 0.015) * 10.0 = 0.05
            'policy_rate_ema'         => 0.03,
            'yield_5y_ema'            => 0.05,  // curveSlope = (0.05 - 0.03) * 2.50 = 0.05
            'market_volatility_ema'   => 0.18,  // neutral VIX floor
        ]);

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.14,
            $macroState,
            $mathMock
        );

        // advisoryMacroFactor = mnaStimulus (0.06 + 0.025) + dcmStimulus (0.05 + 0.05) = +0.185 (+18.5% stimulus)
        // advisoryRevenue = 1000.0 * 0.75 * (1.0 + 0.185) = 888.75
        // tradingRevenue  = 1000.0 * 0.25 * 1.0 = 250.0
        // actualRevenue   = 888.75 + 250.0 = 1138.75
        $this->assertEqualsWithDelta(1138.75, $result->actualRevenue, 0.01);
    }

    public function testWholesaleLeverageLimitMatchesOperatingCapacity(): void
    {
        $this->assertSame(8.0, $this->model->getWholesaleLeverageLimit());
    }

    public function testPereOptionWritingPurePlayWithZeroAdvisoryMaintainsZeroAdvisoryAcrossQuarters(): void
    {
        $stock = new Stock();
        $stock->setTicker('PERE'); // PERE: advisory = 0.0, trading = 0.40, options_premium_income = 0.60
        // Simulate previous quarter's momentum
        $stock->setEarningsMomentumZ([
            'weight:advisory'               => 0.00,
            'weight:trading'                => 0.40,
            'weight:options_premium_income' => 0.60,
            'share:advisory'                => 0.00,
            'share:trading'                 => 0.40,
            'share:options_premium_income'  => 0.60,
            'advisory'                      => 0.0,
            'trading'                       => 0.0,
            'options_premium_income'        => 0.0,
            'event'                         => 0.0,
        ]);

        $mathMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal', 'generateUniform'])
            ->getMock();
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('generateUniform')->willReturn(0.50);

        $macroState = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.02, // Positive macro boom that would otherwise stimulate advisory
            'equity_risk_premium'     => 0.04,
            'macro_credit_spread_ema' => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD,
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04,
            'market_volatility_ema'   => 0.28,
        ]);

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.14,
            $macroState,
            $mathMock
        );

        // optionsVegaBonus = 0.10 * 1.50 = 0.15 -> optionsRevenue = 1000 * 0.60 * 1.15 = 690.0
        // tradingRevenue  = 1000 * 0.40 * (1 + 0.10 * 1.80) = 472.0
        $this->assertEqualsWithDelta(1162.0, $result->actualRevenue, 0.01);
        $this->assertSame(0.0, $result->streamZ['weight:advisory'] ?? null);
        $this->assertArrayNotHasKey('advisory', $result->streamRevenue);
    }

    public function testDealActivityIndexStimulatesAdvisoryRevenue(): void
    {
        $stock = new Stock();
        $stock->setTicker('ADVISORY_IB');

        $mathMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal', 'generateUniform'])
            ->getMock();
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('generateUniform')->willReturn(0.50);

        $macroNormal = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'equity_risk_premium'     => MacroEngine::BASE_EQUITY_RISK_PREMIUM,
            'macro_credit_spread_ema' => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD,
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04,
            'deal_activity_index_ema' => 100.0,
        ]);

        $resultNormal = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.0,
            $macroNormal,
            $mathMock
        );

        $macroBoom = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'equity_risk_premium'     => MacroEngine::BASE_EQUITY_RISK_PREMIUM,
            'macro_credit_spread_ema' => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD,
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04,
            'deal_activity_index_ema' => 150.0, // High deal activity
        ]);

        $resultBoom = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.0,
            $macroBoom,
            $mathMock
        );

        $this->assertGreaterThan($resultNormal->streamRevenue['advisory'], $resultBoom->streamRevenue['advisory'], 'Elevated deal activity index must expand advisory deal flow revenue.');
    }
}
