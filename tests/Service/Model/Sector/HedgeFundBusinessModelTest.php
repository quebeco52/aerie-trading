<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\HedgeFundBusinessModel;
use PHPUnit\Framework\TestCase;

class HedgeFundBusinessModelTest extends TestCase
{
    private HedgeFundBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new HedgeFundBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testGetTargetMetricsCalculatesEarningAssetsAndRoic(): void
    {
        $stock = new Stock();
        $stock->setTicker('SWAN');
        $stock->setTotalEquity('1000.0');
        $stock->setWholesaleDebt('2000.0');
        $stock->setCorporateTreasury('100.0');
        $stock->setBaselineRoe('0.25');
        $stock->setRoeTtm('0.0');
        $stock->setOperatingMargin('0.40');
        $stock->setCreditSpread('0.003');
        $stock->setFloatingDebtRatio('0.5');
        $stock->setIndustry('Hedge Fund');

        $macroState = MacroStateDTO::fromArray([
            'policy_rate_ema'     => 0.04,
            'yield_5y_ema'        => 0.045,
            'corporate_tax_rate'  => 0.25,
            'equity_risk_premium' => 0.05,
        ]);

        $metrics = $this->model->getTargetMetrics($stock, $macroState, $this->mathUtility);

        $this->assertArrayHasKey('invested_capital', $metrics);
        $this->assertArrayHasKey('baseline_roic', $metrics);
        $this->assertEqualsWithDelta(2900.0, $metrics['invested_capital'], 0.01);
        $this->assertGreaterThan(0.01, $metrics['baseline_roic']);
        $this->assertLessThan(0.60, $metrics['baseline_roic']);
    }

    // --- Analyst-visible macro uplift ---

    private function zeroAlphaMath(): MathUtility
    {
        $math = $this->createStub(MathUtility::class);
        $math->method('generatePersistentZ')->willReturn(0.0);
        $math->method('generateStandardNormal')->willReturn(0.0);

        return $math;
    }

    private function swan(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('SWAN');
        $stock->setBeta('1.5');
        $stock->setTotalEquity('1000.0');
        $stock->setWholesaleDebt('0.0');
        $stock->setEarningsMomentumZ(['management_fees' => 0.0, 'directional_bets' => 0.0, 'quant_alpha' => 0.0]);

        return $stock;
    }

    public function testAnalystsSeeTheMacroUpliftInTheRootDemandShift(): void
    {
        // The AUM beta, the directional tilt and the VIX regime are all built from published series.
        // Zeroing the root shift hid them from consensus and made every boom quarter a fresh beat.
        $stock = $this->swan();
        $calm = MacroStateDTO::fromArray(['output_gap_ema' => 0.0, 'market_volatility_ema' => HedgeFundBusinessModel::VIX_ALPHA_BASELINE, 'money_supply_growth_ema' => \App\Service\Math\MacroConstants::M2_BASE_GROWTH]);
        $boom = MacroStateDTO::fromArray(['output_gap_ema' => 0.03, 'market_volatility_ema' => HedgeFundBusinessModel::VIX_ALPHA_BASELINE, 'money_supply_growth_ema' => \App\Service\Math\MacroConstants::M2_BASE_GROWTH]);
        $bust = MacroStateDTO::fromArray(['output_gap_ema' => -0.03, 'market_volatility_ema' => HedgeFundBusinessModel::VIX_ALPHA_BASELINE, 'money_supply_growth_ema' => \App\Service\Math\MacroConstants::M2_BASE_GROWTH]);

        $this->assertEqualsWithDelta(0.0, $this->model->getMacroPhysics($stock, $calm)['macro_demand_shift'], 1e-12);
        $this->assertGreaterThan(0.0, $this->model->getMacroPhysics($stock, $boom)['macro_demand_shift']);
        $this->assertLessThan(0.0, $this->model->getMacroPhysics($stock, $bust)['macro_demand_shift']);
        $this->assertSame(1.0, $this->model->getMacroPhysics($stock, $boom)['pricing_power_multiplier']);
    }

