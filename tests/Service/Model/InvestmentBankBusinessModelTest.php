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
            'macro_credit_spread_ema' => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD - 0.005, // creditSpreadGap = 0.005 * 10.0 = 0.05
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
        $stock->setTotalEquity('12500000000.0'); // $12.5B equity: the 8x wholesale limit, so the sheet balances
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
        // Funded book = equity + total debt - treasury = 12.5B + 100B - 10B = $102.5B
        // Prime financing = $102.5B * 0.70 * (0.0550 + 0.0150) = $71.75B * 0.0700 = $5.0225B
        // Repo inventory = $102.5B * 0.30 * max(0, 0.0550 - 0.0025) = $30.75B * 0.0525 = $1.614375B
        // Excess cash = 0 (minCash = 100B * 0.10 = 10B)
        // Total interest income = 5.0225B + 1.614375B = $6.636875B
        $this->assertEqualsWithDelta(6_636_875_000.0, $income, 1_000.0);
    }

    /**
     * Interest expense is charged on getTotalDebt(); interest income must be struck on the same funding.
     * Two banks holding the same book differ only in the FORM of the funding behind it, so they must book
     * the same interest income. This failed before: the asset leg read getWholesaleDebt(), so the bank
     * whose funding had migrated onto its revolver earned nothing on that slice while still paying its
     * coupon -- and fundMaturityFromCash() performs exactly that migration every time a refused rollover
     * is taken out on the committed line.
     *
     * Both fixtures hold treasury below the liquidity floor so the excess-cash leg is zero on each and the
     * comparison isolates the spread legs. That floor is still struck on the wholesale book on purpose --
     * it is a requirement against funding that has to be rolled, which a drawn revolver no longer does.
     */
    public function testInterestIncomeDependsOnTotalFundingNotItsComposition(): void
    {
        $macroState = \App\DTO\MacroStateDTO::fromArray([
            'policy_rate'                    => 0.04,
            'policy_rate_ema'                => 0.04,
            'yield_5y_ema'                   => 0.04,
            'interbank_liquidity_spread_ema' => 0.0,
        ]);
        $mathMock = $this->createStub(MathUtility::class);

        $allTermNotes = new Stock();
        $allTermNotes->setTicker('KING');
        $allTermNotes->setCreditSpread('0.0150');
        $allTermNotes->setTotalEquity('12500000000.0');
        $allTermNotes->setCorporateTreasury('5000000000.0');
        $allTermNotes->setWholesaleDebt('100000000000.0');

        // Same $100B of funding, 40% of it now sitting on the drawn revolver after refused rollovers.
        $halfOnTheRevolver = new Stock();
        $halfOnTheRevolver->setTicker('KING');
        $halfOnTheRevolver->setCreditSpread('0.0150');
        $halfOnTheRevolver->setTotalEquity('12500000000.0');
        $halfOnTheRevolver->setCorporateTreasury('5000000000.0');
        $halfOnTheRevolver->setWholesaleDebt('60000000000.0');
        $halfOnTheRevolver->setRevolverDrawn('40000000000.0');

        $this->assertSame(
            (float) $allTermNotes->getTotalDebt(),
            (float) $halfOnTheRevolver->getTotalDebt(),
            'fixture guard: both banks must carry the same total funding'
        );

        $this->assertEqualsWithDelta(
            $this->model->calculateInterestIncome($allTermNotes, $macroState, $mathMock),
            $this->model->calculateInterestIncome($halfOnTheRevolver, $macroState, $mathMock),
            1_000.0,
            'a dollar of funding earns the same whether it is a term note or a revolver draw'
        );
    }

    /**
     * Regression on the balance sheet that actually failed. CORV died with $953.8B of wholesale debt and
     * $1,551.6B of fully drawn revolver: expense ran on all $2,505.4B while income was booked on the
     * wholesale slice alone, so 62% of the book paid a coupon and earned nothing. Net interest was roughly
     * -$24B a quarter, which walked equity to a 1.79% capital ratio and through the 2.0% liquidation floor.
     * The matched book carries a structural 0.70 * 150bps - 0.30 * 25bps = 97.5bps spread, so on any
     * balance sheet the asset legs must more than cover the funding they are struck on.
     */
    public function testFundedBookCoversItsOwnFundingCost(): void
    {
        $realizedWholesaleRate = 0.0683; // the rate CORV's reported interest expense implies on total debt

        $corv = new Stock();
        $corv->setTicker('CORV');
        $corv->setCreditSpread('0.0150');
        $corv->setTotalEquity('45644707140.98');
        $corv->setCorporateTreasury('61541476622.02');
        $corv->setWholesaleDebt('953793346624.08');
        $corv->setRevolverDrawn('1551605717620.60');

        $macroState = \App\DTO\MacroStateDTO::fromArray([
            'policy_rate'                    => 0.036,
            'policy_rate_ema'                => 0.036,
            'yield_5y_ema'                   => 0.044,
            'interbank_liquidity_spread_ema' => 0.0,
        ]);

        $totalDebt = (float) $corv->getTotalDebt();
        $interestExpense = $totalDebt * $realizedWholesaleRate;
        $interestIncome = $this->model->calculateInterestIncome(
            $corv,
            $macroState,
            $this->createStub(MathUtility::class),
            $realizedWholesaleRate
        );

        $this->assertGreaterThan(
            $interestExpense,
            $interestIncome,
            'the funded book must out-earn the funding interest expense is charged on'
        );

        // Treasury ($61.5B) is below the 10% liquidity floor on the wholesale book, so there is no
        // excess-cash leg at all. Net interest is then the structural 97.5bps on the funded book, less the
        // carry on the borrowed cash lying idle: the treasury exceeds equity, so $15.9B of the funding is
        // sitting in cash that pays a coupon and earns nothing. That drag is real and deliberate -- what
        // was wrong before was charging it on 62% of the balance sheet rather than on the idle slice.
        $fundedBook = 45_644_707_140.98 + $totalDebt - 61_541_476_622.02;
        $idleBorrowedCash = $totalDebt - $fundedBook;
        $this->assertEqualsWithDelta(
            ($fundedBook * 0.00975) - ($idleBorrowedCash * $realizedWholesaleRate),
            $interestIncome - $interestExpense,
            1_000_000.0
        );
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

    public function testLevFinHungBridgeDebtIsMarkedOnTheSpreadMoveNotItsLevel(): void
    {
        $stock = new Stock();
        $stock->setTicker('LEVFIN_IB');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('generateUniform')->willReturn(0.50);

        $baseline = MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER;
        $margin = function (float $spot, float $ema) use ($stock, $mathMock): float {
            $macro = \App\DTO\MacroStateDTO::fromArray([
                'output_gap_ema'               => 0.0,
                'equity_risk_premium'          => MacroEngine::BASE_EQUITY_RISK_PREMIUM,
                'macro_credit_spread_ema'      => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD,
                'high_yield_credit_spread'     => $spot,
                'high_yield_credit_spread_ema' => $ema,
                'policy_rate'                  => 0.04, // synced with EMA: isolate this test's variable, avoid a phantom FICC rate-shock
                'policy_rate_ema'              => 0.04,
                'yield_5y_ema'                 => 0.04 + InvestmentBankBusinessModel::DCM_NEUTRAL_CURVE_SLOPE, // neutral slope over the 4% policy rate: no DCM stimulus
            ]);

            return $this->model->computeActualFinancials($stock, 1000.0, 0.50, 100.0, 0.0, $macro, $mathMock)->clampedMargin;
        };

        $calm = $margin($baseline, $baseline);
        // A quarter in which high-yield spreads gap 300 bps wider: 0.030 * 1.50 * 0.40 (advisory weight) = 0.018.
        $this->assertEqualsWithDelta(0.018, $margin($baseline + 0.030, $baseline) - $calm, 0.001, 'Bridge commitments are marked down as spreads widen.');
        // Spreads that stay wide have already been marked: no charge every quarter they stay there.
        $this->assertEqualsWithDelta($calm, $margin($baseline + 0.030, $baseline + 0.030), 1e-9, 'A spread that stays wide is not a new loss.');
        // And the mark comes back as the spread tightens and the book is syndicated out.
        $this->assertLessThan($calm, $margin($baseline + 0.030, $baseline + 0.060), 'A tightening quarter writes the bridge book back up.');
    }

    /**
     * The activity the committed cost base staffs to is the revenue the desks actually book: with the noise
     * and the tail events switched off, the reported swing is realized over expected revenue, less one. Two
     * separate formulas would drift, and the base would staff to a drought the income statement never had.
     */
    public function testSectorActivityShiftIsTheSwingTheDesksBook(): void
    {
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateStandardNormal')->willReturn(0.0);
        $mathMock->method('generateUniform')->willReturn(0.50);

        $drought = \App\DTO\MacroStateDTO::fromArray([
            'output_gap_ema'          => -0.045,
            'equity_risk_premium'     => MacroEngine::BASE_EQUITY_RISK_PREMIUM + 0.02,
            'macro_credit_spread_ema' => InvestmentBankBusinessModel::DEAL_BASELINE_CREDIT_SPREAD + 0.01,
            'market_volatility_ema'   => 0.27,
            'policy_rate'             => 0.01,
            'policy_rate_ema'         => 0.02,
            'yield_5y_ema'            => 0.025,
        ]);

        foreach (['KING', 'PERE', 'IBX'] as $ticker) {
            $stock = new Stock();
            $stock->setTicker($ticker);
            $shift = $this->model->resolveSectorActivityShift($stock, $drought);
            $booked = $this->model->computeActualFinancials($stock, 1000.0, 0.50, 100.0, 0.0, $drought, $mathMock)->actualRevenue / 1000.0 - 1.0;

            $this->assertNotEqualsWithDelta(0.0, $shift, 0.01, "$ticker: a drought is not neutral activity.");
            $this->assertEqualsWithDelta($booked, $shift, 1e-9, "$ticker staffs to the revenue its desks book.");
        }
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

        // Funded book = equity + total debt - treasury = $50B + $100B - $25B = $125B
        // 1. Prime financing: $125B * 70% * (0.04 + 0.0150) = $87.5B * 0.055 = $4.8125B
        // 2. Repo inventory: $125B * 30% * max(0, 0.04 - 0.0025) = $37.5B * 0.0375 = $1.40625B
        // 3. Min cash = max(operatingBase * 0.10, $100B * 0.10) = $10B
        //    Excess cash = $25B - $10B = $15B. Cash yield = max(0, 0.04 - 0.0025) = 0.0375
        //    Cash interest = $15B * 0.0375 = $0.5625B
        // Total interest income = $4.8125B + $1.40625B + $0.5625B = $6.78125B
        $this->assertEqualsWithDelta(6_781_250_000.0, $interestIncome, 1000.0);
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

        // Funded book = equity + total debt - treasury = $0B + $10B - $1B = $9B
        // Prime: $9B * 70% * (0.0250 + 0.0150) = $6.3B * 0.0400 = $252M
        // Repo: $9B * 30% * (0.0250 - 0.0025) = $2.7B * 0.0225 = $60.75M
        // Total interest income = $312.75M
        $this->assertEqualsWithDelta(312_750_000.0, $income, 100.0);
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

        // Funded book = equity + total debt - treasury = $0B + $100B - $10B = $90B
        // Prime: $90B * 70% * (0.15 + 0.0150) = $63B * 0.1650 = $10.395B
        // Repo:  $90B * 30% * (0.15 - 0.0025) = $27B * 0.1475 = $3.9825B
        // Total = $14.3775B
        $this->assertEqualsWithDelta(14_377_500_000.0, $distressedIncome, 1_000.0);

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

    public function testPrimeBrokerageLosesOnAClientBlowUpNotOnTheDefaultRate(): void
    {
        $stock = new Stock();
        $stock->setTicker('GS');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generateUniform')->willReturn(0.50); // No regulatory fine

        // 1. Corporate default rate doubles from baseline 0.018 to 0.036
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

        // Margin debit is lent against liquid collateral with daily variation margin, so a rising corporate
        // default rate is not a loss on the prime book. Charged as one (relative excess x 0.35 of revenue), a
        // doubling cost 35% of revenue and a 2009-scale default wave 170%, clamped only by the 85% comp cap.
        // What does lose money is a single client blowing through its margin, the Archegos event below.
        $this->assertEqualsWithDelta(0.50, $resultDefaults->clampedMargin, 1e-9);

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
