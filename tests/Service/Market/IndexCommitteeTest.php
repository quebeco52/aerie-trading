<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\Etf;
use App\Entity\Stock;
use App\Service\Market\EtfTracker;
use App\Service\Market\IndexCommittee;
use App\Service\Market\Index\InMemoryIndexMembershipStore;
use App\Service\Market\Index\MarketIndex;
use App\Command\MarketTickerCommand;
use App\Service\Math\FinancialConstants;
use App\Tests\Support\StockBuilder;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Market\LiquidityEngine;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * The index committee.
 *
 * The property everything else rests on is CONTINUITY: an index measures the market's return, not the
 * arithmetic of its own composition, so changing who is counted must not move the level. Everything else
 * here — banding, float adjustment, dropping the dead — is about which changes are worth making at all.
 */
class IndexCommitteeTest extends TestCase
{
    private InMemoryIndexMembershipStore $store;
    private EtfTracker $etfTracker;
    /** @var array<string, string> */
    private array $redisStore = [];

    protected function setUp(): void
    {
        $this->store = new InMemoryIndexMembershipStore();
        $this->redisStore = [];

        // A Redis stand-in holding just the divisor, which is the only key the tracker touches here.
        $redis = new class($this->redisStore) extends \Redis {
            /** @param array<string, string> $backing */
            public function __construct(private array &$backing) {}

            public function get($key): string|false
            {
                return $this->backing[$key] ?? false;
            }

            public function set($key, $value, $options = null): \Redis|string|bool
            {
                $this->backing[$key] = (string) $value;

                return true;
            }
        };

        $this->etfTracker = new EtfTracker(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(\App\Service\Event\MarketEventPublisher::class),
            $redis,
            new \App\Service\Market\IndexFundAccountant($this->createStub(EntityManagerInterface::class))
        );
    }

    private function committee(): IndexCommittee
    {
        return new IndexCommittee($this->store, $this->etfTracker, new LiquidityEngine(new MathUtility()));
    }

    /**
     * @param array<string, float> $caps ticker => float-adjusted capitalisation
     * @return array<int, Stock>
     */
    private function universe(array $caps): array
    {
        $stocks = [];

        foreach ($caps as $ticker => $cap) {
            // One share outstanding and a full float, so the price IS the capitalisation and the fixtures
            // read as the number the test is actually about.
            $stocks[] = StockBuilder::create($ticker)
                ->withPrice($cap)
                ->withSharesOutstanding(1)
                ->withPublicFloatPercentage(1.0)
                ->build();
        }

        return $stocks;
    }

    /** A universe of $n names with strictly decreasing capitalisation. */
    private function rankedUniverse(int $n, float $top = 1.0e12): array
    {
        $caps = [];
        for ($i = 0; $i < $n; $i++) {
            $caps[sprintf('T%03d', $i)] = $top * (1.0 - ($i / ($n * 2.0)));
        }

        return $this->universe($caps);
    }

    // --- Continuity ---

    public function testTheLevelDoesNotMoveWhenTheMembershipDoes(): void
    {
        $committee = $this->committee();
        $stocks = $this->rankedUniverse(60);

        $first = $committee->reconstitute(MarketIndex::Headline, $stocks, 0);
        $levelAfterFirst = $first['level'];

        // A new name arrives large enough to force its way in, without any price changing.
        $stocks[] = StockBuilder::create('NEWCO')->withPrice(9.0e11)->withSharesOutstanding(1)->withPublicFloatPercentage(1.0)->build();

        $second = $committee->reconstitute(MarketIndex::Headline, $stocks, 100);

        $this->assertNotEmpty($second['added'], 'the fixture should have forced a membership change');
        $this->assertEqualsWithDelta($levelAfterFirst, $second['level'], $levelAfterFirst * 1e-9);
    }

    public function testTheDivisorIsRestatedRatherThanLeftAlone(): void
    {
        $committee = $this->committee();
        $stocks = $this->rankedUniverse(60);

        $committee->reconstitute(MarketIndex::Headline, $stocks, 0);
        $divisorBefore = $this->etfTracker->currentDivisor('LBI');

        $stocks[] = StockBuilder::create('NEWCO')->withPrice(9.0e11)->withSharesOutstanding(1)->withPublicFloatPercentage(1.0)->build();
        $committee->reconstitute(MarketIndex::Headline, $stocks, 100);

        $this->assertNotNull($divisorBefore);
        $this->assertNotEqualsWithDelta($divisorBefore, (float) $this->etfTracker->currentDivisor('LBI'), 1.0);
    }

    public function testAReconstitutionThatChangesNothingChangesNothing(): void
    {
        $committee = $this->committee();
        $stocks = $this->rankedUniverse(60);

        $committee->reconstitute(MarketIndex::Headline, $stocks, 0);
        $divisor = $this->etfTracker->currentDivisor('LBI');

        $second = $committee->reconstitute(MarketIndex::Headline, $stocks, 100);

        $this->assertSame([], $second['added']);
        $this->assertSame([], $second['deleted']);
        $this->assertEqualsWithDelta((float) $divisor, (float) $this->etfTracker->currentDivisor('LBI'), 1e-6);
    }

    // --- Who Is In ---

    public function testTheIndexCarriesTheConfiguredNumberOfNames(): void
    {
        $result = $this->committee()->reconstitute(MarketIndex::Headline, $this->rankedUniverse(60), 0);

        $this->assertCount(FinancialConstants::INDEX_CONSTITUENT_COUNT, $result['tickers']);
    }

    public function testASmallerMarketThanTheIndexJustTakesEverythingListed(): void
    {
        $result = $this->committee()->reconstitute(MarketIndex::Headline, $this->rankedUniverse(12), 0);

        $this->assertCount(12, $result['tickers']);
    }