    public function testTheUpliftIsNotCountedTwiceSoThePhysicsIsUnchanged(): void
    {
        // The engine hands the physics expected revenue WITH the root shift inside it. The streams apply
        // their macro terms in full, so the physics has to divide the root back out — the boom revenue on
        // a base of 100 must be what the streams alone produce from 100, not the streams on top of the shift.
        $stock = $this->swan();
        $gap = 0.03;
        $boom = MacroStateDTO::fromArray(['output_gap_ema' => $gap, 'market_volatility_ema' => HedgeFundBusinessModel::VIX_ALPHA_BASELINE, 'money_supply_growth_ema' => \App\Service\Math\MacroConstants::M2_BASE_GROWTH, 'macro_credit_spread_ema' => 0.02]);

        $root = $this->model->getMacroPhysics($stock, $boom)['macro_demand_shift'];
        $result = $this->model->computeActualFinancials($stock, 100.0 * (1.0 + $root), 0.40, 10.0, 0.10, $boom, $this->zeroAlphaMath());
        // A quarter with no alpha books the average year's incentive fees short whatever the cycle; the calm quarter
        // carries the same shortfall, so the boom is read against it.
        $calm = MacroStateDTO::fromArray(['output_gap_ema' => 0.0, 'market_volatility_ema' => HedgeFundBusinessModel::VIX_ALPHA_BASELINE, 'money_supply_growth_ema' => \App\Service\Math\MacroConstants::M2_BASE_GROWTH, 'macro_credit_spread_ema' => 0.02]);
        $calmRevenue = $this->model->computeActualFinancials($this->swan(), 100.0 * (1.0 + $this->model->getMacroPhysics($this->swan(), $calm)['macro_demand_shift']), 0.40, 10.0, 0.10, $calm, $this->zeroAlphaMath())->actualRevenue;

        $beta = 1.5;
        $expectedStreams = 100.0 * (
            (HedgeFundBusinessModel::DEFAULT_MGMT_FEE_WEIGHT * (1.0 + ($gap * $beta * HedgeFundBusinessModel::AUM_MARKET_BETA_SCALAR)))
            + (HedgeFundBusinessModel::DEFAULT_DIRECTIONAL_BETS_WEIGHT * (1.0 + ($gap * $beta * HedgeFundBusinessModel::DIRECTIONAL_MACRO_SCALAR)))
            + HedgeFundBusinessModel::DEFAULT_QUANT_ALPHA_WEIGHT
        );

        $this->assertEqualsWithDelta($expectedStreams - 100.0, $result->actualRevenue - $calmRevenue, $expectedStreams * 0.001);
    }

    public function testABoomIsNotAStandingBeatOnceAnalystsSeeIt(): void
    {
        // With the fund's own alpha flat, what the physics produces in a boom equals what expected revenue
        // (the consensus base) already carries. Before, expected stayed at the calm figure and the whole
        // uplift landed as surprise every quarter for as long as the boom lasted.
        $stock = $this->swan();
        $boom = MacroStateDTO::fromArray(['output_gap_ema' => 0.04, 'market_volatility_ema' => 0.22, 'money_supply_growth_ema' => \App\Service\Math\MacroConstants::M2_BASE_GROWTH + 0.03, 'macro_credit_spread_ema' => 0.02]);

        $expected = 100.0 * (1.0 + $this->model->getMacroPhysics($stock, $boom)['macro_demand_shift']);
        $result = $this->model->computeActualFinancials($stock, $expected, 0.40, 10.0, 0.10, $boom, $this->zeroAlphaMath());
        // A quarter with no alpha falls short of the average year's incentive fees in any cycle; the boom adds nothing.
        $calm = MacroStateDTO::fromArray(['output_gap_ema' => 0.0, 'market_volatility_ema' => 0.22, 'money_supply_growth_ema' => \App\Service\Math\MacroConstants::M2_BASE_GROWTH, 'macro_credit_spread_ema' => 0.02]);
        $calmExpected = 100.0 * (1.0 + $this->model->getMacroPhysics($this->swan(), $calm)['macro_demand_shift']);
        $calmResult = $this->model->computeActualFinancials($this->swan(), $calmExpected, 0.40, 10.0, 0.10, $calm, $this->zeroAlphaMath());

        $this->assertGreaterThan(100.0, $expected, 'A boom lifts what analysts expect.');
        $this->assertEqualsWithDelta(($calmResult->actualRevenue - $calmExpected) / $calmExpected, ($result->actualRevenue - $expected) / $expected, 0.002, 'No standing surprise.');
    }

