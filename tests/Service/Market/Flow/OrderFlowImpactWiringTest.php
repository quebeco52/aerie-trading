<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Flow;

use App\Data\StrategicHoldings;
use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Service\Corporate\CorporateActionEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\MergerAndAcquisitionEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\Flow\InMemoryOrderFlowStore;
use App\Service\Market\Pricing\LiquidityEngine;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\StockTracker;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The seam between the two halves of the microstructure work: a fill recorded on the web process has to
 * reach the price on the ticker process.
 *
 * Each half is tested on its own elsewhere. This is the join, and a join is where a feature quietly does
 * nothing: the store could accumulate perfectly and the tracker could apply impact perfectly while never
 * being wired to each other, and every other test would still pass.
 *
 * The price engine is stubbed to return the price it was given, so anything that moves is the order flow
 * and not the diffusion.
 */
#[AllowMockObjectsWithoutExpectations]
class OrderFlowImpactWiringTest extends TestCase
{
    private InMemoryOrderFlowStore $orderFlow;
    private LiquidityEngine $liquidity;

    protected function setUp(): void
    {
        $this->orderFlow = new InMemoryOrderFlowStore();
        $this->liquidity = new LiquidityEngine(new MathUtility());
    }

    /** A healthy, unremarkable balance sheet: nothing here is what the tests are about. */
    private function debtHealth(): \App\DTO\DebtHealthDTO
    {
        return new \App\DTO\DebtHealthDTO(
            grossCost: 0.05,
            effectiveCost: 0.04,
            cashYield: 0.02,
            isNegativeCarry: false,
            isSevereNegativeCarry: false,
            interestCoverage: 5.0,
            wantsToPaydownDebt: false,
            canIssueDebt: true,
            debtTolerance: 2.0,
            wacc: 0.08,
            costOfEquity: 0.10,
            leveredBeta: 1.0,
            rawMetrics: new \App\DTO\DebtMetricsDTO(
                interestExpense: 0.0,
                blendedRate: 0.05,
                historicalFixedRate: 0.05,
                dynamicSpread: 0.01,
                currentMarketRate: 0.05,
                wholesaleRate: 0.05,
                ebit: 1000.0,
                revenue: 5000.0,
                depreciation: 100.0,
                ebitda: 1100.0
            ),
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        );
    }

    private function tracker(
        ?MarketPricingContext &$captured = null,
        ?\App\Service\Market\Agent\AgentStateStoreInterface $agentStateStore = null,
        ?\Closure $onEarnings = null
    ): StockTracker {
        $marketEngine = $this->createStub(MarketEngine::class);
        $marketEngine->method('calculateNextPrice')->willReturnCallback(
            static function (MarketPricingContext $context) use (&$captured): array {
                $captured = $context;

                // The diffusion is a flat line here, so the only thing that can move the price is flow.
                return [
                    'price' => $context->currentPrice,
                    'shock' => null,
                    'next_volatility' => $context->currentVolatility,
                    'analyst_targets' => [],
                    'perceived_fair_value' => $context->currentPrice,
                    'dynamic_reversion' => 0.0,
                ];
            }
        );

        $corporateActions = $this->createStub(CorporateActionEngine::class);
        $corporateActions->method('processSplits')->willReturnCallback(
            static fn (Stock $stock, float $price, float $shares): array => [
                'price' => $price,
                'shares' => $shares,
                'event' => null,
            ]
        );

        $debtEngine = $this->createStub(DebtEngine::class);
        $debtEngine->method('analyzeDebtHealth')->willReturn($this->debtHealth());

        $earnings = $this->createStub(EarningsEngine::class);
        // A report can issue or retire stock, or pay a dividend; a test that needs one hands in what the report does to
        // the company, and the events it raises if any.
        $earnings->method('calculate')->willReturnCallback(static function (Stock $stock) use ($onEarnings): ?array {
            $events = $onEarnings !== null ? $onEarnings($stock) : null;

            return is_array($events) ? $events : null;
        });
        $earnings->method('evaluatePreAnnouncement')->willReturn([]);

        $ma = $this->createStub(MergerAndAcquisitionEngine::class);
        $ma->method('evaluatePrivateAcquisition')->willReturn(null);
        $ma->method('evaluateCorporateDivestiture')->willReturn(null);

        return new StockTracker(
            $marketEngine,
            $earnings,
            $corporateActions,
            $ma,
            $this->createStub(MarketEventPublisher::class),
            $debtEngine,
            $this->createStub(CorporateMetrics::class),
            $this->liquidity,
            $this->orderFlow,
            new \App\Service\Market\Agent\AgentFlowEngine(
                new \App\Service\Market\Agent\AgentPopulation(),
                $agentStateStore ?? new \App\Service\Market\Agent\InMemoryAgentStateStore(),
                new \App\Service\Market\Flow\InMemoryOrderFlowStore(),
                []
            ),
            // Stubbed maths, so checkProbability() is false and no succession fires in tests that
            // are about something else.
            new \App\Service\Corporate\ManagementSuccessionEngine(
                $this->createStub(\App\Service\Event\MarketEventPublisher::class),
                $this->createStub(\App\Service\Math\MathUtility::class)
            )
        );
    }

