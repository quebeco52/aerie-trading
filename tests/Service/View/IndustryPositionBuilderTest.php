<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Data\Company\IndustryMarkets;
use App\DTO\MacroStateDTO;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Repository\CorporateReportRepository;
use App\Repository\StockRepository;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Industry\InMemoryIndustryShareStore;
use App\Service\Math\FinancialConstants;
use App\Service\View\IndustryPositionBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class IndustryPositionBuilderTest extends TestCase
{
    private InMemoryIndustryShareStore $store;
    private IndustryShareLedger $ledger;

    protected function setUp(): void
    {
        $this->store = new InMemoryIndustryShareStore();
        $this->ledger = new IndustryShareLedger($this->store);
    }

    private function stock(string $ticker, string $industry, float $revenue, float $equity = 100_000_000_000.0): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setIndustry($industry);
        $stock->setTotalRevenue((string) $revenue);
        $stock->setTotalEquity((string) $equity);
        $stock->setSamRatio('1.00');
        $stock->setRoicTtm('0.20');

        return $stock;
    }

    /** @var list<CorporateReport> The firm's newest filings, newest first. */
    private array $filings = [];

    /**
     * @param array<string, float>|null $kpis
     */
    private function builder(?array $kpis, int $tick = 100, ?StockRepository $stocks = null): IndustryPositionBuilder
    {
        $report = null;
        if ($kpis !== null) {
            $report = new CorporateReport();
            $report->setReportedKpis($kpis);
        }
        $reports = $this->createStub(CorporateReportRepository::class);
        $reports->method('findLatestFor')->willReturn($report);
        $reports->method('findForStocksNewestFirst')->willReturn($this->filings);

        $redis = $this->createStub(\Redis::class);
        $redis->method('get')->willReturn((string) $tick);

        return new IndustryPositionBuilder($this->ledger, new CorporateMetrics(), $reports, $redis, 252, $stocks);
    }

    /** @param list<Stock> $allStocks */
    private function stockRepository(array $allStocks): StockRepository
    {
        $repo = $this->createStub(StockRepository::class);
        $repo->method('findBy')->willReturnCallback(static function (array $criteria) use ($allStocks): array {
            if (!isset($criteria['industry'])) {
                return $allStocks;
            }

            return array_values(array_filter(
                $allStocks,
                static fn(Stock $s): bool => $s->getIndustry() === $criteria['industry'],
            ));
        });

        return $repo;
    }

    public function testAnIndustrialReadsItsShareTheBalanceAndTheLastReportsPriceResponse(): void
    {
        $firm = $this->stock('STL', 'Steel', 2_000.0);
        $rival = $this->stock('RVL', 'Steel', 2_000.0);
        $this->ledger->resolveIndustryCapacityRatio($firm, 2_000.0, 0.5, 1.0, 0.0, 0.0, 10, 252);
        $this->ledger->resolveIndustryCapacityRatio($rival, 2_000.0, 0.5, 1.0, 0.0, 0.0, 20, 252);
        // The rival, half its market, then builds half again: 1,000 of excess plant over a market of 4,000.
        $this->ledger->resolveIndustryCapacityRatio($rival, 3_000.0, 0.5, 1.0, 0.0, 0.0, 80, 252);

        $view = $this->builder(['industry_price_level' => 0.93, 'rival_share_drain' => -0.012, 'own_price_volume_shift' => 0.004])
            ->build($firm, new MacroStateDTO());

        // Headline: $100B of capital against a $1T addressable market. Whole-market shares come from the ledger.
        $this->assertEqualsWithDelta(0.10, $view['marketShare'], 1e-9);
        $industry = $view['industry'];
        $this->assertEqualsWithDelta(0.10, $industry['addressableShare'], 1e-9);
        $this->assertTrue($industry['sharedMarket']);
        $this->assertEqualsWithDelta(4_000.0, $industry['marketSize'], 1e-6);
        $this->assertEqualsWithDelta(0.5, $industry['marketShares']['STL'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $industry['marketShares']['RVL'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $industry['listedShare'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $industry['fringeShare'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $industry['herfindahl'], 1e-9);
        $this->assertSame(0.0, $industry['saturation']['penalty'], 'well under the saturation line');
        $this->assertEqualsWithDelta(0.20, $industry['saturation']['marginalReturn'], 1e-9);
        $this->assertTrue($industry['tracked']);
        $this->assertTrue($industry['pricesCapacity']);
        $this->assertEqualsWithDelta(1.25, $industry['capacityRatio'], 1e-9);
        $this->assertSame('overbuilt', $industry['balance']);
        $this->assertSame('warn', $industry['balanceTone'], 'an overbuilt industry is a caution, not a fall');
        $this->assertSame(2, $industry['listedCount']);
        $this->assertEqualsWithDelta(-0.07, $industry['priceMove'], 1e-9);
        $this->assertEqualsWithDelta(-0.012, $industry['rivalShareDrain'], 1e-9);
        $this->assertEqualsWithDelta(0.004, $industry['ownPriceVolumeShift'], 1e-9);
    }

    /**
     * The card shows the outlook the public holds, never the truth behind it, and the plant the last report left idle;
     * an industry with no outlook shows none.
     */
    public function testTheCardShowsThePublicsOutlookAndTheIdlePlant(): void
    {
        $firm = $this->stock('STL', 'Steel', 2_000.0);
        $this->store->writeOutlooks(['salt' => 1, 'quarter' => 4, 'industries' => ['Steel' => ['x' => 0.4, 'm' => 0.08, 'v' => 0.0036, 'f' => 0.0, 'w' => 1.0, 'prior_belief' => 0.02, 'revised_tick' => 252]]]);

        $industry = $this->builder(['idle_plant_share' => 0.12])->build($firm, new MacroStateDTO())['industry'];

        $this->assertNotNull($industry['outlook']);
        $this->assertEqualsWithDelta(exp(0.0818), $industry['outlook']['level'], 1e-12, 'the belief, not the true level');
        $this->assertEqualsWithDelta(0.06, $industry['outlook']['doubt'], 1e-12);
        $this->assertEqualsWithDelta(exp(0.0818 - 0.02) - 1.0, $industry['outlook']['revision'], 1e-12);
        $this->assertEqualsWithDelta(0.12, $industry['idlePlant'], 1e-12);

        $bank = $this->builder(null)->build($this->stock('BNK', 'Banks - Regional', 500.0), new MacroStateDTO())['industry'];
        $this->assertNull($bank['outlook']);
        $this->assertNull($bank['idlePlant']);
    }

    public function testALenderIsNotPricedOnCapacityButStillHasAShareOfItsIndustry(): void
    {
        $marketSize = IndustryMarkets::openingMarket('Banks - Regional');
        $bank = $this->stock('BNK', 'Banks - Regional', 0.10 * $marketSize);
        $peer = $this->stock('BNQ', 'Banks - Regional', 0.30 * $marketSize);
        $this->ledger->recordIdiosyncraticGain($bank, 0.10 * $marketSize, 0.0, 0.10, 10);
        $this->ledger->recordIdiosyncraticGain($peer, 0.30 * $marketSize, 0.0, 0.25, 10);

        $view = $this->builder(null)->build($bank, new MacroStateDTO());

        $this->assertEqualsWithDelta(0.10, $view['marketShare'], 1e-9);
        $this->assertTrue($view['industry']['isFinancial']);
        $this->assertTrue($view['industry']['sharedMarket']);
        $this->assertFalse($view['industry']['pricesCapacity']);
        $this->assertNull($view['industry']['capacityRatio']);
        $this->assertSame('unknown', $view['industry']['balance']);
        $this->assertSame(['BNQ', 'BNK'], array_keys($view['industry']['marketShares']), 'largest seller first');
        $this->assertEqualsWithDelta(0.10, $view['industry']['marketShares']['BNK'], 1e-9);
        $this->assertEqualsWithDelta(0.30, $view['industry']['marketShares']['BNQ'], 1e-9);
        $this->assertEqualsWithDelta(0.40, $view['industry']['listedShare'], 1e-9);
        $this->assertEqualsWithDelta(0.60, $view['industry']['fringeShare'], 1e-9);
        $this->assertEqualsWithDelta(0.10, $view['industry']['herfindahl'], 1e-9);
        $this->assertEqualsWithDelta($marketSize, $view['industry']['marketSize'], 1e-3);
        $this->assertNull($view['industry']['priceMove'], 'no report has been filed yet');
    }

    /**
     * Conglomerates is a listing label: its members sell nothing the others buy, so no shared market share is shown.
     */
    public function testListedPeersThatSellIntoDifferentMarketsShowNoShareAmongThem(): void
    {
        $group = $this->stock('GRP', 'Conglomerates', 1_000.0);
        $other = $this->stock('OTH', 'Conglomerates', 3_000.0);
        $this->ledger->recordIdiosyncraticGain($other, 3_000.0, 0.0, 0.5, 10);

        $industry = $this->builder(null, 100, $this->stockRepository([$group, $other]))
            ->build($group, new MacroStateDTO())['industry'];

        $this->assertSame(2, $industry['listedCount']);
        $this->assertFalse($industry['sharedMarket']);
        $this->assertSame([], $industry['marketShares']);
        $this->assertNull($industry['marketSize']);
        $this->assertNull($industry['fringeShare']);
        $this->assertNull($industry['herfindahl']);
    }

    /**
     * The card reads the market the M&A engine sizes deals on. A leader at 30% beside a peer at 10% sits in a
     * market with HHI 1,000 and 60% of fringe; at 30% the leader already holds the share ceiling, so the only
     * clearance left is the 100-point safe harbour, 1/60 of the market, and the review binds before the fringe.
     */
    public function testTheCardShowsTheMergerReviewTheEngineSizesDealsOn(): void
    {
        $leader = $this->stock('LDR', 'Steel', 3_000.0);
        $peer = $this->stock('PER', 'Steel', 1_000.0);
        $this->ledger->resolveIndustryCapacityRatio($leader, 3_000.0, 0.30, 1.0, 0.0, 0.0, 10, 252);
        $this->ledger->resolveIndustryCapacityRatio($peer, 1_000.0, 0.10, 1.0, 0.0, 0.0, 20, 252);

        $review = $this->builder(null, 30)->build($leader, new MacroStateDTO(potentialGdpIndex: 1.0, gdpDeflator: 1.0))['industry']['mergerReview'];

        $this->assertNotNull($review);
        $this->assertEqualsWithDelta(0.10, $review['herfindahl'], 1e-12);
        $this->assertFalse($review['highlyConcentrated']);
        $this->assertEqualsWithDelta(0.30, $review['acquirerShare'], 1e-12);
        $this->assertEqualsWithDelta(0.60, $review['fringeShare'], 1e-12);
        $this->assertEqualsWithDelta(\App\Service\Corporate\Deal\MergerReview::maxClearedTargetShare(0.30, 0.10), $review['clearedShare'], 1e-12);
        $this->assertEqualsWithDelta(0.01 / 0.60, $review['clearedShare'], 1e-12);
        $this->assertTrue($review['reviewBinds']);
    }

    /** Before the ledger has priced the firm the engine applies no review, and the card shows none. */
    public function testAnUnpricedFirmShowsNoMergerReview(): void
    {
        $this->assertNull($this->builder(null)->build($this->stock('NEW', 'Steel', 1_000.0), new MacroStateDTO())['industry']['mergerReview']);
    }

    /**
     * The page's trailing return is what the last four filings measured, not the model's own return, which is pulled
     * toward what competition lets the business keep; before any filing there is none to show.
     */
    public function testTheTrailingReturnIsWhatTheFilingsMeasured(): void
    {
        $firm = $this->stock('STL', 'Steel', 2_000.0);
        $this->assertNull($this->builder(null)->build($firm, new MacroStateDTO())['industry']['saturation']['trailingReturn']);

        $this->filings = array_map(static fn(string $roic): CorporateReport => (new CorporateReport())->setRoic($roic), ['0.1600', '0.1200', '0.0800', '0.1000']);
        $this->assertEqualsWithDelta(0.115, $this->builder(null)->build($firm, new MacroStateDTO())['industry']['saturation']['trailingReturn'], 1e-12);
    }

    public function testADelistedCompanyHasNoPositionLeft(): void
    {
        $shell = $this->stock('GONE', 'Steel', 2_000.0);
        $shell->setIsBankrupt(true);

        $view = $this->builder(null)->build($shell, new MacroStateDTO());

        $this->assertSame(0.0, $view['marketShare']);
        $this->assertFalse($view['industry']['tracked']);
        $this->assertFalse($view['industry']['sharedMarket']);
        $this->assertSame([], $view['industry']['marketShares']);
        $this->assertNull($view['industry']['marketSize']);
        $this->assertNull($view['industry']['fringeShare']);
        $this->assertNull($view['industry']['herfindahl']);
        $this->assertSame(0, $view['industry']['listedCount']);
    }

    public function testALoneFirmShowsItsWholeMarketShareAndSaturationPenalty(): void
    {
        // $750B of capital against a $1T market: past the 50% line, so the Penrose friction and the
        // Cobb-Douglas decay both bite, while its share bar reflects the 25% unlisted fringe rather than 100%.
        $giant = $this->stock('GNT', 'Railroads', 5_000.0, 750_000_000_000.0);
        $this->ledger->resolveIndustryCapacityRatio($giant, 5_000.0, 0.75, 1.0, 0.0, 0.0, 10, 252);

        $view = $this->builder(null)->build($giant, new MacroStateDTO(nominalGdpIndex: 1.0));
        $industry = $view['industry'];

        $this->assertEqualsWithDelta(0.75, $view['marketShare'], 1e-9);
        $this->assertTrue($industry['sharedMarket']);
        $this->assertEqualsWithDelta(['GNT' => 0.75], $industry['marketShares'], 1e-9);
        $this->assertEqualsWithDelta(0.25, $industry['fringeShare'], 1e-9);
        $this->assertSame(1, $industry['listedCount']);
        $this->assertGreaterThan(0.0, $industry['saturation']['penalty']);
        $this->assertGreaterThan(0.0, $industry['saturation']['severity']);
        $this->assertLessThan($industry['saturation']['trueReturn'], $industry['saturation']['marginalReturn']);
        $this->assertSame(FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD, $industry['saturation']['threshold']);
        // Its own plant against trend, weighed by its share, is still a balance worth showing.
        $this->assertEqualsWithDelta(1.0, $industry['capacityRatio'], 1e-9);
    }

    public function testPreFirstFilingMultiFirmIndustryReadsIndustryMarketsAndEquityShares(): void
    {
        $plvr = $this->stock('PLVR', 'Banks - Regional', 100.0, 350_000_000_000.0);
        $rivr = $this->stock('RIVR', 'Banks - Regional', 80.0, 200_000_000_000.0);
        $repo = $this->stockRepository([$plvr, $rivr]);
        $macro = new MacroStateDTO(nominalGdpIndex: 1.0, potentialGdpIndex: 1.0, gdpDeflator: 1.0, totalTime: 0.0);

        $view = $this->builder(null, 0, $repo)->build($plvr, $macro);
        $industry = $view['industry'];

        $this->assertTrue($industry['sharedMarket']);
        $this->assertSame(2, $industry['listedCount']);
        $this->assertEqualsWithDelta(['PLVR' => 0.35, 'RIVR' => 0.20], $industry['marketShares'], 1e-9);
        $this->assertEqualsWithDelta(0.55, $industry['listedShare'], 1e-9);
        $this->assertEqualsWithDelta(0.45, $industry['fringeShare'], 1e-9);
        $this->assertEqualsWithDelta((0.35 ** 2) + (0.20 ** 2), $industry['herfindahl'], 1e-12);
        $this->assertEqualsWithDelta(IndustryMarkets::openingMarket('Banks - Regional'), $industry['marketSize'], 1e-3);
        $this->assertEqualsWithDelta(IndustryMarkets::gdpShare('Banks - Regional'), $industry['marketGdpShare'], 1e-9);
        $this->assertNull($industry['mergerReview'], 'merger review remains absent before the ledger prices the firm');
    }

    public function testPreFirstFilingSingleFirmIndustryShowsUnlistedFringeInsteadOfFullMonopoly(): void
    {
        $firm = $this->stock('STL', 'Steel', 100.0, 120_000_000_000.0);
        $macro = new MacroStateDTO(nominalGdpIndex: 1.0, potentialGdpIndex: 1.0, gdpDeflator: 1.0, totalTime: 0.0);

        $industry = $this->builder(null, 0, $this->stockRepository([$firm]))->build($firm, $macro)['industry'];

        $this->assertTrue($industry['sharedMarket']);
        $this->assertSame(1, $industry['listedCount']);
        $this->assertEqualsWithDelta(['STL' => 0.12], $industry['marketShares'], 1e-9);
        $this->assertEqualsWithDelta(0.12, $industry['listedShare'], 1e-9);
        $this->assertEqualsWithDelta(0.88, $industry['fringeShare'], 1e-9);
        $this->assertEqualsWithDelta(IndustryMarkets::openingMarket('Steel'), $industry['marketSize'], 1e-3);
    }

    public function testNonIndustryMarketsSingleFirmImpliesSizeFromRevenueOrLeavesNullWithoutOperatingSales(): void
    {
        $fund = $this->stock('SWAN', 'Hedge Fund', 383_000_000_000.0, 400_000_000_000.0);
        $holding = $this->stock('BRKW', 'Investment Companies', 0.0, 700_000_000_000.0);
        $macro = new MacroStateDTO(nominalGdpIndex: 1.0, potentialGdpIndex: 1.0, gdpDeflator: 1.0, totalTime: 0.0);

        $fundIndustry = $this->builder(null, 0, $this->stockRepository([$fund]))->build($fund, $macro)['industry'];
        $holdingIndustry = $this->builder(null, 0, $this->stockRepository([$holding]))->build($holding, $macro)['industry'];

        $this->assertTrue($fundIndustry['sharedMarket']);
        $this->assertEqualsWithDelta(383_000_000_000.0 / 0.40, $fundIndustry['marketSize'], 1.0);
        $this->assertNotNull($fundIndustry['marketGdpShare']);

        $this->assertTrue($holdingIndustry['sharedMarket']);
        $this->assertNull($holdingIndustry['marketSize']);
        $this->assertNull($holdingIndustry['marketGdpShare']);
    }
}
