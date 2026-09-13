<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate\Industry;

use App\Entity\Stock;
use App\Service\Corporate\Industry\InMemoryIndustryShareStore;
use App\Service\Corporate\Industry\IndustryShareLedger;
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
        $ledger->recordIdiosyncraticGain($bigPeer, 3_000.0, 0.0, 10);
        $ledger->recordIdiosyncraticGain($smallPeer, 1_000.0, 0.0, 10);
        $ledger->recordIdiosyncraticGain($winner, 1_000.0, 0.10, 20);

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

        $ledger->recordIdiosyncraticGain($peer, 2_000.0, 0.0, 10);
        $ledger->recordIdiosyncraticGain($otherPeer, 2_000.0, 0.0, 10);
        // A 5% beat on 100,000 is 5,000 of "share" against a 4,000 pool of others: unbounded that is -125%.
        $ledger->recordIdiosyncraticGain($leader, 100_000.0, 0.05, 20);

        $this->assertEqualsWithDelta(-IndustryShareLedger::MAX_SHARE_DRAIN_PER_REPORT, $ledger->resolveRivalShareDrain($peer, 30, 252), 1e-9);

        // And symmetrically when the leader collapses: a peer cannot win more than the bound either.
        $ledger->recordIdiosyncraticGain($leader, 100_000.0, -0.05, 40);
        $this->assertEqualsWithDelta(IndustryShareLedger::MAX_SHARE_DRAIN_PER_REPORT, $ledger->resolveRivalShareDrain($otherPeer, 50, 252), 1e-9);
    }

    public function testEachRivalRecordIsAbsorbedOnlyOnce(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $winner = $this->stock('WIN', 1_000.0);
        $peer = $this->stock('PEER', 1_000.0);

        $ledger->recordIdiosyncraticGain($peer, 1_000.0, 0.0, 5);
        $ledger->recordIdiosyncraticGain($winner, 1_000.0, 0.10, 20);

        $this->assertEqualsWithDelta(-0.10, $ledger->resolveRivalShareDrain($peer, 30, 252), 1e-9);
        $this->assertEqualsWithDelta(0.0, $ledger->resolveRivalShareDrain($peer, 95, 252), 1e-9);
    }

    public function testStaleRecordsAndOtherIndustriesAreIgnored(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $peer = $this->stock('PEER', 1_000.0);
        $ledger->recordIdiosyncraticGain($this->stock('OLD', 1_000.0), 1_000.0, 0.50, 0);
        $ledger->recordIdiosyncraticGain($this->stock('FAR', 1_000.0, 'Airlines'), 1_000.0, 0.50, 990);

        $this->assertEqualsWithDelta(0.0, $ledger->resolveRivalShareDrain($peer, 1_000, 252), 1e-9);
    }

    public function testFirmWithoutIndustryIsIgnored(): void
    {
        $ledger = new IndustryShareLedger(new InMemoryIndustryShareStore());
        $orphan = new Stock();
        $orphan->setTicker('ORPH');

        $this->assertSame(0.0, $ledger->resolveRivalShareDrain($orphan, 10, 252));
    }
}