    public function testMembershipIsRankedOnTheFloatNotTheWholeCompany(): void
    {
        // A firm that is nine tenths closely held is a smaller position than its market capitalisation
        // says, and an index that weighted it on the whole would have passive funds trying to buy stock
        // that is not for sale.
        $closelyHeld = StockBuilder::create('CLOSED')->withPrice(1000.0)->withSharesOutstanding(1)->withPublicFloatPercentage(0.05)->build();
        $widelyHeld = StockBuilder::create('OPEN')->withPrice(500.0)->withSharesOutstanding(1)->withPublicFloatPercentage(1.0)->build();

        $this->assertGreaterThan(
            IndexCommittee::floatAdjustedCap($closelyHeld),
            IndexCommittee::floatAdjustedCap($widelyHeld)
        );
    }

    public function testABankruptShellIsNotRanked(): void
    {
        $dead = StockBuilder::create('DEAD')->withPrice(1000.0)->withSharesOutstanding(1)->build();
        $dead->setIsBankrupt(true);

        $this->assertSame(0.0, IndexCommittee::floatAdjustedCap($dead));
    }

    public function testABankruptMemberIsDroppedAtTheNextReconstitution(): void
    {
        $committee = $this->committee();
        $stocks = $this->rankedUniverse(45);

        $first = $committee->reconstitute(MarketIndex::Headline, $stocks, 0);
        $this->assertContains('T000', $first['tickers']);

        $stocks[0]->setIsBankrupt(true);
        $second = $committee->reconstitute(MarketIndex::Headline, $stocks, 100);

        $this->assertNotContains('T000', $second['tickers']);
        $this->assertContains('T000', $second['deleted']);
    }

    // --- Banding ---

    public function testASittingMemberIsNotEvictedByAMarginalOvertake(): void
    {
        // Ranking noise at the boundary would otherwise churn the whole passive book twice a year for
        // nothing, which is exactly what real indices band to prevent.
        $committee = $this->committee();

        $caps = [];
        for ($i = 0; $i < 50; $i++) {
            $caps[sprintf('T%03d', $i)] = 1000.0 - $i;
        }
        $stocks = $this->universe($caps);

        $count = FinancialConstants::INDEX_CONSTITUENT_COUNT;
        $first = $committee->reconstitute(MarketIndex::Headline, $stocks, 0);

        $weakestIn = sprintf('T%03d', $count - 1);
        $strongestOut = sprintf('T%03d', $count);

        $this->assertContains($weakestIn, $first['tickers']);
        $this->assertNotContains($strongestOut, $first['tickers']);

        // The name just outside edges barely past the name just inside — one rank, on noise.
        foreach ($stocks as $stock) {
            if ($stock->getTicker() === $strongestOut) {
                $stock->setPrice((string) ($caps[$weakestIn] + 0.5));
            }
        }

        $second = $committee->reconstitute(MarketIndex::Headline, $stocks, 100);

        $this->assertContains($weakestIn, $second['tickers'], 'the incumbent should have been banded in');
        $this->assertNotContains($strongestOut, $second['tickers'], 'a one-rank overtake should not buy a seat');
        $this->assertSame([], $second['added']);
        $this->assertSame([], $second['deleted']);
    }

    public function testAClearOvertakeStillGetsIn(): void
    {
        // Banding protects an incumbent from noise, not from a name that has plainly overtaken it.
        $committee = $this->committee();
        $stocks = $this->rankedUniverse(60);

        $committee->reconstitute(MarketIndex::Headline, $stocks, 0);

        foreach ($stocks as $stock) {
            if ($stock->getTicker() === 'T059') {
                $stock->setPrice('9000000000000.00000000');
            }
        }

        $second = $committee->reconstitute(MarketIndex::Headline, $stocks, 100);

        $this->assertContains('T059', $second['tickers']);
    }

    // --- Membership Lookups ---

    public function testTheStandingMembershipIsReadableAsALookup(): void
    {
        $committee = $this->committee();
        $committee->reconstitute(MarketIndex::Headline, $this->rankedUniverse(60), 0);

        $members = $committee->currentMembers(MarketIndex::Headline);

        $this->assertCount(FinancialConstants::INDEX_CONSTITUENT_COUNT, $members);
        $this->assertTrue($members['T000'] ?? false);
        $this->assertArrayNotHasKey('T059', $members);
    }

    public function testAFreshMarketHasNoMembershipUntilItIsTaken(): void
    {
        $this->assertSame([], $this->committee()->currentMembers(MarketIndex::Headline));
    }

    public function testMemberCapitalisationSumsOnlyTheMembers(): void
    {
        $committee = $this->committee();
        $committee->reconstitute(MarketIndex::Headline, $this->rankedUniverse(60), 0);

        $caps = [];
        for ($i = 0; $i < 60; $i++) {
            $caps[sprintf('T%03d', $i)] = 100.0;
        }

        $this->assertEqualsWithDelta(
            FinancialConstants::INDEX_CONSTITUENT_COUNT * 100.0,
            $committee->memberCapitalisation(MarketIndex::Headline, $caps),
            1e-9
        );
    }

    public function testAMarketWithNoMembershipYetCountsEverything(): void
    {
        // Before the first reconstitution the index is the whole board, which is what it was before there
        // was a membership at all.
        $this->assertEqualsWithDelta(300.0, $this->committee()->memberCapitalisation(MarketIndex::Headline, ['A' => 100.0, 'B' => 200.0]), 1e-9);
    }

    // --- Two Indices ---

    public function testTheHeadlineIndexIsATopThirty(): void
    {
        $this->assertSame(30, MarketIndex::Headline->constituentCount());
        $this->assertSame(FinancialConstants::INDEX_CONSTITUENT_COUNT, MarketIndex::Headline->constituentCount());
    }

    public function testTheCompositeCarriesEveryLiveNameBestRankedFirst(): void
    {
        $stocks = $this->rankedUniverse(60);
        $stocks[5]->setIsBankrupt(true);

        $result = $this->committee()->reconstitute(MarketIndex::Composite, $stocks, 0);

        $this->assertCount(59, $result['tickers']);
        $this->assertNotContains('T005', $result['tickers']);
        $this->assertSame('T000', $result['tickers'][0]);
        $this->assertSame('T059', $result['tickers'][58]);
        $this->assertNull(MarketIndex::Composite->constituentCount());
        $this->assertFalse(MarketIndex::Composite->isSelective());
    }

