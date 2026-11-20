<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Data\Company\IndustryMarkets;
use App\Data\Company\Segments;
use App\DTO\MacroStateDTO;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Repository\CorporateReportRepository;
use App\Repository\StockEventRepository;
use App\Repository\StockRepository;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\Deal\MergerReview;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Industry\InMemoryIndustryShareStore;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Macro\MacroStateProvider;
use App\Service\News\EventPresenter;
use App\Service\View\IndustryPageBuilder;
use App\Service\View\IndustryPositionBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Industry list and single-industry page assembly against whole-market shares (listed sellers + unlisted fringe).
 */
#[Group('unit')]
final class IndustryPageBuilderTest extends TestCase
{
    private InMemoryIndustryShareStore $store;
    private IndustryShareLedger $ledger;

    protected function setUp(): void
    {
        $this->store = new InMemoryIndustryShareStore();
        $this->ledger = new IndustryShareLedger($this->store);
    }

    public function testIndexExposesMarketSizeListedShareAndHhiForSharedIndustries(): void
    {
        $lake = $this->stock('LAKE', 'Lakebird Bank', 'Banks - Diversified', 'Financials', 200e9, 430_000_000_000.0);
        $rivr = $this->stock('RIVR', 'Riverstone Financial', 'Banks - Regional', 'Financials', 50e9, 200_000_000_000.0);
        $plvr = $this->stock('PLVR', 'Plover Savings Bank', 'Banks - Regional', 'Financials', 80e9, 350_000_000_000.0);
        $owls = $this->stock('OWLS', 'Owl Holdings', 'Conglomerates', 'Industrials', 100e9, 100_000_000_000.0);
        $triv = $this->stock('TRIV', 'Trivium Corp', 'Conglomerates', 'Industrials', 60e9, 80_000_000_000.0);

        $rows = $this->builder([$lake, $rivr, $plvr, $owls, $triv], tick: 0)->index();
        $byName = array_column($rows, null, 'name');

        $div = $byName['Banks - Diversified'];
        $this->assertEqualsWithDelta(IndustryMarkets::openingMarket('Banks - Diversified'), $div['marketSize'], 1.0);
        $this->assertEqualsWithDelta(0.43, $div['listedShare'], 1e-9);
        $this->assertEqualsWithDelta(0.43 ** 2, $div['herfindahl'], 1e-12);
        $this->assertTrue($div['highlyConcentrated'], '0.43^2 = 0.1849 exceeds default 0.18 concentration line');

        $reg = $byName['Banks - Regional'];
        $this->assertEqualsWithDelta(IndustryMarkets::openingMarket('Banks - Regional'), $reg['marketSize'], 1.0);
        $this->assertEqualsWithDelta(0.55, $reg['listedShare'], 1e-9);
        $this->assertEqualsWithDelta((0.35 ** 2) + (0.20 ** 2), $reg['herfindahl'], 1e-12);
        $this->assertFalse($reg['highlyConcentrated'], '0.1625 sits below default 0.18 concentration line');

        $cong = $byName['Conglomerates'];
        $this->assertNull($cong['marketSize']);
        $this->assertNull($cong['listedShare']);
        $this->assertNull($cong['herfindahl']);
        $this->assertFalse($cong['highlyConcentrated']);
    }

    public function testSingleFirmIndustryShowsWholeMarketShareAndUnlistedFringeNotFullMonopoly(): void
    {
        $lake = $this->stock('LAKE', 'Lakebird Bank', 'Banks - Diversified', 'Financials', 200e9, 430_000_000_000.0);
        $page = $this->builder([$lake], tick: 0)->build('Banks - Diversified');

        $this->assertNotNull($page);
        $this->assertTrue($page['sharedMarket']);
        $this->assertTrue($page['isFinancial']);
        $this->assertEqualsWithDelta(0.43, $page['companies'][0]['share'], 1e-9);
        $this->assertNull($page['companies'][0]['shareChange']);
        $this->assertEqualsWithDelta(0.43, $page['listedShare'], 1e-9);
        $this->assertEqualsWithDelta(0.57, $page['fringeShare'], 1e-9);
        $this->assertNull($page['mover']);
    }

    public function testPreFirstFilingMultiFirmIndustryPopulatesAllPeersFromEquityAndMarkets(): void
    {
        $plvr = $this->stock('PLVR', 'Plover Savings Bank', 'Banks - Regional', 'Financials', 80e9, 350_000_000_000.0);
        $rivr = $this->stock('RIVR', 'Riverstone Financial', 'Banks - Regional', 'Financials', 50e9, 200_000_000_000.0);

        $page = $this->builder([$plvr, $rivr], tick: 0)->build('Banks - Regional');

        $this->assertNotNull($page);
        $byTicker = array_column($page['companies'], null, 'ticker');
        $this->assertEqualsWithDelta(0.35, $byTicker['PLVR']['share'], 1e-9);
        $this->assertEqualsWithDelta(0.20, $byTicker['RIVR']['share'], 1e-9);
        $this->assertEqualsWithDelta(0.55, $page['listedShare'], 1e-9);
        $this->assertEqualsWithDelta(0.45, $page['fringeShare'], 1e-9);
        $this->assertEqualsWithDelta((0.35 ** 2) + (0.20 ** 2), $page['herfindahl'], 1e-12);
    }

