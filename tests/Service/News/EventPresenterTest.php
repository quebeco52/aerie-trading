<?php

declare(strict_types=1);

namespace App\Tests\Service\News;

use App\Entity\DistrictNews;
use App\Entity\EtfEvent;
use App\Entity\StockEvent;
use App\Service\Event\ShockEvent;
use App\Service\News\EventPresenter;
use PHPUnit\Framework\TestCase;

class EventPresenterTest extends TestCase
{
    private EventPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new EventPresenter();
    }

    public function testPresentEarningsBeatWithFullCorporateActions(): void
    {
        $event = new StockEvent();
        $event->setEventType('EARNINGS');
        $event->setDescription('Q-Earnings: $2.26 (Beat expectations by $0.01 | +$80.92B EVA).');
        $event->setFacts([
            'eps' => 2.26,
            'surprise' => 0.01,
            'economicProfit' => 80.92e9,
            'actions' => [
                ['kind' => 'dividend', 'perShare' => 0.61, 'yield' => 0.0197],
                ['kind' => 'bonds', 'amount' => 18.31e9, 'purpose' => 'expansion'],
                ['kind' => 'buyback', 'shares' => 410_288_930],
                ['kind' => 'note', 'text' => 'Launched next-gen AI platform.'],
            ],
        ]);
        $event->setChangePercent('2.45');

        $presented = $this->presenter->present($event);

        $this->assertTrue($presented['isEarnings']);
        $this->assertSame('EARNINGS BEAT', $presented['badge']);
        $this->assertSame('$2.26', $presented['eps']);
        $this->assertSame('beat', $presented['surpriseType']);
        $this->assertSame('$0.01', $presented['surpriseAmount']);
        $this->assertSame('+$80.92B EVA', $presented['eva']);
        $this->assertTrue($presented['evaPositive']);
        $this->assertSame(2.45, $presented['changePercent']);

        // Check sub-actions (pills)
        $this->assertCount(4, $presented['pills']);

        $this->assertSame('dividend', $presented['pills'][0]['kind']);
        $this->assertStringContainsString('$0.61/sh', $presented['pills'][0]['text']);
        $this->assertStringContainsString('1.97% yield', $presented['pills'][0]['text']);

        $this->assertSame('bonds', $presented['pills'][1]['kind']);
        $this->assertSame('Issued $18.31B bonds (expansion)', $presented['pills'][1]['text']);

        $this->assertSame('buyback', $presented['pills'][2]['kind']);
        $this->assertStringContainsString('410.29M shares', $presented['pills'][2]['text']);

        $this->assertSame('note', $presented['pills'][3]['kind']);
        $this->assertStringContainsString('Launched next-gen AI platform', $presented['pills'][3]['text']);
    }

    public function testPresentEarningsMiss(): void
    {
        $event = new StockEvent();
        $event->setEventType('EARNINGS');
        $event->setDescription('Q-Earnings: -$0.45 (Missed expectations by $0.10 | -$15.00M EVA).');
        $event->setFacts(['eps' => -0.45, 'surprise' => -0.10, 'economicProfit' => -15.0e6, 'actions' => []]);
        $event->setChangePercent('-3.20');

        $presented = $this->presenter->present($event);

        $this->assertTrue($presented['isEarnings']);
        $this->assertSame('EARNINGS MISS', $presented['badge']);
        $this->assertSame('-$0.45', $presented['eps']);
        $this->assertSame('miss', $presented['surpriseType']);
        $this->assertSame('$0.10', $presented['surpriseAmount']);
        $this->assertSame('-$15.00M EVA', $presented['eva']);
        $this->assertFalse($presented['evaPositive']);
        $this->assertSame(-3.20, $presented['changePercent']);
        $this->assertEmpty($presented['pills']);
    }

    /** When special items separate adjusted from reported earnings, the beat or miss is on the adjusted figure. */
    public function testPresentEarningsWithAdjustedAndReportedFiguresKeepsTheBeat(): void
    {
        $event = new StockEvent();
        $event->setEventType('EARNINGS');
        $event->setDescription('Q-Earnings: $0.71 adjusted, -$1.20 reported (Beat expectations by $0.05 | +$1.20B EVA).');
        $event->setFacts(['eps' => 0.71, 'reportedEps' => -1.20, 'surprise' => 0.05, 'economicProfit' => 1.2e9, 'actions' => []]);

        $presented = $this->presenter->present($event);

        $this->assertSame('EARNINGS BEAT', $presented['badge']);
        $this->assertSame('$0.71', $presented['eps']);
        $this->assertSame('$0.05', $presented['surpriseAmount']);
        $this->assertSame('+$1.20B EVA', $presented['eva']);
        $this->assertEmpty($presented['pills']);
    }

    public function testPresentEarningsMet(): void
    {
        $event = new StockEvent();
        $event->setEventType('EARNINGS');
        $event->setDescription('Q-Earnings: $1.50 (Met expectations exactly | +$5.00M EVA).');
        $event->setFacts(['eps' => 1.50, 'surprise' => 0.0, 'economicProfit' => 5.0e6, 'actions' => []]);

        $presented = $this->presenter->present($event);

        $this->assertTrue($presented['isEarnings']);
        $this->assertSame('EARNINGS IN-LINE', $presented['badge']);
        $this->assertSame('met', $presented['surpriseType']);
        $this->assertSame('In-Line', $presented['surpriseText']);
        $this->assertNull($presented['surpriseAmount']);
        $this->assertSame('+$5.00M EVA', $presented['eva']);
        $this->assertTrue($presented['evaPositive']);
    }

    public function testPresentEarningsWithSubCentDeltaNormalizesToInLine(): void
    {
        $eventMiss = new StockEvent();
        $eventMiss->setEventType('EARNINGS');
        $eventMiss->setDescription('x');
        $eventMiss->setFacts(['eps' => 2.08, 'surprise' => -0.0, 'economicProfit' => 74.14e9, 'actions' => []]);
        $presentedMiss = $this->presenter->present($eventMiss);

        $this->assertSame('EARNINGS IN-LINE', $presentedMiss['badge']);
        $this->assertSame('met', $presentedMiss['surpriseType']);
        $this->assertNull($presentedMiss['surpriseAmount']);
        $this->assertSame('In-Line', $presentedMiss['surpriseText']);

        $eventBeat = new StockEvent();
        $eventBeat->setEventType('EARNINGS');
        $eventBeat->setDescription('x');
        $eventBeat->setFacts(['eps' => 1.69, 'surprise' => 0.004, 'economicProfit' => 68.37e9, 'actions' => []]);
        $presentedBeat = $this->presenter->present($eventBeat);

        $this->assertSame('EARNINGS IN-LINE', $presentedBeat['badge']);
        $this->assertSame('met', $presentedBeat['surpriseType']);
        $this->assertNull($presentedBeat['surpriseAmount']);
        $this->assertSame('In-Line', $presentedBeat['surpriseText']);
    }

    public function testPresentMarketShock(): void
    {
        $event = [
            'type' => 'SHOCK',
            'description' => '',
            'change_percent' => -4.50,
            'recorded_at' => '2026-08-29 18:04:00',
        ];

        $presented = $this->presenter->present($event);

        $this->assertFalse($presented['isEarnings']);
        $this->assertSame('MARKET SHOCK', $presented['badge']);
        $this->assertSame('bolt', $presented['icon']);
        $this->assertSame(-4.50, $presented['changePercent']);
    }

    /** A district story is badged for what happened; a crisis takes the caution tone, an election does not. */
    public function testPresentDistrictStoryByTopic(): void
    {
        $crisis = (new DistrictNews())->setDesk(DistrictNews::DESK_ECONOMY)->setTopic(ShockEvent::BANKING_CRISIS)->setDescription('Banks stop lending to each other.')->setChangePercent('-7.10');
        $vote = (new DistrictNews())->setDesk(DistrictNews::DESK_GOVERNMENT)->setTopic(ShockEvent::ELECTION_HELD)->setDescription('The Diet has been elected.');

        $crisisCard = $this->presenter->present($crisis);
        $voteCard = $this->presenter->present($vote);

        $this->assertSame(['economy', 'BANKING CRISIS', 'badge-warn'], [$crisisCard['category'], $crisisCard['badge'], $crisisCard['badgeClass']]);
        $this->assertSame(-7.1, $crisisCard['changePercent']);
        $this->assertSame(['government', 'ELECTION', 'badge-accent'], [$voteCard['category'], $voteCard['badge'], $voteCard['badgeClass']]);
        $this->assertNull($voteCard['changePercent']);
        $this->assertSame('GOVERNMENT', $this->presenter->present(['type' => 'GOVERNMENT', 'description' => 'x'])['badge'], 'A topic with no badge reads as its desk.');
    }

    /**
     * A story is dated in the District's calendar; a row from before it carried a simulation time keeps its clock time.
     */
    public function testAStoryIsDatedInTheDistrictsCalendar(): void
    {
        $stamped = (new StockEvent())->setEventType('SPLIT')->setDescription('2-for-1 split.')->setSimTime(13.25);
        $old = (new StockEvent())->setEventType('SPLIT')->setDescription('2-for-1 split.')->setRecordedAt(new \DateTime('2026-10-06 14:22'));

        $this->assertSame('1 Apr, Year 14', $this->presenter->present($stamped)['dateline']);
        $this->assertSame('2026-10-06 14:22', $this->presenter->present($old)['dateline']);
        $this->assertSame('1 Apr, Year 14', $this->presenter->presentForWire(['type' => 'SPLIT', 'description' => 'x', 'sim_time' => 13.25])['dateline']);
    }

    /** A quarter's results read as a sentence, so the headline strip and the district map can print them. */
    public function testEarningsReadAsASentence(): void
    {
        $headline = fn(float $eps, float $surprise): string => $this->presenter->present([
            'type' => 'EARNINGS',
            'description' => 'x',
            'facts' => ['eps' => $eps, 'surprise' => $surprise, 'economicProfit' => 1.0e9, 'actions' => []],
        ])['headline'];

        $this->assertSame('Quarterly earnings of $1.42 a share beat forecasts by $0.11.', $headline(1.42, 0.11));
        $this->assertSame('Quarterly loss of $0.45 a share missed forecasts by $0.10.', $headline(-0.45, -0.10));
        $this->assertSame('Quarterly earnings of $1.00 a share were in line with forecasts.', $headline(1.00, 0.0));
    }

    /** A report stored before stories carried their facts shows no figures and claims no beat or miss. */
    public function testAReportWithoutFactsClaimsNothing(): void
    {
        $presented = $this->presenter->present(['type' => 'EARNINGS', 'description' => 'Q-Earnings: $1.42 (Beat expectations by $0.11 | +$1.20B EVA).']);

        $this->assertSame(['EARNINGS', 'neutral'], [$presented['badge'], $presented['tone']]);
        $this->assertNull($presented['eps']);
        $this->assertNull($presented['surpriseType']);
        $this->assertNull($presented['eva']);
        $this->assertSame([], $presented['pills']);
    }

    /** A price shock with no description of its own says what the shares did. */
    public function testAPriceShockSaysWhatTheSharesDid(): void
    {
        $this->assertSame('Hummock Foods shares jump 12.4% in a sudden move.', EventPresenter::shockHeadline('Hummock Foods shares', 12.44));
        $this->assertSame('Shares drop 11.0% in a sudden move.', $this->presenter->present(['type' => 'SHOCK', 'description' => '', 'change_percent' => -11.0])['headline']);
        $this->assertSame('Shares moved sharply.', $this->presenter->present(['type' => 'SHOCK', 'description' => ''])['headline']);
    }

    public function testPresentStockSplit(): void
    {
        $event = new EtfEvent();
        $event->setEventType('SPLIT');
        $event->setDescription('2:1 Stock Split executed');

        $presented = $this->presenter->present($event);

        $this->assertFalse($presented['isEarnings']);
        $this->assertSame('STOCK SPLIT', $presented['badge']);
        $this->assertSame('call_split', $presented['icon']);
    }

    public function testPresentRatingUpgradeAndDowngrade(): void
    {
        $upgrade = [
            'type' => 'CREDIT_UPGRADE',
            'description' => 'Credit rating upgraded from BBB to A',
            'facts' => ['from' => 'BBB', 'to' => 'A'],
        ];
        $presentedUp = $this->presenter->present($upgrade);
        $this->assertSame('RATING UPGRADE', $presentedUp['badge']);
        $this->assertSame('credit_score', $presentedUp['icon']);

        $downgrade = [
            'type' => 'CREDIT_DOWNGRADE',
            'description' => 'Credit rating downgraded from A to BBB',
            'facts' => ['from' => 'A', 'to' => 'BBB'],
        ];
        $presentedDown = $this->presenter->present($downgrade);
        $this->assertSame('RATING DOWNGRADE', $presentedDown['badge']);
    }

    public function testPresentBankruptcy(): void
    {
        $event = [
            'type' => 'BANKRUPTCY',
            'description' => 'Filed for Chapter 11 bankruptcy liquidation.',
        ];
        $presented = $this->presenter->present($event);

        $this->assertSame('BANKRUPTCY', $presented['badge']);
        $this->assertSame('gavel', $presented['icon']);
        $this->assertSame(-100.0, $presented['changePercent']);
    }

    /**
     * A change at the top reads differently depending on whether the manager jumped or was pushed, and it
     * carries no move of its own — the policy the successor brings is priced by the engines, not the card.
     */
    public function testPresentManagementSuccessionSeparatesADismissalFromADeparture(): void
    {
        $dismissal = $this->presenter->present([
            'type' => 'MANAGEMENT CHANGE',
            'description' => 'Falcon Holdings chief executive was removed by the board after 5.2 years. The incoming management is expected to hold a high internal hurdle and return what it cannot beat.',
            'facts' => ['forced' => true],
            'change_percent' => 0.0,
        ]);

        $this->assertSame('governance', $dismissal['category']);
        $this->assertSame('BOARD REMOVAL', $dismissal['badge']);
        $this->assertSame('gavel', $dismissal['icon']);
        $this->assertSame(0.0, $dismissal['changePercent'], 'A succession implies no price move of its own.');

        $orderly = $this->presenter->present([
            'type' => 'MANAGEMENT CHANGE',
            'description' => 'Lakeside Mutual chief executive stepped down after 13.4 years. An internal promotion inherits the mandate unchanged.',
            'facts' => ['forced' => false],
            'change_percent' => 0.0,
        ]);

        $this->assertSame('MANAGEMENT CHANGE', $orderly['badge']);
        $this->assertSame('badge', $orderly['icon']);
        $this->assertFalse($orderly['isEarnings']);
    }

    /** A street roster change reads as a promotion or an eviction from its facts. */
    public function testAStreetRosterChangeReadsFromItsFacts(): void
    {
        $promoted = $this->presenter->present(['type' => 'DISTRICT', 'description' => 'Promoted to Glasswater Row.', 'facts' => ['promoted' => true]]);
        $evicted = $this->presenter->present(['type' => 'DISTRICT', 'description' => 'Lost its frontage on Glasswater Row.', 'facts' => ['promoted' => false]]);

        $this->assertSame(['STREET PROMOTION', 'accent'], [$promoted['badge'], $promoted['tone']]);
        $this->assertSame(['STREET EVICTION', 'warn'], [$evicted['badge'], $evicted['tone']]);
    }
}
