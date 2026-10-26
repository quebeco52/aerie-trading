<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate\Segment;

use App\Data\Company\Segments;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\Industry\IndustryOutlook;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Industry\InMemoryIndustryShareStore;
use App\Service\Corporate\Segment\SegmentOutlook;
use App\Service\Math\MathUtility;
use App\Service\Model\StreamContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
class SegmentOutlookTest extends TestCase
{
    /** Each line lands in one segment, and the shares are of the quarter's whole revenue. */
    public function testTheSharesAreEachSegmentsPartOfTheQuartersRevenue(): void
    {
        $this->assertEqualsWithDelta(
            [Segments::MAIN => 0.9, 'air_cargo' => 0.1],
            SegmentOutlook::shares('TERN', ['leisure_passengers' => 450.0, 'business_passengers' => 320.0, 'air_cargo' => 100.0, 'loyalty_miles' => 130.0]),
            1e-12,
        );
        $this->assertSame([], SegmentOutlook::shares('LOON', ['wireless_subscriptions' => 1.0]), 'a company in one market files none');
        $this->assertSame([], SegmentOutlook::shares('TERN', []), 'nor a report with no revenue');
    }

    /**
     * The blend is the log of the segments' expected levels averaged at their shares; with one segment, or no share
     * filed yet, it is the main segment's outlook exactly.
     */
    public function testTheBlendAveragesTheSegmentsExpectedLevels(): void
    {
        $beliefs = [Segments::MAIN => 0.05, 'air_cargo' => -0.20];

        $this->assertEqualsWithDelta(log((0.9 * exp(0.05)) + (0.1 * exp(-0.20))), SegmentOutlook::blend([Segments::MAIN => 0.9, 'air_cargo' => 0.1], $beliefs), 1e-12);
        $this->assertSame(0.05, SegmentOutlook::blend([], $beliefs));
        $this->assertSame(0.05, SegmentOutlook::blend([Segments::MAIN => 1.0], $beliefs));
    }

    /** A segment's lines lean by its expected level over the blend; the main segment's under its own key. */
    public function testEachLineLeansByItsSegmentsOutlookAgainstTheBlend(): void
    {
        $beliefs = [Segments::MAIN => 0.05, 'air_cargo' => -0.20];
        $blend = SegmentOutlook::blend([Segments::MAIN => 0.9, 'air_cargo' => 0.1], $beliefs);
        $tilts = SegmentOutlook::tilts('TERN', $beliefs, $blend);
        $momentum = [];
        foreach ($tilts as $key => $tilt) {
            $momentum[SegmentOutlook::STATE_TILT . $key] = $tilt;
        }

        $this->assertEqualsWithDelta(exp(-0.20 - $blend), SegmentOutlook::tilt($momentum, 'air_cargo'), 1e-12);
        $this->assertEqualsWithDelta(exp(0.05 - $blend), SegmentOutlook::tilt($momentum, 'leisure_passengers'), 1e-12, 'a main line');
        $this->assertSame(1.0, SegmentOutlook::tilt([], 'air_cargo'), 'no lean before a report has filed one');
        $this->assertSame([], SegmentOutlook::tilts('LOON', [Segments::MAIN => 0.3], 0.3));
    }

    /**
     * A line's target share moves toward the segment whose outlook leads, and the plan still splits to one; with no
     * lean filed the weights are exactly what they were.
     */
    public function testTheLeanMovesTheTargetMixAndLeavesTheTotal(): void
    {
        $targets = ['carrier_networks' => 0.9, 'consumer_terminals' => 0.1];
        $plain = (new StreamContext([], new MathUtility()))->resolveActiveStreamWeights($targets);
        $leaning = (new StreamContext([SegmentOutlook::STATE_TILT . 'consumer_terminals' => 1.5, SegmentOutlook::STATE_TILT . Segments::MAIN => 0.95], new MathUtility()))->resolveActiveStreamWeights($targets);

        $this->assertEqualsWithDelta(0.1, $plain['consumer_terminals'], 1e-12);
        $this->assertEqualsWithDelta(0.15 / (0.15 + 0.855), $leaning['consumer_terminals'], 1e-12);
        $this->assertEqualsWithDelta(1.0, array_sum($leaning), 1e-12);
    }

