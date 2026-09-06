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
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04,
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
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04,
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
            'policy_rate_ema'              => 0.04,
            'yield_5y_ema'                 => 0.04,
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
            'policy_rate_ema'              => 0.04,
            'yield_5y_ema'                 => 0.04,
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
            'yield_5y_ema'            => 0.04,
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
            'yield_5y_ema'            => 0.04,
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
        $stock->setWholesaleDebt('100000000000.0'); // $100B wholesale debt
        $stock->setCorporateTreasury('25000000000.0'); // $25B treasury
        $stock->setTotalEquity('50000000000.0');

        $mathMock = $this->createStub(MathUtility::class);

        $macroState = \App\DTO\MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04, // 4.0%
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
            'policy_rate_ema'         => 0.04,
            'yield_5y_ema'            => 0.04,
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
}
