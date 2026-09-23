<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate\Industry;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\Industry\InMemoryIndustryShareStore;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Math\FinancialConstants;
use PHPUnit\Framework\TestCase;

class IndustryShareLedgerTest extends TestCase
{
    private function stock(string $ticker, float $annualRevenue, string $industry = 'Steel'): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setIndustry($industry);
        $stock->setTotalRevenue((string) $annualRevenue);

        return $stock;
    }

    public function testRivalGainIsZeroSumAcrossPeersWeightedBySize(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $winner = $this->stock('WIN', 1_000.0);
        $bigPeer = $this->stock('BIG', 3_000.0);
        $smallPeer = $this->stock('SML', 1_000.0);

        // Everyone is known to the ledger before the winner books a +10% idiosyncratic quarter.
        $ledger->recordIdiosyncraticGain($bigPeer, 3_000.0, 0.0, 0.5, 10);
        $ledger->recordIdiosyncraticGain($smallPeer, 1_000.0, 0.0, 0.5, 10);
        $ledger->recordIdiosyncraticGain($winner, 1_000.0, 0.10, 0.5, 20);

        $bigDrain = $ledger->resolveRivalShareDrain($bigPeer, 30, 252);
        $smallDrain = $ledger->resolveRivalShareDrain($smallPeer, 30, 252);

        // The winner took 100 of revenue from a 4,000 pool of others: 75 from BIG (2.5%), 25 from SML (2.5%).
        $this->assertEqualsWithDelta(-0.025, $bigDrain, 1e-9);
        $this->assertEqualsWithDelta(-0.025, $smallDrain, 1e-9);
        $this->assertEqualsWithDelta(-100.0, ($bigDrain * 3_000.0) + ($smallDrain * 1_000.0), 1e-9);
    }

    /**
     * In a concentrated industry a leader's ordinary beat can exceed everything its peers sell, and proportional
     * absorption would then take more than 100% of a peer's revenue. The drain is bounded per report, in both
     * directions, and the excess is left as market growth rather than share.
     */
    public function testDrainIsBoundedWhenALeaderOutweighsItsPeers(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $leader = $this->stock('BIG', 100_000.0);
        $peer = $this->stock('SML', 2_000.0);
        $otherPeer = $this->stock('SML2', 2_000.0);

        $ledger->recordIdiosyncraticGain($peer, 2_000.0, 0.0, 0.5, 10);
        $ledger->recordIdiosyncraticGain($otherPeer, 2_000.0, 0.0, 0.5, 10);
        // A leader that is its whole market: a 5% beat on 100,000 is 5,000 of "share" against a 4,000 pool
        // of others, and unbounded that is -125%.
        $ledger->recordIdiosyncraticGain($leader, 100_000.0, 0.05, 1.0, 20);

        $this->assertEqualsWithDelta(-IndustryShareLedger::MAX_SHARE_DRAIN_PER_REPORT, $ledger->resolveRivalShareDrain($peer, 30, 252), 1e-9);

        // And symmetrically when the leader collapses: a peer cannot win more than the bound either.
        $ledger->recordIdiosyncraticGain($leader, 100_000.0, -0.05, 1.0, 40);
        $this->assertEqualsWithDelta(IndustryShareLedger::MAX_SHARE_DRAIN_PER_REPORT, $ledger->resolveRivalShareDrain($otherPeer, 50, 252), 1e-9);
    }

    public function testEachRivalRecordIsAbsorbedOnlyOnce(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $winner = $this->stock('WIN', 1_000.0);
        $peer = $this->stock('PEER', 1_000.0);

        $ledger->recordIdiosyncraticGain($peer, 1_000.0, 0.0, 0.5, 5);
        $ledger->recordIdiosyncraticGain($winner, 1_000.0, 0.10, 0.5, 20);

        $this->assertEqualsWithDelta(-0.10, $ledger->resolveRivalShareDrain($peer, 30, 252), 1e-9);
        $this->assertEqualsWithDelta(0.0, $ledger->resolveRivalShareDrain($peer, 95, 252), 1e-9);
    }

    public function testStaleRecordsAndOtherIndustriesAreIgnored(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $peer = $this->stock('PEER', 1_000.0);
        $ledger->recordIdiosyncraticGain($this->stock('OLD', 1_000.0), 1_000.0, 0.50, 0.5, 0);
        $ledger->recordIdiosyncraticGain($this->stock('FAR', 1_000.0, 'Airlines'), 1_000.0, 0.50, 0.5, 990);

        $this->assertEqualsWithDelta(0.0, $ledger->resolveRivalShareDrain($peer, 1_000, 252), 1e-9);
    }

    public function testFirmWithoutIndustryIsIgnored(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $orphan = new Stock();
        $orphan->setTicker('ORPH');

        $this->assertSame(0.0, $ledger->resolveRivalShareDrain($orphan, 10, 252));
        $this->assertSame(1.0, $ledger->resolveIndustryCapacityRatio($orphan, 1_000.0, 0.5, 1.0, 0.0, 0.0, 10, 252));
    }

    // --- Capacity balance ---

    public function testAFirmsFirstPricingIsItsAnchorAndEntryIsNeutral(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $first = $this->stock('ONE', 4_000.0);
        $second = $this->stock('TWO', 2_000.0);

        // Each firm's plant at first pricing is trend plant, whatever its share: the balance is one.
        $this->assertEqualsWithDelta(1.0, $ledger->resolveIndustryCapacityRatio($first, 4_000.0, 0.5, 1.0, 0.0, 0.0, 10, 252), 1e-9);
        $this->assertEqualsWithDelta(1.0, $ledger->resolveIndustryCapacityRatio($second, 2_000.0, 0.25, 1.0, 0.0, 0.0, 20, 252), 1e-9);
        // Neither firm has built anything: the balance is still one on the next report.
        $this->assertEqualsWithDelta(1.0, $ledger->resolveIndustryCapacityRatio($first, 4_000.0, 0.5, 1.0, 0.0, 0.0, 73, 252), 1e-9);
    }

    public function testABuildBeyondTrendIsExcessSupplyWeightedByTheBuildersAddressableShare(): void
    {
        $ratioAfterBuild = function (float $buildersShare): array {
            $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
            $builder = $this->stock('BLD', 4_000.0);
            $peer = $this->stock('PER', 2_000.0);

            $ledger->resolveIndustryCapacityRatio($builder, 4_000.0, $buildersShare, 1.0, 0.0, 0.0, 10, 252);
            $ledger->resolveIndustryCapacityRatio($peer, 2_000.0, 0.25, 1.0, 0.0, 0.0, 20, 252);

            // The builder adds half again to its plant; trend has not moved.
            return [
                'builder' => $ledger->resolveIndustryCapacityRatio($builder, 6_000.0, $buildersShare, 1.0, 0.0, 0.0, 73, 252),
                'peer' => $ledger->resolveIndustryCapacityRatio($peer, 2_000.0, 0.25, 1.0, 0.0, 0.0, 83, 252),
            ];
        };

        // Half the market: 2,000 of excess plant against a market of 8,000 lifts industry supply a quarter,
        // and the peer that built nothing sells into the same glut.
        $dominant = $ratioAfterBuild(0.5);
        $this->assertEqualsWithDelta(1.25, $dominant['builder'], 1e-9);
        $this->assertEqualsWithDelta(1.25, $dominant['peer'], 1e-9);

        // Five percent of the market: the same build is 2,000 against 80,000, and the fringe absorbs the rest.
        $small = $ratioAfterBuild(0.05);
        $this->assertEqualsWithDelta(1.025, $small['builder'], 1e-9);
        $this->assertEqualsWithDelta(1.025, $small['peer'], 1e-9);
    }

    public function testTrendPlantGrowsWithTrendNominalGdpAndTheSecularExcess(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $firm = $this->stock('GRW', 1_000.0);

        $ledger->resolveIndustryCapacityRatio($firm, 1_000.0, 0.5, 1.0, 0.0, 0.03, 10, 252);

        // Nominal trend GDP up ten percent and two years of a three-point secular excess: trend plant is
        // 1,000 * 1.1 * e^0.06 and the market 2,000 times that, against plant that has not moved — so the
        // firm has fallen BEHIND trend and the industry is short of capacity by its shortfall over the market.
        $growth = 1.10 * exp(0.03 * 2.0);
        $expected = 1.0 + (1_000.0 - 1_000.0 * $growth) / (2_000.0 * $growth);
        $ratio = $ledger->resolveIndustryCapacityRatio($firm, 1_000.0, 0.5, 1.10, 2.0, 0.03, 514, 252);

        $this->assertEqualsWithDelta($expected, $ratio, 1e-9);
        $this->assertLessThan(1.0, $ratio);
    }

    public function testARetiredFirmsPlantLeavesTheBalanceButItsDemandDoesNotUntilItsRecordAgesOut(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $survivor = $this->stock('SRV', 3_000.0);
        $failed = $this->stock('FLD', 3_000.0);

        $ledger->resolveIndustryCapacityRatio($survivor, 3_000.0, 0.5, 1.0, 0.0, 0.0, 10, 252);
        $ledger->resolveIndustryCapacityRatio($failed, 3_000.0, 0.5, 1.0, 0.0, 0.0, 20, 252);

        $ledger->retireFirm($failed);

        // Half the market's plant is gone against unchanged demand: the survivor sells into a tight market.
        $this->assertEqualsWithDelta(0.5, $ledger->resolveIndustryCapacityRatio($survivor, 3_000.0, 0.5, 1.0, 0.0, 0.0, 73, 252), 1e-9);
        // A year on the failed firm's record has aged out: the fringe is taken to have filled the gap.
        $this->assertEqualsWithDelta(1.0, $ledger->resolveIndustryCapacityRatio($survivor, 3_000.0, 0.5, 1.0, 0.0, 0.0, 20 + 253, 252), 1e-9);
        // Retiring a firm the ledger never saw writes nothing.
        $ledger->retireFirm($this->stock('NVR', 1.0));
        $this->assertArrayNotHasKey('NVR', (new \ReflectionProperty($ledger, 'store'))->getValue($ledger)->readIndustry('Steel'));
    }

    /**
     * Buying an off-board company buys plant that was already supplying the market. The same 2,000 that
     * reads as a 25% glut when it is BUILT (see the test above) leaves the balance at one when it is BOUGHT:
     * the builder's share of the market rises and the fringe's falls by exactly that much.
     */
    public function testAnAcquisitionMovesPlantFromTheFringeWithoutMovingTheBalance(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $acquirer = $this->stock('ACQ', 4_000.0);
        $peer = $this->stock('PER', 2_000.0);
        $macro = new MacroStateDTO(potentialGdpIndex: 1.0, gdpDeflator: 1.0, totalTime: 0.0);

        $ledger->resolveIndustryCapacityRatio($acquirer, 4_000.0, 0.5, 1.0, 0.0, 0.0, 10, 252);
        $ledger->resolveIndustryCapacityRatio($peer, 2_000.0, 0.25, 1.0, 0.0, 0.0, 20, 252);
        $this->assertEqualsWithDelta(0.75, $ledger->resolveRosterTrendShare($acquirer, 30, 252), 1e-9);

        $ledger->recordAcquiredCapacity($acquirer, 2_000.0, $macro, 0.0);

        $this->assertEqualsWithDelta(1.0, $ledger->resolveIndustryCapacityRatio($acquirer, 6_000.0, 0.5, 1.0, 0.0, 0.0, 73, 252), 1e-9);
        $this->assertEqualsWithDelta(1.0, $ledger->resolveIndustryCapacityRatio($peer, 2_000.0, 0.25, 1.0, 0.0, 0.0, 83, 252), 1e-9);
        $this->assertEqualsWithDelta(1.0, $ledger->resolveRosterTrendShare($acquirer, 90, 252), 1e-9, 'the fringe ceded the share the acquirer bought');
    }

    /**
     * The transfer is struck at the deal date, where trend has moved on from the anchor: the ratio the day
     * after the deal is the ratio the day before it, whatever the firm's standing against trend.
     */
    public function testATransferUnderTrendGrowthLeavesTheBalanceWhereItWas(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $firm = $this->stock('GRW', 1_000.0);
        $ledger->resolveIndustryCapacityRatio($firm, 1_000.0, 0.5, 1.0, 0.0, 0.03, 10, 252);

        $before = $ledger->resolveIndustryCapacityRatio($firm, 1_000.0, 0.5, 1.10, 2.0, 0.03, 514, 252);
        $ledger->recordAcquiredCapacity($firm, 400.0, new MacroStateDTO(potentialGdpIndex: 1.10, gdpDeflator: 1.0, totalTime: 2.0), 0.03);
        $after = $ledger->resolveIndustryCapacityRatio($firm, 1_400.0, 0.5, 1.10, 2.0, 0.03, 520, 252);

        $this->assertEqualsWithDelta($before, $after, 1e-9);
    }

    /**
     * A division sold to an off-board buyer keeps supplying the market from the fringe. A seller overbuilt by
     * 2,000 that sells half of its 6,000 takes its excess with it pro rata to nothing: the balance holds.
     */
    public function testADivestitureHandsPlantBackToTheFringeWithoutMovingTheBalance(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $seller = $this->stock('SEL', 4_000.0);
        $peer = $this->stock('PER', 2_000.0);
        $macro = new MacroStateDTO(potentialGdpIndex: 1.0, gdpDeflator: 1.0, totalTime: 0.0);

        $ledger->resolveIndustryCapacityRatio($seller, 4_000.0, 0.5, 1.0, 0.0, 0.0, 10, 252);
        $ledger->resolveIndustryCapacityRatio($peer, 2_000.0, 0.25, 1.0, 0.0, 0.0, 20, 252);
        $overbuilt = $ledger->resolveIndustryCapacityRatio($seller, 6_000.0, 0.5, 1.0, 0.0, 0.0, 73, 252);
        $this->assertEqualsWithDelta(1.25, $overbuilt, 1e-9);

        $ledger->recordDivestedFraction($seller, 0.5, $macro, 0.0);

        $this->assertEqualsWithDelta($overbuilt, $ledger->resolveIndustryCapacityRatio($seller, 3_000.0, 0.5, 1.0, 0.0, 0.0, 136, 252), 1e-9);
    }

    public function testATransferForAFirmTheLedgerHasNotPricedWritesNothing(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $macro = new MacroStateDTO(potentialGdpIndex: 1.0, gdpDeflator: 1.0, totalTime: 0.0);

        $ledger->recordAcquiredCapacity($this->stock('NEW', 1_000.0), 500.0, $macro, 0.0);
        $ledger->recordDivestedFraction($this->stock('NEW', 1_000.0), 0.3, $macro, 0.0);

        $this->assertArrayNotHasKey('NEW', (new \ReflectionProperty($ledger, 'store'))->getValue($ledger)->readIndustry('Steel'));
    }

    public function testTheRosterTrendShareIsTheSumOfAnchorSharesAndTheFringeIsTheRest(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $leader = $this->stock('LDR', 4_000.0);
        $peer = $this->stock('PER', 2_000.0);

        $this->assertSame(0.0, $ledger->resolveRosterTrendShare($leader, 10, 252), 'nothing anchored yet');

        $ledger->resolveIndustryCapacityRatio($leader, 4_000.0, 0.40, 1.0, 0.0, 0.0, 10, 252);
        $this->assertEqualsWithDelta(0.40, $ledger->resolveRosterTrendShare($leader, 10, 252), 1e-9);

        $ledger->resolveIndustryCapacityRatio($peer, 2_000.0, 0.25, 1.0, 0.0, 0.0, 20, 252);
        $this->assertEqualsWithDelta(0.65, $ledger->resolveRosterTrendShare($leader, 20, 252), 1e-9);

        // The anchor share does not move when the firm builds: the fringe is sized off trend, not off plant.
        $ledger->resolveIndustryCapacityRatio($leader, 6_000.0, 0.55, 1.0, 0.0, 0.0, 73, 252);
        $this->assertEqualsWithDelta(0.65, $ledger->resolveRosterTrendShare($leader, 73, 252), 1e-9);

        // A retired firm's hole stays on the roster until its record ages out, then belongs to the fringe.
        $ledger->retireFirm($peer);
        $this->assertEqualsWithDelta(0.65, $ledger->resolveRosterTrendShare($leader, 73, 252), 1e-9);
        $this->assertEqualsWithDelta(0.40, $ledger->resolveRosterTrendShare($leader, 20 + 253, 252), 1e-9);

        // A roster past its whole market is a closed loop: no fringe.
        $ledger->resolveIndustryCapacityRatio($this->stock('BIG', 9_000.0), 9_000.0, 0.90, 1.0, 0.0, 0.0, 300, 252);
        $this->assertSame(1.0, $ledger->resolveRosterTrendShare($leader, 300, 252));
        $this->assertSame(0.0, $ledger->resolveRosterTrendShare($this->stock('', 1.0), 300, 252));

        $described = $ledger->describeIndustry($leader, ['trend_nominal_gdp' => 1.0, 'total_time' => 0.0, 'secular_excess_growth' => 0.0], 300, 252);
        $this->assertEqualsWithDelta(1.0, $described['roster_trend_share'], 1e-9);
    }

    /**
     * A merger review measures the acquirer's market: its trend demand in revenue, the roster's trend shares
     * of it (the concentration index is their sum of squares, the atomistic fringe adding nothing) and the
     * fringe still off the board. A leader at 30% beside a peer at 10% sits in a market of 10,000 with
     * HHI 1,000 and 60% left to buy.
     */
    public function testTheMergerMarketIsTheAcquirersTrendDemandAndTheRostersShares(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $leader = $this->stock('LDR', 3_000.0);
        $peer = $this->stock('PER', 1_000.0);
        $macro = new MacroStateDTO(potentialGdpIndex: 1.0, gdpDeflator: 1.0, totalTime: 0.0);

        $this->assertNull($ledger->describeMergerMarket($leader, $macro, 0.0, 10, 252), 'no market before the firm is priced');

        $ledger->resolveIndustryCapacityRatio($leader, 3_000.0, 0.30, 1.0, 0.0, 0.0, 10, 252);
        $ledger->resolveIndustryCapacityRatio($peer, 1_000.0, 0.10, 1.0, 0.0, 0.0, 20, 252);

        $market = $ledger->describeMergerMarket($leader, $macro, 0.0, 30, 252);
        $this->assertNotNull($market);
        $this->assertEqualsWithDelta(10_000.0, $market['market_revenue'], 1e-6);
        $this->assertEqualsWithDelta(0.30, $market['acquirer_share'], 1e-12);
        $this->assertEqualsWithDelta(0.10, $market['herfindahl'], 1e-12);
        $this->assertEqualsWithDelta(0.60, $market['fringe_share'], 1e-12);

        // The market rides trend nominal GDP and the industry's secular excess, as the anchor's trend demand does.
        $later = $ledger->describeMergerMarket($leader, new MacroStateDTO(potentialGdpIndex: 1.2, gdpDeflator: 1.1, totalTime: 5.0), 0.02, 40, 252);
        $this->assertEqualsWithDelta(10_000.0 * 1.2 * 1.1 * exp(0.02 * 5.0), $later['market_revenue'], 1e-6);

        // A peer whose record has aged out is off the roster: its share is back in the fringe.
        $stale = $ledger->describeMergerMarket($leader, $macro, 0.0, 20 + 253, 252);
        $this->assertEqualsWithDelta(0.09, $stale['herfindahl'], 1e-12);
        $this->assertEqualsWithDelta(0.70, $stale['fringe_share'], 1e-12);
    }

    /** What a deal buys leaves the fringe and joins the acquirer's share; the concentration index follows. */
    public function testAnAcquisitionMovesItsShareOutOfTheFringeAndIntoTheAcquirers(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $leader = $this->stock('LDR', 3_000.0);
        $macro = new MacroStateDTO(potentialGdpIndex: 1.0, gdpDeflator: 1.0, totalTime: 0.0);
        $ledger->resolveIndustryCapacityRatio($leader, 3_000.0, 0.30, 1.0, 0.0, 0.0, 10, 252);
        $ledger->resolveIndustryCapacityRatio($this->stock('PER', 1_000.0), 1_000.0, 0.10, 1.0, 0.0, 0.0, 20, 252);

        $ledger->recordAcquiredCapacity($leader, 500.0, $macro, 0.0);

        $market = $ledger->describeMergerMarket($leader, $macro, 0.0, 30, 252);
        $this->assertEqualsWithDelta(10_000.0, $market['market_revenue'], 1e-6, 'a deal changes who owns the market, not its size');
        $this->assertEqualsWithDelta(0.35, $market['acquirer_share'], 1e-12);
        $this->assertEqualsWithDelta((0.35 ** 2) + (0.10 ** 2), $market['herfindahl'], 1e-12);
        $this->assertEqualsWithDelta(0.55, $market['fringe_share'], 1e-12);
    }

    public function testTheCapacityRatioIsBoundedToTheRangeThePriceRespondsTo(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $firm = $this->stock('BIG', 1_000.0);

        // A firm that IS its whole market: the closed-loop case, so its build is the industry's.
        $ledger->resolveIndustryCapacityRatio($firm, 1_000.0, 1.0, 1.0, 0.0, 0.0, 10, 252);

        $this->assertSame(FinancialConstants::MAX_INDUSTRY_CAPACITY_RATIO, $ledger->resolveIndustryCapacityRatio($firm, 10_000.0, 1.0, 1.0, 0.0, 0.0, 73, 252));
        $this->assertSame(FinancialConstants::MIN_INDUSTRY_CAPACITY_RATIO, $ledger->resolveIndustryCapacityRatio($firm, 10.0, 1.0, 1.0, 0.0, 0.0, 136, 252));
    }

    public function testAShareAboveOneIsAClosedLoopAndAShareOfZeroIsNotPriced(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $saturated = $this->stock('SAT', 1_000.0);
        $capitalless = $this->stock('NIL', 1_000.0);

        // Capital past the whole addressable market anchors at a share of one, never a negative fringe.
        $ledger->resolveIndustryCapacityRatio($saturated, 1_000.0, 1.8, 1.0, 0.0, 0.0, 10, 252);
        $this->assertEqualsWithDelta(1.5, $ledger->resolveIndustryCapacityRatio($saturated, 1_500.0, 1.8, 1.0, 0.0, 0.0, 73, 252), 1e-9);

        $this->assertSame(1.0, $ledger->resolveIndustryCapacityRatio($capitalless, 1_000.0, 0.0, 1.0, 0.0, 0.0, 10, 252));
    }

    public function testCapacityRecordsAndShareRecordsShareOneRosterWithoutOverwritingEachOther(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $firm = $this->stock('MIX', 1_000.0);
        $peer = $this->stock('PEE', 1_000.0);

        $ledger->resolveIndustryCapacityRatio($peer, 1_000.0, 0.5, 1.0, 0.0, 0.0, 5, 252);
        $ledger->resolveIndustryCapacityRatio($firm, 1_000.0, 0.5, 1.0, 0.0, 0.0, 10, 252);
        $ledger->recordIdiosyncraticGain($firm, 1_000.0, 0.10, 0.5, 10);

        // The gain booked after the capacity record survives, and so does the capacity after the drain read.
        $this->assertEqualsWithDelta(-0.10, $ledger->resolveRivalShareDrain($peer, 30, 252), 1e-9);
        $this->assertEqualsWithDelta(1.0, $ledger->resolveIndustryCapacityRatio($peer, 1_000.0, 0.5, 1.0, 0.0, 0.0, 73, 252), 1e-9);
        // And the firm's anchor survived both share writes: its build is priced against the plant it was anchored at.
        $this->assertEqualsWithDelta(1.25, $ledger->resolveIndustryCapacityRatio($firm, 1_500.0, 0.5, 1.0, 0.0, 0.0, 74, 252), 1e-9);
    }

    public function testARivalsGainIsSpreadOverItsWholeMarketNotJustTheModelledRoster(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $giant = $this->stock('GNT', 10_000.0);
        $small = $this->stock('SML', 1_000.0);

        // The giant holds 28% of its market and beats by 3%. Its $300 came out of everyone else in a market
        // of 10,000 / 0.28, of which the small peer is a sliver — not out of the one modelled peer alone.
        $ledger->recordIdiosyncraticGain($giant, 10_000.0, 0.03, 0.28, 10);
        $othersInMarket = 10_000.0 * (1.0 - 0.28) / 0.28;
        $this->assertEqualsWithDelta(-300.0 / $othersInMarket, $ledger->resolveRivalShareDrain($small, 20, 252), 1e-9);

        // A rival that IS its whole market has no fringe: the roster absorbs all of it, up to the bound.
        $closedLoop = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $closedLoop->recordIdiosyncraticGain($giant, 10_000.0, 0.03, 1.0, 10);
        $this->assertEqualsWithDelta(-IndustryShareLedger::MAX_SHARE_DRAIN_PER_REPORT, $closedLoop->resolveRivalShareDrain($small, 20, 252), 1e-9);
    }

    public function testABookedGainIsBoundedLikeTheDrainAndTheShareSurvivesLaterWrites(): void
    {
        $store = new InMemoryIndustryShareStore();
        $ledger = new IndustryShareLedger($store);
        $firm = $this->stock('BND', 1_000.0);

        $ledger->recordIdiosyncraticGain($firm, 1_000.0, 0.90, 0.30, 10);
        $this->assertSame(IndustryShareLedger::MAX_SHARE_DRAIN_PER_REPORT, $store->readIndustry('Steel')['BND']['gain']);

        $ledger->resolveRivalShareDrain($firm, 20, 252);
        $ledger->resolveIndustryCapacityRatio($firm, 1_000.0, 0.30, 1.0, 0.0, 0.0, 20, 252);
        $this->assertEqualsWithDelta(0.30, $store->readIndustry('Steel')['BND']['addressable_share'], 1e-9);
    }

    // --- Description for the page ---

    public function testDescribeIndustryReportsRevenueSharesAndTheBalanceWithoutWritingAnything(): void
    {
        $store = new InMemoryIndustryShareStore();
        $ledger = new IndustryShareLedger($store);
        $leader = $this->stock('LDR', 3_000.0);
        $peer = $this->stock('PER', 1_000.0);

        $ledger->resolveIndustryCapacityRatio($leader, 3_000.0, 0.5, 1.0, 0.0, 0.0, 10, 252);
        $ledger->resolveIndustryCapacityRatio($peer, 1_000.0, 0.5, 1.0, 0.0, 0.0, 20, 252);
        $ledger->recordIdiosyncraticGain($leader, 3_000.0, 0.0, 0.5, 10);
        $ledger->recordIdiosyncraticGain($peer, 1_000.0, 0.0, 0.5, 20);
        // The leader then builds a third more: 1,000 over a market of 6,000.
        $ledger->resolveIndustryCapacityRatio($leader, 4_000.0, 0.5, 1.0, 0.0, 0.0, 25, 252);
        $before = $store->readIndustry('Steel');

        $trend = ['trend_nominal_gdp' => 1.0, 'total_time' => 0.0, 'secular_excess_growth' => 0.0];
        $description = $ledger->describeIndustry($peer, $trend, 30, 252);

        $this->assertEqualsWithDelta(0.25, $description['revenue_share'], 1e-9);
        $this->assertEqualsWithDelta(0.75, $description['peers']['LDR']['revenue_share'], 1e-9);
        $this->assertEqualsWithDelta(5_000.0, $description['installed_capacity'], 1e-9);
        $this->assertEqualsWithDelta(4_000.0, $description['trend_capacity'], 1e-9);
        $this->assertEqualsWithDelta(1.0 + 1_000.0 / 6_000.0, $description['capacity_ratio'], 1e-9);
        $this->assertSame($before, $store->readIndustry('Steel'), 'a page view must not move the balance');
    }

    public function testDescribeIndustryCountsAnUnreportedFirmAtItsCurrentRevenueAndLeavesTheBalanceUnknown(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $known = $this->stock('KNW', 1_000.0);
        $fresh = $this->stock('NEW', 1_000.0);
        $ledger->recordIdiosyncraticGain($known, 1_000.0, 0.0, 0.5, 10);

        $description = $ledger->describeIndustry($fresh, ['trend_nominal_gdp' => 1.0, 'total_time' => 0.0, 'secular_excess_growth' => 0.0], 20, 252);

        $this->assertEqualsWithDelta(0.5, $description['revenue_share'], 1e-9);
        $this->assertNull($description['capacity_ratio']);
        $this->assertEqualsWithDelta(0.5, $ledger->resolveRevenueShare($fresh, 20, 252), 1e-9);
    }
}