    public function testADeathMovesTheCompositeOnceAsAPriceAndNotAgainAsAComposition(): void
    {
        // A bankruptcy is a price event: the name's capitalisation goes to nothing and the index falls by its
        // weight, there and then. Removing the shell from the roster afterwards is a change of composition
        // and must not be a second move — the level after the review is the level the death left behind.
        $committee = $this->committee();
        $stocks = $this->rankedUniverse(60);

        $first = $committee->reconstitute(MarketIndex::Composite, $stocks, 0);

        $total = 0.0;
        foreach ($stocks as $stock) {
            $total += IndexCommittee::floatAdjustedCap($stock);
        }
        $weightOfTheDead = IndexCommittee::floatAdjustedCap($stocks[3]) / $total;

        $stocks[3]->setIsBankrupt(true);
        $second = $committee->reconstitute(MarketIndex::Composite, $stocks, 100);

        $this->assertContains('T003', $second['deleted']);
        $this->assertEqualsWithDelta($first['level'] * (1.0 - $weightOfTheDead), $second['level'], $first['level'] * 1e-9);
    }

    public function testEachIndexKeepsItsOwnMembershipAndDivisor(): void
    {
        $committee = $this->committee();
        $stocks = $this->rankedUniverse(60);

        $committee->reconstitute(MarketIndex::Headline, $stocks, 0);

        $this->assertSame([], $committee->currentMembers(MarketIndex::Composite), 'the composite has not been taken yet');
        $this->assertNull($this->etfTracker->currentDivisor('LBC'));

        $committee->reconstitute(MarketIndex::Composite, $stocks, 0);

        $this->assertCount(30, $committee->currentMembers(MarketIndex::Headline));
        $this->assertCount(60, $committee->currentMembers(MarketIndex::Composite));
        $this->assertNotEqualsWithDelta(
            (float) $this->etfTracker->currentDivisor('LBI'),
            (float) $this->etfTracker->currentDivisor('LBC'),
            1.0
        );
    }

    /**
     * The passive book is SPLIT across the published indices, and the split is exhaustive.
     *
     * If the shares did not sum to one, passive ownership would stop being a reallocation of a fixed pool
     * across names and become a dial on how much passive money exists — every name's multiple would be
     * scaled by the same missing or surplus fraction, and the agent population's index book would quietly
     * grow or shrink with the number of indices published rather than with anything economic.
     */
    public function testThePassiveBookIsSplitAcrossEveryPublishedIndex(): void
    {
        $this->assertSame(MarketIndex::Headline, MarketIndex::benchmark());
        $this->assertSame(MarketIndex::Composite, MarketIndex::market());

        $total = 0.0;
        foreach (MarketIndex::cases() as $index) {
            $this->assertTrue($index->carriesPassiveBook(), $index->value . ' carries indexed money');
            $total += $index->passiveShare();
        }

        $this->assertEqualsWithDelta(1.0, $total, 1e-9);

        // The headline is still where most of it sits: a broad cap-weighted benchmark is what indexed money
        // overwhelmingly buys, and the smart-beta and sector vehicles are the tail.
        $this->assertGreaterThan(0.5, MarketIndex::benchmark()->passiveShare());
    }

    // --- Naming ---

    /**
     * An index and the fund tracking it are different things with different names.
     *
     * The fund is a product a sponsor sells; the index is a benchmark somebody else publishes and the fund
     * licenses. Collapsing the two into one string is the commonest way a fictional market gives itself
     * away, and it also makes the page unable to say the one thing a factsheet exists to say: which index
     * this fund tracks.
     */
    public function testEveryIndexIsNamedSeparatelyFromTheFundThatTracksIt(): void
    {
        $seen = [];

        foreach (MarketIndex::cases() as $index) {
            $name = $index->indexName();

            $this->assertNotSame('', $name);
            $this->assertStringStartsWith('Lakebird', $name, 'the indices are one published family');
            $this->assertStringNotContainsStringIgnoringCase('ETF', $name, 'an index is not a fund');
            $this->assertStringNotContainsStringIgnoringCase('Skein', $name, 'the sponsor does not name the index');

            $seen[] = $name;
        }

        $this->assertSame($seen, array_unique($seen), 'two indices may not share a name');
        $this->assertNotSame('', MarketIndex::PROVIDER);
    }

    /**
     * The headline index carries its seat count, and carries the REAL one.
     *
     * A fixed-membership index conventionally puts its size in its name, and the moment that number is
     * written into the string rather than read from the constant it can quietly stop being true — an index
     * called "Lakebird 30" holding thirty-two names is a worse name than no name at all.
     */
    public function testTheHeadlineIndexNameCarriesItsActualSeatCount(): void
    {
        $this->assertSame(
            'Lakebird ' . FinancialConstants::INDEX_CONSTITUENT_COUNT,
            MarketIndex::benchmark()->indexName()
        );

        $committee = $this->committee();
        $result = $committee->reconstitute(MarketIndex::benchmark(), $this->rankedUniverse(60), 0);

        $this->assertCount(FinancialConstants::INDEX_CONSTITUENT_COUNT, $result['tickers']);
        $this->assertStringContainsString(
            (string) count($result['tickers']),
            MarketIndex::benchmark()->indexName(),
            'the name must state the membership the committee actually seats'
        );
    }

    // --- Weighting Schemes ---