    public function testIndustryWithLedgerAndCrossIndustryEntrantSumsMainEntrantsAndFringeToWhole(): void
    {
        $ipp = $this->stock('VOLT', 'Volt Generation', 'Utilities - Independent Power Producers', 'Utilities', 40e9, 100_000_000_000.0);
        $bird = $this->stock('BIRD', 'Birdsong Utilities', 'Utilities - Regulated Electric', 'Utilities', 90e9, 120_000_000_000.0);
        $bird->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.8, 'merchant_power' => 0.2], 1.0, []));

        $this->ledger->resolveIndustryCapacityRatio($ipp, 3_000.0, 0.30, 1.0, 0.0, 0.0, 10, 252);
        $this->ledger->resolveSegmentSupply($bird, 'merchant_power', 1_000.0, 1_000.0, 0.10, 1.0, 0.0, 0.0, 10, 252);

        $page = $this->builder([$ipp, $bird], tick: 20)->build('Utilities - Independent Power Producers');

        $this->assertNotNull($page);
        $this->assertCount(1, $page['companies']);
        $this->assertCount(1, $page['entrants']);
        $this->assertSame('BIRD', $page['entrants'][0]['ticker']);
        $this->assertEqualsWithDelta(0.30, $page['companies'][0]['share'], 1e-9);
        $this->assertEqualsWithDelta(0.10, $page['entrants'][0]['share'], 1e-9);
        $this->assertEqualsWithDelta(0.40, $page['listedShare'], 1e-9);
        $this->assertEqualsWithDelta(0.60, $page['fringeShare'], 1e-9);
        $this->assertEqualsWithDelta(
            1.0,
            $page['companies'][0]['share'] + $page['entrants'][0]['share'] + $page['fringeShare'],
            1e-9,
        );
    }

    public function testConglomeratesOmitMarketSharesMoversAndShareChart(): void
    {
        $owls = $this->stock('OWLS', 'Owl Holdings', 'Conglomerates', 'Industrials', 100e9, 100_000_000_000.0);
        $triv = $this->stock('TRIV', 'Trivium Corp', 'Conglomerates', 'Industrials', 60e9, 80_000_000_000.0);
        $reports = [
            $this->report($owls, 1.25, 2.0e9),
            $this->report($triv, 1.25, 1.0e9),
            $this->report($owls, 4.25, 2.5e9),
            $this->report($triv, 4.25, 1.0e9),
        ];

        $page = $this->builder([$owls, $triv], $reports, tick: 400)->build('Conglomerates');

        $this->assertNotNull($page);
        $this->assertFalse($page['sharedMarket']);
        $this->assertNull($page['mover']);
        $this->assertNull($page['charts']['shares']);
        foreach ($page['companies'] as $company) {
            $this->assertNull($company['share']);
            $this->assertNull($company['shareChange']);
        }
        $this->assertSame([], $page['entrants']);
        $this->assertEqualsWithDelta(
            MergerReview::reviewScreens((new MacroStateDTO())->mergerReviewLeniency)['concentrated'],
            $page['concentrationLine'],
            1e-12,
        );
    }

    /**
     * @param list<Stock>           $allStocks
     * @param list<CorporateReport> $reports
     */
    private function builder(array $allStocks, array $reports = [], int $tick = 100): IndustryPageBuilder
    {
        $stocksRepo = $this->createStub(StockRepository::class);
        $stocksRepo->method('findBy')->willReturnCallback(static function (array $criteria) use ($allStocks): array {
            if (!isset($criteria['industry'])) {
                return $allStocks;
            }

            return array_values(array_filter(
                $allStocks,
                static fn(Stock $s): bool => $s->getIndustry() === $criteria['industry'],
            ));
        });
        $stocksRepo->method('findOneByTicker')->willReturnCallback(static function (string $ticker) use ($allStocks): ?Stock {
            foreach ($allStocks as $s) {
                if ($s->getTicker() === $ticker) {
                    return $s;
                }
            }

            return null;
        });

        $reportsRepo = $this->createStub(CorporateReportRepository::class);
        $reportsRepo->method('findForStocksNewestFirst')->willReturn($reports);
        $reportsRepo->method('findLatestFor')->willReturn(null);

        $eventsRepo = $this->createStub(StockEventRepository::class);
        $eventsRepo->method('findLatestAmong')->willReturn([]);

        $redis = $this->createStub(\Redis::class);
        $redis->method('get')->willReturn((string) $tick);

        $macroProvider = $this->createStub(MacroStateProvider::class);
        $macroProvider->method('liveState')->willReturn(new MacroStateDTO(nominalGdpIndex: 1.0, potentialGdpIndex: 1.0, gdpDeflator: 1.0, totalTime: 0.0));

        $position = new IndustryPositionBuilder($this->ledger, new CorporateMetrics(), $reportsRepo, $redis, 252, $stocksRepo);

        return new IndustryPageBuilder(
            $stocksRepo,
            $reportsRepo,
            $eventsRepo,
            $this->ledger,
            $position,
            $macroProvider,
            new EventPresenter(),
        );
    }

    private function stock(string $ticker, string $name, string $industry, string $sector, float $marketValue, float $equity): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setName($name);
        $stock->setIndustry($industry);
        $stock->setSector($sector);
        $stock->setPrice('100.00');
        $stock->setSharesOutstanding((string) (int) ($marketValue / 100.0));
        $stock->setTotalEquity((string) $equity);
        $stock->setSamRatio('1.00');
        $stock->setTotalRevenue('1000000000.0');
        $stock->setRoicTtm('0.12');

        return $stock;
    }

    private function report(Stock $stock, float $totalTime, float $revenue): CorporateReport
    {
        return (new CorporateReport())
            ->setStock($stock)
            ->setTotalTime((string) $totalTime)
            ->setRevenue((string) $revenue)
            ->setEbit((string) ($revenue * 0.1))
            ->setNetPpe('1000000000.0')
            ->setCip('0.0')
            ->setReportedKpis([]);
    }
}
