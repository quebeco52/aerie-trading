<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Data\Company\FinancialBooks;
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

    public function testSegmentOnlyIndustryBuildsBeforeAndAfterFilingsAndRejectsUnused(): void
    {
        $gdwt = $this->stock('GDWT', 'Goodwit Advisory', 'Consulting Services', 'Industrials', 120e9, 50e9);
        $tstn = $this->stock('TSTN', 'Teststone Group', 'Consulting Services', 'Industrials', 40e9, 20e9);
        $avct = $this->stock('AVCT', 'Avocet Assurance', 'Consulting Services', 'Industrials', 180e9, 80e9);

        // Pre-filing: all three Consulting firms with an IT Services segment appear cleanly with unknown shares.
        $pre = $this->builder([$gdwt, $tstn, $avct], tick: 0)->build('Information Technology Services');
        $this->assertNotNull($pre);
        $this->assertNull($pre['sector']);
        $this->assertSame([], $pre['companies']);
        $this->assertCount(3, $pre['entrants']);
        $this->assertNull($pre['marketSize']);
        $this->assertNull($pre['listedShare']);
        foreach ($pre['entrants'] as $entrant) {
            $this->assertNull($entrant['share']);
            $this->assertSame('Technology programmes', $entrant['segmentLabel']);
        }

        // Post-filing: shares across the three segment sellers plus the unlisted fringe sum to 1.0.
        $momentum = SegmentBooks::open([Segments::MAIN => 0.7, 'technology' => 0.3], 1.0, []);
        $gdwt->setEarningsMomentumZ($momentum);
        $tstn->setEarningsMomentumZ($momentum);
        $avct->setEarningsMomentumZ($momentum);
        $this->ledger->resolveSegmentSupply($avct, 'technology', 1_000.0, 1_000.0, 0.10, 1.0, 0.0, 0.0, 10, 252);
        $this->ledger->resolveSegmentSupply($gdwt, 'technology', 600.0, 600.0, 0.06, 1.0, 0.0, 0.0, 10, 252);
        $this->ledger->resolveSegmentSupply($tstn, 'technology', 400.0, 400.0, 0.04, 1.0, 0.0, 0.0, 10, 252);

        $live = $this->builder([$gdwt, $tstn, $avct], tick: 20)->build('Information Technology Services');
        $this->assertNotNull($live);
        $this->assertEqualsWithDelta(10_000.0, $live['marketSize'], 1e-6);
        $this->assertEqualsWithDelta(0.20, $live['listedShare'], 1e-9);
        $this->assertEqualsWithDelta(0.80, $live['fringeShare'], 1e-9);
        $this->assertEqualsWithDelta((0.10 ** 2) + (0.06 ** 2) + (0.04 ** 2), $live['herfindahl'], 1e-12);
        $this->assertSame(['AVCT', 'GDWT', 'TSTN'], array_column($live['entrants'], 'ticker'));
        $this->assertEqualsWithDelta(
            1.0,
            array_sum(array_column($live['entrants'], 'share')) + $live['fringeShare'],
            1e-9,
        );

        $this->assertNull($this->builder([$gdwt, $tstn, $avct], tick: 0)->build('No Such Trade'));
    }

    public function testFinancialBooksPopulateCrossIndustryEntrantInLifeInsurance(): void
    {
        $well = $this->stock('WELL', 'Wellspring Life', 'Insurance - Life', 'Financials', 90e9, 60_000_000_000.0)
            ->setTotalRevenue('90000000000')
            ->setSamRatio('0.252');
        $dove = $this->stock('DOVE', 'White Dove Insurance', 'Insurance - Diversified', 'Financials', 121e9, 84_700_000_000.0)
            ->setTotalRevenue('121000000000')
            ->setSamRatio('0.248');

        $expectedWell = 90_000_000_000.0 / IndustryMarkets::openingMarket('Insurance - Life');
        $expectedDoveLife = (121_000_000_000.0 * 0.45) / IndustryMarkets::openingMarket('Insurance - Life');
        $expectedDovePc = (121_000_000_000.0 * 0.55) / IndustryMarkets::openingMarket('Insurance - Diversified');

        $preLife = $this->builder([$well, $dove], tick: 0)->build('Insurance - Life');
        $preDiv = $this->builder([$well, $dove], tick: 0)->build('Insurance - Diversified');
        $this->assertNotNull($preLife);
        $this->assertNotNull($preDiv);
        $this->assertCount(1, $preLife['companies']);
        $this->assertCount(1, $preLife['entrants']);
        $this->assertSame('DOVE', $preLife['entrants'][0]['ticker']);
        $this->assertSame('Insurance - Diversified', $preLife['entrants'][0]['home']);
        $this->assertSame('Life and annuities', $preLife['entrants'][0]['segmentLabel']);
        $this->assertEqualsWithDelta($expectedWell, $preLife['companies'][0]['share'], 1e-6);
        $this->assertEqualsWithDelta($expectedDoveLife, $preLife['entrants'][0]['share'], 1e-6);
        $this->assertEqualsWithDelta(1.0, $expectedWell + $expectedDoveLife + $preLife['fringeShare'], 1e-6);
        $this->assertEqualsWithDelta($expectedDovePc, $preDiv['companies'][0]['share'], 1e-6);
        $this->assertSame([], $preDiv['entrants']);

        // Post-Q1 ledger records preserve the entrant label and combined whole-market shares.
        $this->ledger->recordIdiosyncraticGain($well, 90_000_000_000.0, 0.0, $expectedWell, 16);
        $this->ledger->recordBookGain($dove, FinancialBooks::MAIN, 121_000_000_000.0 * 0.55, 0.0, $expectedDovePc, 20);
        $this->ledger->recordBookGain($dove, 'life', 121_000_000_000.0 * 0.45, 0.0, $expectedDoveLife, 20);

        $postLife = $this->builder([$well, $dove], tick: 30)->build('Insurance - Life');
        $this->assertNotNull($postLife);
        $this->assertSame('Life and annuities', $postLife['entrants'][0]['segmentLabel'] ?? null);
        $this->assertEqualsWithDelta($expectedDoveLife, $postLife['entrants'][0]['share'], 1e-6);
        $this->assertEqualsWithDelta($expectedWell, $postLife['companies'][0]['share'], 1e-6);
        $this->assertEqualsWithDelta(1.0, $expectedWell + $expectedDoveLife + $postLife['fringeShare'], 1e-6);
    }

    public function testFinancialDataMarketCombinesListedCompaniesOperatingSegmentAndFinancialBookEntrants(): void
    {
        $dataMarket = IndustryMarkets::openingMarket('Financial Data & Ratings');
        $shrk = $this->stock('SHRK', 'Shrike Standard Ratings', 'Financial Data & Ratings', 'Financials', 40e9, 12_000_000_000.0)
            ->setTotalRevenue('8670000000')
            ->setSamRatio((string) (8_670_000_000.0 / $dataMarket));
        $tick = $this->stock('TICK', 'Tickline Terminal', 'Financial Data & Ratings', 'Financials', 42e9, 15_000_000_000.0)
            ->setTotalRevenue('8870000000')
            ->setSamRatio((string) (8_870_000_000.0 / $dataMarket));
        $rook = $this->stock('ROOK', 'Rook Exchange', 'Securities & Commodity Exchanges', 'Financials', 30e9, 18_000_000_000.0)
            ->setTotalRevenue('7630000000');
        $acc = $this->stock('ACC', 'Aerie Clearing Corp', 'Financial Clearinghouses', 'Financials', 50e9, 20_000_000_000.0)
            ->setTotalRevenue('12500000000');

        $expectedShrk = 8_670_000_000.0 / $dataMarket;
        $expectedTick = 8_870_000_000.0 / $dataMarket;
        $expectedRook = (7_630_000_000.0 * 0.30) / $dataMarket;
        $expectedAcc = (12_500_000_000.0 * 0.20) / $dataMarket;

        $pre = $this->builder([$shrk, $tick, $rook, $acc], tick: 0)->build('Financial Data & Ratings');
        $this->assertNotNull($pre);
        $this->assertSame(['TICK', 'SHRK'], array_column($pre['companies'], 'ticker'));
        $this->assertSame(['ACC'], array_column($pre['entrants'], 'ticker'));
        $this->assertSame('Data and licensing', $pre['entrants'][0]['segmentLabel'] ?? null);
        $this->assertEqualsWithDelta($expectedAcc, $pre['entrants'][0]['share'], 1e-6);
        $this->assertEqualsWithDelta(
            1.0,
            array_sum(array_column($pre['companies'], 'share')) + $pre['entrants'][0]['share'] + $pre['fringeShare'],
            1e-9,
        );

        $this->ledger->resolveIndustryCapacityRatio($shrk, 8_670_000_000.0, $expectedShrk, 1.0, 0.0, 0.0, 23, 252);
        $this->ledger->resolveIndustryCapacityRatio($tick, 8_870_000_000.0, $expectedTick, 1.0, 0.0, 0.0, 36, 252);
        $rook->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.70, 'market_data' => 0.30], 1.0, []));
        $this->ledger->resolveSegmentSupply($rook, 'market_data', 2_289_000_000.0, 2_289_000_000.0, $expectedRook, 1.0, 0.0, 0.0, 38, 252);
        $this->ledger->recordBookGain($acc, 'data', 2_500_000_000.0, 0.0, $expectedAcc, 21);

        $live = $this->builder([$shrk, $tick, $rook, $acc], tick: 40)->build('Financial Data & Ratings');
        $this->assertNotNull($live);
        $this->assertSame(['TICK', 'SHRK'], array_column($live['companies'], 'ticker'));
        $entrants = array_column($live['entrants'], null, 'ticker');
        $this->assertArrayHasKey('ROOK', $entrants);
        $this->assertArrayHasKey('ACC', $entrants);
        $this->assertSame('Market data', $entrants['ROOK']['segmentLabel'] ?? null);
        $this->assertSame('Data and licensing', $entrants['ACC']['segmentLabel'] ?? null);
        $this->assertEqualsWithDelta($expectedRook, $entrants['ROOK']['share'], 1e-9);
        $this->assertEqualsWithDelta($expectedAcc, $entrants['ACC']['share'], 1e-9);
        $this->assertEqualsWithDelta(
            1.0,
            $expectedShrk + $expectedTick + $expectedRook + $expectedAcc + $live['fringeShare'],
            1e-6,
        );
    }

    /**
     * @param list<Stock>           $allStocks
     * @param list<CorporateReport> $reports
     */
    private function builder(array $allStocks, array $reports = [], int $tick = 100): IndustryPageBuilder
    {
        $stocksRepo = $this->createStub(StockRepository::class);
        $stocksRepo->method('findAll')->willReturn($allStocks);
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