    /**
     * A low-volatility index selects on quiet and weights on it, and the two are separate decisions.
     *
     * The membership test is the easy half. The weighting is the half that a framework which only knew how
     * to rank by size gets wrong: the quietest name must carry MORE than a larger but noisier one, which is
     * the opposite of what its capitalisation would give it.
     */
    public function testTheLowVolatilityIndexSelectsAndWeightsOnQuiet(): void
    {
        $stocks = [];
        // Deliberately adversarial: the loudest name is also the largest, so a size-ranked or size-weighted
        // index would put it first on both counts.
        $spec = [
            ['LOUD', 9.0e11, 0.60],
            ['MID', 5.0e11, 0.30],
            ['CALM', 1.0e11, 0.08],
            ['STILL', 2.0e11, 0.06],
        ];

        foreach ($spec as [$ticker, $cap, $vol]) {
            $stocks[] = StockBuilder::create($ticker)
                ->withPrice($cap)
                ->withSharesOutstanding(1)
                ->withPublicFloatPercentage(1.0)
                ->withCurrentVolatility($vol)
                ->build();
        }

        $result = $this->committee()->reconstitute(MarketIndex::LowVolatility, $stocks, 0);

        // Four names, twenty seats: everyone is in, so this test is purely about the weights.
        $this->assertSame(['STILL', 'CALM', 'MID', 'LOUD'], $result['tickers'], 'ranked quietest first');

        $this->assertGreaterThan($result['weights']['CALM'], $result['weights']['STILL']);
        $this->assertGreaterThan($result['weights']['MID'], $result['weights']['CALM']);
        $this->assertGreaterThan($result['weights']['LOUD'], $result['weights']['MID']);

        // The smallest company by a factor of nine carries the most, which is the whole point.
        $this->assertEqualsWithDelta(1.0, array_sum($result['weights']), 1e-9);
        $this->assertEqualsWithDelta(0.06 / 0.08, $result['weights']['CALM'] / $result['weights']['STILL'], 1e-9);
    }

    /**
     * The adjusted-weight factor is what makes a weighted index computable as a capitalisation index.
     *
     * Struck correctly, the members' adjusted capitalisations are in exactly the proportions the committee
     * decided. This is the property the level depends on: if the factors did not reproduce the weights, the
     * index would be running a portfolio nobody chose.
     */
    public function testTheFactorsReproduceTheWeightsOnTheDayTheyAreStruck(): void
    {
        $stocks = [];
        foreach ([['A', 4.0e11, 0.10], ['B', 1.0e11, 0.20], ['C', 5.0e11, 0.40]] as [$ticker, $cap, $vol]) {
            $stocks[] = StockBuilder::create($ticker)
                ->withPrice($cap)
                ->withSharesOutstanding(1)
                ->withPublicFloatPercentage(1.0)
                ->withCurrentVolatility($vol)
                ->build();
        }

        $result = $this->committee()->reconstitute(MarketIndex::LowVolatility, $stocks, 0);

        $caps = ['A' => 4.0e11, 'B' => 1.0e11, 'C' => 5.0e11];
        $adjusted = [];
        foreach ($result['factors'] as $ticker => $factor) {
            $adjusted[$ticker] = $caps[$ticker] * $factor;
        }
        $total = array_sum($adjusted);

        foreach ($result['weights'] as $ticker => $weight) {
            $this->assertEqualsWithDelta($weight, $adjusted[$ticker] / $total, 1e-9, $ticker);
        }

        // And the level is struck off exactly that total, so the committee's portfolio is what is published.
        $this->assertEqualsWithDelta(
            $total,
            $this->committee()->memberCapitalisation(MarketIndex::LowVolatility, $caps),
            $total * 1e-9
        );
    }

    /**
     * A cap-weighted index comes out of the same machinery with every factor at one.
     *
     * Worth pinning: it is what lets one arithmetic serve all four indices, and a regression here would
     * silently re-weight the headline index without anything else failing.
     */
    public function testACapWeightedIndexKeepsUnitFactors(): void
    {
        $result = $this->committee()->reconstitute(MarketIndex::Headline, $this->rankedUniverse(60), 0);

        foreach ($result['factors'] as $ticker => $factor) {
            $this->assertEqualsWithDelta(1.0, $factor, 1e-9, $ticker);
        }
    }

    /**
     * Weights drift with prices between reviews rather than being silently restruck every tick.
     *
     * A fund that rebalanced continuously would be selling every winner and buying every loser for free,
     * which is not a portfolio anyone can run. The frozen factor is what makes the drift happen.
     */
    public function testWeightsDriftWithPricesBetweenReviews(): void
    {
        $stocks = [];
        foreach ([['A', 1.0e11, 0.10], ['B', 1.0e11, 0.10]] as [$ticker, $cap, $vol]) {
            $stocks[] = StockBuilder::create($ticker)
                ->withPrice($cap)
                ->withSharesOutstanding(1)
                ->withPublicFloatPercentage(1.0)
                ->withCurrentVolatility($vol)
                ->build();
        }

        $committee = $this->committee();
        $struck = $committee->reconstitute(MarketIndex::LowVolatility, $stocks, 0);

        // Equal volatility, equal size: the review splits the fund in half.
        $this->assertEqualsWithDelta(0.5, $struck['weights']['A'], 1e-9);

        // A doubles. The fund did not trade, so it is now two thirds A — which is exactly what a real fund
        // holding a fixed share count would be.
        $moved = ['A' => 2.0e11, 'B' => 1.0e11];
        $total = $committee->memberCapitalisation(MarketIndex::LowVolatility, $moved);

        $this->assertEqualsWithDelta(
            2.0 / 3.0,
            ($moved['A'] * $struck['factors']['A']) / $total,
            1e-9
        );
    }

    // --- Sector Universe And Diversification Caps ---

    public function testASectorIndexHoldsItsSectorAndNothingElse(): void
    {
        $stocks = [
            StockBuilder::create('MILK')->withPrice(3.0e11)->withSharesOutstanding(1)->withPublicFloatPercentage(1.0)->withSector('Consumer Staples')->build(),
            StockBuilder::create('BEER')->withPrice(1.0e11)->withSharesOutstanding(1)->withPublicFloatPercentage(1.0)->withSector('Consumer Staples')->build(),
            // Larger than either staple, and irrelevant: the universe is the mandate, not a ranking cut.
            StockBuilder::create('MEGA')->withPrice(9.0e11)->withSharesOutstanding(1)->withPublicFloatPercentage(1.0)->withSector('Information Technology')->build(),
        ];

        $result = $this->committee()->reconstitute(MarketIndex::Staples, $stocks, 0);

        $this->assertSame(['MILK', 'BEER'], $result['tickers']);
    }