    public function testOneTickOpensAndCommitsTheAgentBookExactlyOnceHoweverManyNamesItCovers(): void
    {
        // The agent store is told where a tick starts and ends so it can serve the whole tick from one
        // bulk read and one bulk write. Per name, the two round trips were most of the tick budget.
        $store = $this->createMock(\App\Service\Market\Agent\AgentStateStoreInterface::class);
        $store->expects($this->once())->method('beginBatch');
        $store->expects($this->once())->method('commitBatch');

        $second = $this->stock()->setTicker('BETA');

        $this->tracker(agentStateStore: $store)
            ->updateStocks([$this->stock(), $second], 1.0 / 14400.0, false, new MacroStateDTO());
    }

    private function stock(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('APEX')
            ->setSector('Information Technology')
            ->setIndustry('Software - Infrastructure')
            ->setPrice('100.00')
            ->setSharesOutstanding('100000000')
            ->setPublicFloatPercentage('0.90')
            ->setEarningsPerShare('5.00')
            ->setVolatility('0.28')
            ->setCurrentVolatility('0.28')
            ->setBeta('1.00')
            ->setJumpIntensity('2.0')
            ->setJumpVol('0.10');

        $stock->setTurnoverRatio(LiquidityEngine::structuralTurnoverRatio(0.28));
        $stock->setImpactVarianceEma(0.0);

        return $stock;
    }

    public function testNetBuyingLiftsThePriceAndNetSellingLowersIt(): void
    {
        $stock = $this->stock();
        $participation = $this->liquidity->averageDailyVolume($stock) * 0.20;

        $this->orderFlow->record('APEX', $participation);
        $this->tracker()->updateStocks([$stock], 1.0 / 14400.0, false, new MacroStateDTO());
        $afterBuying = (float) $stock->getPrice();

        $this->assertGreaterThan(100.0, $afterBuying);

        $stock->setPrice('100.00');
        $this->orderFlow->record('APEX', -$participation);
        $this->tracker()->updateStocks([$stock], 1.0 / 14400.0, false, new MacroStateDTO());

        $this->assertLessThan(100.0, (float) $stock->getPrice());
    }

    public function testTheAppliedMoveIsExactlyTheEnginesPermanentImpact(): void
    {
        // Only the permanent leg belongs here. The temporary leg was already paid by whoever traded, as
        // slippage on their own fill; applying it again would charge it twice and leave it in the quote.
        $stock = $this->stock();
        $quantity = $this->liquidity->averageDailyVolume($stock) * 0.35;
        $expected = 100.0 * exp($this->liquidity->permanentImpact($stock, $quantity));

        $this->orderFlow->record('APEX', $quantity);
        $this->tracker()->updateStocks([$stock], 1.0 / 14400.0, false, new MacroStateDTO());

        $this->assertEqualsWithDelta($expected, (float) $stock->getPrice(), 1e-9);
    }

    public function testARepurchaseProgramIsWorkedAtTheTenBEighteenPaceThroughTheImpactChannel(): void
    {
        // A quarter's buyback used to be an invented per-report shock. Now it is a backlog of shares the
        // company has to buy, executed at no more than a quarter of ADV a day and charged the same
        // permanent impact as any other buyer of that many shares.
        $stock = $this->stock();
        $dt = 1.0 / 14400.0;
        $stepDays = $dt * FinancialConstants::TRADING_DAYS_PER_YEAR;
        $adv = $this->liquidity->averageDailyVolume($stock);
        $program = $adv * 3.0; // Three days of volume: far more than one tick may execute.
        $stock->setCorporateFlowBacklog($program);

        $expectedSlice = $adv * FinancialConstants::CORPORATE_FLOW_MAX_ADV_SHARE_PER_DAY * $stepDays;
        $expectedPrice = 100.0 * exp($this->liquidity->permanentImpact($stock, $expectedSlice));

        $this->tracker()->updateStocks([$stock], $dt, false, new MacroStateDTO());

        $this->assertEqualsWithDelta($expectedPrice, (float) $stock->getPrice(), 1e-9);
        $this->assertEqualsWithDelta($program - $expectedSlice, $stock->getCorporateFlowBacklog(), 1e-6, 'The slice executed comes off the backlog.');
    }

