<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Pricing;

use App\DTO\DebtHealthDTO;
use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Service\Corporate\CorporateActionEngine;
use App\Service\Corporate\Deal\AcquisitionEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\StockTracker;
use App\Service\Math\MathUtility;
use App\Tests\Support\DebtHealthBuilder;
use App\Tests\Support\StockTrackerFactory;
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
    use StockTrackerFactory;

    private function debtHealth(float $leveredBeta, float $equityLeverageMultiplier = 1.0): DebtHealthDTO
    {
        return DebtHealthBuilder::healthy()
            ->withLeveredBeta($leveredBeta)
            ->withEquityLeverageMultiplier($equityLeverageMultiplier)
            ->build();
    }

    /**
     * @param list<float> $pricePath Price the engine returns on each successive tick.
     * @param-out MarketPricingContext|null $captured
     */
    private function buildTracker(
        float $leveredBeta,
        array $pricePath,
        ?MarketPricingContext &$captured,
        ?float $postSplitPrice = null,
        ?\App\Service\Market\Agent\AgentStrategyInterface $agentStrategy = null,
        ?\Closure $earningsReport = null,
        ?AcquisitionEngine $acquisitionEngine = null,
        float $equityLeverageMultiplier = 1.0,
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
                    'market_repricing' => 1.0,
                    'franchise_power' => 0.0,
                ];
            },
        );

        $corporateActions = $this->createStub(CorporateActionEngine::class);
        $corporateActions->method('processSplits')->willReturnCallback(
            static fn(Stock $stock, float $price, float $shares): array => [
                'price' => $postSplitPrice ?? $price,
                'shares' => $shares,
                'event' => null,
            ],
        );

        $debtEngine = $this->createStub(DebtEngine::class);
        $debtEngine->method('analyzeDebtHealth')->willReturn($this->debtHealth($leveredBeta, $equityLeverageMultiplier));

        $earnings = $this->createStub(EarningsEngine::class);
        if ($earningsReport === null) {
            $earnings->method('calculate')->willReturn(null);
        } else {
            $earnings->method('calculate')->willReturnCallback($earningsReport);
        }

        return self::stockTracker(
            $marketEngine,
            $earnings,
            $corporateActions,
            $debtEngine,
            new \App\Service\Market\Pricing\LiquidityEngine(new MathUtility()),
            acquisitions: $acquisitionEngine,
            agentStrategies: $agentStrategy === null ? [] : [$agentStrategy],
        );
    }

    /**
     * A strategy that only records what the tracker let it see.
     *
     * @param-out \App\DTO\AgentMarketViewDTO|null $seen
     */
    private function observingStrategy(?\App\DTO\AgentMarketViewDTO &$seen): \App\Service\Market\Agent\AgentStrategyInterface
    {
        $record = static function (\App\DTO\AgentMarketViewDTO $view) use (&$seen): void {
            $seen = $view;
        };

        return new class ($record) implements \App\Service\Market\Agent\AgentStrategyInterface {
            public function __construct(private \Closure $record) {}

            public function identifier(): string
            {
                return 'observer';
            }

            public function signal(\App\DTO\AgentMarketViewDTO $view): float
            {
                ($this->record)($view);

                return 0.0;
            }

            public function competesForCapital(): bool
            {
                return false;
            }
        };
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
            sectorZ: ['Financials' => 1.75, 'Information Technology' => -2.50],
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

    /**
     * The merger review reads the acquirer's rivals off the industry ledger, whose records are dated in
     * ticks: the tracker hands the acquisition engine the tick and the year's tick count so a failed peer's share
     * returns to the fringe on the same clock the capacity balance uses.
     */
    public function testTheAcquisitionReviewIsDatedOnTheTrackersTick(): void
    {
        $stock = $this->stock();
        $acquisitions = $this->createMock(AcquisitionEngine::class);
        $acquisitions->expects($this->once())
            ->method('evaluatePrivateAcquisition')
            ->with($stock, $this->anything(), 1.0 / 360.0, 7_205, 360)
            ->willReturn(null);

        $captured = null;
        $tracker = $this->buildTracker(1.0, [100.0], $captured, acquisitionEngine: $acquisitions);
        $tracker->updateStocks([$stock], 1.0 / 360.0, false, new MacroStateDTO(), 7_205, 360);
    }

    /**
     * The debt engine levers the firm's own signed beta through Hamada, so the beta it reports is already
     * the one this diffusion wants and reaches the price engine untouched. The sign invariant itself is
     * enforced where the levering happens, in DebtEngineTest.
     */
    public function testTheDebtEnginesLeveredBetaReachesThePriceEngineIntact(): void
    {
        $captured = null;

        // A -0.10 hedge re-levered 1.5x by its own debt: still a hedge, just a bigger one.
        $tracker = $this->buildTracker(-0.15, [100.0], $captured);

        $tracker->updateStocks([$this->stock(beta: '-0.10')], 1.0 / 252.0, false, new MacroStateDTO());

        $this->assertInstanceOf(MarketPricingContext::class, $captured);
        $this->assertEqualsWithDelta(-0.15, $captured->beta, 1e-9);
        $this->assertLessThan(0.0, $captured->beta, 'An inverse hedge must never reach the price engine as a market-following name.');
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

        $tracker->updateStocks([$stock], $dt, false, $macro);

        $this->assertEqualsWithDelta(
            ($firstTrend * exp(-1.0)) + log(1.10),
            (float) $stock->getPriceMomentumTrend(),
            1e-9,
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
    public function testADividendPaidOnTheReportTickIsCreditedToTheReturnTheTrendAndTheAgentsSee(): void
    {
        // The engine prints 110; the report pays $2 a share and the ex-dividend price is 108. A holder
        // made 10%, not 8%: the momentum trend and the agents' return both add the cash back.
        $captured = null;
        $seen = null;
        $tracker = $this->buildTracker(
            1.0,
            [110.0],
            $captured,
            agentStrategy: $this->observingStrategy($seen),
            earningsReport: static function (Stock $stock): array {
                $stock->setPrice('108.00');

                return [['type' => 'EARNINGS', 'ticker' => 'TEST', 'description' => 'Q', 'change_percent' => 0.0, 'dividend_per_share' => 2.0]];
            },
        );

        $stock = $this->stock();
        $tracker->updateStocks([$stock], 1.0 / 252.0, false, new MacroStateDTO());

        $this->assertNotNull($seen, 'the agents were shown the tick');
        $this->assertEqualsWithDelta(log(1.10), $seen->logReturn, 1e-9);
        $this->assertEqualsWithDelta(log(1.10), (float) $stock->getPriceMomentumTrend(), 1e-9);
        $this->assertEqualsWithDelta(log(1.10), $seen->momentumTrend, 1e-9);
    }

    /** A redemption beside the dividend is cash per share alike: the holder's return adds both back. */
    public function testARedemptionPaidOnTheReportTickIsCreditedToTheReturn(): void
    {
        $captured = null;
        $seen = null;
        $tracker = $this->buildTracker(
            1.0,
            [110.0],
            $captured,
            agentStrategy: $this->observingStrategy($seen),
            earningsReport: static function (Stock $stock): array {
                $stock->setPrice('107.00');

                return [['type' => 'EARNINGS', 'ticker' => 'TEST', 'description' => 'Q', 'change_percent' => 0.0, 'dividend_per_share' => 1.0, 'redemption_per_share' => 2.0]];
            },
        );

        $tracker->updateStocks([$this->stock()], 1.0 / 252.0, false, new MacroStateDTO());

        $this->assertNotNull($seen, 'the agents were shown the tick');
        $this->assertEqualsWithDelta(log(1.10), $seen->logReturn, 1e-9);
    }

    public function testWithoutAReportTheReturnIsThePriceAlone(): void
    {
        $captured = null;
        $seen = null;
        $tracker = $this->buildTracker(1.0, [110.0], $captured, agentStrategy: $this->observingStrategy($seen));

        $tracker->updateStocks([$this->stock()], 1.0 / 252.0, false, new MacroStateDTO());

        $this->assertNotNull($seen, 'the agents were shown the tick');
        $this->assertEqualsWithDelta(log(1.10), $seen->logReturn, 1e-9);
    }

    public function testTheAgentsAreSizedOnTheStructuralVolumeNotTodaysActivity(): void
    {
        // A stressed tape carries more volume today. The agent books are standing capital and do not grow
        // because of it: the tracker hands them the structural figure.
        $captured = null;
        $seen = null;
        $tracker = $this->buildTracker(1.0, [100.0], $captured, agentStrategy: $this->observingStrategy($seen));
        $liquidity = new \App\Service\Market\Pricing\LiquidityEngine(new MathUtility());

        $stock = $this->stock();
        $stock->setSharesOutstanding('500000000');
        $stock->setPublicFloatPercentage('0.90');
        $stock->setTurnoverRatio(1.2);
        $stock->setVolatility('0.20');
        $stock->setCurrentVolatility('0.60');

        // The depth an order would meet on this stressed tape, read before the tick writes the engine's
        // next volatility back onto the stock.
        $stressedDepth = $liquidity->averageDailyVolume($stock);

        $tracker->updateStocks([$stock], 1.0 / 252.0, false, new MacroStateDTO());

        $this->assertNotNull($seen, 'the agents were shown the tick');
        $this->assertEqualsWithDelta($liquidity->structuralDailyVolume($stock), $seen->averageDailyVolume, 1e-6);
        $this->assertLessThan($stressedDepth, $seen->averageDailyVolume);
    }

    /**
     * Persistence round-trip: assessCredit() writes leveredVolatility onto the stock, StockTickColumns exports it in
     * the bulk update row, and OptionPricingEngine::surfaceFor() reads that levered long-run volatility and matching
     * jump scale back from a reloaded entity.
     */
    public function testAssessCreditPersistsLeveredVolatilityThroughTickColumnsToOptionSurface(): void
    {
        $captured = null;
        $leveredBeta = 1.80;
        $leverageMultiplier = 1.80;
        $businessVol = 0.22;
        $lambda = 0.80;
        $tracker = $this->buildTracker($leveredBeta, [100.0], $captured, equityLeverageMultiplier: $leverageMultiplier);

        $stock = $this->stock();
        (new \ReflectionProperty(Stock::class, 'id'))->setValue($stock, 42);
        $stock->setBeta('1.00');
        $stock->setVolatility('0.28');
        $stock->setCurrentVolatility('0.28');
        $stock->setJumpIntensity((string) $lambda);
        $stock->setJumpVol('0.06');
        $stock->setBusinessVolatility($businessVol);

        $tracker->updateStocks([$stock], 1.0 / 252.0, false, new MacroStateDTO());

        $expectedProfile = \App\Service\Market\Pricing\VarianceBudget::leveredRiskProfile(
            $leveredBeta,
            $leverageMultiplier,
            $businessVol,
            $lambda,
        );
        $this->assertNotNull($captured);
        $this->assertEqualsWithDelta($expectedProfile['volatility'], $captured->longTermVolatility, 1e-12);
        $this->assertEqualsWithDelta($expectedProfile['jump_scale'], $captured->jumpVol, 1e-12);
        $this->assertEqualsWithDelta($expectedProfile['volatility'], (float) $stock->getLeveredVolatility(), 1e-12);

        // Verify the StockTickColumns bulk-update row carries the exact leveredVolatility written by assessCredit().
        $rows = \App\Service\Market\Ticker\StockTickColumns::rows([$stock]);
        $colIndex = array_search('levered_volatility', \App\Service\Market\Ticker\StockTickColumns::COLUMNS, true);
        $betaIndex = array_search('levered_beta', \App\Service\Market\Ticker\StockTickColumns::COLUMNS, true);
        $this->assertIsInt($colIndex);
        $this->assertIsInt($betaIndex);
        $persistedVol = $rows[42][$colIndex];
        $persistedBeta = $rows[42][$betaIndex];
        $this->assertEqualsWithDelta($expectedProfile['volatility'], (float) $persistedVol, 1e-12);

        // Reconstruct a stock as a fresh request would read it back from the database and quote its option surface.
        $reloaded = $this->stock();
        $reloaded->setBeta('1.00');
        $reloaded->setVolatility('0.28');
        $reloaded->setCurrentVolatility('0.28');
        $reloaded->setJumpIntensity((string) $lambda);
        $reloaded->setJumpVol('0.06');
        $reloaded->setLeveredBeta((float) $persistedBeta);
        $reloaded->setLeveredVolatility((float) $persistedVol);

        $optionEngine = new \App\Service\Market\Option\OptionPricingEngine(new \App\Service\Market\Bond\BondPricingEngine());
        $fromStock = $optionEngine->surfaceFor($reloaded, 1.0);
        $expectedSurface = $optionEngine->surface(
            0.28,
            $expectedProfile['volatility'],
            $leveredBeta,
            $lambda,
            $expectedProfile['jump_scale'],
            1.0,
        );
        $staleSurface = $optionEngine->surface(0.28, 0.28, $leveredBeta, $lambda, 0.06, 1.0);

        $this->assertEqualsWithDelta($expectedSurface['atm_volatility'], $fromStock['atm_volatility'], 1e-12);
        $this->assertEqualsWithDelta($expectedSurface['skewness'], $fromStock['skewness'], 1e-12);
        $this->assertGreaterThan($staleSurface['atm_volatility'] + 0.05, $fromStock['atm_volatility']);
    }
}
