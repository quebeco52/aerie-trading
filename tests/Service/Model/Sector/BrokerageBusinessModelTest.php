<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\OpeningBoardBuilder;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\BrokerageBusinessModel;
use PHPUnit\Framework\TestCase;

class BrokerageBusinessModelTest extends TestCase
{
    private BrokerageBusinessModel $model;
    private MathUtility $mathUtility;
    private MacroStateDTO $opening;

    protected function setUp(): void
    {
        $this->model = new BrokerageBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testRookIsRegisteredInInitialMarketAndBrokeragesIndustry(): void
    {
        $rookConfig = null;
        foreach (InitialMarket::STOCKS as $stock) {
            if ($stock['ticker'] === 'ROOK') {
                $rookConfig = $stock;
                break;
            }
        }

        $this->assertNotNull($rookConfig, 'ROOK must be registered in InitialMarket::STOCKS');
        $this->assertSame('Rook Proprietary Trading', $rookConfig['name']);
        $this->assertSame('Financials', $rookConfig['sector']);
        $this->assertSame('Brokerages', $rookConfig['industry']);

        $industryConfig = Sectors::INDUSTRY_METRICS['Brokerages'] ?? null;
        $this->assertNotNull($industryConfig);
        $this->assertSame('brokerage', $industryConfig['business_model']);
        $this->assertTrue(Sectors::isFinancial($industryConfig['business_model']));
    }

    public function testTradingVolumeBonusScalesWithVix(): void
    {
        $stock = new Stock();
        $stock->setTicker('ROOK');
        $stock->setOperatingMargin('0.30');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        // Macro state with elevated VIX (30% against the 15% anchor)
        $macroState = MacroStateDTO::fromArray([
            'output_gap_ema' => 0.0,
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
            'corporate_tax_rate' => 0.21,
            'market_volatility_ema' => 0.30,
        ]);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 1000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 100.0,
            baselineVol: 0.20,
            macroState: $macroState,
            mathUtility: $mathMock,
        );

        // ROOK custom tuning overrides: trading_weight = 0.85, advisory_weight = 0.15
        // VIX term = (0.30 - 0.15 anchor) * 0.50 = 0.075
        // Trading revenue = 1000 * 0.85 * (1.0 + 0.075) = 913.75
        // Advisory revenue = 1000 * 0.15 * 1.0 = 150.0
        // Total expected revenue = 913.75 + 150.0 = 1063.75
        $this->assertEqualsWithDelta(1063.75, $result->actualRevenue, 0.01);
        $this->assertEqualsWithDelta(913.75, $result->streamRevenue['trading'], 0.01);
        $this->assertEqualsWithDelta(150.0, $result->streamRevenue['advisory'], 0.01);
    }

    /**
     * The volatility term is centred where the sim's volatility lives: at the anchor it moves nothing, and a
     * quiet tape thins commissions as a busy one swells them.
     */
    public function testTheVolatilityTermIsNeutralAtTheAnchor(): void
    {
        $stock = (new Stock())->setTicker('ROOK');
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);
        $anchor = new MacroStateDTO(marketVolatilityEma: MacroConstants::MACRO_VOL_BASE_ANCHOR);
        $quiet = new MacroStateDTO(marketVolatilityEma: MacroConstants::MACRO_VOL_BASE_ANCHOR - 0.05);

        $atAnchor = $this->model->computeActualFinancials($stock, 1000.0, 0.35, 100.0, 0.0, $anchor, $mathMock);
        $this->assertEqualsWithDelta(1000.0, $atAnchor->actualRevenue, 1e-9);