    public function testSectorPhysicsQuantAlphaSurgeUnderHighVix(): void
    {
        $stock = new Stock();
        $stock->setTicker('SWAN');
        $stock->setBeta('1.5');
        $stock->setTotalEquity('1000.0');
        $stock->setWholesaleDebt('2000.0');
        $stock->setEarningsMomentumZ([
            'quant_alpha'      => 2.0,
            'directional_bets' => 0.0,
            'management_fees'  => 0.0,
        ]);

        $panicMacro = MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'market_volatility_ema'   => 0.35, // High VIX > 0.30 threshold
            'macro_credit_spread_ema' => 0.02,
        ]);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturnCallback(function ($prevZ, $phi) {
            return $prevZ > 1.0 ? 1.50 : 0.0;
        });

        $result = $this->model->computeActualFinancials(
            $stock,
            100.0,
            0.40,
            10.0,
            0.10,
            $panicMacro,
            $mathMock,
        );

        $this->assertSame(ShockEvent::HF_QUANT_ALPHA_SURGE, $result->eventType);
        $this->assertGreaterThan(20.0, $result->streamRevenue['quant_alpha'] ?? 0.0);
    }

    public function testSectorPhysicsMarginCallTriggeredUnderCreditBlowoutAndHighLeverage(): void
    {
        $stock = new Stock();
        $stock->setTicker('HFUN'); // its funds at the default gross leverage, above the margin-call threshold
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('250.0');
        $stock->setEarningsMomentumZ([
            'directional_bets' => -2.5, // Negative directional momentum
            'quant_alpha'      => 0.0,
            'management_fees'  => 0.0,
        ]);

        $creditFreezeMacro = MacroStateDTO::fromArray([
            'output_gap_ema'          => -0.03,
            'market_volatility_ema'   => 0.25,
            'macro_credit_spread_ema' => 0.055, // 550bps > 400bps margin call threshold
        ]);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturnCallback(function ($prevZ, $phi) {
            return $prevZ < 0 ? -1.50 : 0.0;
        });

        $result = $this->model->computeActualFinancials(
            $stock,
            100.0,
            0.40,
            10.0,
            0.10,
            $creditFreezeMacro,
            $mathMock,
        );

        $this->assertSame(ShockEvent::HF_MARGIN_CALL, $result->eventType);
        // Margin call penalty increases variable cost margin
        $this->assertGreaterThan(0.40, $result->clampedMargin);
    }

    public function testSectorPhysicsPerformanceFeeCrystallization(): void
    {
        $stock = new Stock();
        $stock->setTicker('SWAN');
        $stock->setBeta('1.5');
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('100.0');
        $stock->setEarningsMomentumZ([
            'directional_bets' => 2.5,
            'quant_alpha'      => 2.5,
            'management_fees'  => 0.0,
        ]);

        $boomMacro = MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.03,
            'market_volatility_ema'   => 0.20,
            'macro_credit_spread_ema' => 0.015,
        ]);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturnCallback(function ($prevZ, $phi) {
            return $prevZ > 1.0 ? 2.0 : 0.0;
        });

        $result = $this->model->computeActualFinancials(
            $stock,
            100.0,
            0.40,
            10.0,
            0.10,
            $boomMacro,
            $mathMock,
        );

        $this->assertSame(ShockEvent::HF_PERFORMANCE_FEE_CRYSTALLIZATION, $result->eventType);
        $this->assertGreaterThan(100.0, $result->actualRevenue);
    }

    public function testSectorPhysicsDirectionalBlowup(): void
    {
        $stock = new Stock();
        $stock->setTicker('SWAN');
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('250.0'); // 2.5x leverage > 2.0x threshold
        $stock->setEarningsMomentumZ([
            'directional_bets' => -3.5,
            'quant_alpha'      => 0.0,
            'management_fees'  => 0.0,
        ]);

        $macro = MacroStateDTO::fromArray([
            'output_gap_ema'          => -0.02,
            'market_volatility_ema'   => 0.20,
            'macro_credit_spread_ema' => 0.02, // Below margin call threshold to isolate blowup
        ]);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturnCallback(function ($prevZ, $phi) {
            return $prevZ < -2.0 ? -2.50 : 0.0;
        });

        $result = $this->model->computeActualFinancials(
            $stock,
            100.0,
            0.40,
            10.0,
            0.10,
            $macro,
            $mathMock,
        );

        $this->assertSame(ShockEvent::HF_DIRECTIONAL_BLOWUP, $result->eventType);
    }

    /**
     * The directional book runs on its funds' leverage, borrowed against its investors' capital: a manager that pays
     * its own debt down leaves its funds' bets as they were, and funds run at higher gross leverage swing further on
     * the same alpha.
     */
    public function testDirectionalBetsRunOnTheFundsLeverageNotTheManagersDebt(): void
    {
        $macro = MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'market_volatility_ema'   => 0.18,
            'macro_credit_spread_ema' => 0.02,
        ]);
        $directional = function (string $ticker, string $debt, float $alphaZ) use ($macro): float {
            $stock = new Stock();
            $stock->setTicker($ticker);
            $stock->setTotalEquity('100.0');
            $stock->setWholesaleDebt($debt);
            $stock->setEarningsMomentumZ(['directional_bets' => $alphaZ, 'quant_alpha' => 0.0, 'management_fees' => 0.0]);
            $math = $this->createStub(MathUtility::class);
            $math->method('generatePersistentZ')->willReturnCallback(static fn(float $prevZ): float => $prevZ);

            return $this->model->computeActualFinancials($stock, 100.0, 0.40, 10.0, 0.10, $macro, $math)->streamRevenue['directional_bets'];
        };

        $this->assertEqualsWithDelta($directional('UNTUNED', '0.0', 1.0), $directional('UNTUNED', '200.0', 1.0), 1e-9, 'The manager\'s own debt is not the funds\' leverage.');

        $beat = static fn(string $ticker): float => $directional($ticker, '0.0', 1.0) / $directional($ticker, '0.0', 0.0) - 1.0;
        $this->assertGreaterThan(0.0, $beat('UNTUNED'));
        $this->assertGreaterThan($beat('UNTUNED'), $beat('SWAN'), 'SWAN\'s funds run above the default gross leverage.');
    }

    public function testManagementFeeStabilityDuringRecession(): void
    {
        $stock = new Stock();
        $stock->setTicker('SWAN');
        $stock->setBeta('1.5');
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('100.0');
        $stock->setEarningsMomentumZ([
            'management_fees'  => 0.0,
            'directional_bets' => -3.0,
            'quant_alpha'      => -3.0,
        ]);

        $severeRecession = MacroStateDTO::fromArray([
            'output_gap_ema'          => -0.05,
            'market_volatility_ema'   => 0.18,
            'macro_credit_spread_ema' => 0.02,
        ]);

        $result = $this->model->computeActualFinancials(
            $stock,
            100.0,
            0.40,
            10.0,
            0.10,
            $severeRecession,
            $this->mathUtility,
        );

        // Management fee stream should remain positive and provide a floor
        $this->assertGreaterThan(20.0, $result->streamRevenue['management_fees']);
    }

    /**
     * The treasury's expected yield is the mix's, and volatility is no drag on it: a crash reaches the co-investments
     * through the board's own fall, at the mark (InvestmentMarkLedger::markEquitySleeve()), not a second time here.
     */
    public function testTreasuryYieldAndItsEquitySleeve(): void
    {
        $calmMacro = MacroStateDTO::fromArray([
            'yield_10y_ema'         => 0.04,
            'output_gap_ema'        => 0.0,
            'market_volatility_ema' => 0.20,
        ]);

        // Extreme VIX 40% (10% above 30% hedge fund seed threshold)
        $panicMacro = MacroStateDTO::fromArray([
            'yield_10y_ema'         => 0.04,
            'output_gap_ema'        => 0.0,
            'market_volatility_ema' => 0.40,
        ]);

        $calmYield = $this->model->calculateCashYield($calmMacro);
        $this->assertGreaterThan(0.0, $calmYield);

        // 80% bonds @ 4% + 20% equity at the market cost of equity, 4% + 4.5% ERP = 0.032 + 0.017 = 0.049
        $this->assertEqualsWithDelta(0.049, $calmYield, 0.001);

        $stock = new Stock();
        $stock->setTicker('SWAN');
        $stock->setTotalRevenue('50000000.0');
        $stock->setTotalEquity('50000000.0');
        $stock->setCorporateTreasury('100000000.0');
        $stock->setWholesaleDebt('0.0');

        $calmIncome = $this->model->calculateInterestIncome($stock, $calmMacro);
        $panicIncome = $this->model->calculateInterestIncome($stock, $panicMacro);

        $this->assertEqualsWithDelta($calmIncome, $panicIncome, 1e-6);
        $sleeve = $this->model->resolveEquitySleeve($stock, $calmMacro);
        $this->assertEqualsWithDelta(HedgeFundBusinessModel::PORTFOLIO_EQUITY_ALLOCATION * $calmIncome / $calmYield, $sleeve['value'], 1e-6, 'the sleeve is the equity share of the invested treasury');
        $this->assertEqualsWithDelta(0.085, $sleeve['expected_return'], 1e-9, 'expected at the market cost of equity');
    }

    public function testDebtExpansionCapacityGatedDuringCreditFreeze(): void
    {
        $normalHealth = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.03,
            isSevereNegativeCarry: false,
            interestCoverage: 5.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 3.0,
            wacc: 0.06,
            costOfEquity: 0.08,
            leveredBeta: 1.5,
            rawMetrics: new DebtMetricsDTO(
                interestExpense: 2.5,
                blendedRate: 0.05,
                historicalFixedRate: 0.05,
                dynamicSpread: 0.020, // Baseline prime spread
                currentMarketRate: 0.05,
                wholesaleRate: 0.05,
                ebit: 30.0,
                revenue: 100.0,
                depreciation: 2.0,
                ebitda: 32.0,
            ),
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false,
        );

        $frozenHealth = new DebtHealthDTO(
            grossCost: 0.12,
            effectiveCost: 0.12,
            cashYield: 0.03,
            isSevereNegativeCarry: true,
            interestCoverage: 2.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 3.0,
            wacc: 0.06,
            costOfEquity: 0.08,
            leveredBeta: 1.5,
            rawMetrics: new DebtMetricsDTO(
                interestExpense: 6.0,
                blendedRate: 0.12,
                historicalFixedRate: 0.05,
                dynamicSpread: 0.060, // Spread blown out to 600bps
                currentMarketRate: 0.12,
                wholesaleRate: 0.12,
                ebit: 30.0,
                revenue: 100.0,
                depreciation: 2.0,
                ebitda: 32.0,
            ),
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false,
        );

        $normalCapacity = $this->model->calculateDebtExpansionCapacity(100.0, 50.0, 50.0, $normalHealth, 0.05, 30.0, 2.0);
        $frozenCapacity = $this->model->calculateDebtExpansionCapacity(100.0, 50.0, 50.0, $frozenHealth, 0.12, 30.0, 2.0);

        $this->assertGreaterThan(0.0, $normalCapacity);
        $this->assertEqualsWithDelta($normalCapacity * HedgeFundBusinessModel::DEBT_GATE_FREEZE_SCALAR, $frozenCapacity, 0.01);

        $criticalHealth = new DebtHealthDTO(
            grossCost: 0.18,
            effectiveCost: 0.18,
            cashYield: 0.03,
            isSevereNegativeCarry: true,
            interestCoverage: 0.5,
            wantsToPaydownDebt: true,
            canIssueDebt: false,
            debtTolerance: 3.0,
            wacc: 0.08,
            costOfEquity: 0.12,
            leveredBeta: 1.5,
            rawMetrics: new DebtMetricsDTO(
                interestExpense: 10.0,
                blendedRate: 0.18,
                historicalFixedRate: 0.05,
                dynamicSpread: 0.130, // 1300bps - exceeds 1000bps critical drag
                currentMarketRate: 0.18,
                wholesaleRate: 0.18,
                ebit: 5.0,
                revenue: 100.0,
                depreciation: 2.0,
                ebitda: 7.0,
            ),
            isLiquidityCrisis: true,
            isLiquidityWarning: true,
            isUnderLeveraged: false,
        );
        $criticalCapacity = $this->model->calculateDebtExpansionCapacity(100.0, 50.0, 50.0, $criticalHealth, 0.18, 5.0, 2.0);
        $this->assertSame(0.0, $criticalCapacity);
    }

    public function testUnderLeveragedThresholdAndSupport(): void
    {
        $this->assertFalse($this->model->supportsUnderleveragedDebtExpansion());

        // Wholesale leverage limit is 3.0. 50% of 3.0 is 1.50.
        $this->assertTrue($this->model->isUnderLeveraged(1.4, 3.0, 5.0, 1.05));
        $this->assertFalse($this->model->isUnderLeveraged(1.6, 3.0, 5.0, 1.05));
    }

    public function testAcquisitionTypeIsHostileTakeover(): void
    {
        $this->assertSame('HOSTILE TAKEOVER', $this->model->getAcquisitionType('ACQUISITION'));
    }

    public function testImpliedAumAndPerformanceIncentiveFees(): void
    {
        $stock = new Stock();
        $stock->setTicker('SWAN');
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('100.0'); // 1.0x leverage -> leverageMultiplier = 1.0 + 1.0 * 0.90 = 1.90
        $stock->setEarningsMomentumZ([
            'directional_bets' => 2.0, // Above hurdle Z (1.00) -> dirAlphaReturn = (2.0 - 1.0) * 0.10 * 1.90 = 0.19
            'quant_alpha'      => 0.0,
            'management_fees'  => 0.0,
        ]);

        $macro = MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'market_volatility_ema'   => 0.18,
            'macro_credit_spread_ema' => 0.02,
        ]);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturnCallback(function ($prevZ, $phi) {
            return $prevZ > 0 ? 2.0 : 0.0;
        });

        $result = $this->model->computeActualFinancials($stock, 100.0, 0.40, 10.0, 0.10, $macro, $mathMock);

        // Dir Incentive Fee crystallizes and adds to directional revenue above baseline ($30.0)
        $this->assertGreaterThan(30.0, $result->streamRevenue['directional_bets']);
    }

    public function testRedemptionDragReducesManagementFeesAndAum(): void
    {
        $stock = new Stock();
        $stock->setTicker('SWAN');
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('0.0');
        $stock->setBeta('1.0');

        $macro = MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'market_volatility_ema'   => 0.18,
            'macro_credit_spread_ema' => 0.02,
        ]);

        // Case 1: Neutral Alpha (dirZ = 0, quantZ = 0 -> composite = 0 > -1.0 floor)
        $mathNeutral = $this->createStub(MathUtility::class);
        $mathNeutral->method('generatePersistentZ')->willReturn(0.0);
        $resultNeutral = $this->model->computeActualFinancials($stock, 100.0, 0.40, 10.0, 0.10, $macro, $mathNeutral);
        $neutralMgmtRev = $resultNeutral->streamRevenue['management_fees'];

        // Case 2: Moderate Negative Alpha (dirZ = -2.0, quantZ = -2.0 -> composite = -2.0)
        // Deficit below -1.0 floor = 1.0 unit * 0.10 scalar = 10% drag
        $mathNegative = $this->createStub(MathUtility::class);
        $mathNegative->method('generatePersistentZ')->willReturnCallback(function ($prevZ, $phi) {
            return $prevZ < 0 ? -2.0 : 0.0;
        });
        $stock->setEarningsMomentumZ([
            'directional_bets' => -2.0,
            'quant_alpha'      => -2.0,
            'management_fees'  => 0.0,
        ]);
        $resultNegative = $this->model->computeActualFinancials($stock, 100.0, 0.40, 10.0, 0.10, $macro, $mathNegative);
        $negativeMgmtRev = $resultNegative->streamRevenue['management_fees'];

        // Mgmt revenue should be reduced by approximately 10%
        $this->assertEqualsWithDelta($neutralMgmtRev * 0.90, $negativeMgmtRev, 0.01);
        $this->assertLessThan($neutralMgmtRev, $negativeMgmtRev);

        // Case 3: Catastrophic Alpha (dirZ = -5.0, quantZ = -5.0 -> composite = -5.0)
        // Deficit = 4.0 units * 0.10 = 40% -> capped at MAX_REDEMPTION_DRAG (30%)
        $mathSevere = $this->createStub(MathUtility::class);
        $mathSevere->method('generatePersistentZ')->willReturnCallback(function ($prevZ, $phi) {
            return $prevZ < 0 ? -5.0 : 0.0;
        });
        $stock->setEarningsMomentumZ([
            'directional_bets' => -5.0,
            'quant_alpha'      => -5.0,
            'management_fees'  => 0.0,
        ]);
        $resultSevere = $this->model->computeActualFinancials($stock, 100.0, 0.40, 10.0, 0.10, $macro, $mathSevere);
        $severeMgmtRev = $resultSevere->streamRevenue['management_fees'];

        // Mgmt revenue should be capped at 30% drag (70% of neutral mgmt revenue)
        $this->assertEqualsWithDelta($neutralMgmtRev * 0.70, $severeMgmtRev, 0.01);
    }

    public function testAlmgrenChrissQuadraticLiquidationSlippage(): void
    {
        $stock = new Stock();
        $stock->setTicker('SWAN');
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('200.0'); // 2.0x leverage
        $stock->setEarningsMomentumZ([
            'directional_bets' => 0.0,
            'quant_alpha'      => 0.0,
            'management_fees'  => 0.0,
        ]);

        // Credit spread blowout: 600bps > 400bps (spreadDelta = 0.02)
        // liquidationFraction = min(1.0, 2.0 * 0.02 * 5.0) = 0.20 (20% AUM liquidated)
        // slippageCostPercent = 0.5 * 0.80 * (0.20^2) = 0.016 (1.6% slippage)
        $macro = MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'market_volatility_ema'   => 0.18,
            'macro_credit_spread_ema' => 0.06,
        ]);

        $result = $this->model->computeActualFinancials($stock, 100.0, 0.40, 10.0, 0.10, $macro, $this->mathUtility);

        // Clamped variable cost margin should increase due to quadratic slippage loss added as cost penalty
        $this->assertGreaterThan(0.40, $result->clampedMargin);
    }

    public function testModelThresholds(): void
    {
        $this->assertSame(3.0, $this->model->getWholesaleLeverageLimit());
        $this->assertSame(1.05, $this->model->getMinIcr());
        $this->assertSame(0.20, $this->model->getReversionSpeed());
        $this->assertSame(0.008, $this->model->getMoatSpread());
    }

    public function testBonusPoolExpenseFlexesWithPerformanceFees(): void
    {
        $stock = new Stock();
        $stock->setTicker('SWAN');
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('0.0');

        $macro = MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'market_volatility_ema'   => 0.18,
            'macro_credit_spread_ema' => 0.02,
        ]);

        // Neutral Alpha -> No excess performance fees -> Normal variable margin
        $mathNeutral = $this->createStub(MathUtility::class);
        $mathNeutral->method('generatePersistentZ')->willReturn(0.0);
        $stock->setEarningsMomentumZ([
            'directional_bets' => 0.0,
            'quant_alpha'      => 0.0,
            'management_fees'  => 0.0,
        ]);
        $neutralResult = $this->model->computeActualFinancials($stock, 100.0, 0.40, 10.0, 0.10, $macro, $mathNeutral);

        // Huge Alpha -> Performance fee bonus crystallizes -> Bonus pool expense flexes variable margin upwards
        $mathBoom = $this->createStub(MathUtility::class);
        $mathBoom->method('generatePersistentZ')->willReturn(3.0);
        $stock->setEarningsMomentumZ([
            'directional_bets' => 3.0,
            'quant_alpha'      => 3.0,
            'management_fees'  => 0.0,
        ]);
        $boomResult = $this->model->computeActualFinancials($stock, 100.0, 0.40, 10.0, 0.10, $macro, $mathBoom);

        $this->assertGreaterThan(100.0, $boomResult->actualRevenue);
        $this->assertGreaterThan($neutralResult->clampedMargin, $boomResult->clampedMargin);
    }

    public function testVariableMarginClampedDuringSevereMarginCall(): void
    {
        $stock = new Stock();
        $stock->setTicker('SWAN');
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('300.0'); // 3.0x leverage

        // Severe credit blowout: 1500bps > 400bps margin call threshold
        $crisisMacro = MacroStateDTO::fromArray([
            'output_gap_ema'          => -0.05,
            'market_volatility_ema'   => 0.40,
            'macro_credit_spread_ema' => 0.15,
        ]);

        $result = $this->model->computeActualFinancials($stock, 100.0, 0.70, 10.0, 0.10, $crisisMacro, $this->mathUtility);

        // Clamped margin must not exceed MAX_VARIABLE_MARGIN_CLAMP (0.85)
        $this->assertLessThanOrEqual(HedgeFundBusinessModel::MAX_VARIABLE_MARGIN_CLAMP, $result->clampedMargin);
    }

    public function testTheCostBaseReadsTheCycleOnceThroughTheRootShift(): void
    {
        // The root shift is the fund's own activity; inheriting the asset manager's client-flow shift doubled it.
        $model = new HedgeFundBusinessModel();
        $stock = new Stock();
        $stock->setTicker('XXXX');
        $stock->setBeta('1.0');
        $recession = new MacroStateDTO(outputGapEma: -0.03, exchangeRateIndexEma: 100.0);

        $this->assertLessThan(0.0, $model->getMacroPhysics($stock, $recession)['macro_demand_shift']);
        $this->assertSame(0.0, $model->resolveSectorActivityShift($stock, $recession));
    }

    /**
     * An incentive fee is a call on alpha: over the alpha draw's distribution the fee a quarter books averages what
     * the average year already carries, so the directional book earns its expected revenue on average.
     */
    #[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
    public function testIncentiveFeesAverageWhatTheAverageYearCarries(): void
    {
        $neutral = new MacroStateDTO();
        $stock = (new Stock())->setTicker('HFX')->setBeta('1.5')->setTotalEquity('1000.0')->setWholesaleDebt('0.0');
        // Expected revenue as the engine hands it over, the root shift inside, on a structural base of 100.
        $expected = 100.0 * (1.0 + $this->model->getMacroPhysics(clone $stock, $neutral)['macro_demand_shift']);
        $directional = function (float $z) use ($stock, $expected, $neutral): float {
            $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateStandardNormal', 'checkProbability'])->getMock();
            $math->method('generateStandardNormal')->willReturn(0.0);
            $math->method('checkProbability')->willReturn(false);
            $quarter = clone $stock;
            $quarter->setEarningsMomentumZ(['directional_bets' => $z / HedgeFundBusinessModel::DIR_PERSISTENCE]);

            return $this->model->computeActualFinancials($quarter, $expected, 0.40, 10.0, 0.10, $neutral, $math)->streamRevenue['directional_bets'];
        };

        $sum = 0.0;
        $weights = 0.0;
        for ($z = -6.0; $z <= 6.0; $z += 0.01) {
            $weight = exp(-0.5 * $z * $z);
            $sum += $weight * $directional($z);
            $weights += $weight;
        }

        $this->assertGreaterThan($directional(1.0) - $directional(0.0), $directional(3.0) - $directional(2.0), 'The fee must pay above the hurdle for the test to have power.');
        $this->assertEqualsWithDelta(100.0 * HedgeFundBusinessModel::DEFAULT_DIRECTIONAL_BETS_WEIGHT, $sum / $weights, 0.01);
    }

    /**
     * The bonus pool is a call on each alpha stream's beat, and the planned cost ratio carries its average quarter:
     * over the alpha draws at a neutral cycle the cost base averages the planned ratio, so the fund earns its margin.
     */
    #[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
    public function testTheBonusPoolAveragesWhatThePlannedCostCarries(): void
    {
        $neutral = new MacroStateDTO(marketVolatilityEma: HedgeFundBusinessModel::VIX_ALPHA_BASELINE);
        // Levered two to one, so the directional call is geared as a levered book's is.
        $stock = (new Stock())->setTicker('HFX')->setBeta('1.5')->setTotalEquity('1000.0')->setWholesaleDebt('2000.0');
        $expected = 100.0 * (1.0 + $this->model->getMacroPhysics(clone $stock, $neutral)['macro_demand_shift']);
        $plannedRatio = 0.40;
        $poolOverPlan = function (float $z) use ($stock, $expected, $neutral, $plannedRatio): float {
            $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateStandardNormal', 'checkProbability'])->getMock();
            $math->method('generateStandardNormal')->willReturn(0.0);
            $math->method('checkProbability')->willReturn(false);
            $quarter = clone $stock;
            $quarter->setEarningsMomentumZ([
                'directional_bets' => $z / HedgeFundBusinessModel::DIR_PERSISTENCE,
                'quant_alpha'      => $z / HedgeFundBusinessModel::QUANT_PERSISTENCE,
            ]);
            $report = $this->model->computeActualFinancials($quarter, $expected, $plannedRatio, 10.0, 0.26, $neutral, $math);

            return $report->actualVariableCosts - ($plannedRatio * $report->actualRevenue);
        };

        // Each stream's draw is a standard normal whatever the two share, so one draw for both gives each its mean.
        $sum = 0.0;
        $weights = 0.0;
        for ($z = -6.0; $z <= 6.0; $z += 0.01) {
            $weight = exp(-0.5 * $z * $z);
            $sum += $weight * $poolOverPlan($z);
            $weights += $weight;
        }

        $this->assertGreaterThan($poolOverPlan(0.0) + 1.0, $poolOverPlan(2.0), 'The pool must pay on a beat for the test to have power.');
        $this->assertEqualsWithDelta(0.0, $sum / $weights, 0.01);
    }
}
