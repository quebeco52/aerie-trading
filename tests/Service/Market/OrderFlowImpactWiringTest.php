<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Service\Corporate\CorporateActionEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\MergerAndAcquisitionEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\Flow\InMemoryOrderFlowStore;
use App\Service\Market\LiquidityEngine;
use App\Service\Market\MarketEngine;
use App\Service\Market\StockTracker;
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

    private function tracker(?MarketPricingContext &$captured = null): StockTracker
    {
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
        $earnings->method('calculate')->willReturn(null);
        $earnings->method('evaluatePreAnnouncement')->willReturn([]);

        $ma = $this->createStub(MergerAndAcquisitionEngine::class);
        $ma->method('evaluatePrivateAcquisition')->willReturn(null);
        $ma->method('evaluateCorporateDivestiture')->willReturn(null);

        return new StockTracker(
            $this->createMock(EntityManagerInterface::class),
            $marketEngine,
            $earnings,
            $corporateActions,
            $ma,
            $this->createStub(MarketEventPublisher::class),
            $debtEngine,
            new MathUtility(),
            $this->createStub(CorporateMetrics::class),
            $this->liquidity,
            $this->orderFlow
        );
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

    public function testEveryQuoteCarriesTheVolumeAndDepthTheBarIsBuiltFrom(): void
    {
        $stock = $this->stock();
        $result = $this->tracker()->updateStocks([$stock], 1.0 / 14400.0, false, new MacroStateDTO());

        $update = $result['updates'][0];

        $this->assertArrayHasKey('volume', $update);
        $this->assertGreaterThan(0.0, $update['volume'], 'A tick with no player flow still prints background volume.');
        $this->assertEqualsWithDelta($this->liquidity->averageDailyVolume($stock), $update['adv_shares'], 1.0);
        $this->assertGreaterThan(0.0, $update['half_spread_bps']);
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
}
