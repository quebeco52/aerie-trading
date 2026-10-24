<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\DebtMetricsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\PrivateEquityBusinessModel;
use App\Tests\Support\DebtHealthBuilder;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class PrivateEquityBusinessModelTest extends TestCase
{
    private const NEUTRAL_RATE = 0.035;
    private const INTERBANK_TREND = MacroConstants::INTERBANK_SPREAD_LONG_RUN_MEAN;
    private const HY_TREND = MacroConstants::CREDIT_SPREAD_LONG_RUN_MEAN * MacroConstants::HY_BASE_SPREAD_MULTIPLIER;

    private PrivateEquityBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new PrivateEquityBusinessModel();
        $this->mathUtility = MathUtility::ownStream(20261024);
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

    /**
     * With rates, the interbank spread and the HY spread at their trends, buyout debt costs what it is underwritten at.
     */
    public function testCalmCreditAtTrendFreezesNothing(): void
    {
        $physics = $this->model->getMacroPhysics(self::sponsor(), self::atTrend());

        $this->assertEqualsWithDelta(1.0, $physics['pricing_power_multiplier'], 1e-12);
    }

    /**
     * A high-rate era the market has learned is the rate deals are underwritten at, so it freezes nothing; the same
     * policy rate above a neutral the market still reads lower is a squeeze.
     */
    public function testTheFreezeReadsThePolicyRateAgainstTheNeutralTheMarketHasLearned(): void
    {
        $learned = self::atTrend(['policy_rate_ema' => 0.06, 'perceived_neutral_rate' => 0.06]);
        $this->assertEqualsWithDelta(1.0, $this->model->getMacroPhysics(self::sponsor(), $learned)['pricing_power_multiplier'], 1e-12);

        $squeeze = self::atTrend(['policy_rate_ema' => 0.06, 'perceived_neutral_rate' => 0.04]);
        $expectedCarry = 1.0 - (0.02 * PrivateEquityBusinessModel::LBO_COST_OF_DEBT_ELASTICITY);
        $this->assertEqualsWithDelta(0.35 + (0.65 * $expectedCarry), $this->model->getMacroPhysics(self::sponsor(), $squeeze)['pricing_power_multiplier'], 1e-12);
    }

    /** A real-rate squeeze and a credit squeeze add up: buyout debt pays both the reference rate and the HY spread. */
    public function testARateAndCreditSqueezeCompressesMultiplesByBoth(): void
    {
        $squeeze = self::atTrend([
            'policy_rate_ema' => self::NEUTRAL_RATE + 0.010,
            'high_yield_credit_spread_ema' => self::HY_TREND + 0.010,
        ]);

        $expectedCarry = 1.0 - (0.020 * PrivateEquityBusinessModel::LBO_COST_OF_DEBT_ELASTICITY);
        $this->assertEqualsWithDelta(0.35 + (0.65 * $expectedCarry), $this->model->getMacroPhysics(self::sponsor(), $squeeze)['pricing_power_multiplier'], 1e-12);
    }

    /** Debt cheaper than its trend lets sponsors pay more for the same deal, as dearer debt makes them pay less. */
    public function testCheapBuyoutDebtExpandsMultiplesAsDearDebtCompressesThem(): void
    {
        $dear = $this->model->getMacroPhysics(self::sponsor(), self::atTrend(['high_yield_credit_spread_ema' => self::HY_TREND + 0.01]))['pricing_power_multiplier'];
        $cheap = $this->model->getMacroPhysics(self::sponsor(), self::atTrend(['high_yield_credit_spread_ema' => self::HY_TREND - 0.01]))['pricing_power_multiplier'];

        $this->assertEqualsWithDelta(1.0 - $dear, $cheap - 1.0, 1e-12);
        $this->assertGreaterThan(1.0, $cheap);
    }

    public function testLboDebtIsPricedOffTheHighYieldTrancheNotInvestmentGrade(): void
    {
        // A 150 bps IG widening with HY at its trend is not a buyout financing shock: LBO debt is speculative grade.
        $igOnly = self::atTrend(['macro_credit_spread_ema' => MacroConstants::CREDIT_SPREAD_LONG_RUN_MEAN + 0.015]);
        $this->assertEqualsWithDelta(1.0, $this->model->getMacroPhysics(self::sponsor(), $igOnly)['pricing_power_multiplier'], 1e-12);

        $hy = self::atTrend(['high_yield_credit_spread_ema' => self::HY_TREND + 0.015]);
        $expectedCarryMultiple = 1.0 - (0.015 * PrivateEquityBusinessModel::LBO_COST_OF_DEBT_ELASTICITY);
        $this->assertEqualsWithDelta(0.35 + (0.65 * $expectedCarryMultiple), $this->model->getMacroPhysics(self::sponsor(), $hy)['pricing_power_multiplier'], 1e-12);
    }

    /**
     * Buyout loans reset over the floating reference rate, so an interbank freeze with the policy rate and the HY
     * tranche at their trends raises their cost by the premium and compresses multiples by it.
     */
    public function testAnInterbankFreezeRaisesTheCostOfBuyoutDebt(): void
    {
        $premium = 0.0102;
        $freeze = self::atTrend(['interbank_liquidity_spread_ema' => self::INTERBANK_TREND + $premium]);

        $this->assertEqualsWithDelta(0.35 + (0.65 * (1.0 - ($premium * PrivateEquityBusinessModel::LBO_COST_OF_DEBT_ELASTICITY))), $this->model->getMacroPhysics(self::sponsor(), $freeze)['pricing_power_multiplier'], 1e-12);
    }

    /** Fewer exits clear the hurdle as the HY spread runs over its trend, and more as it runs under it. */
    public function testTheHurdleCliffReadsTheHighYieldSpreadAgainstItsTrend(): void
    {
        $stock = new Stock();
        $stock->setTicker('PE_CORP');
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('50.0');
        $stock->setEarningsMomentumZ(['carried_interest' => 0.0, 'management_fees' => 0.0]);
        // A quarter just under the trend cliff: it misses at trend, clears once the spread is 20 bps under it.
        $mathMock = new ScriptedDraws(generatePersistentZ: PrivateEquityBusinessModel::HURDLE_RATE_Z_CLIFF - 0.01);

        $atTrend = $this->model->computeActualFinancials($stock, 100.0, 0.35, 10.0, 0.10, self::atTrend(), $mathMock);
        $tight = $this->model->computeActualFinancials($stock, 100.0, 0.35, 10.0, 0.10, self::atTrend(['high_yield_credit_spread_ema' => self::HY_TREND - 0.002]), $mathMock);

        $this->assertSame(ShockEvent::PE_HURDLE_RATE_MISSED, $atTrend->eventType);
        $this->assertGreaterThan(0.0, $tight->streamRevenue['carried_interest']);
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
            'high_yield_credit_spread_ema' => 0.12,
        ]);

        $mathMock = new ScriptedDraws(
            generatePersistentZ: function ($prevZ, $phi) {
                return $prevZ < 0 ? -2.0 : 0.0;
            },
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            100.0,
            0.35,
            10.0,
            0.10,
            $severeMacro,
            $mathMock,
        );

        $this->assertSame(ShockEvent::PE_HURDLE_RATE_MISSED, $result->eventType);
        // Announced, so the street reads it at the event visibility the coverage profile sets, not the base one.
        $this->assertTrue($result->isPublicEvent);
        $this->assertEqualsWithDelta(0.0, $result->streamRevenue['carried_interest'] ?? 0.0, 0.001);
    }

    /**
     * Portfolio companies need rescue capital only once the floating rate rises past the headroom over its
     * through-the-cycle level, and then in proportion to the excess.
     */
    public function testRescueCapitalStartsPastTheHeadroomOverTheThroughTheCycleRate(): void
    {
        $stock = new Stock();
        $stock->setTicker('PE_CORP');
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('50.0');
        $stock->setEarningsMomentumZ(['carried_interest' => 0.0, 'management_fees' => 0.0]);
        $margin = fn(float $rateGap): float => $this->model->computeActualFinancials(
            $stock,
            100.0,
            0.40,
            10.0,
            0.05,
            self::atTrend(['policy_rate_ema' => self::NEUTRAL_RATE + $rateGap]),
            $this->mathUtility,
        )->clampedMargin;

        $headroom = PrivateEquityBusinessModel::RESCUE_RATE_HEADROOM;
        $this->assertEqualsWithDelta(0.40, $margin(0.0), 1e-12, 'At trend no capital is injected.');
        $this->assertEqualsWithDelta(0.40, $margin(0.005), 1e-12, 'Half a point over trend is inside the headroom.');
        $this->assertEqualsWithDelta(0.40, $margin($headroom), 1e-12, 'The headroom absorbs the first rise.');
        $onePoint = $margin($headroom + 0.01) - 0.40;
        $this->assertGreaterThan(0.0, $onePoint);
        $this->assertEqualsWithDelta(2.0 * $onePoint, $margin($headroom + 0.02) - 0.40, 1e-12, 'The drag grows with the excess.');
    }

    /** The treasury's expected yield is the mix's; a crash reaches the co-investments at the mark, not here. */
    public function testTreasuryYieldIsNotDraggedByVolatility(): void
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

        // 80% bonds @ 4% + 20% equity at the market cost of equity, 4% + 4.5% ERP = 0.032 + 0.017 = 0.049
        $this->assertEqualsWithDelta(0.049, $calmYield, 0.001);

        $stock = new Stock();
        $stock->setTicker('PE_CORP');
        $stock->setTotalRevenue('50000000.0');
        $stock->setTotalEquity('50000000.0');
        $stock->setCorporateTreasury('100000000.0');
        $stock->setWholesaleDebt('0.0');

        $calmIncome = $this->model->calculateInterestIncome($stock, $calmMacro);
        $panicIncome = $this->model->calculateInterestIncome($stock, $panicMacro);

        $this->assertGreaterThan(0.0, $calmIncome);
        $this->assertEqualsWithDelta($calmIncome, $panicIncome, 1e-6);
    }

    public function testDealFlowAsymmetryExpansionVsContraction(): void
    {
        $this->assertGreaterThan(
            PrivateEquityBusinessModel::DEAL_FLOW_BOOM_MULT,
            PrivateEquityBusinessModel::DEAL_FLOW_BUST_MULT,
        );
        $this->assertSame(3.0, PrivateEquityBusinessModel::DEAL_FLOW_BOOM_MULT);
        $this->assertSame(5.0, PrivateEquityBusinessModel::DEAL_FLOW_BUST_MULT);
    }

    public function testTreasury8020BondEquityAllocation(): void
    {
        $this->assertSame(0.80, PrivateEquityBusinessModel::PORTFOLIO_BOND_ALLOCATION);
        $this->assertSame(0.20, PrivateEquityBusinessModel::PORTFOLIO_EQUITY_ALLOCATION);
        $this->assertEqualsWithDelta(1.0, PrivateEquityBusinessModel::PORTFOLIO_BOND_ALLOCATION + PrivateEquityBusinessModel::PORTFOLIO_EQUITY_ALLOCATION, 0.0001);
    }

    public function testDebtExpansionCapacityGatedDuringCreditFreeze(): void
    {
        $normalHealth = DebtHealthBuilder::healthy()
            ->withCarry(0.05, 0.05, 0.03)
            ->withDebtTolerance(2.5)
            ->withCostOfCapital(0.06, 0.08)
            ->withMetrics(new DebtMetricsDTO(
                interestExpense: 2.5,
                blendedRate: 0.05,
                historicalFixedRate: 0.05,
                dynamicSpread: 0.020, // Baseline spread
                currentMarketRate: 0.05,
                wholesaleRate: 0.05,
                ebit: 30.0,
                revenue: 100.0,
                depreciation: 5.0,
                ebitda: 35.0,
            ))
            ->build();

        $frozenHealth = DebtHealthBuilder::healthy()
            ->withCarry(0.12, 0.12, 0.03)
            ->withSevereNegativeCarry()
            ->withCoverage(2.0)
            ->withDebtTolerance(2.5)
            ->withCostOfCapital(0.06, 0.08)
            ->withMetrics(new DebtMetricsDTO(
                interestExpense: 6.0,
                blendedRate: 0.12,
                historicalFixedRate: 0.05,
                dynamicSpread: 0.060, // Spread blown out to 600bps
                currentMarketRate: 0.12,
                wholesaleRate: 0.12,
                ebit: 30.0,
                revenue: 100.0,
                depreciation: 5.0,
                ebitda: 35.0,
            ))
            ->build();

        $normalCapacity = $this->model->calculateDebtExpansionCapacity(100.0, 50.0, 50.0, $normalHealth, 0.05, 30.0, 5.0);
        $frozenCapacity = $this->model->calculateDebtExpansionCapacity(100.0, 50.0, 50.0, $frozenHealth, 0.12, 30.0, 5.0);

        $this->assertGreaterThan(0.0, $normalCapacity);
        $this->assertEqualsWithDelta($normalCapacity * PrivateEquityBusinessModel::DEBT_GATE_DROUGHT_SCALAR, $frozenCapacity, 0.01);
    }

    public function testASponsorIsOnlyUnderLeveredWhereItMayStillBorrow(): void
    {
        // DebtEngine passes the sector's equity limit, which sits above the sponsor's own wholesale limit.
        $sectorTolerance = (float) \App\Data\Company\Sectors::INDUSTRY_METRICS['Private Equity']['equity_limit'];
        $this->assertGreaterThan($this->model->getWholesaleLeverageLimit(), $sectorTolerance);

        $health = DebtHealthBuilder::healthy()
            ->withCarry(0.05, 0.05, 0.03)
            ->withDebtTolerance($sectorTolerance)
            ->withCostOfCapital(0.06, 0.08)
            ->withMetrics(new DebtMetricsDTO(
                interestExpense: 2.5,
                blendedRate: 0.05,
                historicalFixedRate: 0.05,
                dynamicSpread: 0.020,
                currentMarketRate: 0.05,
                wholesaleRate: 0.05,
                ebit: 30.0,
                revenue: 100.0,
                depreciation: 5.0,
                ebitda: 35.0,
            ))
            ->build();

        // Under-levered means "should borrow", so it must never be true where there is nothing left to borrow:
        // a financial flagged under-levered skips investment and buys back stock, and one that also cannot
        // borrow is frozen there. TIER sat at 2.4x for twenty years with its book unchanged.
        for ($ratio = 0.0; $ratio <= $sectorTolerance; $ratio += 0.05) {
            if ($this->model->isUnderLeveraged($ratio, $sectorTolerance, 5.0, 1.05)) {
                $headroom = $this->model->calculateDebtExpansionCapacity(100.0, $ratio * 100.0, $ratio * 100.0, $health, 0.05, 30.0, 5.0);
                $this->assertGreaterThan(0.0, $headroom, sprintf('Under-levered at %.2fx with no capacity left to borrow.', $ratio));
            }
        }
        $this->assertFalse($this->model->isUnderLeveraged(2.4, $sectorTolerance, 5.0, 1.05));
    }

    /**
     * At trend with a flat draw, carry is the target grossed up by the share of trend quarters that clear the hurdle.
     */
    public function testTrendCarryIsGrossedUpForTheHurdleCliff(): void
    {
        $stock = new Stock();
        $stock->setTicker('PE_CORP');
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('50.0');
        $stock->setEarningsMomentumZ(['carried_interest' => 0.0, 'management_fees' => 0.0]);

        $mathMock = new ScriptedDraws(generatePersistentZ: 0.0);

        $trend = self::atTrend([
            'output_gap_ema' => 0.0,
            'deal_activity_index_ema' => MacroConstants::DEAL_ACTIVITY_BASELINE,
            'deal_activity_index_trend' => MacroConstants::DEAL_ACTIVITY_BASELINE,
        ]);
        $result = $this->model->computeActualFinancials($stock, 100.0, 0.35, 10.0, 0.10, $trend, $mathMock);

        $shockScale = 0.10 * PrivateEquityBusinessModel::REVENUE_VARIANCE_SCALAR * PrivateEquityBusinessModel::CARRY_BASE_VOLATILITY_SCALAR
            * (1.0 + (0.5 * PrivateEquityBusinessModel::CARRY_LEVERAGE_AMPLIFIER_SCALAR));
        $this->assertEqualsWithDelta(
            100.0 * 0.65 / PrivateEquityBusinessModel::expectedHurdleClearedCarry($shockScale),
            $result->streamRevenue['carried_interest'],
            1e-6,
        );
    }

    public function testDealActivityIndexStimulatesPeCarriedInterest(): void
    {
        $stock = new Stock();
        $stock->setTicker('PE_CORP');
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('50.0');
        $stock->setEarningsMomentumZ(['carried_interest' => 0.0, 'management_fees' => 0.0]);

        $mathMock = new ScriptedDraws(generatePersistentZ: 0.0);

        $baselineMacro = self::atTrend([
            'output_gap_ema' => 0.0,
            'deal_activity_index_ema' => MacroConstants::DEAL_ACTIVITY_BASELINE,
            'deal_activity_index_trend' => MacroConstants::DEAL_ACTIVITY_BASELINE,
        ]);

        $hotDealMacro = self::atTrend([
            'output_gap_ema' => 0.0,
            'deal_activity_index_ema' => MacroConstants::DEAL_ACTIVITY_BASELINE * 1.5,
            'deal_activity_index_trend' => MacroConstants::DEAL_ACTIVITY_BASELINE,
        ]);

        $baselineResult = $this->model->computeActualFinancials(
            $stock,
            100.0,
            0.35,
            10.0,
            0.10,
            $baselineMacro,
            $mathMock,
        );

        $hotDealResult = $this->model->computeActualFinancials(
            $stock,
            100.0,
            0.35,
            10.0,
            0.10,
            $hotDealMacro,
            $mathMock,
        );

        $this->assertGreaterThan(0.0, $baselineResult->streamRevenue['carried_interest']);
        $this->assertGreaterThan(
            $baselineResult->streamRevenue['carried_interest'],
            $hotDealResult->streamRevenue['carried_interest'],
        );
    }

    public function testTheDealTeamsFollowDealFlowNotTheFinancingFreezeOrTheMarks(): void
    {
        $stock = new Stock();
        $stock->setTicker('PE_TEST');
        $stock->setVolatility('0.30');
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('50.0');
        $recession = self::atTrend([
            'output_gap_ema' => -0.03,
            'deal_activity_index_ema' => MacroConstants::DEAL_ACTIVITY_BASELINE,
            'deal_activity_index_trend' => MacroConstants::DEAL_ACTIVITY_BASELINE,
        ]);

        $this->assertEqualsWithDelta(0.0, $this->model->getMacroPhysics($stock, $recession)['macro_demand_shift'], 1e-12);

        // Carry 0.65 x deal flow (-0.03 x 5) x vol 0.30 x 0.15 x 2.2 x leverage amplifier (1 + 0.5 x 0.8).
        $expected = 0.65 * (-0.03 * 5.0) * (0.30 * 0.15 * 2.2 * 1.4);
        $this->assertEqualsWithDelta($expected, $this->model->resolveSectorActivityShift($stock, $recession), 1e-9);
    }

    /**
     * Credit at its trends: the policy rate at the neutral the market reads, the interbank and HY spreads at their
     * decade trends and lending standards neutral.
     *
     * @param array<string, float> $overrides
     */
    private static function atTrend(array $overrides = []): MacroStateDTO
    {
        return MacroStateDTO::fromArray($overrides + [
            'policy_rate_ema' => self::NEUTRAL_RATE,
            'perceived_neutral_rate' => self::NEUTRAL_RATE,
            'interbank_liquidity_spread_ema' => self::INTERBANK_TREND,
            'interbank_liquidity_spread_trend' => self::INTERBANK_TREND,
            'high_yield_credit_spread_ema' => self::HY_TREND,
            'high_yield_credit_spread_trend' => self::HY_TREND,
            'sloos_tightening_index_ema' => 0.0,
        ]);
    }

    private static function sponsor(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('PE_CORP');

        return $stock;
    }
}
