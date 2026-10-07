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
        $val1 = $this->model->calculateEarningsValue(100.0, 150.0);
        $this->assertSame(150.0, $val1);

        $val2 = $this->model->calculateEarningsValue(200.0, 150.0);
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
        $stockMock = new Stock();
        $stockMock->setTotalEquity('35000000000.0');
        $stockMock->setCustomerDeposits('1300000000000.0');

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

    /**
     * The sector's 50x tolerance is room for the members' margin pool, not for the house's own bonds: a
     * clearinghouse gets no discretionary wholesale capacity however much equity it has. A brokerage handed
     * the same balance sheet still has room up to its own wholesale limit, so the zero is not a blanket cut.
     */
    public function testAClearinghouseHasNoRoomToBorrowInTheBondMarket(): void
    {
        $health = $this->health(debtTolerance: 50.0);

        $this->assertSame(0.0, $this->model->calculateDebtExpansionCapacity(90e9, 1_317e9, 17e9, $health, 0.05, 20e9, 0.5e9));

        $brokerage = new \App\Service\Model\Sector\BrokerageBusinessModel();
        $this->assertEqualsWithDelta(
            (90e9 * $brokerage->getWholesaleLeverageLimit()) - 17e9,
            $brokerage->calculateDebtExpansionCapacity(90e9, 1_317e9, 17e9, $health, 0.05, 20e9, 0.5e9),
            1.0
        );
    }

    /**
     * Fee revenue follows the capital that backs clearing, not how the house is financed: $400B of bonds
     * parked in cash leaves the revenue target where it was. Before, the interest bill was added to the
     * operating profit required, so every borrowed dollar raised the revenue that paid for it.
     */
    public function testTheRevenueTargetDoesNotDependOnHowTheHouseIsFinanced(): void
    {
        $macroState = $this->targetMacro();
        $equityFunded = $this->clearingHouse(equity: 90e9, goodwill: 0.0);
        $borrowed = $this->clearingHouse(equity: 90e9, goodwill: 0.0);
        $borrowed->setWholesaleDebt((string) 400e9);
        $borrowed->setCorporateTreasury((string) ((float) $borrowed->getCorporateTreasury() + 400e9));

        $mathMock = $this->createStub(MathUtility::class);
        $this->assertSame(
            $this->model->getTargetMetrics($equityFunded, $macroState, $mathMock),
            $this->model->getTargetMetrics($borrowed, $macroState, $mathMock)
        );
    }

    /**
     * Clearing capacity is tangible equity: goodwill absorbs no member default. The premium paid for a deal
     * adds none, and writing it off (equity and goodwill falling together) removes none.
     */
    public function testClearingCapacityIsTangibleEquitySoAGoodwillWriteOffLeavesItWhole(): void
    {
        $macroState = $this->targetMacro();
        $mathMock = $this->createStub(MathUtility::class);

        $beforeWriteOff = $this->model->getTargetMetrics($this->clearingHouse(equity: 90e9, goodwill: 60e9), $macroState, $mathMock);
        $afterWriteOff = $this->model->getTargetMetrics($this->clearingHouse(equity: 30e9, goodwill: 0.0), $macroState, $mathMock);

        $this->assertSame(30e9, $beforeWriteOff['invested_capital']);
        $this->assertSame($afterWriteOff, $beforeWriteOff);
        $this->assertEqualsWithDelta(0.22, $beforeWriteOff['baseline_roic'], 1e-12, 'the target return is earned on tangible equity');
    }

    private function clearingHouse(float $equity, float $goodwill): Stock
    {
        $stock = new Stock();
        $stock->setTicker('CCP');
        $stock->setSamRatio('1.0');
        $stock->setTotalEquity((string) $equity);
        $stock->setGoodwill((string) $goodwill);
        $stock->setCustomerDeposits((string) 1_300e9);
        $stock->setCorporateTreasury((string) 1_315e9);
        $stock->setWholesaleDebt('0');
        $stock->setOperatingMargin('0.55');
        $stock->setBaselineRoe('0.22');
        $stock->setCreditSpread('0.005');
        $stock->setFloatingDebtRatio('0.30');

        return $stock;
    }

    private function targetMacro(): MacroStateDTO
    {
        return new MacroStateDTO(policyRate: 0.03, equityRiskPremium: 0.05, corporateTaxRate: 0.20, policyRateEma: 0.03, yield5yEma: 0.04, nominalGdpIndex: 1.0);
    }

    private function health(float $debtTolerance): \App\DTO\DebtHealthDTO
    {
        return new \App\DTO\DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.04,
            cashYield: 0.03,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 20.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: $debtTolerance,
            wacc: 0.07,
            costOfEquity: 0.09,
            leveredBeta: 0.8,
            rawMetrics: new \App\DTO\DebtMetricsDTO(interestExpense: 1e9, blendedRate: 0.05, historicalFixedRate: 0.05, dynamicSpread: 0.005, currentMarketRate: 0.05, wholesaleRate: 0.05, ebit: 20e9, revenue: 40e9, depreciation: 0.5e9, ebitda: 20.5e9),
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        );
    }

    /**
     * Initial margin is positions times a VaR rate: with volatility unchanged, the pool grows with trend nominal
     * income whatever the volatility LEVEL. Read as a rate, a quiet market (below the old 0.20 baseline) bled
     * the pool away every quarter it stayed quiet.
     */
    public function testAQuietMarketNoLongerBleedsTheMarginPool(): void
    {
        $macro = new MacroStateDTO(inflationEma: 0.02, marketVolatilityEma: 0.12);
        $state = $this->rollMarginPool(1000.0, [ClearingHouseBusinessModel::STATE_MARGIN_RATE_CHANGE => 0.0], $macro);

        $trend = (0.02 + \App\Service\Macro\MacroEngine::TFP_DRIFT + \App\Service\Macro\MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE) / 4.0;
        $this->assertEqualsWithDelta(1000.0 * (1.0 + $trend), $state['customerDeposits'], 1e-9);
        $this->assertEqualsWithDelta($state['customerDeposits'], $state['treasury'], 1e-9, 'Margin arrives as cash held against it.');
    }

    /** At fixed positions, a doubling of the volatility the margin is struck on doubles the pool (VaR scales with sigma). */
    public function testTheMarginPoolScalesWithTheVolatilityItIsStruckOn(): void
    {
        $macro = new MacroStateDTO(inflationEma: 0.02, marketVolatilityEma: 0.30);
        $state = $this->rollMarginPool(1000.0, [ClearingHouseBusinessModel::STATE_MARGIN_RATE_CHANGE => log(2.0)], $macro);

        $trend = (0.02 + \App\Service\Macro\MacroEngine::TFP_DRIFT + \App\Service\Macro\MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE) / 4.0;
        $this->assertEqualsWithDelta(2000.0 * (1.0 + $trend), $state['customerDeposits'], 1e-9);
    }

    /**
     * Sector physics carries the quarter's change in the smoothed volatility to the treasury: none on the first
     * report (no phantom margin call), then the log change from the volatility the pool was last struck on.
     */
    public function testSectorPhysicsCarriesTheChangeInMarginVolatility(): void
    {
        $stock = (new Stock())->setTicker('ACC');
        $first = $this->model->computeActualFinancials($stock, 100.0, 0.30, 0.0, 0.20, new MacroStateDTO(marketVolatilityEma: 0.15), $this->quietDraws());
        $this->assertEqualsWithDelta(0.0, $first->streamZ[ClearingHouseBusinessModel::STATE_MARGIN_RATE_CHANGE], 1e-12);

        $stock->setEarningsMomentumZ($first->streamZ);
        $second = $this->model->computeActualFinancials($stock, 100.0, 0.30, 0.0, 0.20, new MacroStateDTO(marketVolatilityEma: 0.30), $this->quietDraws());
        $this->assertEqualsWithDelta(log(2.0), $second->streamZ[ClearingHouseBusinessModel::STATE_MARGIN_RATE_CHANGE], 1e-12);
    }

    /**
     * Volatility is centred where the sim's volatility lives: at the anchor, with a flat curve and the custody
     * rate at its pivot, the house books exactly its expected revenue and staffs to no swing at all.
     */
    public function testVolatilityIsNeutralAtTheAnchor(): void
    {
        $anchor = $this->clearingMacro(\App\Service\Macro\MacroEngine::MACRO_VOL_BASE_ANCHOR);
        $stock = (new Stock())->setTicker('ACC');

        $report = $this->model->computeActualFinancials($stock, 100.0, 0.30, 0.0, 0.20, $anchor, $this->quietDraws());

        $this->assertEqualsWithDelta(100.0, $report->actualRevenue, 1e-9);
        $this->assertSame(0.0, $this->model->getMacroPhysics($stock, $anchor)['macro_demand_shift']);
        $this->assertEqualsWithDelta(0.0, $this->model->resolveSectorActivityShift($stock, $anchor), 1e-12);
    }

    /** Volatility reaches fees through one channel, clearing volume: custody float and data licences do not move with it, nor does the root demand shift. */
    public function testVolatilityMovesClearingVolumeAndNothingElse(): void
    {
        $anchorVol = \App\Service\Macro\MacroEngine::MACRO_VOL_BASE_ANCHOR;
        $calm = $this->model->computeActualFinancials((new Stock())->setTicker('ACC'), 100.0, 0.30, 0.0, 0.20, $this->clearingMacro($anchorVol), $this->quietDraws());
        $busy = $this->model->computeActualFinancials((new Stock())->setTicker('ACC'), 100.0, 0.30, 0.0, 0.20, $this->clearingMacro($anchorVol + 0.10), $this->quietDraws());

        $this->assertEqualsWithDelta(
            1.0 + 0.10 * ClearingHouseBusinessModel::VIX_REVENUE_SCALAR,
            $busy->streamRevenue['clearing_fees'] / $calm->streamRevenue['clearing_fees'],
            1e-9
        );
        $this->assertEqualsWithDelta($calm->streamRevenue['custody_float'], $busy->streamRevenue['custody_float'], 1e-9);
        $this->assertEqualsWithDelta($calm->streamRevenue['data_licensing'], $busy->streamRevenue['data_licensing'], 1e-9);
        $this->assertSame(0.0, $this->model->getMacroPhysics(new Stock(), $this->clearingMacro($anchorVol + 0.10))['macro_demand_shift']);
    }

    /** A flat 2s10s curve and the custody rate at its 2% pivot, so only volatility can move fees. */
    private function clearingMacro(float $volatility): MacroStateDTO
    {
        return new MacroStateDTO(policyRateEma: 0.02, yield2yEma: 0.04, yield10yEma: 0.04, marketVolatilityEma: $volatility);
    }

    /**
     * @param array<string, float> $momentum
     * @return array{customerDeposits: float, treasury: float, events: list<array<string, mixed>>}
     */
    private function rollMarginPool(float $deposits, array $momentum, MacroStateDTO $macro): array
    {
        $stock = (new Stock())->setTicker('ACC');
        $stock->setEarningsMomentumZ($momentum);
        $state = ['customerDeposits' => $deposits, 'treasury' => $deposits, 'events' => []];
        $this->model->processPassiveLiabilityGrowth($stock, $macro, $state, $this->quietDraws());

        return $state;
    }

    private function quietDraws(): MathUtility
    {
        $math = $this->createStub(MathUtility::class);
        $math->method('generateStandardNormal')->willReturn(0.0);
        $math->method('generatePersistentZ')->willReturn(0.0);

        return $math;
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
        // At the District's $12T scale.
        $this->assertSame(25_000_000_000.00, (float) $accConfig['total_equity'], 'ACC total equity must be $25B (lean equity)');
        $this->assertSame(650_000_000_000.00, (float) $accConfig['customer_deposits'], 'ACC customer deposits must be $650B (collateral float)');
        $this->assertSame(657_500_000_000.00, (float) $accConfig['corporate_treasury'], 'ACC corporate treasury must cover 100% margin float + $7.5B operating buffer');
        $this->assertSame(7_500_000_000.00, (float) $accConfig['wholesale_debt'], 'ACC wholesale debt must be $7.5B');
        $this->assertSame(10_000_000_000.00, (float) $accConfig['retained_earnings'], 'ACC retained earnings must be $10B');
        $this->assertSame(0.26, (float) $accConfig['baseline_roe'], 'ACC baseline ROE must be 26%');

        // Extreme collateral leverage (> 25x customer deposits to equity)
        $collateralLeverage = $accConfig['customer_deposits'] / $accConfig['total_equity'];
        $this->assertGreaterThan(25.0, $collateralLeverage, 'Clearinghouses operate on extreme collateral float leverage relative to lean equity');
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

