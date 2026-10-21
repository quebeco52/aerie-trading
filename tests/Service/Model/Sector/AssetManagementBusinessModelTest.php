<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\AssetManagementBusinessModel;
use App\Service\Model\Sector\PrivateEquityBusinessModel;
use PHPUnit\Framework\TestCase;

class AssetManagementBusinessModelTest extends TestCase
{
    public function testGetTargetMetricsDoesNotDoubleLeverageOperatingYield(): void
    {
        $model = new AssetManagementBusinessModel();
        $stock = new Stock();
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('300.0');
        $stock->setCorporateTreasury('0.0');
        $stock->setBaselineRoe('0.20');
        $stock->setRoeTtm('0.0');
        $stock->setOperatingMargin('0.30');
        $stock->setCreditSpread('0.01');
        $stock->setFloatingDebtRatio('0.5');
        $stock->setIndustry('Asset Management');

        $mathUtilityMock = $this->createStub(MathUtility::class);
        $macroState = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.04,
            'corporate_tax_rate' => 0.25,
        ]);

        $metrics = $model->getTargetMetrics($stock, $macroState, $mathUtilityMock);

        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('invested_capital', $metrics);
        $this->assertArrayHasKey('baseline_roic', $metrics);

        // Invested capital should equal earning assets (Equity + WholesaleDebt - Treasury = 400.0)
        $this->assertEqualsWithDelta(400.0, $metrics['invested_capital'], 0.01);

        // Baseline ROIC should reflect structural operating return without double-leverage inflation With 20% target
        // ROE and 25% tax, target net income on 100 equity is 20, EBT is 26.67. Interest expense on 300 debt at ~5% is
        // 15, so optimal EBIT is ~41.67 across 400 earning assets (~10.42% yield).
        $this->assertLessThan(0.30, $metrics['baseline_roic']);
        $this->assertGreaterThan(0.01, $metrics['baseline_roic']);
    }

    public function testPrivateEquityBusinessModelInheritsCorrectTargetMetrics(): void
    {
        $model = new PrivateEquityBusinessModel();
        $stock = new Stock();
        $stock->setTotalEquity('100.0');
        $stock->setWholesaleDebt('200.0');
        $stock->setCorporateTreasury('10.0');
        $stock->setBaselineRoe('0.21');
        $stock->setRoeTtm('0.0');
        $stock->setOperatingMargin('0.35');
        $stock->setCreditSpread('0.015');
        $stock->setFloatingDebtRatio('0.4');
        $stock->setIndustry('Private Equity');

        $mathUtilityMock = $this->createStub(MathUtility::class);
        $macroState = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
            'corporate_tax_rate' => 0.25,
        ]);

        $metrics = $model->getTargetMetrics($stock, $macroState, $mathUtilityMock);

        $this->assertArrayHasKey('invested_capital', $metrics);
        $this->assertArrayHasKey('baseline_roic', $metrics);
        $this->assertEqualsWithDelta(290.0, $metrics['invested_capital'], 0.01);
    }

    public function testModelTraitsAndCoverageProfile(): void
    {
        $model = new AssetManagementBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHRK');

        $this->assertEquals(0.12, $model->getReversionSpeed());
        $this->assertEquals(0.012, $model->getMoatSpread());
        // Trend growth plus securities and investments value added sliding from 1.41% to 1.30% of GDP over 1997-2019.
        $this->assertEqualsWithDelta(MacroConstants::TREND_REAL_GROWTH - 0.0037, $model->getSecularGrowthRate($stock), 1e-4);

        $coverage = $model->getCoverageProfile($stock);
        $this->assertEquals(AssetManagementBusinessModel::BASE_COVERAGE_VISIBILITY, $coverage->baseVisibility);
        $this->assertEquals(AssetManagementBusinessModel::BASE_COVERAGE_ERROR, $coverage->errorStdDev);
        $this->assertEquals(AssetManagementBusinessModel::BASE_COVERAGE_MIN_VISIBILITY, $coverage->minVisibility);
    }

    public function testMacroPhysicsNullifiesGenericDemandShift(): void
    {
        $model = new AssetManagementBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHRK');
        $stock->setBeta('1.2');

        $macro = new MacroStateDTO(outputGapEma: 0.05);
        $physics = $model->getMacroPhysics($stock, $macro);

        $this->assertEquals(0.0, $physics['macro_demand_shift'], 'Asset management must nullify generic demand shift to avoid double-counting AUM beta.');
    }

    public function testPerformanceFeeSurgeAndCompensationPoolFlex(): void
    {
        $model = new AssetManagementBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHRK');
        $stock->setBeta('1.0');

        $macro = new MacroStateDTO(outputGapEma: 0.0);

        // Alpha Z = 2.50 (above 1.50 threshold), BaseFee Z = 0.0
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturnOnConsecutiveCalls(0.0, 2.50);

        $result = $model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $mathMock,
        );

        // Alpha fee bonus = (2.50 - 1.50) * 0.08 = +0.08
        // Perf revenue = 20M * (1 + 0.08) = 21.6M
        // Base revenue = 80M
        // Total revenue = 101.6M
        $this->assertGreaterThan(100_000_000.0, $result->actualRevenue);
        $this->assertGreaterThan(20_000_000.0, $result->streamRevenue['alpha']);

        // Compensation pool flex: $1.6M excess * 0.40 = $640k bonus pool expense
        // Raw variable margin increases from baseline to fund bonus pool
        $this->assertGreaterThan(0.30, $result->clampedMargin, 'Incentive fee crystallization must expand variable compensation bonus pools.');
    }

    public function testInstitutionalRedemptionOutflowAttrition(): void
    {
        $model = new AssetManagementBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHRK');
        $stock->setBeta('1.0');

        $macro = new MacroStateDTO(outputGapEma: 0.0);

        // Alpha Z = -2.50 (severe redemptions below -1.50), BaseFee Z = 0.0
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturnOnConsecutiveCalls(0.0, -2.50);

        $result = $model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0, // Zero baseline vol to isolate deterministic attrition
            macroState: $macro,
            mathUtility: $mathMock,
        );

        // Redemption excess = |-2.50 - (-1.50)| = 1.0
        // Base attrition = 1.0 * 0.04 = 0.04
        // Base revenue = 80M * (1 - 0.04) = 76.8M
        $this->assertEqualsWithDelta(76_800_000.0, $result->streamRevenue['base_fee'], 1.0);
        $this->assertLessThan(80_000_000.0, $result->streamRevenue['base_fee'], 'Institutional redemptions must erode base AUM management fee revenue.');
    }

    public function testBaseFeeRevenueMarksToTheEquityMarketLevelAgainstItsTrend(): void
    {
        $model = new AssetManagementBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHRK');
        $stock->setBeta('1.0');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $run = function (MacroStateDTO $macro) use ($model, $stock, $mathMock): float {
            return $model->computeActualFinancials(
                $stock,
                expectedRevenue: 100_000_000.0,
                realizedVariableMargin: 0.30,
                fixedCosts: 20_000_000.0,
                baselineVol: 0.0,
                macroState: $macro,
                mathUtility: $mathMock,
            )->streamRevenue['base_fee'];
        };

        // No market reported: both wealth fields at zero, the term is silent and the base fee is the plain 80M.
        $noMarket = $run(new MacroStateDTO(outputGapEma: 0.0));
        $this->assertEqualsWithDelta(80_000_000.0, $noMarket, 1.0);

        // A market 20% above its three-year trend lifts assets, and fees, by 20% x the level beta.
        $bull = $run(new MacroStateDTO(outputGapEma: 0.0, equityWealthRatio: 1.20, equityWealthTrend: 1.00));
        $this->assertEqualsWithDelta(80_000_000.0 * (1.0 + (0.20 * AssetManagementBusinessModel::AUM_MARKET_LEVEL_BETA)), $bull, 1.0);

        // A crash beyond the cap is clamped, so the fee base cannot be wiped out by a single mark.
        $crash = $run(new MacroStateDTO(outputGapEma: 0.0, equityWealthRatio: 0.20, equityWealthTrend: 1.00));
        $this->assertEqualsWithDelta(80_000_000.0 * (1.0 - (AssetManagementBusinessModel::AUM_MARKET_LEVEL_DEVIATION_CAP * AssetManagementBusinessModel::AUM_MARKET_LEVEL_BETA)), $crash, 1.0);
    }

    /** A fee manager closed at capacity never borrows to recapitalise, a buyout sponsor included. */
    public function testNoFeeManagerBorrowsToRecapitalise(): void
    {
        $this->assertFalse((new AssetManagementBusinessModel())->supportsUnderleveragedDebtExpansion());
        $this->assertFalse((new PrivateEquityBusinessModel())->supportsUnderleveragedDebtExpansion());
    }

    public function testMoneyFundMigrationIsFeeEarningInflow(): void
    {
        $model = new AssetManagementBusinessModel();
        $stock = new Stock();
        $stock->setTicker('SHRK');
        $stock->setBeta('1.0');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $run = function (float $share, float $shareEma) use ($model, $stock, $mathMock): float {
            return $model->computeActualFinancials(
                $stock,
                expectedRevenue: 100_000_000.0,
                realizedVariableMargin: 0.30,
                fixedCosts: 20_000_000.0,
                baselineVol: 0.0,
                macroState: new MacroStateDTO(outputGapEma: 0.0, moneyMarketFundShare: $share, moneyMarketFundShareEma: $shareEma),
                mathUtility: $mathMock,
            )->streamRevenue['base_fee'];
        };

        $steady = $run(0.15, 0.15);
        $migrating = $run(0.1625, 0.15);
        $this->assertEqualsWithDelta(80_000_000.0 * 0.0125 * AssetManagementBusinessModel::MMF_AUM_INFLOW_SENSITIVITY, $migrating - $steady, 1.0);
        $this->assertEqualsWithDelta($steady, $run(0.14, 0.15), 1.0, 'Share flowing back to deposits is not a fee-earning inflow.');
    }

    public function testTheCostBaseFollowsClientFlowsNotTheMarketMark(): void
    {
        $model = new AssetManagementBusinessModel();
        $stock = new Stock();
        $stock->setTicker('AM_TEST');
        // The equity market 20% under its trend marks the fee base down; no client has moved any money.
        $recession = new MacroStateDTO(
            outputGapEma: -0.03,
            equityWealthRatio: 0.8,
            equityWealthTrend: 1.0,
            moneyMarketFundShare: 0.15,
            moneyMarketFundShareEma: 0.15,
            moneySupplyGrowthEma: MacroConstants::M2_BASE_GROWTH,
        );

        $this->assertEqualsWithDelta(0.0, $model->getMacroPhysics($stock, $recession)['macro_demand_shift'], 1e-12);

        // Base fees 0.80 x net flows (-0.03 x 1.20 x 0.40); performance fees carry no macro term.
        $this->assertEqualsWithDelta(0.80 * (-0.03 * 1.20 * 0.40), $model->resolveSectorActivityShift($stock, $recession), 1e-9);
    }

    /**
     * The seed portfolio's equity earns the market cost of equity, which moves with rates: at a fixed 7% it paid the
     * same in a 0.25% world as in a 7% one.
     */
    public function testTheEquitySeedEarnsTheMarketCostOfEquity(): void
    {
        $model = new AssetManagementBusinessModel();
        $low = $model->calculateCashYield(new MacroStateDTO(yield10yEma: 0.01, equityRiskPremium: 0.045));
        $high = $model->calculateCashYield(new MacroStateDTO(yield10yEma: 0.06, equityRiskPremium: 0.045));

        // A point on the ten-year moves both tranches by a point.
        $this->assertEqualsWithDelta(0.05, $high - $low, 1e-12);
        $this->assertEqualsWithDelta((0.60 * 0.01) + (0.40 * 0.055), $low, 1e-12);
    }

    /**
     * Every fee manager closes at a capacity: an untuned one where its returns to scale start to fall, a tuned one at
     * its own. Lenders and underwriters grow wherever their returns clear the hurdle.
     */
    public function testEveryFeeManagerClosesAtACapacity(): void
    {
        $untuned = (new Stock())->setTicker('FUND');
        foreach (['Asset Management', 'Hedge Fund', 'Private Equity', 'Distressed Debt'] as $industry) {
            $this->assertSame(AssetManagementBusinessModel::DEFAULT_CAPACITY_SHARE, Sectors::strategyFor($industry)->getCapacityShare($untuned), $industry);
        }
        $this->assertSame(FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD, AssetManagementBusinessModel::DEFAULT_CAPACITY_SHARE);
        $this->assertSame(0.4035, Sectors::strategyFor('Hedge Fund')->getCapacityShare((new Stock())->setTicker('SWAN')));

        foreach (['Banks - Regional', 'Credit Services', 'Insurance - Diversified', 'Investment Banking', 'Brokerages'] as $industry) {
            $this->assertNull(Sectors::strategyFor($industry)->getCapacityShare($untuned), $industry);
        }
    }

    /**
     * A listed fee manager's capacity is the share of its market it opens with, its seeded equity over its seeded
     * market, so its franchise grows with that market and the open is its steady state.
     */
    public function testEachListedFeeManagerClosesAtTheShareItOpensWith(): void
    {
        $managers = 0;
        foreach (InitialMarket::STOCKS as $row) {
            $strategy = Sectors::strategyFor($row['industry']);
            if (!$strategy instanceof AssetManagementBusinessModel) {
                continue;
            }
            $managers++;
            $seededShare = $row['total_equity'] / (CorporateMetrics::BASELINE_SECTOR_TAM * $row['sam_ratio']);
            $capacity = $strategy->getCapacityShare((new Stock())->setTicker($row['ticker']));

            $this->assertEqualsWithDelta($seededShare, $capacity, 1e-3, $row['ticker']);
            $this->assertLessThanOrEqual(AssetManagementBusinessModel::DEFAULT_CAPACITY_SHARE, $capacity, $row['ticker']);
        }
        $this->assertSame(4, $managers, 'SWAN, TIER, CROW and VULT');
    }
}
