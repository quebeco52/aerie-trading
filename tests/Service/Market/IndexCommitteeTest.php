<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\Etf;
use App\Entity\Stock;
use App\Service\Market\EtfTracker;
use App\Service\Market\IndexCommittee;
use App\Service\Market\Index\InMemoryIndexMembershipStore;
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

        $first = $committee->reconstitute($stocks, 0);
        $levelAfterFirst = $first['level'];

        // A new name arrives large enough to force its way in, without any price changing.
        $stocks[] = StockBuilder::create('NEWCO')->withPrice(9.0e11)->withSharesOutstanding(1)->withPublicFloatPercentage(1.0)->build();

        $second = $committee->reconstitute($stocks, 100);

        $this->assertNotEmpty($second['added'], 'the fixture should have forced a membership change');
        $this->assertEqualsWithDelta($levelAfterFirst, $second['level'], $levelAfterFirst * 1e-9);
    }

    public function testTheDivisorIsRestatedRatherThanLeftAlone(): void
    {
        $committee = $this->committee();
        $stocks = $this->rankedUniverse(60);

        $committee->reconstitute($stocks, 0);
        $divisorBefore = $this->etfTracker->currentDivisor();

        $stocks[] = StockBuilder::create('NEWCO')->withPrice(9.0e11)->withSharesOutstanding(1)->withPublicFloatPercentage(1.0)->build();
        $committee->reconstitute($stocks, 100);

        $this->assertNotNull($divisorBefore);
        $this->assertNotEqualsWithDelta($divisorBefore, (float) $this->etfTracker->currentDivisor(), 1.0);
    }

    public function testAReconstitutionThatChangesNothingChangesNothing(): void
    {
        $committee = $this->committee();
        $stocks = $this->rankedUniverse(60);

        $committee->reconstitute($stocks, 0);
        $divisor = $this->etfTracker->currentDivisor();

        $second = $committee->reconstitute($stocks, 100);

        $this->assertSame([], $second['added']);
        $this->assertSame([], $second['deleted']);
        $this->assertEqualsWithDelta((float) $divisor, (float) $this->etfTracker->currentDivisor(), 1e-6);
    }

    // --- Who Is In ---

    public function testTheIndexCarriesTheConfiguredNumberOfNames(): void
    {
        $result = $this->committee()->reconstitute($this->rankedUniverse(60), 0);

        $this->assertCount(FinancialConstants::INDEX_CONSTITUENT_COUNT, $result['tickers']);
    }

    public function testASmallerMarketThanTheIndexJustTakesEverythingListed(): void
    {
        $result = $this->committee()->reconstitute($this->rankedUniverse(12), 0);

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

        $first = $committee->reconstitute($stocks, 0);
        $this->assertContains('T000', $first['tickers']);

        $stocks[0]->setIsBankrupt(true);
        $second = $committee->reconstitute($stocks, 100);

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
        $first = $committee->reconstitute($stocks, 0);

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

        $second = $committee->reconstitute($stocks, 100);

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

        $committee->reconstitute($stocks, 0);

        foreach ($stocks as $stock) {
            if ($stock->getTicker() === 'T059') {
                $stock->setPrice('9000000000000.00000000');
            }
        }

        $second = $committee->reconstitute($stocks, 100);

        $this->assertContains('T059', $second['tickers']);
    }

    // --- Membership Lookups ---

    public function testTheStandingMembershipIsReadableAsALookup(): void
    {
        $committee = $this->committee();
        $committee->reconstitute($this->rankedUniverse(60), 0);

        $members = $committee->currentMembers();

        $this->assertCount(FinancialConstants::INDEX_CONSTITUENT_COUNT, $members);
        $this->assertTrue($members['T000'] ?? false);
        $this->assertArrayNotHasKey('T059', $members);
    }

    public function testAFreshMarketHasNoMembershipUntilItIsTaken(): void
    {
        $this->assertSame([], $this->committee()->currentMembers());
    }

    public function testMemberCapitalisationSumsOnlyTheMembers(): void
    {
        $committee = $this->committee();
        $committee->reconstitute($this->rankedUniverse(60), 0);

        $caps = [];
        for ($i = 0; $i < 60; $i++) {
            $caps[sprintf('T%03d', $i)] = 100.0;
        }

        $this->assertEqualsWithDelta(
            FinancialConstants::INDEX_CONSTITUENT_COUNT * 100.0,
            $committee->memberCapitalisation($caps),
            1e-9
        );
    }

    public function testAMarketWithNoMembershipYetCountsEverything(): void
    {
        // Before the first reconstitution the index is the whole board, which is what it was before there
        // was a membership at all.
        $this->assertEqualsWithDelta(300.0, $this->committee()->memberCapitalisation(['A' => 100.0, 'B' => 200.0]), 1e-9);
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
