<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Pricing;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateActionEngine;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\Deal\AcquisitionEngine;
use App\Service\Corporate\Deal\DivestitureEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\Industry\IndustryOutlook;
use App\Service\Corporate\Industry\InMemoryIndustryShareStore;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\StockTracker;
use App\Service\Math\MathUtility;
use App\Service\News\MarketEventPublisher;
use App\Tests\Support\DebtHealthBuilder;
use App\Tests\Support\StockTrackerFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class StockTrackerTest extends TestCase
{
    use StockTrackerFactory;

    private EntityManagerInterface&MockObject $entityManagerMock;
    private MarketEngine&Stub $marketEngineMock;
    private EarningsEngine&Stub $earningsEngineMock;
    private CorporateActionEngine&Stub $corporateActionEngineMock;
    private AcquisitionEngine&Stub $acquisitionEngineMock;
    private DivestitureEngine&Stub $divestitureEngineMock;
    private MarketEventPublisher&Stub $marketEventMock;
    private DebtEngine&Stub $debtEngineMock;
    private CorporateMetrics&Stub $corporateMetricsMock;
    private \App\Service\Market\Flow\InMemoryOrderFlowStore $orderFlow;
    private StockTracker $tracker;

    protected function setUp(): void
    {
        $this->entityManagerMock = $this->createMock(EntityManagerInterface::class);
        $this->marketEngineMock = $this->createStub(MarketEngine::class);
        $this->earningsEngineMock = $this->createStub(EarningsEngine::class);
        $this->corporateActionEngineMock = $this->createStub(CorporateActionEngine::class);
        $this->acquisitionEngineMock = $this->createStub(AcquisitionEngine::class);
        $this->divestitureEngineMock = $this->createStub(DivestitureEngine::class);
        $this->marketEventMock = $this->createStub(MarketEventPublisher::class);
        $this->debtEngineMock = $this->createStub(DebtEngine::class);
        $this->corporateMetricsMock = $this->createStub(CorporateMetrics::class);

        $this->debtEngineMock->method('analyzeDebtHealth')->willReturn(DebtHealthBuilder::healthy()->build());

        $this->orderFlow = new \App\Service\Market\Flow\InMemoryOrderFlowStore();

        $this->tracker = self::stockTracker(
            $this->marketEngineMock,
            $this->earningsEngineMock,
            $this->corporateActionEngineMock,
            $this->debtEngineMock,
            new \App\Service\Market\Pricing\LiquidityEngine(new MathUtility()),
            acquisitions: $this->acquisitionEngineMock,
            divestitures: $this->divestitureEngineMock,
            publisher: $this->marketEventMock,
            metrics: $this->corporateMetricsMock,
            orderFlow: $this->orderFlow,
        );
    }

    private function createDummyStock(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('TEST');
        $stock->setSector('Technology');
        $stock->setPrice('100.00');
        $stock->setSharesOutstanding('1000');
        $stock->setEarningsPerShare('5.00');
        $stock->setVolatility('0.20');
        $stock->setCurrentVolatility('0.20');
        $stock->setBeta('1.0');
        $stock->setJumpIntensity('2.0'); // Corresponds to lambda
        $stock->setJumpVol('0.10'); // Corresponds to jump_vol

        return $stock;
    }

    public function testUpdateStocksStandardFlowWithoutEventsOrHistory(): void
    {
        $stock = $this->createDummyStock();

        // Mock the engines returning standard, uneventful updates
        $this->marketEngineMock->method('calculateNextPrice')->willReturn([
            'price' => 105.0,
            'shock' => null, // No market shock
            'next_volatility' => 0.21,
            'analyst_targets' => [],
            'perceived_fair_value' => 100.0,
        ]);

        $this->earningsEngineMock->method('calculate')->willReturn(null); // No earnings report

        $this->corporateActionEngineMock->method('processSplits')->willReturn([
            'price' => 105.0,
            'shares' => 1000,
            'event' => null, // No split
        ]);

        // We no longer flush in the tracker, so expect it to never be called
        $this->entityManagerMock->expects($this->never())->method('flush');

        // We passed false to $recordHistory and have no shocks, so persist should never be called
        $this->entityManagerMock->expects($this->never())->method('persist');

        $result = $this->tracker->updateStocks([$stock], 1.0, false);

        $this->assertIsArray($result);
        $this->assertCount(1, $result['updates']);
        $this->assertEmpty($result['events']);

        // Market cap should be 105.0 * 1000
        $this->assertEquals(105000.0, $result['total_cap']);

        // Verify the Doctrine entity was properly mutated
        $this->assertEquals('105', $stock->getPrice());
        $this->assertEquals('0.21', $stock->getCurrentVolatility());
    }

    public function testUpdateStocksWithMarketShockAndHistoryRecording(): void
    {
        $stock = $this->createDummyStock()->setName('Test Corp');

        $this->marketEngineMock->method('calculateNextPrice')->willReturn([
            'price' => 90.0,
            'shock' => -10.0, // Simulate a 10% drop shock
            'next_volatility' => 0.50,
            'analyst_targets' => [],
            'perceived_fair_value' => 100.0,
        ]);

        $this->earningsEngineMock->method('calculate')->willReturn(null);

        $this->corporateActionEngineMock->method('processSplits')->willReturn([
            'price' => 90.0,
            'shares' => 1000,
            'event' => null,
        ]);

        // The wire line names the company and says what its shares did.
        $published = [];
        $this->marketEventMock->method('publish')->willReturnCallback(function (...$args) use (&$published): array {
            $published[] = array_slice($args, 1, 3);

            return ['type' => $args[1], 'ticker' => 'TEST', 'description' => $args[2], 'change_percent' => $args[3]];
        });

        $this->entityManagerMock->expects($this->never())->method('persist');
        $this->entityManagerMock->expects($this->never())->method('flush');

        $result = $this->tracker->updateStocks([$stock], 1.0, true);

        $this->assertCount(1, $result['events']);
        $this->assertEquals('SHOCK', $result['events'][0]['type']);
        $this->assertSame([['SHOCK', 'Test Corp shares drop 10.0% in a sudden move.', -10.0]], $published);

        $this->assertIsArray($result['history']);
        $this->assertCount(1, $result['history']);
        $this->assertEquals(90.0, $result['history'][0]['price']);
    }

    // --- Post-Report Fair Value ---

    /**
     * @return array{0: array<string, mixed>, 1: int} The tick's update row, and how many times fair value was
     *                                                re-struck.
     */
    private function tickWithReport(?array $report): array
    {
        $stock = $this->createDummyStock();
        $this->marketEngineMock->method('calculateNextPrice')->willReturn([
            'price' => 100.0, 'shock' => null, 'next_volatility' => 0.20,
            'analyst_targets' => ['growth_analyst' => 100.0], 'perceived_fair_value' => 100.0,
        ]);
        $strikes = 0;
        $this->marketEngineMock->method('strikeFairValue')->willReturnCallback(function () use (&$strikes): array {
            $strikes++;

            return ['perceived_fair_value' => 130.0, 'analyst_targets' => ['growth_analyst' => 130.0]];
        });
        $this->earningsEngineMock->method('calculate')->willReturn($report);
        $this->corporateActionEngineMock->method('processSplits')->willReturn(['price' => 100.0, 'shares' => 1000, 'event' => null]);

        $result = $this->tracker->updateStocks([$stock], 1.0 / 252.0, false);

        return [$result['updates'][0], $strikes];
    }

    public function testAReportTickHandsTheAgentsThePostReportFairValue(): void
    {
        [$update, $strikes] = $this->tickWithReport([]);

        // The agents and the analysts read the same figure the update carries.
        $this->assertSame(1, $strikes);
        $this->assertEqualsWithDelta(130.0, $update['perceived_fair_value'], 1e-9);
        $this->assertEqualsWithDelta(130.0, $update['analyst_targets']['growth_analyst'], 1e-9);
    }

    public function testAnOrdinaryTickKeepsThePriceStepsFairValue(): void
    {
        [$update, $strikes] = $this->tickWithReport(null);

        $this->assertSame(0, $strikes);
        $this->assertEqualsWithDelta(100.0, $update['perceived_fair_value'], 1e-9);
    }

    // --- Industry Outlook Stories ---

    /**
     * A quarter's turn that moves an industry's outlook far puts a story on every listed company in it, with no price
     * move; an industry whose outlook barely moved gets none.
     */
    public function testAFarRevisionPutsAStoryOnEveryCompanyInTheIndustry(): void
    {
        // The public is unsure and the truth has run far above its belief, so the quarter's data revise it a long way
        // up; the grocers' settled outlook, on its truth, barely moves. The chip makers are too small a share of
        // spending to move the grocers'.
        $store = new InMemoryIndustryShareStore();
        $store->writeOutlooks(['salt' => 3, 'quarter' => 0, 'industries' => [
            'Semiconductors' => ['x' => 1.0, 'm' => -0.5, 'v' => 1.0, 'f' => 0.0, 'w' => 1e-6, 'prior_belief' => 0.0, 'revised_tick' => -1],
            'Grocery Stores' => ['x' => 0.0, 'm' => 0.0, 'v' => 0.0, 'f' => 0.0, 'w' => 1.0, 'prior_belief' => 0.0, 'revised_tick' => -1],
        ]]);
        $tracker = self::stockTracker(
            $this->marketEngineMock,
            $this->earningsEngineMock,
            $this->corporateActionEngineMock,
            $this->debtEngineMock,
            new \App\Service\Market\Pricing\LiquidityEngine(new MathUtility()),
            publisher: $this->marketEventMock,
            metrics: $this->corporateMetricsMock,
            industryOutlook: new IndustryOutlook($store, new CorporateMetrics()),
        );
        $this->marketEngineMock->method('calculateNextPrice')->willReturn([
            'price' => 100.0, 'shock' => null, 'next_volatility' => 0.20, 'analyst_targets' => [], 'perceived_fair_value' => 100.0,
        ]);
        $this->earningsEngineMock->method('calculate')->willReturn(null);
        $this->corporateActionEngineMock->method('processSplits')->willReturn(['price' => 100.0, 'shares' => 1000, 'event' => null]);
        $published = [];
        $this->marketEventMock->method('publish')->willReturnCallback(function (...$args) use (&$published): array {
            $published[] = $args;

            return ['type' => $args[1], 'ticker' => $args[0]->getTicker(), 'description' => $args[2], 'change_percent' => $args[3]];
        });
        $stocks = [];
        foreach (['CHP' => 'Semiconductors', 'CHQ' => 'Semiconductors', 'GRC' => 'Grocery Stores'] as $ticker => $industry) {
            $stocks[] = $this->createDummyStock()->setTicker($ticker)->setIndustry($industry);
        }

        $tracker->updateStocks($stocks, 1.0 / 252.0, false, new MacroStateDTO(), 63, 252);

        $stories = array_values(array_filter($published, static fn(array $args): bool => $args[1] === 'OUTLOOK'));
        $this->assertSame(['CHP', 'CHQ'], array_map(static fn(array $args): string => $args[0]->getTicker(), $stories));
        $this->assertMatchesRegularExpression('/^Industry data lift the demand outlook for Semiconductors [\d,]+\.\d% to \d+\.\d\d\.$/', $stories[0][2]);
        $this->assertNull($stories[0][3], 'a story, not a price move');
        $this->assertGreaterThan(StockTracker::OUTLOOK_STORY_REVISION, $stories[0][4]['revision']);
        $this->assertSame('Semiconductors', $stories[0][4]['industry']);
    }
}
