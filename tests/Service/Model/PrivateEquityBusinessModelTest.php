<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\PrivateEquityBusinessModel;
use PHPUnit\Framework\TestCase;

class PrivateEquityBusinessModelTest extends TestCase
{
    private PrivateEquityBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new PrivateEquityBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testGetTargetMetricsCalculatesEarningAssetsAndRoic(): void
    {
        $stock = new Stock();
        $stock->setTicker('PE_CORP');
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('200.0');
        $stock->setCorporateTreasury('10.0');
        $stock->setBaselineRoe('0.20');
        $stock->setRoeTtm('0.0');
        $stock->setOperatingMargin('0.35');
        $stock->setCreditSpread('0.015');
        $stock->setFloatingDebtRatio('0.4');
        $stock->setIndustry('Private Equity');

        $macroState = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
            'corporate_tax_rate' => 0.25,
            'equity_risk_premium' => 0.05,
        ]);

        $metrics = $this->model->getTargetMetrics($stock, $macroState, $this->mathUtility);

        $this->assertArrayHasKey('invested_capital', $metrics);
        $this->assertArrayHasKey('baseline_roic', $metrics);
        $this->assertEqualsWithDelta(290.0, $metrics['invested_capital'], 0.01);
        $this->assertGreaterThan(0.01, $metrics['baseline_roic']);
        $this->assertLessThan(0.50, $metrics['baseline_roic']);
    }

    public function testMacroPhysicsCompressesPricingPowerWhenCostOfDebtSpikes(): void
    {
        $stock = new Stock();
        $stock->setTicker('PE_CORP');

        // Normal macro conditions (cost of debt below threshold)
        $normalMacro = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.03,
            'macro_credit_spread_ema' => 0.015,
        ]);
        $normalPhysics = $this->model->getMacroPhysics($stock, $normalMacro);
        $this->assertEqualsWithDelta(1.0, $normalPhysics['pricing_power_multiplier'], 0.001);

        // High cost of debt macro (policy rate 5.5% + spread 3.0% = 8.5% CoD > 6.5% baseline)
        // Delta = 0.02 * 15.0 = 0.30 compression => carried interest multiple = 0.70
        // Blended (35% mgmt + 65% carry) = 0.35*1.0 + 0.65*0.70 = 0.35 + 0.455 = 0.805
        $highCoDMacro = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.055,
            'macro_credit_spread_ema' => 0.030,
        ]);
        $distressedPhysics = $this->model->getMacroPhysics($stock, $highCoDMacro);
        $this->assertLessThan(1.0, $distressedPhysics['pricing_power_multiplier']);
        $this->assertEqualsWithDelta(0.805, $distressedPhysics['pricing_power_multiplier'], 0.01);
    }

    public function testCalculateSectorPhysicsCarriedInterestHurdleMiss(): void
    {
        $stock = new Stock();
        $stock->setTicker('PE_CORP');
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('50.0');
        $stock->setEarningsMomentumZ(['carried_interest' => -3.0, 'management_fees' => 0.0]);

        $severeMacro = MacroStateDTO::fromArray([
            'output_gap_ema' => -0.05,
            'policy_rate_ema' => 0.05,
            'macro_credit_spread_ema' => 0.04,
        ]);

        $mathMock = $this->createMock(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturnCallback(function ($prevZ, $phi) {
            return $prevZ < 0 ? -2.0 : 0.0;
        });

        $result = $this->model->computeActualFinancials(
            $stock,
            100.0,
            0.35,
            10.0,
            0.10,
            $severeMacro,
            $mathMock
        );

        $this->assertSame(ShockEvent::PE_HURDLE_RATE_MISSED, $result->eventType);
        $this->assertEqualsWithDelta(0.0, $result->streamRevenue['carried_interest'] ?? 0.0, 0.001);
    }

    public function testRescueCapitalIncreasesVariableMargin(): void
    {
        $stock = new Stock();
        $stock->setTicker('PE_CORP');
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('50.0');
        $stock->setEarningsMomentumZ(['carried_interest' => 0.0, 'management_fees' => 0.0]);

        // High policy rate creates rescue capital drag
        $highRateMacro = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.065, // 200bps above 4.5% threshold
            'macro_credit_spread_ema' => 0.02,
            'output_gap_ema' => 0.0,
        ]);

        $result = $this->model->computeActualFinancials(
            $stock,
            100.0,
            0.40,
            10.0,
            0.05,
            $highRateMacro,
            $this->mathUtility
        );

        // Clamped variable margin should be higher than baseline 0.40 due to rescue capital drag
        $this->assertGreaterThan(0.40, $result->clampedMargin);
    }

    public function testTreasuryYieldAndVixSensitivity(): void
    {
        $calmMacro = MacroStateDTO::fromArray([
            'yield_10y_ema' => 0.04,
            'output_gap_ema' => 0.0,
            'market_volatility_ema' => 0.15,
        ]);
        $panicMacro = MacroStateDTO::fromArray([
            'yield_10y_ema' => 0.04,
            'output_gap_ema' => 0.0,
            'market_volatility_ema' => 0.35, // VIX above 0.20 threshold
        ]);

        $calmYield = $this->model->calculateCashYield($calmMacro);
        $this->assertGreaterThan(0.0, $calmYield);

        $stock = new Stock();
        $stock->setTicker('PE_CORP');
        $stock->setTotalRevenue('50000000.0');
        $stock->setTotalEquity('50000000.0');
        $stock->setCorporateTreasury('100000000.0');
        $stock->setWholesaleDebt('0.0');

        $calmIncome = $this->model->calculateInterestIncome($stock, $calmMacro, $this->mathUtility);
        $panicIncome = $this->model->calculateInterestIncome($stock, $panicMacro, $this->mathUtility);

        $this->assertGreaterThan(0.0, $calmIncome);
        $this->assertLessThan($calmIncome, $panicIncome);
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
            debtTolerance: 2.5,
            wacc: 0.06,
            costOfEquity: 0.08,
            leveredBeta: 1.0,
            rawMetrics: new DebtMetricsDTO(
                interestExpense: 2.5,
                blendedRate: 0.05,
                historicalFixedRate: 0.05,
                dynamicSpread: 0.020, // Baseline spread
                currentMarketRate: 0.05,
                wholesaleRate: 0.05,
                ebit: 30.0,
                revenue: 100.0,
                depreciation: 5.0,
                ebitda: 35.0
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
            debtTolerance: 2.5,
            wacc: 0.06,
            costOfEquity: 0.08,
            leveredBeta: 1.0,
            rawMetrics: new DebtMetricsDTO(
                interestExpense: 6.0,
                blendedRate: 0.12,
                historicalFixedRate: 0.05,
                dynamicSpread: 0.060, // Spread blown out to 600bps
                currentMarketRate: 0.12,
                wholesaleRate: 0.12,
                ebit: 30.0,
                revenue: 100.0,
                depreciation: 5.0,
                ebitda: 35.0
            ),
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        );

        $normalCapacity = $this->model->calculateDebtExpansionCapacity(100.0, 50.0, 50.0, $normalHealth, 0.05, 30.0, 5.0);
        $frozenCapacity = $this->model->calculateDebtExpansionCapacity(100.0, 50.0, 50.0, $frozenHealth, 0.12, 30.0, 5.0);

        $this->assertGreaterThan(0.0, $normalCapacity);
        $this->assertEqualsWithDelta($normalCapacity * PrivateEquityBusinessModel::DEBT_GATE_DROUGHT_SCALAR, $frozenCapacity, 0.01);
    }

    public function testUnderLeveragedThresholdAndSupport(): void
    {
        $this->assertTrue($this->model->supportsUnderleveragedDebtExpansion());

        // Wholesale leverage limit is 2.5. 85% of 2.5 is 2.125.
        $this->assertTrue($this->model->isUnderLeveraged(2.0, 2.5, 5.0, 1.05, 0.12, 0.05));
        $this->assertFalse($this->model->isUnderLeveraged(2.2, 2.5, 5.0, 1.05, 0.12, 0.05));
    }
}
