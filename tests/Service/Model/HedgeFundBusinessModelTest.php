<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

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

    public function testMacroPhysicsVixIncreasesQuantPricingPower(): void
    {
        $stock = new Stock();
        $stock->setTicker('SWAN');

        // Low VIX (15% <= 18% baseline) -> no VIX alpha boost
        $calmMacro = MacroStateDTO::fromArray([
            'output_gap_ema'        => 0.0,
            'market_volatility_ema' => 0.15,
        ]);
        $calmPhysics = $this->model->getMacroPhysics($stock, $calmMacro);

        // High VIX (30% > 18% baseline -> 12% excess * 2.0 = 24% quant boost -> 1.24 quant pricing power)
        // Blended for SWAN (60% mgmt*1.0 + 20% dir*1.0 + 20% quant*1.24) = 0.60 + 0.20 + 0.248 = 1.048
        $panicMacro = MacroStateDTO::fromArray([
            'output_gap_ema'        => 0.0,
            'market_volatility_ema' => 0.30,
        ]);
        $panicPhysics = $this->model->getMacroPhysics($stock, $panicMacro);

        $this->assertGreaterThan($calmPhysics['pricing_power_multiplier'], $panicPhysics['pricing_power_multiplier']);
        $this->assertEqualsWithDelta(1.048, $panicPhysics['pricing_power_multiplier'], 0.001);
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
            $mathMock
        );

        $this->assertSame(ShockEvent::HF_QUANT_ALPHA_SURGE, $result->eventType);
        $this->assertGreaterThan(20.0, $result->streamRevenue['quant_alpha'] ?? 0.0);
    }

    public function testSectorPhysicsMarginCallTriggeredUnderCreditBlowoutAndHighLeverage(): void
    {
        $stock = new Stock();
        $stock->setTicker('SWAN');
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('250.0'); // 2.5x leverage > 1.8x threshold
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
            $mathMock
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
            $mathMock
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
            $mathMock
        );

        $this->assertSame(ShockEvent::HF_DIRECTIONAL_BLOWUP, $result->eventType);
    }

    public function testDirectionalBetsLeverageAmplification(): void
    {
        $unleveredStock = new Stock();
        $unleveredStock->setTicker('UNLEVERED');
        $unleveredStock->setTotalEquity('100.0');
        $unleveredStock->setWholesaleDebt('0.0');
        $unleveredStock->setEarningsMomentumZ([
            'directional_bets' => 1.0,
            'quant_alpha'      => 0.0,
            'management_fees'  => 0.0,
        ]);

        $leveredStock = new Stock();
        $leveredStock->setTicker('LEVERED');
        $leveredStock->setTotalEquity('100.0');
        $leveredStock->setWholesaleDebt('200.0'); // 2.0x leverage
        $leveredStock->setEarningsMomentumZ([
            'directional_bets' => 1.0,
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
            return $prevZ > 0 ? 1.0 : 0.0;
        });

        $unleveredResult = $this->model->computeActualFinancials($unleveredStock, 100.0, 0.40, 10.0, 0.10, $macro, $mathMock);
        $leveredResult = $this->model->computeActualFinancials($leveredStock, 100.0, 0.40, 10.0, 0.10, $macro, $mathMock);

        $this->assertGreaterThan(
            $unleveredResult->streamRevenue['directional_bets'],
            $leveredResult->streamRevenue['directional_bets']
        );
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
            $this->mathUtility
        );

        // Management fee stream should remain positive and provide a floor
        $this->assertGreaterThan(20.0, $result->streamRevenue['management_fees']);
    }

    public function testTreasuryYieldAndVixResilience(): void
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

        // 80% bonds @ 4% + 20% equity @ 7% = 0.032 + 0.014 = 0.046
        $this->assertEqualsWithDelta(0.046, $calmYield, 0.001);

        $stock = new Stock();
        $stock->setTicker('SWAN');
        $stock->setTotalRevenue('50000000.0');
        $stock->setTotalEquity('50000000.0');
        $stock->setCorporateTreasury('100000000.0');
        $stock->setWholesaleDebt('0.0');

        $calmIncome = $this->model->calculateInterestIncome($stock, $calmMacro, $this->mathUtility);
        $panicIncome = $this->model->calculateInterestIncome($stock, $panicMacro, $this->mathUtility);

        $this->assertGreaterThan(0.0, $panicIncome);
        // Due to active risk hedging, income should only see modest reduction
        $this->assertLessThan($calmIncome, $panicIncome);
        $this->assertGreaterThan($calmIncome * 0.90, $panicIncome);
    }

    public function testDebtExpansionCapacityGatedDuringCreditFreeze(): void
    {
        $normalHealth = new DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.05,
            cashYield: 0.03,
            isNegativeCarry: false,
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
                ebitda: 32.0
            ),
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        );

        $frozenHealth = new DebtHealthDTO(
            grossCost: 0.12,
            effectiveCost: 0.12,
            cashYield: 0.03,
            isNegativeCarry: true,
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
                ebitda: 32.0
            ),
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        );

        $normalCapacity = $this->model->calculateDebtExpansionCapacity(100.0, 50.0, 50.0, $normalHealth, 0.05, 30.0, 2.0);
        $frozenCapacity = $this->model->calculateDebtExpansionCapacity(100.0, 50.0, 50.0, $frozenHealth, 0.12, 30.0, 2.0);

        $this->assertGreaterThan(0.0, $normalCapacity);
        $this->assertEqualsWithDelta($normalCapacity * HedgeFundBusinessModel::DEBT_GATE_FREEZE_SCALAR, $frozenCapacity, 0.01);
    }

    public function testUnderLeveragedThresholdAndSupport(): void
    {
        $this->assertTrue($this->model->supportsUnderleveragedDebtExpansion());

        // Wholesale leverage limit is 3.0. 85% of 3.0 is 2.55.
        $this->assertTrue($this->model->isUnderLeveraged(2.4, 3.0, 5.0, 1.05, 0.12, 0.05));
        $this->assertFalse($this->model->isUnderLeveraged(2.7, 3.0, 5.0, 1.05, 0.12, 0.05));
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

        // Implied AUM = (100 * 0.30) / 0.02 = 1500.0
        // Dir Incentive Fee = (1500 * 0.60) * 0.19 * 0.20 = 34.2
        // Dir Base Revenue = 100 * 0.40 * (1.0 + 2.0 * 0.10 * 0.18 * 2.50 * 1.90) = 40 * (1 + 0.171) = 46.84
        // Total Dir Revenue = 46.84 + 34.2 = 81.04
        $this->assertGreaterThan(80.0, $result->streamRevenue['directional_bets']);
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

    public function testAnalystObservableShockOpacityWeighting(): void
    {
        $stock = new Stock();
        $stock->setTicker('SWAN');
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('0.0');
        $stock->setEarningsMomentumZ([
            'directional_bets' => 2.0,
            'quant_alpha'      => 2.0,
            'management_fees'  => 0.0,
        ]);

        $macro = MacroStateDTO::fromArray([
            'output_gap_ema'          => 0.0,
            'market_volatility_ema'   => 0.18,
            'macro_credit_spread_ema' => 0.02,
        ]);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturnCallback(fn($prevZ, $phi) => $prevZ);

        $result = $this->model->computeActualFinancials($stock, 100.0, 0.40, 10.0, 0.10, $macro, $mathMock);

        // Observable shock is properly dampened by low analyst visibility (DIR = 40%, QUANT = 5%)
        $this->assertGreaterThan(0.0, $result->observableShockZ);
        $this->assertLessThan(0.05, $result->observableShockZ);
    }

    public function testModelThresholds(): void
    {
        $thresholds = $this->model->getModelThresholds();
        $this->assertSame(3.0, $thresholds['wholesale_leverage_limit']);
        $this->assertSame(1.05, $thresholds['min_icr']);
        $this->assertSame(0.20, $thresholds['reversion_speed']);
        $this->assertSame(0.008, $thresholds['moat_spread']);
    }
}

