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
            'policy_rate'             => 0.04, // synced with EMA: isolate this test's variable, avoid a phantom FICC rate-shock
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // Neutral curve slope: no DCM stimulus either way
            'market_volatility_ema'   => 0.28,
            'macro_credit_spread_ema' => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD, // Neutral spread (0.02)
            'equity_risk_premium'     => MacroEngine::BASE_EQUITY_RISK_PREMIUM, // Neutral ERP (0.045)
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
            'policy_rate'             => 0.04, // synced with EMA: isolate this test's variable, avoid a phantom FICC rate-shock
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
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
        // Under extreme panic (VIX = 0.80), the IPO window freeze triggers max discount (0.30 * 0.35 = -0.105 on advisory)
        // advisoryRevenue = 1000.0 * 0.40 * (1.0 - 0.105) = 358.0
        // tradingRevenue  = 1000.0 * 0.60 * (1.0 + 0.279) = 767.4
        // total           = 358.0 + 767.4 = 1125.4
        // Without VaR deleveraging, tradingRevenue would be 1000 * 0.60 * (1 + 0.744) = 1046.4, total = 1404.4
        $this->assertEqualsWithDelta(1125.4, $result->actualRevenue, 0.01);
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
            'policy_rate'             => 0.04, // synced with EMA: isolate this test's variable, avoid a phantom FICC rate-shock
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
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

        // Boom conditions: +2% output gap, ERP down 50bps, Credit spreads tight by 50bps, curve steep by 200bps
        $macroState = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.02,  // output gap boom
            'equity_risk_premium'     => 0.04,  // erpGap = (0.045 - 0.04) = 0.005
            'macro_credit_spread_ema' => 0.015, // creditSpreadGap = (0.020 - 0.015) * 10.0 = 0.05
            'policy_rate'             => 0.03, // synced with EMA: isolate this test's variable, avoid a phantom FICC rate-shock
            'policy_rate_ema'         => 0.03,
            'yield_5y_ema'            => 0.03 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE + 0.02, // curveSlopeGap = +0.02 over neutral -> 0.02 * 2.50 = 0.05
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

        // M&A stimulus (65% share) = (0.02 * 3.00 + 0.005 * 5.00) * 0.65 = 0.085 * 0.65 = 0.05525
        // ECM stimulus (35% share) = (0.02 * 2.50 + 0.005 * 4.00) * 0.35 = 0.070 * 0.35 = 0.0245
        // DCM stimulus = (0.005 * 10.0) + (0.020 * 2.50) = 0.05 + 0.05 = 0.10
        // advisoryMacroFactor = 0.05525 + 0.0245 + 0.10 = 0.17975 (+17.975% stimulus)
        // advisoryRevenue = 1000.0 * 0.75 * (1.0 + 0.17975) = 884.8125
        // tradingRevenue  = 1000.0 * 0.25 * 1.0 = 250.0
        // actualRevenue   = 884.8125 + 250.0 = 1134.8125
        $this->assertEqualsWithDelta(1134.8125, $result->actualRevenue, 0.01);
    }

    public function testWholesaleLeverageLimitMatchesOperatingCapacity(): void
    {
        $this->assertSame(8.0, $this->model->getWholesaleLeverageLimit());
    }

    public function testPrimeFinancingRatesPassThroughFirmFundingCreditSpread(): void
    {
        $stock = new Stock();
        $stock->setTicker('KING');
        $stock->setCreditSpread('0.0150'); // 150 bps structural spread
        $stock->setWholesaleDebt('100000000000.0'); // $100B debt
        $stock->setCorporateTreasury('10000000000.0'); // $10B treasury (equal to min cash)

        $mathMock = $this->createStub(MathUtility::class);
        $macroState = \App\DTO\MacroStateDTO::fromArray([
            'policy_rate'                    => 0.04, // synced with EMA: isolate this test's variable, avoid a phantom FICC rate-shock
            'policy_rate_ema'                => 0.04,
            'yield_5y_ema'                   => 0.04,
            'interbank_liquidity_spread_ema' => 0.0,
        ]);

        $income = $this->model->calculateInterestIncome($stock, $macroState, $mathMock);

        // Funding benchmark = max(0.0, 0.04 + 0.0150) = 0.0550
        // Prime financing = $70B * (0.0550 + 0.0150) = $70B * 0.0700 = $4.90B
        // Repo inventory = $30B * max(0, 0.0550 - 0.0025) = $30B * 0.0525 = $1.575B
        // Excess cash = 0 (minCash = 100B * 0.10 = 10B)
        // Total interest income = 4.90B + 1.575B = 6.475B ($6,475,000,000.0)
        $this->assertEqualsWithDelta(6_475_000_000.0, $income, 1_000.0);
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
            'policy_rate'             => 0.04, // synced with EMA: isolate this test's variable, avoid a phantom FICC rate-shock
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
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
            'policy_rate'             => 0.04, // synced with EMA: isolate this test's variable, avoid a phantom FICC rate-shock
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
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
            'policy_rate'             => 0.04, // synced with EMA: isolate this test's variable, avoid a phantom FICC rate-shock
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
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

    public function testEcmUnderwritingStimulusAndIpoWindowFreeze(): void
    {
        $stock = new Stock();
        $stock->setTicker('ECM_IB');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('generateUniform')->willReturn(0.50);

        // Boom in deal activity with normal volatility (VIX = 0.20)
        $macroBoomCalm = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'equity_risk_premium'     => MacroEngine::BASE_EQUITY_RISK_PREMIUM,
            'macro_credit_spread_ema' => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD,
            'policy_rate'             => 0.04, // synced with EMA: isolate this test's variable, avoid a phantom FICC rate-shock
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
            'deal_activity_index_ema' => 150.0,
            'market_volatility_ema'   => 0.20,
        ]);

        $resultCalm = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.0,
            $macroBoomCalm,
            $mathMock
        );

        // Market panic: Same high deal pipeline, but VIX = 0.50 (well above IPO_WINDOW_FREEZE_VIX 0.35)
        $macroBoomPanic = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'equity_risk_premium'     => MacroEngine::BASE_EQUITY_RISK_PREMIUM,
            'macro_credit_spread_ema' => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD,
            'policy_rate'             => 0.04, // synced with EMA: isolate this test's variable, avoid a phantom FICC rate-shock
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
            'deal_activity_index_ema' => 150.0,
            'market_volatility_ema'   => 0.50,
        ]);

        $resultPanic = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.0,
            $macroBoomPanic,
            $mathMock
        );

        // IPO window freeze must severely curtail advisory/ECM underwriting revenue despite nominal pipeline
        $this->assertLessThan(
            $resultCalm->streamRevenue['advisory'],
            $resultPanic->streamRevenue['advisory'],
            'Severe market panic (VIX > 0.35) must trigger the IPO window freeze and curtail ECM underwriting deal flow.'
        );
    }

    public function testLevFinHungBridgeDebtImpairmentDuringHighYieldBlowout(): void
    {
        $stock = new Stock();
        $stock->setTicker('LEVFIN_IB');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('generateUniform')->willReturn(0.50);

        // Baseline high-yield spread (0.048) -> No hung debt
        $macroNormal = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'               => 0.0,
            'equity_risk_premium'          => MacroEngine::BASE_EQUITY_RISK_PREMIUM,
            'macro_credit_spread_ema'      => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD,
            'high_yield_credit_spread_ema' => InvestmentBankBusinessModel::HY_BRIDGE_SPREAD_BASELINE,
            'policy_rate'                  => 0.04, // synced with EMA: isolate this test's variable, avoid a phantom FICC rate-shock
            'policy_rate_ema'              => 0.04,
            'yield_5y_ema'                 => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
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

        // High-yield spread blowout: 0.078 (+300 bps blowout above 0.048 baseline)
        $macroBlowout = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'               => 0.0,
            'equity_risk_premium'          => MacroEngine::BASE_EQUITY_RISK_PREMIUM,
            'macro_credit_spread_ema'      => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD,
            'high_yield_credit_spread_ema' => 0.078,
            'policy_rate'                  => 0.04, // synced with EMA: isolate this test's variable, avoid a phantom FICC rate-shock
            'policy_rate_ema'              => 0.04,
            'yield_5y_ema'                 => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
        ]);

        $resultBlowout = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.0,
            $macroBlowout,
            $mathMock
        );

        // hySpreadStress = 0.078 - 0.048 = 0.030
        // hungDebtCost = 0.030 * 1.50 * 0.40 (advisoryWeight) = 0.018 (+180 bps variable cost drag)
        $this->assertGreaterThan(
            $resultNormal->clampedMargin,
            $resultBlowout->clampedMargin,
            'High yield credit spread surge must force hung bridge debt markdowns that increase variable costs.'
        );
        $this->assertEqualsWithDelta(0.018, $resultBlowout->clampedMargin - $resultNormal->clampedMargin, 0.001);
    }

    public function testFiccDeskRatesVolatilityArbitrage(): void
    {
        $stock = new Stock();
        $stock->setTicker('FICC_IB');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('generateUniform')->willReturn(0.50);

        // No rate shock: policy_rate = 0.04, policy_rate_ema = 0.04
        $macroNoShock = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'policy_rate'             => 0.04,
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
            'macro_credit_spread_ema' => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD,
            'equity_risk_premium'     => MacroEngine::BASE_EQUITY_RISK_PREMIUM,
            'market_volatility_ema'   => InvestmentBankBusinessModel::VIX_ARBITRAGE_FLOOR,
        ]);

        $resultNoShock = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.0,
            $macroNoShock,
            $mathMock
        );

        // Un-trended monetary policy shock: policy_rate moves to 0.06 while policy_rate_ema is 0.04 (+200 bps shock)
        $macroRateShock = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'policy_rate'             => 0.06,
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
            'macro_credit_spread_ema' => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD,
            'equity_risk_premium'     => MacroEngine::BASE_EQUITY_RISK_PREMIUM,
            'market_volatility_ema'   => InvestmentBankBusinessModel::VIX_ARBITRAGE_FLOOR,
        ]);

        $resultRateShock = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.0,
            $macroRateShock,
            $mathMock
        );

        // rateShock = 0.02 * 3.50 = 0.07 (+7% trading revenue boost)
        // tradingRevenue = 1000 * 0.60 * 1.07 = 642.0 (vs 600.0)
        $this->assertGreaterThan(
            $resultNoShock->streamRevenue['trading'],
            $resultRateShock->streamRevenue['trading'],
            'Monetary policy rate dislocation must stimulate FICC desk hedging flows and boost trading revenue.'
        );
        $this->assertEqualsWithDelta(642.0, $resultRateShock->streamRevenue['trading'], 0.01);
    }

    public function testInstitutionalPrimeFinancingInterestIncomeReplacesRetailSweeps(): void
    {
        $stock = new Stock();
        $stock->setTicker('GS');
        $stock->setCreditSpread('0.0000'); // Baseline zero-spread test
        $stock->setWholesaleDebt('100000000000.0'); // $100B wholesale debt
        $stock->setCorporateTreasury('25000000000.0'); // $25B treasury
        $stock->setTotalEquity('50000000000.0');

        $mathMock = $this->createStub(MathUtility::class);

        $macroState = \App\DTO\MacroStateDTO::fromArray([
            'policy_rate'                    => 0.04, // synced with EMA: isolate this test's variable, avoid a phantom FICC rate-shock
            'policy_rate_ema'                => 0.04, // 4.0%
            'yield_5y_ema'                   => 0.04,
            'interbank_liquidity_spread_ema' => 0.0,
        ]);

        $interestIncome = $this->model->calculateInterestIncome($stock, $macroState, $mathMock);

        // 1. Prime financing: $100B * 70% * (0.04 + 0.0150) = $70B * 0.055 = $3.85B
        // 2. Repo inventory: $100B * 30% * max(0, 0.04 - 0.0025) = $30B * 0.0375 = $1.125B
        // 3. Min cash = max(operatingBase * 0.10, $100B * 0.10) = $10B
        //    Excess cash = $25B - $10B = $15B. Cash yield = max(0, 0.04 - 0.0025) = 0.0375
        //    Cash interest = $15B * 0.0375 = $0.5625B
        // Total interest income = $3.85B + $1.125B + $0.5625B = $5.5375B ($5,537,500,000.0)
        $this->assertEqualsWithDelta(5_537_500_000.0, $interestIncome, 1000.0);
    }

    public function testWallStreetCompensationRatioOperatingLeverage(): void
    {
        $stock = new Stock();
        $stock->setTicker('EVER');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('generateUniform')->willReturn(0.50);

        // Neutral baseline macro
        $macroNormal = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'policy_rate'             => 0.04, // synced with EMA: isolate this test's variable, avoid a phantom FICC rate-shock
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
            'market_volatility_ema'   => InvestmentBankBusinessModel::VIX_ARBITRAGE_FLOOR,
            'macro_credit_spread_ema' => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD,
            'equity_risk_premium'     => MacroEngine::BASE_EQUITY_RISK_PREMIUM,
        ]);

        // Windfall quarter: expected = 500.0, realized base margin = 0.45, fixed costs = 20.0
        // Because actual revenue is 1000.0, revenue surplus ratio = (1000 - 500) / 500 = 1.0
        // minCompRatio decreases, allowing lower comp expenses (higher operating leverage)
        $resultWindfall = $this->model->computeActualFinancials(
            $stock,
            1000.0, // expected revenue
            0.45,
            50.0,
            0.0,
            $macroNormal,
            $mathMock
        );

        $this->assertSame(0.45, $resultWindfall->clampedMargin);
    }

    public function testDcmStimulusIsNeutralAtBaselineTermStructure(): void
    {
        $stock = new Stock();
        $stock->setTicker('GS');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('generateUniform')->willReturn(0.50);

        // Term structure slope = 0.065 - 0.040 = 0.025 (identical to DCM_NEUTRAL_CURVE_SLOPE)
        $macroNeutralSlope = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'policy_rate'             => 0.04,
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
            'macro_credit_spread_ema' => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD,
            'equity_risk_premium'     => MacroEngine::BASE_EQUITY_RISK_PREMIUM,
            'market_volatility_ema'   => InvestmentBankBusinessModel::VIX_ARBITRAGE_FLOOR,
            'deal_activity_index_ema' => MacroEngine::DEAL_ACTIVITY_BASELINE,
        ]);

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.0,
            $macroNeutralSlope,
            $mathMock
        );

        // At neutral slope and neutral spreads, advisory revenue must exactly equal baseline expected (400.0)
        $this->assertEqualsWithDelta(400.0, $result->streamRevenue['advisory'], 0.001);
        $this->assertEqualsWithDelta(600.0, $result->streamRevenue['trading'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $result->actualRevenue, 0.001);
    }

    public function testIpoWindowFreezesWhenPipelineAlreadyDepressed(): void
    {
        $stock = new Stock();
        $stock->setTicker('ECM_IB');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('generateUniform')->willReturn(0.50);

        // Depressed deal pipeline (dealActivityIndex = 70, so dealActivityShift = -0.30 < 0)
        $macroDepressedCalm = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'policy_rate'             => 0.04,
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
            'macro_credit_spread_ema' => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD,
            'equity_risk_premium'     => MacroEngine::BASE_EQUITY_RISK_PREMIUM,
            'market_volatility_ema'   => 0.20,
            'deal_activity_index_ema' => 70.0,
        ]);

        $resultCalm = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.0,
            $macroDepressedCalm,
            $mathMock
        );

        // Severe panic during depression: VIX = 0.50 (above 0.35 threshold)
        $macroDepressedPanic = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'policy_rate'             => 0.04,
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
            'macro_credit_spread_ema' => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD,
            'equity_risk_premium'     => MacroEngine::BASE_EQUITY_RISK_PREMIUM,
            'market_volatility_ema'   => 0.50,
            'deal_activity_index_ema' => 70.0,
        ]);

        $resultPanic = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.0,
            $macroDepressedPanic,
            $mathMock
        );

        $this->assertLessThan(
            $resultCalm->streamRevenue['advisory'],
            $resultPanic->streamRevenue['advisory'],
            'IPO window freeze must fire and reduce ECM advisory revenue even when the pipeline is already depressed.'
        );
    }

    public function testPrimeFinancingDoesNotOutEarnFundingUnderCurveInversion(): void
    {
        $stock = new Stock();
        $stock->setTicker('KING');
        $stock->setCreditSpread('0.0050'); // 50bps credit spread
        $stock->setWholesaleDebt('10000000000.0'); // $10B debt
        $stock->setCorporateTreasury('1000000000.0');
        $stock->setFloatingDebtRatio('0.0'); // 100% fixed debt

        $mathMock = $this->createStub(MathUtility::class);

        // Hard curve inversion: policy rate = 0.06, 5Y yield = 0.02
        $macroInverted = \App\DTO\MacroStateDTO::fromArray([
            'policy_rate'     => 0.06,
            'policy_rate_ema' => 0.06,
            'yield_5y_ema'    => 0.02,
        ]);

        // Blended wholesale rate = (0 * 0.06) + (1 * 0.02) + 0.0050 = 0.0250 (2.5%)
        // Because policy rate is 6.0%, if max($policyRate, ...) were used, fundingBenchmark would be 6.0%,
        // generating 6.0% + 1.5% = 7.5% prime financing income on a book funded at 2.5% (phantom NII).
        // With max(0.0, blendedWholesaleRate), fundingBenchmark = 0.0250, primeRate = 0.0400.
        $income = $this->model->calculateInterestIncome($stock, $macroInverted, $mathMock);

        // Prime: $7B * (0.0250 + 0.0150) = $7B * 0.0400 = $280M
        // Repo: $3B * (0.0250 - 0.0025) = $3B * 0.0225 = $67.5M
        // Total interest income = $347.5M ($347,500,000.0)
        $this->assertEqualsWithDelta(347_500_000.0, $income, 100.0);
    }

    public function testRealizedWholesaleRateOverridesStaticApproximationUnderDistress(): void
    {
        // Regression test for the PERE/KING negative-carry bankruptcy bug: prime brokerage margin loans and
        // matched-book repo assets MUST reprice off the firm's own realized (dynamic Merton/BGG-widened)
        // wholesale funding cost, not a static approximation built off the seed credit_spread. Otherwise a
        // distressed firm keeps earning calm-market NII while paying the live blown-out rate on its
        // liabilities -- an unhedgeable, self-amplifying negative carry on a multi-hundred-billion book.
        $stock = new Stock();
        $stock->setTicker('PERE');
        $stock->setCreditSpread('0.0200'); // Calm-market static spread (200 bps)
        $stock->setWholesaleDebt('100000000000.0'); // $100B wholesale debt
        $stock->setCorporateTreasury('10000000000.0'); // $10B treasury (equal to min cash -> excess cash = 0)

        $mathMock = $this->createStub(MathUtility::class);
        $macroState = \App\DTO\MacroStateDTO::fromArray([
            'policy_rate'                    => 0.04,
            'policy_rate_ema'                => 0.04, // Static blended rate would be ~0.04 + 0.0200 = 0.0600
            'yield_5y_ema'                   => 0.04,
            'interbank_liquidity_spread_ema' => 0.0,
        ]);

        // Without a realized rate, the strategy falls back to the static ~6.0% approximation.
        $calmIncome = $this->model->calculateInterestIncome($stock, $macroState, $mathMock);

        // DebtEngine measured a live, distressed wholesale rate of 15.0% this tick (dynamic Merton/BGG spread
        // widening on top of the static credit_spread) -- the exact scenario where the two rails used to diverge.
        $distressedIncome = $this->model->calculateInterestIncome($stock, $macroState, $mathMock, 0.15);

        // Prime: $70B * (0.15 + 0.0150) = $70B * 0.1650 = $11.55B
        // Repo:  $30B * (0.15 - 0.0025) = $30B * 0.1475 = $4.425B
        // Total = $15.975B ($15,975,000,000.0)
        $this->assertEqualsWithDelta(15_975_000_000.0, $distressedIncome, 1_000.0);

        // The realized rate must dominate the static approximation, not be silently ignored.
        $this->assertGreaterThan($calmIncome, $distressedIncome, 'Realized wholesale rate must override the static credit_spread approximation once a live debt calc exists.');
    }

    public function testObservableShockZIncludesPublicMacroDrivers(): void
    {
        $stock = new Stock();
        $stock->setTicker('GS');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0); // All idiosyncratic private z-scores = 0
        $mathMock->method('generateUniform')->willReturn(0.50);

        // Macro boom that expands public advisory and trading drivers
        $macroBoom = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.02,
            'policy_rate'             => 0.04,
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.075, // +100bps steep
            'market_volatility_ema'   => 0.28,  // vol-arb boom
            'macro_credit_spread_ema' => 0.015,
            'equity_risk_premium'     => 0.04,
            'deal_activity_index_ema' => 120.0,
        ]);

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.14,
            $macroBoom,
            $mathMock
        );

        // Even with 0 private z-scores, observableShockZ must be strictly positive from public macro
        $this->assertGreaterThan(0.05, $result->observableShockZ, 'Observable shock Z must incorporate public macro drivers.');
    }

    public function testFiccCurveDislocationStimulatesTradingRevenue(): void
    {
        $stock = new Stock();
        $stock->setTicker('FICC_IB');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('generateUniform')->willReturn(0.50);

        // No curve dislocation
        $macroNormal = \App\DTO\MacroStateDTO::fromArray([
            'policy_rate'           => 0.04,
            'policy_rate_ema'       => 0.04,
            'yield_5y_ema'          => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
            'market_volatility_ema' => InvestmentBankBusinessModel::VIX_ARBITRAGE_FLOOR,
            'ns_slope'              => 0.02,
            'ns_slope_ema'          => 0.02,
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

        // Nelson-Siegel curve slope dislocation: instantaneous slope 0.05 vs smoothed 0.02 (+300bps dislocation)
        $macroCurveDislocation = \App\DTO\MacroStateDTO::fromArray([
            'policy_rate'           => 0.04,
            'policy_rate_ema'       => 0.04,
            'yield_5y_ema'          => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
            'market_volatility_ema' => InvestmentBankBusinessModel::VIX_ARBITRAGE_FLOOR,
            'ns_slope'              => 0.05,
            'ns_slope_ema'          => 0.02,
        ]);

        $resultDislocation = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.0,
            $macroCurveDislocation,
            $mathMock
        );

        // curveShock = 0.03 * 1.50 = 0.045 (+4.5% trading revenue boost)
        $this->assertGreaterThan($resultNormal->streamRevenue['trading'], $resultDislocation->streamRevenue['trading']);
        $this->assertEqualsWithDelta(600.0 * 1.045, $resultDislocation->streamRevenue['trading'], 0.01);
    }

    public function testViolentRateDislocationPenalizedByInventoryMarkdownAndCapped(): void
    {
        $stock = new Stock();
        $stock->setTicker('FICC_IB');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('generateUniform')->willReturn(0.50);

        // 400 bps violent rate shock: policyRate = 0.08, policyRateEma = 0.04
        $macroViolent = \App\DTO\MacroStateDTO::fromArray([
            'policy_rate'           => 0.08,
            'policy_rate_ema'       => 0.04,
            'yield_5y_ema'          => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
            'market_volatility_ema' => InvestmentBankBusinessModel::VIX_ARBITRAGE_FLOOR,
        ]);

        $resultViolent = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            100.0,
            0.0,
            $macroViolent,
            $mathMock
        );

        // grossFicc = 0.04 * 3.50 = 0.14 <= FICC_ARBITRAGE_CAP (0.15)
        // ratesInventoryMarkdown = (0.04 - 0.02) * 2.00 = 0.04
        // net ficcArbitrage = 0.14 - 0.04 = 0.10 (+10% trading revenue)
        // tradingRevenue = 1000 * 0.60 * 1.10 = 660.0
        $this->assertEqualsWithDelta(660.0, $resultViolent->streamRevenue['trading'], 0.01);
    }

    public function testCrisisInventoryMarkdownDuringBaselVarDeleveraging(): void
    {
        $stock = new Stock();
        $stock->setTicker('GS');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('generateUniform')->willReturn(0.50);

        // VIX at 0.60 -> varDeleverageFactor = 0.30 / 0.60 = 0.50
        $macroPanic = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'policy_rate'             => 0.04,
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
            'market_volatility_ema'   => 0.60,
            'macro_credit_spread_ema' => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD,
            'equity_risk_premium'     => MacroEngine::BASE_EQUITY_RISK_PREMIUM,
        ]);

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            0.0,
            0.0,
            $macroPanic,
            $mathMock
        );

        // tradingInventoryMarkdownCost = (1.0 - 0.50) * 0.20 * 0.60 = 0.06 (+600 bps margin hit)
        // Raw margin = 0.50 + 0.06 = 0.56
        $this->assertGreaterThan(0.50, $result->clampedMargin);
        $this->assertEqualsWithDelta(0.56, $result->clampedMargin, 0.001);
    }

    public function testPrimeBrokerageCounterpartyCreditProvisionAndArchegosDefault(): void
    {
        $stock = new Stock();
        $stock->setTicker('GS');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateUniform')->willReturn(0.50); // No regulatory fine

        // 1. Corporate default rate rise from baseline 0.018 to 0.036 (+100% excess defaults)
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $macroHighDefaults = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'             => 0.0,
            'policy_rate'                => 0.04,
            'policy_rate_ema'            => 0.04,
            'yield_5y_ema'               => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
            'market_volatility_ema'      => InvestmentBankBusinessModel::VIX_ARBITRAGE_FLOOR,
            'corporate_default_rate_ema' => 0.036,
        ]);

        $resultDefaults = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            0.0,
            0.0,
            $macroHighDefaults,
            $mathMock
        );

        // corpDefaultExcess = (0.036 - 0.018) / 0.018 = 1.0
        // primeCreditProvisionCost = 1.0 * 0.50 * 0.70 = 0.35
        $this->assertGreaterThan(0.50, $resultDefaults->clampedMargin);

        // 2. Archegos single-counterparty tail blowup: eventZ < -3.00
        $mathArchegos = $this->createStub(MathUtility::class);
        $mathArchegos->method('generateUniform')->willReturn(0.50);
        $mathArchegos->method('generateStandardNormal')->willReturn(-3.20);
        $mathArchegos->method('generatePersistentZ')->willReturn(-3.20);

        $macroNormal = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'policy_rate'             => 0.04,
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
            'market_volatility_ema'   => InvestmentBankBusinessModel::VIX_ARBITRAGE_FLOOR,
        ]);

        $resultArchegos = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            0.0,
            0.0,
            $macroNormal,
            $mathArchegos
        );

        $this->assertSame(\App\Service\Event\ShockEvent::IB_COUNTERPARTY_DEFAULT, $resultArchegos->eventType);
    }

    public function testRegulatorySettlementPrecedenceOverMegaDealLore(): void
    {
        $stock = new Stock();
        $stock->setTicker('GS');

        $mathMock = $this->createStub(MathUtility::class);
        // eventZ = +3.5 (> 2.80 MEGA_DEAL_WIN_Z_SCORE)
        $mathMock->method('generateStandardNormal')->willReturn(3.50);
        $mathMock->method('generatePersistentZ')->willReturn(3.50);
        // Regulatory fine fires (uniform = 0.01 <= 0.04)
        $mathMock->method('generateUniform')->willReturn(0.01);

        $macroState = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'policy_rate'             => 0.04,
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
            'market_volatility_ema'   => InvestmentBankBusinessModel::VIX_ARBITRAGE_FLOOR,
        ]);

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            0.0,
            0.0,
            $macroState,
            $mathMock
        );

        // Regulatory settlement must take eventType precedence over landmark deal win
        $this->assertSame(\App\Service\Event\ShockEvent::IB_REGULATORY_SETTLEMENT, $result->eventType);
    }

    public function testRepoFundingStressPassesThroughToWholesaleRate(): void
    {
        $stock = new Stock();
        $stock->setTicker('GS');
        $stock->setCreditSpread('0.0100'); // 100 bps
        $stock->setFloatingDebtRatio('1.0'); // 100% floating debt
        $stock->setWholesaleDebt('10000000000.0');

        $mathMock = $this->createStub(MathUtility::class);

        // Interbank liquidity spread widens by 200 bps (0.0215 vs baseline 0.0015)
        $macroStressedRepo = \App\DTO\MacroStateDTO::fromArray([
            'policy_rate'                    => 0.04,
            'policy_rate_ema'                => 0.04,
            'yield_5y_ema'                   => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
            'interbank_liquidity_spread_ema' => 0.0215,
        ]);

        $income = $this->model->calculateInterestIncome($stock, $macroStressedRepo, $mathMock);

        // Floating rate = 0.04 + 0.0215 = 0.0615
        // Blended wholesale rate = 0.0615 + 0.0100 = 0.0715
        // Prime rate = 0.0715 + 0.0150 = 0.0865 -> 7B * 0.0865 = 0.6055B
        // Repo yield = 0.0715 - 0.0025 = 0.0690 -> 3B * 0.0690 = 0.2070B
        // Total = 0.8125B ($812,500,000.0)
        $this->assertEqualsWithDelta(812_500_000.0, $income, 1000.0);
    }
}
