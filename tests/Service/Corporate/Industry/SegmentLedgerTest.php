<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate\Industry;

use App\Data\Company\Segments;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Industry\InMemoryIndustryShareStore;
use App\Service\Corporate\Segment\SegmentBooks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The industry ledger for a company that keeps plant by segment: one record per segment, each in its own industry.
 */
#[Group('unit')]
class SegmentLedgerTest extends TestCase
{
    private const FREIGHT = 'Integrated Freight & Logistics';

    /**
     * When the books open, the main record keeps the main segment's plant and market, so its share of its own market
     * and its excess over trend per unit of that market stay put; the blend's gap folds into the anchor's outlook, so
     * its trend path does not move.
     */
    public function testOpeningTheBooksMovesTheOtherSegmentsOutOfTheMainRecord(): void
    {
        $store = new InMemoryIndustryShareStore();
        $ledger = new IndustryShareLedger($store);
        $tern = $this->tern();
        $macro = new MacroStateDTO(totalTime: 0.0, potentialGdpIndex: 1.0, gdpDeflator: 1.0);
        $ledger->resolveIndustryCapacityRatio($tern, 1_000.0, 0.4, 1.0, 0.0, 0.0, 10, 252);
        $before = $store->readIndustry('Airlines')['TERN'];
        $ledger->resolveIndustryCapacityRatio($tern, 1_100.0, 0.4, 1.0, 0.0, 0.0, 73, 252);
        $record = $store->readIndustry('Airlines')['TERN'];
        $record['segment_outlook_gap'] = 0.05;
        $store->writeRecord('Airlines', 'TERN', $record);
        $gapBefore = $ledger->resolveTrendCapacityGap($tern, $macro, 0.0);

        $ledger->openSegmentBooks($tern, 0.9);
        $tern->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.9, 'air_cargo' => 0.1], 1.0, []));

        $main = $store->readIndustry('Airlines')['TERN'];
        $this->assertEqualsWithDelta(0.9 * $before['anchor_capacity_share'], $main['anchor_capacity_share'], 1e-15);
        $this->assertEqualsWithDelta(0.9 * $before['anchor_demand_share'], $main['anchor_demand_share'], 1e-15);
        $this->assertEqualsWithDelta(990.0, $main['capacity'], 1e-9);
        $this->assertArrayNotHasKey('segment_outlook_gap', $main);
        $this->assertEqualsWithDelta(-0.05, $main['anchor_outlook'] ?? null, 1e-15);
        $this->assertEqualsWithDelta((float) $gapBefore, $ledger->resolveTrendCapacityGap($tern, $macro, 0.0), 1e-12, 'the gap the blend carried is the anchor\'s now');

        $ledger->resolveIndustryCapacityRatio($tern, 990.0, 0.4, 1.0, 0.0, 0.0, 136, 252);
        $this->assertArrayNotHasKey('segment_outlook_gap', $store->readIndustry('Airlines')['TERN'], 'and the main record does not file it again');

        // A report replayed after its tick rolled back opens the books again; the main record is split once.
        $ledger->openSegmentBooks($tern, 0.9);
        $this->assertEqualsWithDelta(0.9 * $before['anchor_capacity_share'], $store->readIndustry('Airlines')['TERN']['anchor_capacity_share'], 1e-15);
    }

    /**
     * A segment's record sits in its own industry under its own key, anchors at its first pricing, books its own
     * sales, and starts reading its rivals' gains from the day it enters.
     */
    public function testASegmentHasItsOwnRecordInItsOwnIndustry(): void
    {
        $store = new InMemoryIndustryShareStore();
        $ledger = new IndustryShareLedger($store);
        $rival = (new Stock())->setTicker('CANV')->setIndustry(self::FREIGHT)->setTotalRevenue('5000');
        $ledger->resolveIndustryCapacityRatio($rival, 5_000.0, 0.3, 1.0, 0.0, 0.0, 5, 252);
        $ledger->recordIdiosyncraticGain($rival, 5_000.0, 0.10, 0.3, 5);
        $tern = $this->tern();
        $tern->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.9, 'air_cargo' => 0.1], 1.0, []));

        $this->assertSame(0.0, $ledger->resolveSegmentRivalDrain($tern, 'air_cargo', 100.0, 10, 252), 'gains booked before it sold there took nothing from it');
        $supply = $ledger->resolveSegmentSupply($tern, 'air_cargo', 100.0, 100.0, 0.02, 1.0, 0.0, 0.0, 10, 252);
        $ledger->recordSegmentGain($tern, 'air_cargo', 120.0, 0.0, 0.02, 10);

        $this->assertNotNull($supply);
        $record = $store->readIndustry(self::FREIGHT)['TERN/air_cargo'];
        $this->assertEqualsWithDelta(100.0, $record['capacity'], 1e-12);
        $this->assertEqualsWithDelta(100.0 / 0.02, $record['anchor_demand_share'], 1e-9);
        $this->assertEqualsWithDelta(120.0, $record['revenue'], 1e-12);
        $this->assertArrayNotHasKey('TERN', $store->readIndustry(self::FREIGHT));
        $this->assertEqualsWithDelta(0.0, $ledger->resolveSegmentCapacityGap($tern, 'air_cargo', new MacroStateDTO(potentialGdpIndex: 1.0, gdpDeflator: 1.0), 0.0), 1e-12, 'on its anchor');

        $ledger->recordIdiosyncraticGain($rival, 5_000.0, 0.10, 0.3, 20);
        $this->assertLessThan(0.0, $ledger->resolveSegmentRivalDrain($tern, 'air_cargo', 100.0, 30, 252), 'a later gain does');
    }

    /** No segment record is written before the company keeps plant by segment. */
    public function testNoSegmentRecordBeforeTheBooksOpen(): void
    {
        $store = new InMemoryIndustryShareStore();
        $ledger = new IndustryShareLedger($store);

        $this->assertNull($ledger->resolveSegmentSupply($this->tern(), 'air_cargo', 100.0, 100.0, 0.02, 1.0, 0.0, 0.0, 10, 252));
        $this->assertSame([], $store->readIndustry(self::FREIGHT));
    }

    /**
     * The market a segment enters is the largest any other firm already in its industry anchored, grown as the ledger
     * grows it; the company's own records do not size it.
     */
    public function testASegmentIsSizedAgainstTheMarketItsIndustrysFirmsSellInto(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $macro = new MacroStateDTO(totalTime: 0.0, potentialGdpIndex: 1.0, gdpDeflator: 1.0);
        $this->assertNull($ledger->resolveIndustryMarketRevenue(self::FREIGHT, 'TERN', $macro, 0.0, 10, 252));

        $ledger->resolveIndustryCapacityRatio((new Stock())->setTicker('CANV')->setIndustry(self::FREIGHT), 5_000.0, 0.25, 1.0, 0.0, 0.0, 5, 252);
        $ledger->resolveIndustryCapacityRatio((new Stock())->setTicker('SMAL')->setIndustry(self::FREIGHT), 100.0, 0.5, 1.0, 0.0, 0.0, 5, 252);
        $tern = $this->tern();
        $tern->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.9, 'air_cargo' => 0.1], 1.0, []));
        $ledger->resolveSegmentSupply($tern, 'air_cargo', 100.0, 100.0, 0.0001, 1.0, 0.0, 0.0, 10, 252);

        $this->assertEqualsWithDelta(20_000.0, $ledger->resolveIndustryMarketRevenue(self::FREIGHT, 'TERN', $macro, 0.0, 10, 252), 1e-6);
        $later = new MacroStateDTO(totalTime: 0.0, potentialGdpIndex: 1.1, gdpDeflator: 1.0);
        $this->assertEqualsWithDelta(22_000.0, $ledger->resolveIndustryMarketRevenue(self::FREIGHT, 'TERN', $later, 0.0, 10, 252), 1e-6);
    }

    /**
     * A failed company's plant leaves every industry it sold into, and a division sold pro rata takes the same slice
     * of every segment's record.
     */
    public function testFailureAndADivestitureReachEverySegment(): void
    {
        $store = new InMemoryIndustryShareStore();
        $ledger = new IndustryShareLedger($store);
        $tern = $this->tern();
        $ledger->resolveIndustryCapacityRatio($tern, 900.0, 0.4, 1.0, 0.0, 0.0, 10, 252);
        $tern->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.9, 'air_cargo' => 0.1], 1.0, []));
        $ledger->resolveSegmentSupply($tern, 'air_cargo', 100.0, 100.0, 0.02, 1.0, 0.0, 0.0, 10, 252);
        $macro = new MacroStateDTO(totalTime: 0.0, potentialGdpIndex: 1.0, gdpDeflator: 1.0);

        $ledger->recordDivestedFraction($tern, 0.25, $macro, 0.0);
        $this->assertEqualsWithDelta(675.0, $store->readIndustry('Airlines')['TERN']['capacity'], 1e-9);
        $this->assertEqualsWithDelta(75.0, $store->readIndustry(self::FREIGHT)['TERN/air_cargo']['capacity'], 1e-9);

        $ledger->retireFirm($tern);
        $this->assertSame(0.0, $store->readIndustry('Airlines')['TERN']['capacity']);
        $this->assertSame(0.0, $store->readIndustry(self::FREIGHT)['TERN/air_cargo']['capacity']);
        $this->assertGreaterThan(0.0, $store->readIndustry(self::FREIGHT)['TERN/air_cargo']['anchor_capacity_share'], 'its demand stays until the record ages out');
    }

    /**
     * A deal into a segment is reviewed in that segment's market, among that industry's firms, and its plant joins that
     * segment's record; a segment sold whole stops selling for the company, its plant run by the buyer until the record
     * ages out, while the main record keeps its plant.
     */
    public function testDealsReachTheSegmentTheyAreIn(): void
    {
        $store = new InMemoryIndustryShareStore();
        $ledger = new IndustryShareLedger($store);
        $macro = new MacroStateDTO(totalTime: 0.0, potentialGdpIndex: 1.0, gdpDeflator: 1.0);
        $ledger->resolveIndustryCapacityRatio((new Stock())->setTicker('CANV')->setIndustry(self::FREIGHT), 5_000.0, 0.25, 1.0, 0.0, 0.0, 5, 252);
        $tern = $this->tern();
        $ledger->resolveIndustryCapacityRatio($tern, 900.0, 0.4, 1.0, 0.0, 0.0, 10, 252);
        $tern->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.9, 'air_cargo' => 0.1], 1.0, []));
        $ledger->resolveSegmentSupply($tern, 'air_cargo', 100.0, 100.0, 0.02, 1.0, 0.0, 0.0, 10, 252);

        $market = $ledger->describeMergerMarket($tern, $macro, 0.0, 10, 252, 'air_cargo');
        $this->assertNotNull($market);
        $this->assertEqualsWithDelta(100.0 / 0.02, $market['market_revenue'], 1e-6);
        $this->assertEqualsWithDelta(0.02, $market['acquirer_share'], 1e-12);
        $this->assertEqualsWithDelta(1.0 - 0.25 - 0.02, $market['fringe_share'], 1e-12, 'freight\'s roster, not the airlines\'');

        $ledger->recordAcquiredCapacity($tern, 50.0, $macro, 0.0, 'air_cargo');
        $this->assertEqualsWithDelta(150.0, $store->readIndustry(self::FREIGHT)['TERN/air_cargo']['capacity'], 1e-9);
        $this->assertEqualsWithDelta(900.0, $store->readIndustry('Airlines')['TERN']['capacity'], 1e-9);

        $ledger->recordSoldSegment($tern, 'air_cargo');
        $sold = $store->readIndustry(self::FREIGHT)['TERN/air_cargo'];
        $this->assertSame(0.0, $sold['revenue'], 'it sells nothing for the company');
        $this->assertEqualsWithDelta(150.0, $sold['capacity'], 1e-9, 'the buyer runs its plant until the record ages out');
        $this->assertSame(10, $sold['tick']);
        $this->assertEqualsWithDelta(900.0, $store->readIndustry('Airlines')['TERN']['capacity'], 1e-9);
    }

    /**
     * A segment sells in another company's industry but runs its own industry's regimes: it is not a member over which
     * this industry's regime odds are spread.
     */
    public function testASegmentIsNotAMemberOfAnotherIndustrysRegimes(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $tern = $this->tern();
        $tern->setEarningsMomentumZ(SegmentBooks::open([Segments::MAIN => 0.9, 'air_cargo' => 0.1], 1.0, []));
        $ledger->recordSegmentGain($tern, 'air_cargo', 120.0, 0.0, 0.02, 10);
        $rival = (new Stock())->setTicker('CANV')->setIndustry(self::FREIGHT);

        $this->assertSame(1, $ledger->openIndustryRegimes($rival, 20, 252)?->members());
    }

    private function tern(): Stock
    {
        return (new Stock())->setTicker('TERN')->setIndustry('Airlines')->setTotalRevenue('1000');
    }
}