    public function testAnOfferingsFlowbackSellsDownAndASmallProgramFinishesInOneTick(): void
    {
        $stock = $this->stock();
        $dt = 1.0 / 14400.0;
        $stepDays = $dt * FinancialConstants::TRADING_DAYS_PER_YEAR;
        $capacity = $this->liquidity->averageDailyVolume($stock) * FinancialConstants::CORPORATE_FLOW_MAX_ADV_SHARE_PER_DAY * $stepDays;

        // Issued stock is a negative backlog: it is distributed, and the price goes down.
        $stock->setCorporateFlowBacklog(-$capacity * 0.5);
        $this->tracker()->updateStocks([$stock], $dt, false, new MacroStateDTO());

        $this->assertLessThan(100.0, (float) $stock->getPrice());
        $this->assertSame(0.0, $stock->getCorporateFlowBacklog(), 'A program smaller than the tick capacity completes in the tick.');
    }

    public function testFlowThatNetsToZeroLeavesThePriceAlone(): void
    {
        // Two traders crossing move the price by nothing: the shares changed hands with no net demand.
        $stock = $this->stock();

        $this->orderFlow->record('APEX', 500000.0);
        $this->orderFlow->record('APEX', -500000.0);
        $this->tracker()->updateStocks([$stock], 1.0 / 14400.0, false, new MacroStateDTO());

        $this->assertEqualsWithDelta(100.0, (float) $stock->getPrice(), 1e-9);
    }

    public function testTheSameFlowCannotMoveThePriceTwice(): void
    {
        $stock = $this->stock();
        $tracker = $this->tracker();

        $this->orderFlow->record('APEX', $this->liquidity->averageDailyVolume($stock) * 0.20);
        $tracker->updateStocks([$stock], 1.0 / 14400.0, false, new MacroStateDTO());
        $afterFirstTick = (float) $stock->getPrice();

        $tracker->updateStocks([$stock], 1.0 / 14400.0, false, new MacroStateDTO());

        $this->assertSame($afterFirstTick, (float) $stock->getPrice(), 'A drained quantity must not be applied again.');
    }

    public function testImpactVarianceIsMeasuredAndHandedBackToTheDiffusion(): void
    {
        $stock = $this->stock();
        $tracker = $this->tracker($captured);

        $this->assertSame(0.0, $stock->getImpactVarianceEma());

        $this->orderFlow->record('APEX', $this->liquidity->averageDailyVolume($stock) * 0.20);
        $tracker->updateStocks([$stock], 1.0 / 14400.0, false, new MacroStateDTO());

        $measured = $stock->getImpactVarianceEma();
        $this->assertGreaterThan(0.0, $measured, 'Flow that moved the price must be recorded as variance it supplied.');

        // The next tick hands the measurement to the price engine, which is where the diffusion gives back
        // what the flow supplied.
        $tracker->updateStocks([$stock], 1.0 / 14400.0, false, new MacroStateDTO());

        $this->assertEqualsWithDelta($measured, $captured->orderFlowVariance, 1e-12);
    }

    public function testTheVarianceEstimateDecaysOnceTheFlowStops(): void
    {
        // A name heavily traded a year ago must not keep reclaiming variance it no longer supplies.
        $stock = $this->stock();
        $tracker = $this->tracker();
        $dt = 1.0 / 14400.0;

        $this->orderFlow->record('APEX', $this->liquidity->averageDailyVolume($stock) * 0.50);
        $tracker->updateStocks([$stock], $dt, false, new MacroStateDTO());

        $peak = $stock->getImpactVarianceEma();

        for ($tick = 0; $tick < 5000; $tick++) {
            $tracker->updateStocks([$stock], $dt, false, new MacroStateDTO());
        }

        $this->assertLessThan($peak * 0.5, $stock->getImpactVarianceEma());
    }

