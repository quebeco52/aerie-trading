<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Service\Corporate\CorporateActionEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\MergerAndAcquisitionEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\MarketEngine;
use App\Service\Market\StockTracker;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Pins what the tracker hands the price engine, and the price-trend state it keeps between ticks.
 *
 * The tracker is the only place that knows which macro sector a stock belongs to and what its live capital
 * structure is, so the sector shock lookup, the leverage re-levering of beta, and the momentum accumulator
 * all live here rather than in the price engine.
 */
#[AllowMockObjectsWithoutExpectations]
final class StockTrackerFactorWiringTest extends TestCase
{
    private function debtHealth(float $leveredBeta): DebtHealthDTO
    {
        $metrics = new DebtMetricsDTO(
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
        );

        return new DebtHealthDTO(
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
            leveredBeta: $leveredBeta,
            rawMetrics: $metrics,
            isLiquidityCrisis: false,
            isLiquidityWarning: false,
            isUnderLeveraged: false
        );
    }

    /**
     * @param list<float> $pricePath Price the engine returns on each successive tick.
     * @param-out MarketPricingContext|null $captured
     */
    private function buildTracker(
        float $leveredBeta,
        array $pricePath,
        ?MarketPricingContext &$captured,
        ?float $postSplitPrice = null
    ): StockTracker {
        $marketEngine = $this->createStub(MarketEngine::class);
        $marketEngine->method('calculateNextPrice')->willReturnCallback(
            function (MarketPricingContext $ctx) use (&$captured, &$pricePath): array {
                $captured = $ctx;

                return [
                    'price' => array_shift($pricePath) ?? $ctx->currentPrice,
                    'shock' => null,
                    'next_volatility' => 0.20,
                    'analyst_targets' => [],
                    'perceived_fair_value' => 100.0,
                ];
            }
        );

        $corporateActions = $this->createStub(CorporateActionEngine::class);
        $corporateActions->method('processSplits')->willReturnCallback(
            static fn (Stock $stock, float $price, float $shares): array => [
                'price' => $postSplitPrice ?? $price,
                'shares' => $shares,
                'event' => null,
            ]
        );

        $debtEngine = $this->createStub(DebtEngine::class);
        $debtEngine->method('analyzeDebtHealth')->willReturn($this->debtHealth($leveredBeta));

        $earnings = $this->createStub(EarningsEngine::class);
        $earnings->method('calculate')->willReturn(null);

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
            $this->createStub(MathUtility::class),
            $this->createStub(CorporateMetrics::class),
            new \App\Service\Market\LiquidityEngine(new MathUtility()),
            new \App\Service\Market\Flow\InMemoryOrderFlowStore(),
            new \App\Service\Market\Agent\AgentFlowEngine(
                new \App\Service\Market\Agent\AgentPopulation(),
                new \App\Service\Market\Agent\InMemoryAgentStateStore(),
                new \App\Service\Market\Flow\InMemoryOrderFlowStore(),
                []
            )
        );
    }

    private function stock(string $sector = 'Financials', string $beta = '1.00'): Stock
    {
        $stock = new Stock();
        $stock->setTicker('TEST');
        $stock->setSector($sector);
        $stock->setIndustry('Banks - Diversified');
        $stock->setPrice('100.00');
        $stock->setSharesOutstanding('1000');
        $stock->setEarningsPerShare('5.00');
        $stock->setVolatility('0.20');
        $stock->setCurrentVolatility('0.20');
        $stock->setBeta($beta);
        $stock->setJumpIntensity('2.0');
        $stock->setJumpVol('0.10');

        return $stock;
    }

    public function testTheStocksOwnSectorShockIsHandedToThePriceEngine(): void
    {
        $captured = null;
        $tracker = $this->buildTracker(1.0, [100.0], $captured);

        $macro = new MacroStateDTO(
            marketJumpMultiplier: 0.95,
            sectorZ: ['Financials' => 1.75, 'Information Technology' => -2.50]
        );

        $tracker->updateStocks([$this->stock('Financials')], 1.0 / 252.0, false, $macro);

        $this->assertInstanceOf(MarketPricingContext::class, $captured);
        $this->assertSame(1.75, $captured->sectorZ, 'The bank must receive the Financials shock, not another sector\'s.');
        $this->assertSame(0.95, $captured->marketJumpMultiplier);
    }

    public function testAnUnmappedSectorFallsBackToNoSectorShock(): void
    {
        $captured = null;
        $tracker = $this->buildTracker(1.0, [100.0], $captured);

        $macro = new MacroStateDTO(sectorZ: ['Financials' => 1.75]);

        $tracker->updateStocks([$this->stock('Utilities')], 1.0 / 252.0, false, $macro);

        $this->assertInstanceOf(MarketPricingContext::class, $captured);
        $this->assertSame(0.0, $captured->sectorZ);
    }

    public function testLeverageAmplifiesBetaWithoutFlippingAnInverseHedge(): void
    {
        $captured = null;

        // The debt engine levers from max(0.5, |beta|), so a 0.5 base returning 0.75 is a 1.5x re-levering.
        $tracker = $this->buildTracker(0.75, [100.0], $captured);

        $tracker->updateStocks([$this->stock(beta: '-0.10')], 1.0 / 252.0, false, new MacroStateDTO());

        $this->assertInstanceOf(MarketPricingContext::class, $captured);

        // Handing the debt engine's levered beta straight through would give +0.75: the sign inverted and the
        // magnitude inflated seven and a half times, turning a defensive hedge into a leveraged market bet.
        $this->assertEqualsWithDelta(-0.15, $captured->beta, 1e-9);
    }

    public function testLeverageLeavesAnUnleveredFirmsBetaUntouched(): void
    {
        $captured = null;
        $tracker = $this->buildTracker(1.20, [100.0], $captured);

        $tracker->updateStocks([$this->stock(beta: '1.20')], 1.0 / 252.0, false, new MacroStateDTO());

        $this->assertInstanceOf(MarketPricingContext::class, $captured);
        $this->assertEqualsWithDelta(1.20, $captured->beta, 1e-9);
    }

    public function testPriceTrendAccumulatesAcrossTicksAndDecaysOnItsFormationHorizon(): void
    {
        $captured = null;
        $tracker = $this->buildTracker(1.0, [110.0, 121.0], $captured);
        $stock = $this->stock();

        // dt equal to the formation horizon makes the decay exactly exp(-1) per tick.
        $dt = 0.50;
        $macro = new MacroStateDTO();

        $tracker->updateStocks([$stock], $dt, false, $macro);
        $firstTrend = (float) $stock->getPriceMomentumTrend();
        $this->assertEqualsWithDelta(log(1.10), $firstTrend, 1e-9);

        // The engine is fed the trend it accumulated on the previous tick, not a hard-coded zero.
        $tracker->updateStocks([$stock], $dt, false, $macro);
        $this->assertEqualsWithDelta($firstTrend, $captured->recentPriceTrend, 1e-9);

        $this->assertEqualsWithDelta(
            ($firstTrend * exp(-1.0)) + log(1.10),
            (float) $stock->getPriceMomentumTrend(),
            1e-9
        );
    }

    public function testPriceTrendIgnoresStockSplits(): void
    {
        $captured = null;

        // A genuine 10% gain, after which a 4-for-1 split quarters the quoted price.
        $tracker = $this->buildTracker(1.0, [110.0], $captured, postSplitPrice: 27.50);
        $stock = $this->stock();

        $tracker->updateStocks([$stock], 1.0 / 252.0, false, new MacroStateDTO());

        // Measuring the return across the split would book log(27.50 / 100) = -1.29, a catastrophic phantom
        // crash that would pin the stock's momentum negative for its whole formation window.
        $this->assertEqualsWithDelta(log(1.10), (float) $stock->getPriceMomentumTrend(), 1e-9);
    }
}