        $quietReport = $this->model->computeActualFinancials($stock, 1000.0, 0.35, 100.0, 0.0, $quiet, $mathMock);
        $this->assertEqualsWithDelta(
            1.0 - 0.05 * BrokerageBusinessModel::VIX_REVENUE_SCALAR,
            $quietReport->streamRevenue['trading'] / $atAnchor->streamRevenue['trading'],
            1e-9,
        );
    }

    /**
     * M2 growth over trend reaches revenue once, through the trading stream at its documented sensitivity; the root
     * demand shift does not carry it.
     */
    public function testBroadMoneyEntersTheTradingStreamOnce(): void
    {
        $stock = (new Stock())->setTicker('ROOK');
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);
        $deviation = 0.05;
        $atTrend = new MacroStateDTO(marketVolatilityEma: MacroConstants::MACRO_VOL_BASE_ANCHOR);
        $flush = new MacroStateDTO(
            marketVolatilityEma: MacroConstants::MACRO_VOL_BASE_ANCHOR,
            moneySupplyGrowthEma: MacroConstants::M2_BASE_GROWTH + $deviation,
        );

        $this->assertEqualsWithDelta(
            $this->model->getMacroPhysics($stock, $atTrend)['macro_demand_shift'],
            $this->model->getMacroPhysics($stock, $flush)['macro_demand_shift'],
            1e-12,
            'The root demand shift does not carry M2.',
        );

        $base = $this->model->computeActualFinancials($stock, 1000.0, 0.35, 100.0, 0.0, $atTrend, $mathMock);
        $liquid = $this->model->computeActualFinancials($stock, 1000.0, 0.35, 100.0, 0.0, $flush, $mathMock);
        $dTradingDm2 = ($liquid->streamRevenue['trading'] / $base->streamRevenue['trading'] - 1.0) / $deviation;
        $this->assertEqualsWithDelta(BrokerageBusinessModel::M2_RETAIL_TRADING_SENSITIVITY, $dTradingDm2, 1e-9);
        $this->assertEqualsWithDelta($base->streamRevenue['advisory'], $liquid->streamRevenue['advisory'], 1e-9);
    }

    public function testClearinghouseCashBackingRequirements(): void
    {
        $operatingBase = 1_000_000.0;
        $currentLiability = 500_000.0;
        $wholesaleDebt = 10_000_000.0;

        // Requires 15% cash backing on wholesale debt
        $targetCash = $this->model->calculateTargetOperatingCash($operatingBase, $currentLiability, $wholesaleDebt);
        $this->assertSame(1_500_000.0, $targetCash);

        // Requires 10% min cash backing floor
        $minCash = $this->model->calculateMinOperatingCash($operatingBase, $currentLiability, $wholesaleDebt);
        $this->assertSame(1_000_000.0, $minCash);
    }

    public function testInterestIncomeIncludesMarginLoanAndClientSweep(): void
    {
        $stock = new Stock();
        $stock->setWholesaleDebt('10000000');
        $stock->setCorporateTreasury('2000000');
        $stock->setTotalEquity('10000000');
        $stock->setTotalRevenue('5000000');

        $macroState = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.05,
            'corporate_tax_rate' => 0.21,
        ]);

        $interestIncome = $this->model->calculateInterestIncome($stock, $macroState);
        $this->assertGreaterThan(0.0, $interestIncome);

        // Margin loan yield = policyRate (0.05) + MARGIN_LOAN_SPREAD (0.025) = 0.075
        // Margin loan interest on $10M debt = $750,000
        // Total interest income must exceed margin interest alone due to sweep/cash yields
        $this->assertGreaterThan(750_000.0, $interestIncome);
    }

    /**
     * Margin debits are struck on the funded book, so two brokerages carrying the same funding book the same
     * margin interest whichever form that funding takes. Before, the leg read getWholesaleDebt() while
     * DebtEngine charged interest on getTotalDebt(), so a drawn revolver paid a coupon and earned nothing.
     * Treasury sits under the liquidity floor on both fixtures, so neither books an excess-cash leg.
     */
    public function testMarginInterestDependsOnTotalFundingNotItsComposition(): void
    {
        $macroState = MacroStateDTO::fromArray([
            'policy_rate_ema' => 0.05,
            'corporate_tax_rate' => 0.21,
        ]);

        $allTermNotes = new Stock();
        $allTermNotes->setWholesaleDebt('100000000');
        $allTermNotes->setCorporateTreasury('1000000');
        $allTermNotes->setTotalEquity('10000000');
        $allTermNotes->setTotalRevenue('5000000');

        $partlyOnTheRevolver = new Stock();
        $partlyOnTheRevolver->setWholesaleDebt('60000000');
        $partlyOnTheRevolver->setRevolverDrawn('40000000');
        $partlyOnTheRevolver->setCorporateTreasury('1000000');
        $partlyOnTheRevolver->setTotalEquity('10000000');
        $partlyOnTheRevolver->setTotalRevenue('5000000');

        $this->assertSame(
            (float) $allTermNotes->getTotalDebt(),
            (float) $partlyOnTheRevolver->getTotalDebt(),
            'fixture guard: both brokerages must carry the same total funding',
        );

        $this->assertEqualsWithDelta(
            $this->model->calculateInterestIncome($allTermNotes, $macroState),
            $this->model->calculateInterestIncome($partlyOnTheRevolver, $macroState),
            1.0,
        );
    }

    public function testTargetMetricsCalculatedOnEarningAssetsAndRoe(): void
    {
        $stock = new Stock();
        $stock->setTicker('ROOK');
        $stock->setIndustry('Brokerages');
        $stock->setTotalEquity('50000000');
        $stock->setWholesaleDebt('100000000');
        $stock->setCorporateTreasury('15000000');
        $stock->setBaselineRoe('0.20');
        $stock->setOperatingMargin('0.30');
        $stock->setCreditSpread('0.015');
        $stock->setFloatingDebtRatio('0.50');

        $macroState = MacroStateDTO::fromArray([
            'policy_rate' => 0.04,
            'policy_rate_ema' => 0.04,
            'yield_5y_ema' => 0.045,
            'corporate_tax_rate' => 0.21,
            'equity_risk_premium' => 0.05,
            'market_saturation_limit' => 500_000_000.0,
        ]);

        $target = $this->model->getTargetMetrics($stock, $macroState, $this->mathUtility);

        $this->assertIsArray($target);
        $this->assertArrayHasKey('invested_capital', $target);
        $this->assertArrayHasKey('baseline_roic', $target);
        $this->assertGreaterThan(0.0, $target['invested_capital']);
        $this->assertGreaterThan(0.0, $target['baseline_roic']);
    }

    public function testMacroPhysicsProducesExpectedDemandShift(): void
    {
        $stock = new Stock();
        $stock->setTicker('BRK');
        $stock->setBeta('1.40');

        $macroState = MacroStateDTO::fromArray([
            'output_gap_ema' => 0.02,
        ]);

        $macroPhysics = $this->model->getMacroPhysics($stock, $macroState);

        // Demand shift = 0.02 * operating cyclicality * 0.50; equity beta is not an operating input.
        $this->assertEqualsWithDelta(0.02 * BrokerageBusinessModel::OPERATING_CYCLICALITY * 0.50, $macroPhysics['macro_demand_shift'], 0.0001);
        $this->assertSame(1.0, $macroPhysics['pricing_power_multiplier']);
    }

    public function testWholesaleLeverageLimitMatchesOperatingCapacity(): void
    {
        $this->assertSame(8.0, $this->model->getWholesaleLeverageLimit());
    }

    public function testDealActivityExpandsAdvisoryRevenue(): void
    {
        $stock = new Stock();
        $stock->setTicker('ROOK');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baselineMacro = new MacroStateDTO(
            dealActivityIndexEma: 100.0,
            marketVolatilityEma: 0.20,
        );

        $boomMacro = new MacroStateDTO(
            dealActivityIndexEma: 140.0, // +40% surge in M&A/capital markets deal flow
            marketVolatilityEma: 0.20,
        );

        $baseResult = $this->model->computeActualFinancials($stock, 1000.0, 0.35, 100.0, 0.0, $baselineMacro, $mathMock);
        $boomResult = $this->model->computeActualFinancials($stock, 1000.0, 0.35, 100.0, 0.0, $boomMacro, $mathMock);

        $this->assertGreaterThan(
            $baseResult->streamRevenue['advisory'],
            $boomResult->streamRevenue['advisory'],
            'Elevated capital markets deal activity must boost brokerage advisory and underwriting revenue.',
        );
    }

    /**
     * A sweep pays its own beta, not the banking system's: the system's deposit pass-through does not reach the
     * broker's spread.
     */
    public function testClientSweepPaysItsOwnBetaWhateverTheSystemPassesThrough(): void
    {
        $stock = new Stock();
        $stock->setWholesaleDebt('10000000');
        $stock->setCorporateTreasury('2000000');
        $stock->setTotalEquity('10000000');
        $stock->setTotalRevenue('5000000');

        $lowBeta = MacroStateDTO::fromArray(['policy_rate_ema' => 0.05, 'corporate_tax_rate' => 0.21, 'system_deposit_beta_ema' => 0.10]);
        $highBeta = MacroStateDTO::fromArray(['policy_rate_ema' => 0.05, 'corporate_tax_rate' => 0.21, 'system_deposit_beta_ema' => 0.40]);

        $this->assertEqualsWithDelta(
            $this->model->calculateInterestIncome($stock, $lowBeta),
            $this->model->calculateInterestIncome($stock, $highBeta),
            1e-6,
        );
    }

    /**
     * Commissions are paid per trade: a stamp duty that thins turnover by a tenth thins the trading stream by a tenth,
     * and leaves advisory alone.
     */
    public function testAStampDutyThinsTradingCommissions(): void
    {
        $stock = new Stock();
        $stock->setTicker('ROOK');
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $founding = $this->model->computeActualFinancials($stock, 1000.0, 0.35, 100.0, 0.0, new MacroStateDTO(marketVolatilityEma: 0.20), $mathMock);
        $taxed = $this->model->computeActualFinancials($stock, 1000.0, 0.35, 100.0, 0.0, new MacroStateDTO(marketVolatilityEma: 0.20, stampDutyRate: \App\Service\Math\FinancialConstants::STAMP_DUTY_RATE + 0.001), $mathMock);

        $this->assertEqualsWithDelta(0.9, $taxed->streamRevenue['trading'] / $founding->streamRevenue['trading'], 1e-3);
        $this->assertEqualsWithDelta($founding->streamRevenue['advisory'], $taxed->streamRevenue['advisory'], 1e-9);
    }

    /**
     * What the market prices a change in the duty against is what the commissions leave over their variable cost: the
     * year's revenue times the share the report keeps, so a duty that thins turnover takes that base times the factor's
     * fall off the year's earnings before fixed costs.
     */
    public function testTheMarketsBaseIsWhatTheCommissionsLeave(): void
    {
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);
        $duty = \App\Service\Math\FinancialConstants::STAMP_DUTY_RATE + 0.001;
        $contribution = static fn(\App\DTO\ActualFinancialsDTO $report): float => $report->actualRevenue - $report->actualVariableCosts;

        $founding = $this->model->computeActualFinancials((new Stock())->setTicker('ROOK'), 1000.0, 0.35, 100.0, 0.0, new MacroStateDTO(marketVolatilityEma: 0.20), $mathMock);
        $taxed = $this->model->computeActualFinancials((new Stock())->setTicker('ROOK'), 1000.0, 0.35, 100.0, 0.0, new MacroStateDTO(marketVolatilityEma: 0.20, stampDutyRate: $duty), $mathMock);
        $stock = (new Stock())->setTicker('ROOK')->setTotalRevenue((string) (4.0 * $founding->actualRevenue))->setEarningsMomentumZ($founding->streamZ);

        $this->assertEqualsWithDelta(
            $contribution($taxed) - $contribution($founding),
            $this->model->annualStampDutyTurnoverBase($stock) / 4.0 * (MacroTransmission::calculateStampDutyVolumeFactor($duty) - 1.0),
            1e-6,
        );
        $this->assertSame(0.0, $this->model->annualStampDutyTurnoverBase($stock->setEarningsMomentumZ(null)), 'Before its first report nothing is known to move with turnover.');
    }

    /** A ROOK-shaped broker: a margin book funded by its equity and a wholesale layer about its size. */
    private function marginBroker(string $baselineRoe = '0.20'): Stock
    {
        return (new Stock())->setTicker('MRGN')->setIndustry('Brokerages')
            ->setTotalEquity('17500000000')->setWholesaleDebt('20000000000')->setCustomerDeposits('0')->setCorporateTreasury('3000000000')
            ->setBaselineRoe($baselineRoe)->setOperatingMargin('0.34')->setCreditSpread('0.032')->setFloatingDebtRatio('0.85');
    }

    /**
     * The return the broker books on a macro: operating profit on its struck yield plus the interest it nets, after
     * tax, its notes at the coupon they would refinance at.
     */
    private function bookedReturn(BrokerageBusinessModel $model, Stock $stock, MacroStateDTO $macro): float
    {
        $fundingRate = DebtEngine::chargedWholesaleRate($stock, $macro, $model->getLossGivenDefault(), true);
        $operatingYield = (new \ReflectionMethod($model, 'resolveOperatingYield'))->invoke($model, $stock, $this->mathUtility);
        $operatingProfit = $operatingYield * $model->resolveEarningAssets($stock) * (float) $stock->getOperatingMargin();
        $netInterest = $model->calculateInterestIncome($stock, $macro, $fundingRate) - ((float) $stock->getTotalDebt() * $fundingRate);

        return ($operatingProfit + $netInterest) * (1.0 - $macro->corporateTaxRate) / $stock->getTangibleEquity();
    }

    /**
     * At the macro its operating yield is struck at, with its notes rolled, the broker books its structural return: the
     * target nets the interest its books show, margin interest on the whole book at its funding rate plus the sweep and
     * the cash. Netted against margin interest on its debt alone at the policy rate, ROOK booked 31% against a 20%
     * target.
     */
    public function testTheOperatingTargetNetsTheInterestTheBookBooks(): void
    {
        $stock = $this->marginBroker();
        $opening = new MacroStateDTO();
        $target = (new \ReflectionMethod($this->model, 'resolveStructuralTargetRoe'))->invoke($this->model, $stock, $opening);

        $this->assertEqualsWithDelta($target, $this->bookedReturn($this->model, $stock, $opening), 1e-9);
    }

    /** The target reads the interest the model books through the one function: more interest, less operating profit. */
    public function testTheTargetReadsTheModelsOwnInterestIncome(): void
    {
        $extra = 1_000_000_000.0;
        $richer = new class ($extra) extends BrokerageBusinessModel {
            public function __construct(private readonly float $extra) {}

            public function calculateInterestIncome(Stock $stock, MacroStateDTO $macroState, ?float $realizedWholesaleRate = null): float
            {
                return parent::calculateInterestIncome($stock, $macroState, $realizedWholesaleRate) + $this->extra;
            }
        };
        $stock = $this->marginBroker();
        $operatingProfit = fn(BrokerageBusinessModel $model): float => (new \ReflectionMethod($model, 'resolveOperatingYield'))->invoke($model, $stock, $this->mathUtility)
            * $model->resolveEarningAssets($stock) * 0.34;

        $this->assertEqualsWithDelta($operatingProfit($this->model) - $extra, $operatingProfit($richer), 1.0);
    }

    /**
     * Struck once, the operating yield holds through the rate cycle and the interest moves: a point on the policy rate
     * leaves commissions where they were and raises the return through the equity-funded margin book and the sweep.
     */
    public function testAHigherRateRaisesTheBrokersReturnThroughItsInterestIncome(): void
    {
        $stock = $this->marginBroker();
        $opening = new MacroStateDTO();
        $raised = new MacroStateDTO(policyRateEma: $opening->policyRateEma + 0.01);
        $stock->setEarningsMomentumZ($this->model->computeActualFinancials($stock, 1_000_000_000.0, 0.50, 0.0, 0.0, $opening, $this->mathUtility)->streamZ);

        $this->assertEqualsWithDelta($this->model->getTargetMetrics($stock, $opening, $this->mathUtility)['baseline_roic'], $this->model->getTargetMetrics($stock, $raised, $this->mathUtility)['baseline_roic'], 1e-12);
        $this->assertGreaterThan($this->bookedReturn($this->model, $stock, $opening), $this->bookedReturn($this->model, $stock, $raised));
    }

    /**
     * The margin book is lent at what the borrowing costs on the part the borrowing funds and at the refinancing rate
     * on the part the equity funds, and the operating yield is struck on that rate, so a broker whose notes carry a
     * coupon far from the market opens on its structural return and stays there as they roll: the coupon passes
     * through.
     */
    public function testABrokerWhoseCouponIsOffTheMarketOpensOnTargetAndStaysThereAsItsNotesRoll(): void
    {
        foreach ([0.02, 0.08] as $coupon) {
            $stock = $this->openRook(['historical_fixed_rate' => $coupon]);
            $marketCoupon = $this->opening->yield5yEma + DebtEngine::throughTheCycleCreditSpread($stock, $this->opening, $this->model->getLossGivenDefault());
            $structural = $this->structuralReturn($stock);

            $this->assertNotEqualsWithDelta($marketCoupon, $coupon, 0.01);
            $this->assertGreaterThan((float) $stock->getTotalDebt(), $this->model->resolveEarningAssets($stock), 'fixture guard: the borrowing is all lent on');
            $this->assertEqualsWithDelta($structural, $this->openingReturn($stock), 1e-9, sprintf('A %.0f%% coupon opens on target', 100 * $coupon));

            $stock->setHistoricalFixedRate((string) $marketCoupon);
            $this->assertEqualsWithDelta($structural, $this->openingReturn($stock), 1e-5, sprintf('A %.0f%% coupon, rolled', 100 * $coupon));
        }
    }

    /**
     * Cash past the equity is borrowed and not lent on, so the coupon gap on that borrowing is the broker's, as a
     * fund's is on all of its: notes cheaper than the market earn it the saving until they roll, dearer ones cost it.
     */
    public function testBorrowedCashTheBookDoesNotLendOnEarnsTheCouponGap(): void
    {
        $lossGivenDefault = $this->model->getLossGivenDefault();
        foreach ([0.02, 0.08] as $coupon) {
            $stock = $this->openRook(['historical_fixed_rate' => $coupon, 'corporate_treasury' => 20_000_000_000.00]);
            $idleBorrowing = (float) $stock->getTotalDebt() - $this->model->resolveEarningAssets($stock);
            $paid = DebtEngine::chargedWholesaleRate($stock, $this->opening, $lossGivenDefault);
            $rolled = DebtEngine::chargedWholesaleRate($stock, $this->opening, $lossGivenDefault, true);
            $couponGap = $idleBorrowing * ($rolled - $paid) * (1.0 - $this->opening->corporateTaxRate) / $stock->getTangibleEquity();

            $this->assertGreaterThan(0.0, $idleBorrowing, 'fixture guard: some of the cash is borrowed');
            $this->assertSame($rolled > $paid, $couponGap > 0.0, 'Cheap notes earn the saving.');
            $this->assertEqualsWithDelta($this->structuralReturn($stock) + $couponGap, $this->openingReturn($stock), 1e-9, sprintf('A %.0f%% coupon', 100 * $coupon));
        }
    }

    /**
     * ROOK as the seed opens it, with its operating yield struck.
     *
     * @param array<string, mixed> $overrides Seed fields to open it with instead.
     */
    private function openRook(array $overrides): Stock
    {
        $seed = null;
        foreach (InitialMarket::STOCKS as $row) {
            if ($row['ticker'] === 'ROOK') {
                $seed = $row;
            }
        }
        $this->assertNotNull($seed);
        $this->opening = \App\Tests\Support\OpeningCreditAtTrend::macro();
        $builder = new OpeningBoardBuilder($this->mathUtility, new DebtEngine($this->mathUtility, CorporateMetrics::getInstance()), new MarketEngine($this->mathUtility), new AnchorStakeLedger());
        $stock = (new Stock())->setTicker('ROOK');
        $builder->open($stock, $overrides + $seed, $this->opening);
        $stock->setEarningsMomentumZ([BrokerageBusinessModel::STATE_OPERATING_YIELD => (new \ReflectionMethod($this->model, 'resolveOperatingYield'))->invoke($this->model, $stock, $this->mathUtility)]);

        return $stock;
    }

    private function structuralReturn(Stock $stock): float
    {
        return (new \ReflectionMethod($this->model, 'resolveStructuralTargetRoe'))->invoke($this->model, $stock, $this->opening);
    }

    /**
     * The return it books at the opening macro on the interest DebtEngine charges it and the interest its books earn.
     */
    private function openingReturn(Stock $stock): float
    {
        $target = $this->model->getTargetMetrics($stock, $this->opening, $this->mathUtility);
        $debt = (new DebtEngine($this->mathUtility, CorporateMetrics::getInstance()))->calculateInterestExpense($stock, $this->opening);
        $operatingProfit = $target['invested_capital'] * $target['baseline_roic'] / (1.0 - $this->opening->corporateTaxRate);
        $netInterest = $this->model->calculateInterestIncome($stock, $this->opening, $debt->wholesaleRate) - $debt->interestExpense;

        return ($operatingProfit + $netInterest) * (1.0 - $this->opening->corporateTaxRate) / $stock->getTangibleEquity();
    }

    /** The floor and the ceiling still bind: interest alone past a low target, and an absurd target. */
    public function testTheOperatingYieldStaysBetweenItsFloorAndCeiling(): void
    {
        $yield = fn(Stock $stock): float => (new \ReflectionMethod($this->model, 'resolveOperatingYield'))->invoke($this->model, $stock, $this->mathUtility);

        $this->assertEqualsWithDelta(BrokerageBusinessModel::MIN_OPERATING_EBIT_YIELD / 0.34, $yield($this->marginBroker('0.01')->setWholesaleDebt('0')), 1e-9);
        $this->assertEqualsWithDelta(BrokerageBusinessModel::MAX_GROSS_ASSET_YIELD, $yield($this->marginBroker('50.0')), 1e-9);
    }
}