    /**
     * The trailing window an index screen ranks on measures what the TAPE printed.
     *
     * It is a different number from `currentVolatility`, which is the variance process's state, and the
     * difference is the whole reason it exists: a screen that ranks on the state admits a name for being
     * about to be quiet rather than for having been quiet, and reconstitutes itself on every spike.
     */
    public function testRealizedVolatilityMeasuresWhatTheTapePrinted(): void
    {
        $stock = $this->stock();
        $tracker = $this->tracker();
        $dt = 1.0 / 14400.0;

        // The price engine is stubbed to hand back the price it was given, so the only thing that can move
        // this name is the order flow — which makes the return of the tick a figure the test knows.
        $before = (float) $stock->getPrice();
        $this->orderFlow->record('APEX', $this->liquidity->averageDailyVolume($stock) * 0.20);
        $tracker->updateStocks([$stock], $dt, false, new MacroStateDTO());

        $realized = log((float) $stock->getPrice() / $before);
        $this->assertGreaterThan(0.0, $realized);

        // One tick into an EMA opened at zero: the reading is the tick's annualized variance times the
        // weight a single tick carries in a window a year long.
        $weight = 1.0 - exp(-$dt / FinancialConstants::INDEX_TRAILING_VOLATILITY_YEARS);
        $this->assertEqualsWithDelta(
            (($realized * $realized) / $dt) * $weight,
            (float) $stock->getRealizedVarianceEma(),
            1e-12
        );
    }

    /** A name that has stopped moving stops reading as volatile, or the screen ranks on ancient history. */
    public function testRealizedVolatilityDecaysOnceTheNameSettlesDown(): void
    {
        $stock = $this->stock();
        $tracker = $this->tracker();
        $dt = 1.0 / 14400.0;

        $this->orderFlow->record('APEX', $this->liquidity->averageDailyVolume($stock) * 0.50);
        $tracker->updateStocks([$stock], $dt, false, new MacroStateDTO());

        $peak = (float) $stock->getRealizedVarianceEma();
        $this->assertGreaterThan(0.0, $peak);

        // A quarter of quiet ticks, which is one review's worth of the window.
        for ($tick = 0; $tick < 3600; $tick++) {
            $tracker->updateStocks([$stock], $dt, false, new MacroStateDTO());
        }

        $this->assertLessThan($peak * 0.80, (float) $stock->getRealizedVarianceEma());
    }

    public function testEveryQuoteCarriesTheVolumeAndDepthTheBarIsBuiltFrom(): void
    {
        $stock = $this->stock();
        $result = $this->tracker()->updateStocks([$stock], 1.0 / 14400.0, false, new MacroStateDTO());

        $update = $result['updates'][0];

        $this->assertArrayHasKey('volume', $update);
        $this->assertGreaterThan(0.0, $update['volume'], 'A tick with no player flow still prints background volume.');
        $this->assertEqualsWithDelta($this->liquidity->averageDailyVolume($stock), $update['adv_shares'], 1.0);
        // The field carries the FULL quoted spread, which is what the UI labels it as; it was named for the
        // half spread while being published at twice it.
        $this->assertGreaterThan(0.0, $update['spread_bps']);
        $this->assertEqualsWithDelta(
            $this->liquidity->halfSpreadFraction($stock) * 20000.0,
            $update['spread_bps'],
            0.01
        );
    }

    public function testPlayerFillsAreCountedInTheTicksPrintedVolume(): void
    {
        $stock = $this->stock();
        $flow = $this->liquidity->averageDailyVolume($stock) * 0.50;

        $this->orderFlow->record('APEX', $flow);
        $result = $this->tracker()->updateStocks([$stock], 1.0 / 14400.0, false, new MacroStateDTO());

        $this->assertGreaterThan($flow, $result['updates'][0]['volume']);
    }

    public function testHistoryRowsCarryTheTickerTheBarIsKeyedBy(): void
    {
        // The ticker folds bars by ticker, so a history row without one cannot find its own open and high.
        $stock = $this->stock();
        $result = $this->tracker()->updateStocks([$stock], 1.0 / 14400.0, true, new MacroStateDTO());

        $this->assertSame('APEX', $result['history'][0]['ticker']);
        $this->assertArrayHasKey('price', $result['history'][0]);
    }

