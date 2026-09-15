<?php

declare(strict_types=1);

namespace App\Tests\Service\District;

use App\Data\DistrictMap;
use App\Entity\Stock;
use App\Service\District\DistrictRoster;
use App\Service\District\DistrictWardComposer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Pins the register's contract: the street is re-ranked only on a reconstitution tick, the stored
 * order survives market-cap changes and bankruptcies in between, and the calendar arithmetic the
 * countdown tile and the ticker share.
 */
#[AllowMockObjectsWithoutExpectations]
class DistrictRosterTest extends TestCase
{
    private const TICKS_PER_YEAR = 3600;

    /** @var array<string, string> a one-key in-memory Redis */
    private array $store = [];

    private DistrictRoster $roster;

    protected function setUp(): void
    {
        $this->store = [];

        $redis = $this->createMock(\Redis::class);
        $redis->method('get')->willReturnCallback(fn (string $key) => $this->store[$key] ?? false);
        $redis->method('set')->willReturnCallback(function (string $key, string $value): bool {
            $this->store[$key] = $value;

            return true;
        });

        $this->roster = new DistrictRoster($redis, new DistrictWardComposer(), self::TICKS_PER_YEAR, 20_000);
    }

    public function testCalendarArithmeticLandsOnQuarterBoundaries(): void
    {
        $interval = DistrictRoster::intervalTicks(self::TICKS_PER_YEAR);
        $this->assertSame(self::TICKS_PER_YEAR / DistrictMap::RECONSTITUTIONS_PER_YEAR, $interval);

        $this->assertTrue(DistrictRoster::isReconstitutionTick(0, self::TICKS_PER_YEAR), 'A fresh market takes its first roster at tick 0');
        $this->assertTrue(DistrictRoster::isReconstitutionTick($interval, self::TICKS_PER_YEAR));
        $this->assertFalse(DistrictRoster::isReconstitutionTick($interval + 1, self::TICKS_PER_YEAR));

        $this->assertSame($interval, DistrictRoster::nextReconstitutionTick(0, self::TICKS_PER_YEAR));
        $this->assertSame($interval, DistrictRoster::nextReconstitutionTick($interval - 1, self::TICKS_PER_YEAR));
        $this->assertSame(2 * $interval, DistrictRoster::nextReconstitutionTick($interval, self::TICKS_PER_YEAR), 'Strictly after: on the boundary itself the next one is a full interval away');
    }

    public function testFirstReconstitutionReportsNobodyMoved(): void
    {
        $result = $this->roster->reconstitute($this->makeStocks(5), 0);

        $this->assertCount(5, $result['tickers']);
        $this->assertSame([], $result['promoted']);
        $this->assertSame([], $result['evicted']);
        $this->assertSame(['tick' => 0, 'tickers' => $result['tickers']], $this->roster->current());
    }

    public function testTheStreetIsFrozenBetweenReconstitutions(): void
    {
        $stocks = $this->makeStocks(DistrictMap::STREET_ROSTER_SIZE + 1);
        $this->roster->reconstitute($stocks, 0);

        // The company below the cut becomes the largest listed. Until the next reconstitution the
        // street ignores it, and the stored order is laid out unchanged.
        $riser = $stocks[DistrictMap::STREET_ROSTER_SIZE];
        $riser->setPrice('10000');
        $frontage = $this->roster->frontageFor($stocks, 17);

        $tickers = array_column($frontage['slots'], 'ticker');
        $this->assertNotContains($riser->getTicker(), $tickers, 'A riser waits below the cut');
        $this->assertSame($this->roster->current()['tickers'], $tickers, 'Stored order is honoured exactly');

        // At the boundary it is admitted and the smallest incumbent is evicted.
        $result = $this->roster->reconstitute($stocks, DistrictRoster::intervalTicks(self::TICKS_PER_YEAR));
        $this->assertSame([$riser->getTicker()], $result['promoted']);
        $this->assertSame([$stocks[DistrictMap::STREET_ROSTER_SIZE - 1]->getTicker()], $result['evicted']);
    }

    public function testABankruptTenantKeepsItsPlotUntilTheNextReconstitution(): void
    {
        $stocks = $this->makeStocks(6);
        $this->roster->reconstitute($stocks, 0);

        $stocks[2]->setIsBankrupt(true);
        $stocks[2]->setPrice('0');

        $tickers = array_column($this->roster->frontageFor($stocks, 40)['slots'], 'ticker');
        $this->assertContains('T002', $tickers, 'Defunct shell stands until the roster is retaken');

        $result = $this->roster->reconstitute($stocks, DistrictRoster::intervalTicks(self::TICKS_PER_YEAR));
        $this->assertSame(['T002'], $result['evicted']);
        $this->assertNotContains('T002', $result['tickers']);
    }

    public function testARenderWithNoRosterOnRecordTakesOne(): void
    {
        $this->assertNull($this->roster->current());

        $frontage = $this->roster->frontageFor($this->makeStocks(4), 3);

        $this->assertCount(4, $frontage['slots']);
        $this->assertSame(3, $this->roster->current()['tick']);
    }

    public function testStreetRankStaysLiveWhileTheOrderIsFrozen(): void
    {
        $stocks = $this->makeStocks(3);
        $this->roster->reconstitute($stocks, 0);

        // The smallest tenant becomes the largest: its plot does not move, its rank plate does.
        $stocks[2]->setPrice('500');
        $slots = $this->roster->frontageFor($stocks, 5)['slots'];

        $byTicker = array_column($slots, null, 'ticker');
        $this->assertSame(1, $byTicker['T002']['rank']);
        $this->assertSame(2, $byTicker['T000']['rank']);
        $this->assertSame(['T000', 'T001', 'T002'], array_column($slots, 'ticker'), 'Position is the stored order');
    }

    public function testScheduleDescribesTheCalendar(): void
    {
        $this->roster->reconstitute($this->makeStocks(2), 900);
        $schedule = $this->roster->schedule(1000);

        $this->assertSame(900, $schedule['lastTick']);
        $this->assertSame(1800, $schedule['nextTick']);
        $this->assertSame(900, $schedule['intervalTicks']);
        $this->assertSame(self::TICKS_PER_YEAR, $schedule['ticksPerYear']);
        $this->assertEqualsWithDelta(0.02, $schedule['secondsPerTick'], 1.0e-9);
    }

    /** @return Stock[] $count distinct bank tenants with descending market cap, T000 the largest. */
    private function makeStocks(int $count): array
    {
        $stocks = [];
        for ($i = 0; $i < $count; $i++) {
            $stock = new Stock();
            $stock->setTicker(sprintf('T%03d', $i));
            $stock->setName("Tenant $i");
            $stock->setSector('Financials');
            $stock->setIndustry('Banks - Diversified');
            $stock->setPrice((string) (100.0 - $i));
            $stock->setSharesOutstanding('1000000');
            $stock->setSystemicImportance('none');
            $stocks[] = $stock;
        }

        return $stocks;
    }
}