    /**
     * A name that leaves the sector leaves the fund at the next review, and the level does not move when it
     * does. Reclassification is a composition change like any other and the divisor has to absorb it.
     */
    public function testASectorIndexDropsAReclassifiedNameWithoutMovingTheLevel(): void
    {
        $staple = fn (string $ticker, float $cap, string $sector) => StockBuilder::create($ticker)
            ->withPrice($cap)->withSharesOutstanding(1)->withPublicFloatPercentage(1.0)->withSector($sector)->build();

        $stocks = [
            $staple('MILK', 3.0e11, 'Consumer Staples'),
            $staple('BEER', 2.0e11, 'Consumer Staples'),
            $staple('SODA', 1.0e11, 'Consumer Staples'),
        ];

        $committee = $this->committee();
        $first = $committee->reconstitute(MarketIndex::Staples, $stocks, 0);

        // SODA is reclassified out of staples. No price changed.
        $stocks[2] = $staple('SODA', 1.0e11, 'Consumer Discretionary');

        $second = $committee->reconstitute(MarketIndex::Staples, $stocks, 100);

        $this->assertSame(['SODA'], $second['deleted']);
        $this->assertEqualsWithDelta($first['level'], $second['level'], $first['level'] * 1e-9);
    }

    /**
     * The single-name cap binds on a narrow sector, and the excess goes to the rest.
     *
     * A six-company sector weighted purely by size puts far more than the regulated limit in its largest
     * name; a fund that did that could not be sold as a diversified fund at all.
     */
    public function testTheDiversificationCapBindsOnANarrowSector(): void
    {
        $stocks = [];
        // One giant and five minnows: uncapped, the giant would be over 70% of the fund.
        $caps = ['GIANT' => 7.0e11, 'M1' => 6.0e10, 'M2' => 5.0e10, 'M3' => 4.0e10, 'M4' => 3.0e10, 'M5' => 2.0e10];
        foreach ($caps as $ticker => $cap) {
            $stocks[] = StockBuilder::create($ticker)
                ->withPrice($cap)->withSharesOutstanding(1)->withPublicFloatPercentage(1.0)
                ->withSector('Consumer Staples')->build();
        }

        $result = $this->committee()->reconstitute(MarketIndex::Staples, $stocks, 0);

        $uncapped = 7.0e11 / array_sum($caps);
        $this->assertGreaterThan(0.7, $uncapped, 'the fixture should be concentrated enough to bind');

        $this->assertEqualsWithDelta(
            FinancialConstants::INDEX_MAX_CONSTITUENT_WEIGHT,
            $result['weights']['GIANT'],
            1e-6
        );
        $this->assertEqualsWithDelta(1.0, array_sum($result['weights']), 1e-9);

        // What came off the giant went to the others in proportion, so their ORDER is untouched.
        $this->assertGreaterThan($result['weights']['M2'], $result['weights']['M1']);
        $this->assertGreaterThan($result['weights']['M5'], $result['weights']['M4']);
    }

    /**
     * A cap that cannot be met is not applied until the weights stop summing to one.
     *
     * Four companies cannot respect a 22.5% ceiling between them — the arithmetic does not close — and the
     * honest response is to leave the weights alone rather than publish a fund that is 90% invested.
     */
    public function testAnUnsatisfiableCapIsNotApplied(): void
    {
        $stocks = [];
        foreach (['A' => 4.0e11, 'B' => 3.0e11, 'C' => 2.0e11, 'D' => 1.0e11] as $ticker => $cap) {
            $stocks[] = StockBuilder::create($ticker)
                ->withPrice($cap)->withSharesOutstanding(1)->withPublicFloatPercentage(1.0)
                ->withSector('Consumer Staples')->build();
        }

        $result = $this->committee()->reconstitute(MarketIndex::Staples, $stocks, 0);

        $this->assertEqualsWithDelta(1.0, array_sum($result['weights']), 1e-9);
        $this->assertEqualsWithDelta(0.4, $result['weights']['A'], 1e-9);
    }

    // --- Headline Eligibility ---

    /**
     * Size alone does not buy a seat on the scoreboard: a company has to be earning to be ADMITTED.
     *
     * The S&P 500's viability screen, and the reason a very large loss-making company can sit outside the
     * index for years while smaller profitable ones sit inside it.
     */
    public function testALossMakerCannotBuyItsWayIntoTheHeadlineIndexOnSize(): void
    {
        $stocks = $this->rankedUniverse(60);

        // A giant that has never earned anything, larger than every incumbent.
        $stocks[] = StockBuilder::create('BURN')
            ->withPrice(2.0e12)->withSharesOutstanding(1)->withPublicFloatPercentage(1.0)
            ->withQuarterlyNetIncome(-4.0e9)
            ->build();

        $result = $this->committee()->reconstitute(MarketIndex::Headline, $stocks, 0);

        $this->assertNotContains('BURN', $result['tickers']);
        $this->assertCount(FinancialConstants::INDEX_CONSTITUENT_COUNT, $result['tickers'], 'the seat it could not take is filled by someone else');
    }

    /**
     * The screen governs JOINING, never staying.
     *
     * An index that ejected every company having a bad year would be a momentum strategy wearing a
     * benchmark's name, and it would sell the bottom of every earnings cycle on behalf of everyone tracking
     * it. A member that falls into losses keeps its seat until the ranking bands take it.
     */
    public function testAMemberThatFallsIntoLossesKeepsItsSeat(): void
    {
        $stocks = $this->rankedUniverse(60);
        $committee = $this->committee();

        $first = $committee->reconstitute(MarketIndex::Headline, $stocks, 0);
        $incumbent = $first['tickers'][5];

        // The same company, same size, now losing money in every quarter of the year.
        foreach ($stocks as $i => $stock) {
            if ($stock->getTicker() === $incumbent) {
                $stocks[$i] = StockBuilder::create($incumbent)
                    ->withPrice((float) $stock->getPrice())
                    ->withSharesOutstanding(1)
                    ->withPublicFloatPercentage(1.0)
                    ->withQuarterlyNetIncome(-1.0e9)
                    ->build();
            }
        }

        $second = $committee->reconstitute(MarketIndex::Headline, $stocks, 100);

        $this->assertContains($incumbent, $second['tickers']);
        $this->assertNotContains($incumbent, $second['deleted']);
    }