    public function testAStockWithNoFlowKeepsItsCalibratedDiffusionUntouched(): void
    {
        // The budget must be measured, not assumed. An assumed participation rate would quietly suppress
        // the volatility of every name nobody trades — which is most of the market.
        $stock = $this->stock();
        $tracker = $this->tracker($captured);

        $tracker->updateStocks([$stock], 1.0 / 14400.0, false, new MacroStateDTO());

        $this->assertSame(0.0, $captured->orderFlowVariance);
        $this->assertSame(0.0, $stock->getImpactVarianceEma());
        $this->assertEqualsWithDelta(100.0, (float) $stock->getPrice(), 1e-12);
    }

    // --- Sovereign Fund Execution ---

    /** A second name with a different price, share count and float, so a pro-rata split is visible. */
    private function secondStock(): Stock
    {
        $stock = $this->stock()->setTicker('BETA');
        $stock->setPrice('50.00')->setSharesOutstanding('200000000')->setPublicFloatPercentage('0.50');
        $stock->setTurnoverRatio(LiquidityEngine::structuralTurnoverRatio(0.28));

        return $stock;
    }

    public function testTheSovereignFundsTradeIsSpreadOverTheFloat(): void
    {
        $apex = $this->stock();
        $beta = $this->secondStock();
        $boardFloat = (100.0 * 1.0e8 * 0.90) + (50.0 * 2.0e8 * 0.50);
        $trade = 0.002 * $boardFloat;

        // Each name takes its float's share of the currency traded, in shares at the price the tick opens on.
        $expectedApex = $this->liquidity->permanentImpact($apex, $trade * (9.0e9 / $boardFloat) / 100.0);
        $expectedBeta = $this->liquidity->permanentImpact($beta, $trade * (5.0e9 / $boardFloat) / 50.0);

        $this->tracker()->updateStocks(
            [$apex, $beta],
            1.0 / 14400.0,
            false,
            new MacroStateDTO(sovereignFundTrade: $trade, boardFloatCap: $boardFloat)
        );

        $this->assertGreaterThan(0.0, $expectedApex);
        $this->assertEqualsWithDelta(100.0 * exp($expectedApex), (float) $apex->getPrice(), 1e-9);
        $this->assertEqualsWithDelta(50.0 * exp($expectedBeta), (float) $beta->getPrice(), 1e-9);
    }

    public function testTheSovereignFundsImpactIsNotChargedToTheIdiosyncraticBudget(): void
    {
        $stock = $this->stock();
        $boardFloat = 100.0 * 1.0e8 * 0.90;

        $this->tracker()->updateStocks(
            [$stock],
            1.0 / 14400.0,
            false,
            new MacroStateDTO(sovereignFundTrade: 0.002 * $boardFloat, boardFloatCap: $boardFloat)
        );

        $this->assertGreaterThan(100.0, (float) $stock->getPrice(), 'The fund\'s buying moves the price.');
        $this->assertSame(0.0, $stock->getImpactVarianceEma(), 'A flow that hits every name at once is systematic, not this name\'s own variance.');
    }

    public function testTheBoardIsReportedAsTheSovereignFundReadsIt(): void
    {
        $apex = $this->stock();
        $beta = $this->secondStock();
        $boardFloat = 9.0e9 + 5.0e9;

        $result = $this->tracker()->updateStocks(
            [$apex, $beta],
            1.0 / 14400.0,
            false,
            new MacroStateDTO(sovereignFundTrade: 0.002 * $boardFloat, boardFloatCap: $boardFloat)
        );

        $apexReturn = ((float) $apex->getPrice() / 100.0) - 1.0;
        $betaReturn = ((float) $beta->getPrice() / 50.0) - 1.0;
        $this->assertEqualsWithDelta((9.0 * $apexReturn + 5.0 * $betaReturn) / 14.0, $result['board_price_return'], 1e-12, 'Float-weighted at the opening float.');
        $this->assertEqualsWithDelta(array_sum($result['float_caps']), $result['board_float_cap'], 1e-3);
        $this->assertSame(0.0, $result['board_dividend_cash']);
    }