    /**
     * The ledger grows a company in several markets by its blend: once air cargo's outlook falls 20% with cargo a tenth
     * of the airline's revenue, the plant its market calls for is the blend's, and a deal's partial write keeps it.
     */
    public function testTheLedgerGrowsACompanyInSeveralMarketsByItsBlend(): void
    {
        $store = new InMemoryIndustryShareStore();
        $ledger = new IndustryShareLedger($store);
        $airline = new Stock();
        $airline->setTicker('TERN');
        $airline->setIndustry('Airlines');
        $airline->setTotalRevenue('1000');
        $macro = new MacroStateDTO(potentialGdpIndex: 1.0, gdpDeflator: 1.0);
        $outlook = static fn(float $belief): array => ['x' => $belief, 'm' => $belief, 'v' => 0.0, 'f' => 0.0, 'w' => 1.0, 'prior_belief' => 0.0, 'revised_tick' => -1];

        $ledger->resolveIndustryCapacityRatio($airline, 1_000.0, 0.4, 1.0, 0.0, 0.0, 1, 252);
        $airline->setEarningsMomentumZ([SegmentOutlook::STATE_SHARE . Segments::MAIN => 0.9, SegmentOutlook::STATE_SHARE . 'air_cargo' => 0.1]);
        $store->writeOutlooks(['salt' => 1, 'quarter' => 1, 'industries' => ['Airlines' => $outlook(0.0), 'Integrated Freight & Logistics' => $outlook(log(0.8))]]);
        $blend = log(0.9 + (0.1 * 0.8));

        $this->assertEqualsWithDelta($blend, $ledger->resolveOutlookBelief($airline), 1e-12);
        $ledger->resolveIndustryCapacityRatio($airline, 1_000.0, 0.4, 1.0, 0.0, 0.0, 2, 252);
        $this->assertEqualsWithDelta($blend, $ledger->resolveTrendCapacityGap($airline, $macro, 0.0), 1e-9, 'the plan calls for the blend');

        $ledger->recordIdiosyncraticGain($airline, 1_000.0, 0.01, 0.4, 3);
        $this->assertEqualsWithDelta($blend, $store->readIndustry('Airlines')['TERN']['segment_outlook_gap'] ?? null, 1e-12, 'a partial write keeps the gap');
        $this->assertArrayNotHasKey(IndustryOutlook::STATE_BELIEF, $airline->getEarningsMomentumZ() ?? [], 'the ledger files nothing on the firm');
    }

    public function testACompanyInOneMarketKeepsItsIndustrysOutlookAndARecordWithoutAGap(): void
    {
        $store = new InMemoryIndustryShareStore();
        $ledger = new IndustryShareLedger($store);
        $telecom = new Stock();
        $telecom->setTicker('LOON');
        $telecom->setIndustry('Telecom Services');
        $telecom->setTotalRevenue('1000');
        $store->writeOutlooks(['salt' => 1, 'quarter' => 1, 'industries' => ['Telecom Services' => ['x' => 0.1, 'm' => 0.1, 'v' => 0.0, 'f' => 0.0, 'w' => 1.0, 'prior_belief' => 0.0, 'revised_tick' => -1]]]);

        $this->assertSame(0.1, $ledger->resolveOutlookBelief($telecom));
        $this->assertNull($ledger->resolveSegmentBeliefs($telecom));
        $ledger->resolveIndustryCapacityRatio($telecom, 1_000.0, 0.4, 1.0, 0.0, 0.0, 1, 252);
        $this->assertArrayNotHasKey('segment_outlook_gap', $store->readIndustry('Telecom Services')['LOON']);
    }
}