    /** Only the headline index screens on earnings; the whole-board composite measures the whole board. */
    public function testTheCompositeCarriesLossMakersBecauseTheMarketDoes(): void
    {
        $stocks = $this->rankedUniverse(10);
        $stocks[] = StockBuilder::create('BURN')
            ->withPrice(5.0e11)->withSharesOutstanding(1)->withPublicFloatPercentage(1.0)
            ->withQuarterlyNetIncome(-4.0e9)
            ->build();

        $result = $this->committee()->reconstitute(MarketIndex::Composite, $stocks, 0);

        $this->assertContains('BURN', $result['tickers']);
    }

    // --- Concentration Caps ---

    /**
     * The headline index does NOT cap its largest constituents: each one weighs its own float share.
     *
     * A benchmark that trims the names that have grown largest reports a re-engineered market rather than
     * the one it measures, which is why the S&P 500, the FTSE 100 and the Nikkei leave the weights where
     * the market puts them. That includes the publisher's own seat — Lakebird Bank publishes this index and
     * is the largest company in it, and its weight is whatever the market makes it.
     */
    public function testTheHeadlineIndexLeavesItsLargestConstituentsUncapped(): void
    {
        // A board whose top three are half the index between them.
        $caps = ['LAKE' => 1.9e12, 'SAFE' => 1.5e12, 'SWAN' => 1.4e12];
        for ($i = 0; $i < 30; $i++) {
            $caps[sprintf('T%03d', $i)] = 2.0e11 - ($i * 1.0e9);
        }

        $result = $this->committee()->reconstitute(MarketIndex::Headline, $this->universe($caps), 0);

        $this->assertEqualsWithDelta(1.0, array_sum($result['weights']), 1e-9);

        // Members are the thirty largest, so the weights are their float shares of that membership.
        $memberCap = 0.0;
        foreach ($result['tickers'] as $ticker) {
            $memberCap += $caps[$ticker];
        }

        foreach ($result['weights'] as $ticker => $weight) {
            $this->assertEqualsWithDelta($caps[$ticker] / $memberCap, $weight, 1e-9, "{$ticker} is not at its float share.");
        }

        $this->assertGreaterThan(0.15, $result['weights']['LAKE'], 'The publisher is no longer held to the old ceiling.');
    }

    /**
     * The cap has to hold for EVERYONE after the redistribution, not just for whoever was over it first.
     *
     * Redistributing the largest name's excess pro rata raises everyone else, and on a concentrated set it
     * pushes the next name straight through the cap it was just under. A single pass therefore leaves the
     * cap breached by whoever caught the redistribution — a cap that does not hold — so the rules are worked
     * until nothing moves.
     */
    public function testRedistributedWeightCannotPushAnotherNameThroughTheCap(): void
    {
        // One giant and five minnows: capping the giant hands the largest minnow enough to breach the cap.
        $caps = ['GIANT' => 7.0e11, 'M1' => 6.0e10, 'M2' => 5.0e10, 'M3' => 4.0e10, 'M4' => 3.0e10, 'M5' => 2.0e10];
        $stocks = [];
        foreach ($caps as $ticker => $cap) {
            $stocks[] = StockBuilder::create($ticker)
                ->withPrice($cap)->withSharesOutstanding(1)->withPublicFloatPercentage(1.0)
                ->withSector('Consumer Staples')->build();
        }

        $result = $this->committee()->reconstitute(MarketIndex::Staples, $stocks, 0);
        $cap = FinancialConstants::INDEX_MAX_CONSTITUENT_WEIGHT;

        $this->assertEqualsWithDelta(1.0, array_sum($result['weights']), 1e-9);

        foreach ($result['weights'] as $ticker => $weight) {
            $this->assertLessThanOrEqual($cap + 1e-9, $weight, "{$ticker} is over the cap after redistribution.");
        }
    }

    // --- Passive Ownership ---

    /**
     * Passive ownership is a REALLOCATION across names, not a dial on how much passive money exists.
     *
     * The capitalisation-weighted average of the multiple must be exactly one however the indexed money is
     * split between vehicles. Without that property, publishing another index would quietly grow or shrink
     * the agent population's whole passive book, and every calibration resting on it would move.
     */
    public function testPassiveOwnershipAveragesToOneAcrossTheMarket(): void
    {
        $stocks = $this->mixedBoard();
        $committee = $this->committee();

        foreach (MarketIndex::cases() as $index) {
            $committee->reconstitute($index, $stocks, 0);
        }

        $multiples = $committee->passiveOwnership();
        $market = $committee->currentRoster(MarketIndex::market())['weights'];

        $average = 0.0;
        foreach ($market as $ticker => $weight) {
            $average += $weight * ($multiples[$ticker] ?? 0.0);
        }

        $this->assertEqualsWithDelta(1.0, $average, 1e-9);
    }

    /**
     * A name an extra index holds carries more passive money than an identical name it does not.
     *
     * Tested on TWINS — same size, same volatility, different sector — so the only thing separating them is
     * membership of the sector fund. This is the whole reason to publish a fund at all: without it, adding
     * an index would be a number on a page that no flow ever reflects.
     */
    public function testAnExtraIndexPutsMorePassiveMoneyBehindAName(): void
    {
        $stocks = $this->mixedBoard();
        $committee = $this->committee();

        foreach (MarketIndex::cases() as $index) {
            $committee->reconstitute($index, $stocks, 0);
        }

        $multiples = $committee->passiveOwnership();

        $this->assertGreaterThan(
            $multiples['TWIN'],
            $multiples['SODA'],
            'the staple is held by the sector fund and its twin is not'
        );

        // The twin is still held: the whole-board fund owns everything that is listed.
        $this->assertGreaterThan(0.0, $multiples['TWIN']);

        // And the difference is exactly the sector fund's contribution, not a side effect of anything else.
        $staples = $committee->currentRoster(MarketIndex::Staples)['weights'];
        $market = $committee->currentRoster(MarketIndex::market())['weights'];

        $this->assertEqualsWithDelta(
            (MarketIndex::Staples->passiveShare() * $staples['SODA']) / $market['SODA'],
            $multiples['SODA'] - $multiples['TWIN'],
            1e-9
        );
    }

