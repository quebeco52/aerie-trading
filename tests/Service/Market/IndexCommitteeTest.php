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
            $redis
        );
    }

    private function committee(): IndexCommittee
    {
        return new IndexCommittee($this->store, $this->etfTracker);
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

    public function testThePassiveBookTracksTheHeadlineOnly(): void
    {
        $this->assertSame(MarketIndex::Headline, MarketIndex::benchmark());
        $this->assertTrue(MarketIndex::Headline->carriesPassiveBook());
        $this->assertFalse(MarketIndex::Composite->carriesPassiveBook());
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