    public function testTheBoardReportsTheCompaniesOwnIssuanceNetOfBuybacks(): void
    {
        $apex = $this->stock();
        $beta = $this->secondStock();

        // APEX retires 1% of its shares on its report, BETA issues 2%.
        $onEarnings = static function (Stock $stock): void {
            $factor = $stock->getTicker() === 'APEX' ? 0.99 : 1.02;
            $stock->setSharesOutstanding((string) ((float) $stock->getSharesOutstanding() * $factor));
        };
        $result = $this->tracker(onEarnings: $onEarnings)->updateStocks([$apex, $beta], 1.0 / 14400.0, false, new MacroStateDTO());

        $expected = (-0.01 * 1.0e8 * 0.90 * 100.0) + (0.02 * 2.0e8 * 0.50 * 50.0);
        $this->assertEqualsWithDelta($expected, $result['board_net_issuance'], 1e-3, 'Float issued less float retired, at the tick\'s price.');
        $this->assertSame(0.0, $this->tracker()->updateStocks([$this->stock()], 1.0 / 14400.0, false, new MacroStateDTO())['board_net_issuance']);
    }

    public function testTheBoardReportsTheStampDutyItsTradingPaid(): void
    {
        $apex = $this->stock();
        $beta = $this->secondStock();

        $result = $this->tracker()->updateStocks([$apex, $beta], 1.0 / 252.0, false, new MacroStateDTO());

        // Buyer and seller each pay the rate on every print; the reported volume is rounded, so allow a share's worth.
        $traded = 0.0;
        foreach ($result['updates'] as $update) {
            $traded += $update['volume'] * $update['price'];
        }
        $this->assertGreaterThan(0.0, $result['board_stamp_duty']);
        $this->assertEqualsWithDelta(2.0 * FinancialConstants::STAMP_DUTY_RATE * $traded, $result['board_stamp_duty'], 2.0 * FinancialConstants::STAMP_DUTY_RATE * 200.0);
    }

    public function testTheDistrictsStrategicStakeIsPaidItsShareOfEveryShareOfTheDividend(): void
    {
        $clearinghouse = $this->stock()->setTicker('ACC');
        $beta = $this->secondStock();
        $onEarnings = static fn (Stock $stock): array => [['type' => 'EARNINGS', 'ticker' => $stock->getTicker(), 'dividend_per_share' => 0.50]];

        $result = $this->tracker(onEarnings: $onEarnings)->updateStocks([$clearinghouse, $beta], 1.0 / 14400.0, false, new MacroStateDTO());

        // The stake is a share of shares outstanding, off the float; BETA carries none.
        $this->assertEqualsWithDelta(0.50 * 1.0e8 * StrategicHoldings::CLEARINGHOUSE_STAKE, $result['strategic_stake_cash'], 1e-6);
        // The board's own cash is the float's, which the stake is not part of.
        $this->assertEqualsWithDelta(0.50 * ((1.0e8 * 0.90) + (2.0e8 * 0.50)), $result['board_dividend_cash'], 1e-6);
        $this->assertSame(0.0, $this->tracker()->updateStocks([$this->stock()->setTicker('ACC')], 1.0 / 14400.0, false, new MacroStateDTO())['strategic_stake_cash'], 'No dividend, no cash.');
    }

    /** The District keeps its percentage by tendering into a buyback and subscribing to an issue, and is paid or pays for it. */
    public function testTheDistrictsStakeTendersIntoABuybackAndSubscribesToAnIssue(): void
    {
        $retire = static function (Stock $stock): void {
            $stock->setSharesOutstanding((string) ((float) $stock->getSharesOutstanding() * 0.99));
        };
        $issue = static function (Stock $stock): void {
            $stock->setSharesOutstanding((string) ((float) $stock->getSharesOutstanding() * 1.02));
        };

        $bought = $this->stock()->setTicker('ACC');
        $result = $this->tracker(onEarnings: $retire)->updateStocks([$bought], 1.0 / 14400.0, false, new MacroStateDTO());
        $this->assertEqualsWithDelta(StrategicHoldings::CLEARINGHOUSE_STAKE * 0.01 * 1.0e8 * (float) $bought->getPrice(), $result['strategic_stake_cash'], 1e-3, 'Paid for its share of the shares retired.');

        $issued = $this->stock()->setTicker('ACC');
        $result = $this->tracker(onEarnings: $issue)->updateStocks([$issued], 1.0 / 14400.0, false, new MacroStateDTO());
        $this->assertEqualsWithDelta(-StrategicHoldings::CLEARINGHOUSE_STAKE * 0.02 * 1.0e8 * (float) $issued->getPrice(), $result['strategic_stake_cash'], 1e-3, 'Pays for its share of the new shares.');

        $this->assertSame(0.0, $this->tracker(onEarnings: $retire)->updateStocks([$this->stock()], 1.0 / 14400.0, false, new MacroStateDTO())['strategic_stake_cash'], 'A company the District holds no stake in pays it nothing.');
    }
}