    /** Before the market's own roster is taken there is nothing to divide by, and the caller is told so. */
    public function testPassiveOwnershipIsEmptyBeforeTheMarketHasARoster(): void
    {
        $this->assertSame([], $this->committee()->passiveOwnership());
    }

    /**
     * A board with enough variety for all four indices to disagree about it: large and small, quiet and
     * loud, staples and not.
     *
     * @return array<int, Stock>
     */
    private function mixedBoard(): array
    {
        $spec = [
            ['MILK', 8.0e11, 0.08, 'Consumer Staples'],
            ['BEER', 3.0e11, 0.12, 'Consumer Staples'],
            ['SODA', 1.0e11, 0.10, 'Consumer Staples'],
            ['MEGA', 9.0e11, 0.30, 'Information Technology'],
            ['BANK', 6.0e11, 0.22, 'Financials'],
            ['RAIL', 4.0e11, 0.14, 'Industrials'],
            ['WILD', 5.0e10, 0.55, 'Energy'],
            ['SPIN', 7.0e10, 0.45, 'Materials'],
            // SODA's twin in every respect but its sector, so the sector fund is the only thing that can
            // separate the two.
            ['TWIN', 1.0e11, 0.10, 'Industrials'],
        ];

        $stocks = [];
        foreach ($spec as [$ticker, $cap, $vol, $sector]) {
            $stocks[] = StockBuilder::create($ticker)
                ->withPrice($cap)
                ->withSharesOutstanding(1)
                ->withPublicFloatPercentage(1.0)
                ->withCurrentVolatility($vol)
                ->withSector($sector)
                ->build();
        }

        return $stocks;
    }

    // --- Surviving A Lost Redis ---

    public function testTheFundsLastPrintedLevelIsPreservedWhenTheCommitteeHasNoState(): void
    {
        // Redis emptied under a market whose database was not: no membership and no divisor on record, but
        // the fund still says what the index was worth a tick ago. That, not the base, is what to preserve.
        $result = $this->committee()->reconstitute(MarketIndex::Headline, $this->rankedUniverse(60), 0, 137.5);

        $this->assertEqualsWithDelta(137.5, $result['level'], 1e-9);
    }

    public function testAFreshMarketOpensAtTheBaseLevel(): void
    {
        $result = $this->committee()->reconstitute(MarketIndex::Headline, $this->rankedUniverse(60), 0);

        $this->assertEqualsWithDelta(FinancialConstants::INDEX_BASE_LEVEL, $result['level'], 1e-9);
    }

    public function testTheCommitteesOwnStateOutranksTheFundsPrint(): void
    {
        // Membership and divisor on record give the exact current level; the fund's print is a tick stale
        // and is only the fallback.
        $committee = $this->committee();
        $stocks = $this->rankedUniverse(60);

        $first = $committee->reconstitute(MarketIndex::Headline, $stocks, 0);
        $second = $committee->reconstitute(MarketIndex::Headline, $stocks, 100, 999.0);

        $this->assertEqualsWithDelta($first['level'], $second['level'], 1e-9);
    }

    // --- What Changed ---

    public function testTheFirstRosterIsAListingNotAnAddition(): void
    {
        $committee = $this->committee();
        $first = $committee->reconstitute(MarketIndex::Headline, $this->rankedUniverse(60), 0);

        $this->assertSame([], $first['added']);
        $this->assertSame([], $committee->currentRoster(MarketIndex::Headline)['added']);
    }

    public function testTheRosterRecordsWhatTheLastReviewChanged(): void
    {
        $committee = $this->committee();
        $stocks = $this->rankedUniverse(60);
        $committee->reconstitute(MarketIndex::Headline, $stocks, 0);

        $stocks[] = StockBuilder::create('NEWCO')->withPrice(9.0e11)->withSharesOutstanding(1)->withPublicFloatPercentage(1.0)->build();
        $committee->reconstitute(MarketIndex::Headline, $stocks, 3600);

        $roster = $committee->currentRoster(MarketIndex::Headline);

        $this->assertSame(3600, $roster['tick']);
        $this->assertSame(['NEWCO'], $roster['added']);
        $this->assertSame(['T029'], $roster['deleted']);
    }

    public function testTheReconstitutionEventTextFitsTheColumn(): void
    {
        $many = [];
        for ($i = 0; $i < 40; $i++) {
            $many[] = sprintf('T%03d', $i);
        }

        $text = MarketTickerCommand::describeReconstitution($many, ['DEAD']);

        $this->assertLessThanOrEqual(255, strlen($text));
        $this->assertStringContainsString('and 32 more', $text);
        $this->assertStringContainsString('dropped DEAD', $text);
        $this->assertSame('Quarterly reconstitution: added A, B.', MarketTickerCommand::describeReconstitution(['A', 'B'], []));
    }

    // --- What the Screen Measures ---

    /**
     * A volatility screen is a TRAILING measurement, and that is what the screen means rather than a detail
     * of how it is computed.
     *
     * `currentVolatility` is the instantaneous state of the variance process — what the name is about to
     * draw from, moved outright by a single jump. Ranking on it meant the index reconstituted itself on
     * volatility spikes rather than on volatility, and since the fund trades every reconstitution, each
     * spike became a round trip: sold for having jumped, bought back a quarter later for having settled.
     */
    public function testTheScreenRanksOnWhatTheNameRealizedNotOnItsVarianceState(): void
    {
        // A quiet name in the middle of a spike, and a noisy one that happens to be still this instant.
        $spiking = StockBuilder::create('QUIET')->withPrice(1.0e11)->withSharesOutstanding(1)
            ->withPublicFloatPercentage(1.0)->withRealizedVolatility(0.10)->withCurrentVolatility(0.90)->build();
        $settled = StockBuilder::create('NOISY')->withPrice(1.0e11)->withSharesOutstanding(1)
            ->withPublicFloatPercentage(1.0)->withRealizedVolatility(0.45)->withCurrentVolatility(0.09)->build();

        $this->assertEqualsWithDelta(0.10, IndexCommittee::trailingVolatility($spiking), 1e-9);
        $this->assertEqualsWithDelta(0.45, IndexCommittee::trailingVolatility($settled), 1e-9);

        $result = $this->committee()->reconstitute(MarketIndex::LowVolatility, [$spiking, $settled], 0);

        $this->assertSame(['QUIET', 'NOISY'], $result['tickers'], 'ranked on the window, not on the state');
        $this->assertGreaterThan($result['weights']['NOISY'], $result['weights']['QUIET']);
    }

