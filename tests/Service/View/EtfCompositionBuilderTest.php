<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Entity\Etf;
use App\Entity\Stock;
use App\Repository\StockRepository;
use App\Service\Market\EtfTracker;
use App\Service\Market\Index\InMemoryIndexMembershipStore;
use App\Service\Market\Index\MarketIndex;
use App\Service\Market\IndexCommittee;
use App\Service\Market\PriceChangeFeed;
use App\Service\Math\FinancialConstants;
use App\Service\View\EtfCompositionBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class EtfCompositionBuilderTest extends TestCase
{
    private const TICKS_PER_YEAR = 14400;

    private InMemoryIndexMembershipStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryIndexMembershipStore();
    }

    /**
     * @param list<Stock>          $board
     * @param array<string, float> $changes ticker => trailing-month change
     */
    private function builder(array $board, int $tick = 0, array $changes = [], ?float $divisor = null): EtfCompositionBuilder
    {
        $stocks = $this->createMock(StockRepository::class);
        $stocks->method('findAll')->willReturn($board);

        $etfTracker = $this->createStub(EtfTracker::class);
        $etfTracker->method('currentDivisor')->willReturn($divisor);

        $feed = $this->createStub(PriceChangeFeed::class);
        $feed->method('changeByTicker')->willReturn($changes);

        $redis = $this->createStub(\Redis::class);
        $redis->method('get')->willReturn((string) $tick);

        return new EtfCompositionBuilder(
            $stocks,
            new IndexCommittee($this->store, $etfTracker),
            $etfTracker,
            $feed,
            $redis,
            self::TICKS_PER_YEAR
        );
    }

    private function listing(string $ticker, float $price, float $shares, bool $bankrupt = false, string $sector = 'Industrials'): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setName($ticker . ' Co');
        $stock->setSector($sector);
        $stock->setPrice((string) $price);
        $stock->setSharesOutstanding((string) $shares);
        $stock->setIsBankrupt($bankrupt);

        return $stock;
    }

    /** @return list<Stock> $n names with strictly decreasing float capitalisation. */
    private function rankedBoard(int $n): array
    {
        $board = [];
        for ($i = 0; $i < $n; $i++) {
            $board[] = $this->listing(sprintf('T%03d', $i), 1000.0 - $i, 1.0);
        }

        return $board;
    }

    // --- Weights ---

    public function testWeightsAreCapitalisationSharesAndSumToTheWholeFund(): void
    {
        // No membership taken, which is the fund tracking the whole live board — still what a market does
        // before its first reconstitution.
        $composition = $this->builder([
            $this->listing('BIG', 100.0, 3_000.0),   // 300,000
            $this->listing('SMALL', 10.0, 1_000.0),  //  10,000
        ])->build(MarketIndex::Composite);

        $weights = array_column($composition['components'], 'weight', 'ticker');

        $this->assertEqualsWithDelta(100.0, array_sum($weights), 1e-9);
        $this->assertEqualsWithDelta(300_000 / 310_000 * 100, $weights['BIG'], 1e-9);
    }

    public function testConstituentsAreListedHeaviestFirstAndRanked(): void
    {
        $composition = $this->builder([
            $this->listing('SMALL', 10.0, 1_000.0),
            $this->listing('BIG', 100.0, 3_000.0),
            $this->listing('MID', 50.0, 1_000.0),
        ])->build(MarketIndex::Composite);

        $this->assertSame(['BIG', 'MID', 'SMALL'], array_column($composition['components'], 'ticker'));
        $this->assertSame([1, 2, 3], array_column($composition['components'], 'rank'));
    }

    /**
     * A delisted shell is not a holding. Counting it would both list it as a constituent and dilute
     * every live weight by a claim that no longer exists.
     */
    public function testADelistedShellIsNotAHolding(): void
    {
        $composition = $this->builder([
            $this->listing('LIVE', 100.0, 1_000.0),
            $this->listing('DEAD', 80.0, 1_000.0, bankrupt: true),
        ])->build(MarketIndex::Composite);

        $this->assertSame(['LIVE'], array_column($composition['components'], 'ticker'));
        $this->assertSame(['LIVE'], $composition['pieLabels']);
        $this->assertArrayNotHasKey('DEAD', $composition['sharesMap']);
        $this->assertEqualsWithDelta(100.0, $composition['components'][0]['weight'], 1e-9);
    }

    public function testTheSeriesStayAlignedWithTheConstituentList(): void
    {
        $composition = $this->builder([
            $this->listing('AAA', 20.0, 1_000.0),
            $this->listing('BBB', 30.0, 1_000.0),
        ])->build(MarketIndex::Composite);

        $this->assertSame(['BBB', 'AAA'], $composition['pieLabels']);
        $this->assertSame([30_000.0, 20_000.0], $composition['pieData']);
        $this->assertSame(['BBB' => 1_000.0, 'AAA' => 1_000.0], $composition['sharesMap']);
    }

    /**
     * A board on which nothing is still listed must not divide by a zero total.
     */
    public function testAnEntirelyDelistedBoardYieldsNoWeightsRatherThanDividingByZero(): void
    {
        $composition = $this->builder([
            $this->listing('DEAD', 80.0, 1_000.0, bankrupt: true),
        ])->build(MarketIndex::Composite);

        $this->assertSame([], $composition['components']);
        $this->assertSame([], $composition['pieData']);
        $this->assertSame(0, $composition['indexFacts']['memberCount']);
        $this->assertNull($composition['indexFacts']['largest']);
    }

    public function testWeightsAreStruckOnTheFloatNotTheWholeCompany(): void
    {
        // What a passive fund can hold is the part of the company that trades. Two firms of identical
        // market capitalisation are not identical positions if one of them is mostly closely held.
        $open = $this->listing('OPEN', 100.0, 1_000_000);
        $closed = $this->listing('CLOSED', 100.0, 1_000_000);
        $closed->setPublicFloatPercentage('0.2500');

        $components = $this->builder([$open, $closed])->build(MarketIndex::Composite)['components'];
        $byTicker = array_column($components, null, 'ticker');

        $this->assertGreaterThan($byTicker['CLOSED']['weight'], $byTicker['OPEN']['weight']);
        $this->assertEqualsWithDelta(80.0, $byTicker['OPEN']['weight'], 1e-6);
        $this->assertEqualsWithDelta(20.0, $byTicker['CLOSED']['weight'], 1e-6);
    }

    public function testTheLiveRepaintSharesAreFloatAdjustedToo(): void
    {
        // The pie is repainted on the client as price x shares. Handing it whole-company shares would let
        // the weights slide back to total capitalisation on the first tick after the page loaded.
        $closed = $this->listing('CLOSED', 100.0, 1_000_000);
        $closed->setPublicFloatPercentage('0.2500');

        $sharesMap = $this->builder([$closed])->build(MarketIndex::Composite)['sharesMap'];

        $this->assertEqualsWithDelta(250_000.0, $sharesMap['CLOSED'], 1e-6);
    }

    // --- Membership ---

    public function testOnlyTheIndexMembersAreHoldings(): void
    {
        $inside = $this->listing('IN', 100.0, 1_000_000);
        $outside = $this->listing('OUT', 100.0, 1_000_000);

        $this->store->store(MarketIndex::Headline, 0, ['IN']);

        $composition = $this->builder([$inside, $outside])->build(MarketIndex::Headline);

        $this->assertSame(['IN'], array_column($composition['components'], 'ticker'));
        $this->assertSame(1, $composition['indexFacts']['memberCount']);
        $this->assertSame(2, $composition['indexFacts']['listedCount']);
    }

    public function testARankIsAgainstTheWholeBoardNotJustTheMembers(): void
    {
        // The largest company on the board may be outside a selective index; a member's rank still says
        // where it stands among everything listed, which is what the bands are measured on.
        $board = $this->rankedBoard(5);
        $this->store->store(MarketIndex::Headline, 0, ['T002', 'T004']);

        $components = $this->builder($board)->build(MarketIndex::Headline)['components'];

        $this->assertSame([3, 5], array_column($components, 'rank'));
    }

    public function testANameAdmittedAtTheLastReviewIsFlagged(): void
    {
        $board = $this->rankedBoard(3);
        $this->store->store(MarketIndex::Headline, 0, ['T000', 'T001'], added: ['T001'], deleted: ['T002']);

        $composition = $this->builder($board)->build(MarketIndex::Headline);
        $byTicker = array_column($composition['components'], null, 'ticker');

        $this->assertFalse($byTicker['T000']['isNew']);
        $this->assertTrue($byTicker['T001']['isNew']);
        $this->assertSame(['T001'], $composition['indexFacts']['added']);
        $this->assertSame(['T002'], $composition['indexFacts']['deleted']);
    }

    // --- Factsheet ---

    public function testSectorWeightsSumToTheFundAndAreListedHeaviestFirst(): void
    {
        $composition = $this->builder([
            $this->listing('A', 60.0, 1.0, sector: 'Technology'),
            $this->listing('B', 30.0, 1.0, sector: 'Financials'),
            $this->listing('C', 10.0, 1.0, sector: 'Technology'),
        ])->build(MarketIndex::Composite);

        $sectors = $composition['indexFacts']['sectorWeights'];

        $this->assertSame(['Technology', 'Financials'], array_column($sectors, 'sector'));
        $this->assertSame([2, 1], array_column($sectors, 'count'));
        $this->assertEqualsWithDelta(70.0, $sectors[0]['weight'], 1e-9);
        $this->assertEqualsWithDelta(100.0, array_sum(array_column($sectors, 'weight')), 1e-9);
    }

    public function testConcentrationIsTheWeightOfTheLargestTen(): void
    {
        $facts = $this->builder($this->rankedBoard(20))->build(MarketIndex::Composite)['indexFacts'];

        $expected = 0.0;
        for ($i = 0; $i < EtfCompositionBuilder::CONCENTRATION_TOP_N; $i++) {
            $expected += 1000.0 - $i;
        }
        $total = 0.0;
        for ($i = 0; $i < 20; $i++) {
            $total += 1000.0 - $i;
        }

        $this->assertSame(EtfCompositionBuilder::CONCENTRATION_TOP_N, $facts['topN']);
        $this->assertEqualsWithDelta($expected / $total * 100.0, $facts['topWeight'], 1e-9);
        $this->assertSame('T000', $facts['largest']['ticker']);
    }

    public function testTheFactsheetCarriesTheIndexIdentity(): void
    {
        $facts = $this->builder($this->rankedBoard(3), divisor: 12345.0)->build(MarketIndex::Headline)['indexFacts'];

        $this->assertSame('LBI', $facts['ticker']);
        $this->assertTrue($facts['isSelective']);
        $this->assertTrue($facts['carriesPassiveBook']);
        $this->assertSame(FinancialConstants::INDEX_CONSTITUENT_COUNT, $facts['constituentCount']);
        $this->assertSame(12345.0, $facts['divisor']);
        $this->assertSame(FinancialConstants::INDEX_RECONSTITUTIONS_PER_YEAR, $facts['reconstitutionsPerYear']);
        $this->assertNotSame('', $facts['mandate']);
    }

    public function testTheCalendarCountsDownToTheNextReviewInSimulatedDays(): void
    {
        $interval = IndexCommittee::intervalTicks(self::TICKS_PER_YEAR);
        $this->store->store(MarketIndex::Headline, $interval, ['T000']);

        // A quarter of a year of ticks after the last review, minus one tick: the next is one tick away.
        $facts = $this->builder($this->rankedBoard(2), tick: 2 * $interval - 1)->build(MarketIndex::Headline)['indexFacts'];

        $daysPerTick = 365.0 / self::TICKS_PER_YEAR;

        $this->assertSame($interval, $facts['lastReconstitutionTick']);
        $this->assertEqualsWithDelta(($interval - 1) * $daysPerTick, $facts['daysSinceReconstitution'], 1e-9);
        $this->assertEqualsWithDelta($daysPerTick, $facts['daysUntilReconstitution'], 1e-9);
    }

    public function testTheChangeFigureRidesAlongPerConstituent(): void
    {
        $composition = $this->builder($this->rankedBoard(2), changes: ['T000' => 0.05])->build(MarketIndex::Composite);
        $byTicker = array_column($composition['components'], null, 'ticker');

        $this->assertEqualsWithDelta(0.05, $byTicker['T000']['changePercent'], 1e-12);
        $this->assertNull($byTicker['T001']['changePercent'], 'no buffered history is unknown, not flat');
    }

    // --- Watch List ---

    public function testTheWatchListDrawsTheCommitteesOwnBands(): void
    {
        $count = FinancialConstants::INDEX_CONSTITUENT_COUNT;
        $bands = IndexCommittee::bands($count);
        $board = $this->rankedBoard(50);

        // The standing membership is the top $count names; then prices move so that one member has sunk
        // to the very bottom of the board and one outsider has risen to the very top of it.
        $members = [];
        for ($i = 0; $i < $count; $i++) {
            $members[] = sprintf('T%03d', $i);
        }
        $this->store->store(MarketIndex::Headline, 0, $members);

        $board[$count - 1]->setPrice('1.00');   // a member, now ranked last
        $board[49]->setPrice('5000.00');       // an outsider, now ranked first

        $facts = $this->builder($board)->build(MarketIndex::Headline)['indexFacts'];

        $this->assertSame($bands, $facts['bands']);

        $atRisk = array_column($facts['atRisk'], null, 'ticker');
        $sunk = sprintf('T%03d', $count - 1);
        $this->assertArrayHasKey($sunk, $atRisk);
        $this->assertSame(50, $atRisk[$sunk]['rank']);
        $this->assertTrue($atRisk[$sunk]['exits'], 'past the outer band, it is dropped at the next review whatever happens');

        $contenders = array_column($facts['contenders'], null, 'ticker');
        $this->assertArrayHasKey('T049', $contenders);
        $this->assertSame(1, $contenders['T049']['rank']);
        $this->assertTrue($contenders['T049']['qualifies'], 'inside the inner band, it is admitted at the next review');

        // A member just inside the core is exposed but not yet leaving; an outsider just past the cut is
        // watched but does not yet qualify.
        foreach ($facts['atRisk'] as $row) {
            $this->assertGreaterThan($bands['inner'], $row['rank']);
        }
        foreach ($facts['contenders'] as $row) {
            $this->assertLessThanOrEqual($bands['outer'], $row['rank']);
            if (!$row['qualifies']) {
                $this->assertGreaterThan($bands['inner'], $row['rank']);
            }
        }
    }

    public function testASettledBoundaryNamesTheExposedTailAndTheNearOutsidersWithNobodyActingYet(): void
    {
        // A full membership at ranks 1..count with the rest of the board below it. The weakest members past
        // the inner band are exposed but not leaving; the outsiders inside the outer band are close but do
        // not qualify. That is what a quiet quarter looks like, and the page must not dress it up as a change.
        $count = FinancialConstants::INDEX_CONSTITUENT_COUNT;
        $bands = IndexCommittee::bands($count);
        $board = $this->rankedBoard($bands['outer'] + 10);

        $members = [];
        for ($i = 0; $i < $count; $i++) {
            $members[] = sprintf('T%03d', $i);
        }
        $this->store->store(MarketIndex::Headline, 0, $members);

        $facts = $this->builder($board)->build(MarketIndex::Headline)['indexFacts'];

        $this->assertCount($count - $bands['inner'], $facts['atRisk']);
        $this->assertCount($bands['outer'] - $count, $facts['contenders']);
        $this->assertSame([], array_filter($facts['atRisk'], static fn (array $row): bool => $row['exits']));
        $this->assertSame([], array_filter($facts['contenders'], static fn (array $row): bool => $row['qualifies']));
    }

    public function testTheCompositeHasNoBoundaryToWatch(): void
    {
        $this->store->store(MarketIndex::Composite, 0, ['T000', 'T001']);

        $facts = $this->builder($this->rankedBoard(2))->build(MarketIndex::Composite)['indexFacts'];

        $this->assertNull($facts['bands']);
        $this->assertSame([], $facts['atRisk']);
        $this->assertSame([], $facts['contenders']);
    }

    // --- Weighted And Sector Funds ---

    /**
     * The factsheet reports the portfolio the fund actually runs, not the one its members' sizes imply.
     *
     * A page that summed raw float caps would print a cap-weighted portfolio for every index, which for a
     * low-volatility or a capped sector fund is simply a different fund from the one on the page.
     */
    public function testWeightsCarryTheFactorTheCommitteeStruck(): void
    {
        $board = [
            $this->listing('BIG', 800.0, 1.0),
            $this->listing('SMALL', 200.0, 1.0),
        ];

        // A review that deliberately inverts size: the small name is to carry three quarters of the fund.
        // factor = target weight x member cap / own cap.
        $this->store->store(
            MarketIndex::LowVolatility,
            0,
            ['SMALL', 'BIG'],
            weights: ['SMALL' => 0.75, 'BIG' => 0.25],
            factors: ['SMALL' => (0.75 * 1000.0) / 200.0, 'BIG' => (0.25 * 1000.0) / 800.0]
        );

        $result = $this->builder($board)->build(MarketIndex::LowVolatility);
        $weights = array_column($result['components'], 'weight', 'ticker');

        $this->assertEqualsWithDelta(75.0, $weights['SMALL'], 1e-9);
        $this->assertEqualsWithDelta(25.0, $weights['BIG'], 1e-9);

        // Rows are ordered by what the fund holds, so the largest HOLDING leads whatever its size.
        $this->assertSame('SMALL', $result['components'][0]['ticker']);
        $this->assertSame('SMALL', $result['indexFacts']['largest']['ticker']);

        // And the target the review set is carried beside the drifted weight.
        $targets = array_column($result['components'], 'targetWeight', 'ticker');
        $this->assertEqualsWithDelta(75.0, $targets['SMALL'], 1e-9);
    }

    /**
     * The live table's fallback capitalisation has to be on the same basis as the figures the live frame
     * carries, or a weighted fund's bars would mix adjusted and unadjusted caps in one total.
     */
    public function testTheLiveFallbackCapitalisationCarriesTheFactorToo(): void
    {
        $board = [$this->listing('ONE', 500.0, 1.0), $this->listing('TWO', 500.0, 1.0)];

        $this->store->store(
            MarketIndex::LowVolatility,
            0,
            ['ONE', 'TWO'],
            weights: ['ONE' => 0.8, 'TWO' => 0.2],
            factors: ['ONE' => 1.6, 'TWO' => 0.4]
        );

        $result = $this->builder($board)->build(MarketIndex::LowVolatility);
        $adjusted = array_column($result['components'], 'adjustedCap', 'ticker');

        $this->assertEqualsWithDelta(800.0, $adjusted['ONE'], 1e-9);
        $this->assertEqualsWithDelta(200.0, $adjusted['TWO'], 1e-9);
        $this->assertEqualsWithDelta(0.8, $adjusted['ONE'] / array_sum($adjusted), 1e-9);

        // The shares the live repaint multiplies by price carry the same factor.
        $this->assertEqualsWithDelta(1.6, $result['sharesMap']['ONE'], 1e-9);
    }

    // --- What The Fund Costs And Pays ---

    /**
     * The factsheet reports the fund's own economics, not just its index's composition.
     *
     * The fee is the figure that matters and it is the one a holder can least easily see: it is taken out
     * of the dividend income before the income is ever distributed, so it never shows up as a fall in the
     * price. Reporting what it has come to is the only place a holder meets it.
     */
    public function testTheFactsheetReportsWhatTheFundCostsAndHasPaid(): void
    {
        $this->store->store(MarketIndex::Headline, 0, ['T000']);

        $fund = new Etf();
        $fund->setTicker('LBI');
        $fund->setName('Skein Lakebird 30 ETF');
        $fund->setPrice('200.00');
        $fund->setExpenseRatio(0.0005);
        $fund->setCumulativeFeesPaid(1.94);
        $fund->setAccruedIncome(0.85);
        $fund->recordDistribution(1.00, new \DateTime());
        $fund->recordDistribution(1.10, new \DateTime());

        $facts = $this->builder($this->rankedBoard(1))->build(MarketIndex::Headline, $fund)['indexFacts'];

        $this->assertEqualsWithDelta(0.05, $facts['expenseRatio'], 1e-9);
        $this->assertEqualsWithDelta(1.94, $facts['feesPaidPerShare'], 1e-9);
        $this->assertEqualsWithDelta(0.85, $facts['accruedIncome'], 1e-9);

        // A trailing yield is the year of payments over the price, not the last one annualised.
        $this->assertEqualsWithDelta(2.10, $facts['trailingDistribution'], 1e-9);
        $this->assertEqualsWithDelta((2.10 / 200.0) * 100.0, $facts['distributionYield'], 1e-9);

        // Nothing was sold to meet the fee, so the fund still owns a whole index unit per share.
        $this->assertEqualsWithDelta(0.0, $facts['holdingsSoldForFees'], 1e-9);
    }

    /** Without a fund there is no fund to report on, and the page says so rather than inventing zeroes. */
    public function testTheFundFiguresAreAbsentWhenNoFundIsGiven(): void
    {
        $this->store->store(MarketIndex::Headline, 0, ['T000']);

        $facts = $this->builder($this->rankedBoard(1))->build(MarketIndex::Headline)['indexFacts'];

        $this->assertNull($facts['expenseRatio']);
        $this->assertNull($facts['feesPaidPerShare']);
        $this->assertNull($facts['distributionYield']);
    }

    /**
     * A sector fund is ranked against its sector, not against the board.
     *
     * Ranking it against the board would tell its largest constituent it is 41st, which is a statement about
     * a decision this index never makes.
     */
    public function testASectorFundIsRankedWithinItsOwnUniverse(): void
    {
        $board = [
            $this->listing('MEGA', 9000.0, 1.0, sector: 'Information Technology'),
            $this->listing('MILK', 300.0, 1.0, sector: 'Consumer Staples'),
            $this->listing('BEER', 100.0, 1.0, sector: 'Consumer Staples'),
        ];

        $this->store->store(MarketIndex::Staples, 0, ['MILK', 'BEER']);

        $result = $this->builder($board)->build(MarketIndex::Staples);
        $ranks = array_column($result['components'], 'rank', 'ticker');

        $this->assertSame(1, $ranks['MILK']);
        $this->assertSame(2, $ranks['BEER']);

        // The listed count is the universe the index draws from, not the whole board.
        $this->assertSame(2, $result['indexFacts']['listedCount']);
        $this->assertSame('Consumer Staples', $result['indexFacts']['universe']);
    }
}