    /** Before a market has a window to measure, the variance state stands in rather than nothing at all. */
    public function testTheVarianceStateStandsInUntilThereIsAWindow(): void
    {
        $fresh = StockBuilder::create('NEW')->withCurrentVolatility(0.33)->build();

        $this->assertNull($fresh->getRealizedVolatility());
        $this->assertEqualsWithDelta(0.33, IndexCommittee::trailingVolatility($fresh), 1e-9);
    }

    // --- What the Review Costs ---

    /**
     * A fund is created in kind, so its first basket costs it nothing to assemble.
     */
    public function testTheFirstReviewOfAFreshMarketIsFree(): void
    {
        $result = $this->committee()->reconstitute(MarketIndex::LowVolatility, $this->volatileUniverse(), 0);

        $this->assertEqualsWithDelta(0.0, $result['turnover'], 1e-12);
        $this->assertEqualsWithDelta(0.0, $result['trading_cost'], 1e-12);
    }

    /**
     * A cap-weighted index pays nothing for a review that changed nothing.
     *
     * This is not an exemption granted to it. Its weights maintain themselves as prices move — a constituent's
     * capitalisation moves with its price and its weight stays correct on its own — so a review that admits
     * and drops nobody leaves the fund with nothing to trade.
     */
    public function testACapWeightedReviewThatChangesNothingCostsNothing(): void
    {
        $committee = $this->committee();
        $stocks = $this->rankedUniverse(60);

        $committee->reconstitute(MarketIndex::Headline, $stocks, 0);
        $again = $committee->reconstitute(MarketIndex::Headline, $stocks, 100);

        $this->assertSame([], $again['added']);
        $this->assertEqualsWithDelta(0.0, $again['turnover'], 1e-12);
        $this->assertEqualsWithDelta(0.0, $again['trading_cost'], 1e-12);
    }

    /**
     * An inverse-volatility index pays for every review, because every review is a trade.
     *
     * Weights drift with prices between reviews and the committee puts them back. That is a real order in
     * every name whose weight moved, and until it was charged for it the index was harvesting a quarterly
     * rebalance for free — selling whatever had drifted up and buying whatever had drifted down, at mid, in
     * unlimited size, four times a year.
     */
    public function testRestrikingDriftedWeightsIsChargedAsATrade(): void
    {
        $committee = $this->committee();

        $struck = [['A', 1.0e11, 0.10], ['B', 1.0e11, 0.10]];
        $committee->reconstitute(MarketIndex::LowVolatility, $this->volatileUniverse($struck), 0);

        // A doubles. The fund did not trade, so it is carrying two thirds A into the review — and the
        // review puts it back to a half, which is a sixth of the fund sold and a sixth bought.
        $moved = [['A', 2.0e11, 0.10], ['B', 1.0e11, 0.10]];
        $again = $committee->reconstitute(MarketIndex::LowVolatility, $this->volatileUniverse($moved), 100);

        $this->assertSame([], $again['added'], 'nobody joined: the whole of this is the re-weighting');
        $this->assertEqualsWithDelta(1.0 / 6.0, $again['turnover'], 1e-9);
        $this->assertGreaterThan(0.0, $again['trading_cost']);

        // Both sides are real and each crosses its own name's half-spread, so the cost is the sum over the
        // weight changes rather than half of it.
        $this->assertLessThan(2.0 * $again['turnover'] * FinancialConstants::MAX_HALF_SPREAD, $again['trading_cost']);
        $this->assertGreaterThanOrEqual(2.0 * $again['turnover'] * FinancialConstants::MIN_HALF_SPREAD, $again['trading_cost']);
    }

    /** A rebalance too small to be worth placing is not charged for. */
    public function testATrivialRebalanceIsNotCharged(): void
    {
        $committee = $this->committee();

        $struck = [['A', 1.0e11, 0.10], ['B', 1.0e11, 0.10]];
        $committee->reconstitute(MarketIndex::LowVolatility, $this->volatileUniverse($struck), 0);

        // A hair of drift: a hundredth of a percent, well inside the threshold.
        $nudged = [['A', 1.00002e11, 0.10], ['B', 1.0e11, 0.10]];
        $again = $committee->reconstitute(MarketIndex::LowVolatility, $this->volatileUniverse($nudged), 100);

        $this->assertEqualsWithDelta(0.0, $again['trading_cost'], 1e-12);
    }

    /**
     * @param list<array{0: string, 1: float, 2: float}> $spec ticker, capitalisation, realized volatility
     * @return array<int, Stock>
     */
    private function volatileUniverse(array $spec = [['A', 4.0e11, 0.10], ['B', 1.0e11, 0.20], ['C', 5.0e11, 0.40]]): array
    {
        $stocks = [];

        foreach ($spec as [$ticker, $cap, $vol]) {
            $stocks[] = StockBuilder::create($ticker)
                ->withPrice($cap)
                ->withSharesOutstanding(1)
                ->withPublicFloatPercentage(1.0)
                ->withRealizedVolatility($vol)
                ->build();
        }

        return $stocks;
    }

    // --- Cadence ---

    public function testTheIndexReconstitutesOnItsOwnQuarterlyCalendar(): void
    {
        $interval = IndexCommittee::intervalTicks(14400);

        $this->assertSame(intdiv(14400, FinancialConstants::INDEX_RECONSTITUTIONS_PER_YEAR), $interval);
        $this->assertTrue(IndexCommittee::isReconstitutionTick(0, 14400));
        $this->assertTrue(IndexCommittee::isReconstitutionTick($interval, 14400));
        $this->assertFalse(IndexCommittee::isReconstitutionTick($interval + 1, 14400));
    }
}
