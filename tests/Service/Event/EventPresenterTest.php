<?php

namespace App\Tests\Service\Event;

use App\Entity\Etf;
use App\Entity\EtfEvent;
use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Service\Event\EventPresenter;
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
        $desc = "Q-Earnings: $2.26 (Beat expectations by $0.01 | +$80.92B EVA).\n• Paid $0.61/share div ($27.64B total, 1.97% yield).\n• Issued $18.31B in bonds for expansion.\n• Bought back 410,288,930 shares.\n• Launched next-gen AI platform.";

        $event = new StockEvent();
        $event->setEventType('EARNINGS');
        $event->setDescription($desc);
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

        $this->assertSame('dividend', $presented['pills'][0]['type']);
        $this->assertStringContainsString('$0.61/sh', $presented['pills'][0]['text']);
        $this->assertStringContainsString('1.97% yield', $presented['pills'][0]['text']);

        $this->assertSame('debt', $presented['pills'][1]['type']);
        $this->assertStringContainsString('$18.31B bonds', $presented['pills'][1]['text']);

        $this->assertSame('buyback', $presented['pills'][2]['type']);
        $this->assertStringContainsString('410.29M shares', $presented['pills'][2]['text']);

        $this->assertSame('lore', $presented['pills'][3]['type']);
        $this->assertStringContainsString('Launched next-gen AI platform', $presented['pills'][3]['text']);
    }

    public function testPresentEarningsMiss(): void
    {
        $desc = "Q-Earnings: -$0.45 (Missed expectations by $0.10 | -$15.00M EVA).";

        $event = new StockEvent();
        $event->setEventType('EARNINGS');
        $event->setDescription($desc);
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

    public function testPresentEarningsMet(): void
    {
        $desc = "Q-Earnings: $1.50 (Met expectations exactly | +$5.00M EVA).";

        $event = new StockEvent();
        $event->setEventType('EARNINGS');
        $event->setDescription($desc);

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
        $descMiss = "Q-Earnings: $2.08 (Missed expectations by $0.00 | +$74.14B EVA).";
        $eventMiss = new StockEvent();
        $eventMiss->setEventType('EARNINGS');
        $eventMiss->setDescription($descMiss);
        $presentedMiss = $this->presenter->present($eventMiss);

        $this->assertSame('EARNINGS IN-LINE', $presentedMiss['badge']);
        $this->assertSame('met', $presentedMiss['surpriseType']);
        $this->assertNull($presentedMiss['surpriseAmount']);
        $this->assertSame('In-Line', $presentedMiss['surpriseText']);

        $descBeat = "Q-Earnings: $1.69 (Beat expectations by $0.00 | +$68.37B EVA).";
        $eventBeat = new StockEvent();
        $eventBeat->setEventType('EARNINGS');
        $eventBeat->setDescription($descBeat);
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
            'description' => 'Sudden market shock detected.',
            'change_percent' => -4.50,
            'recorded_at' => '2026-08-29 18:04:00',
        ];

        $presented = $this->presenter->present($event);

        $this->assertFalse($presented['isEarnings']);
        $this->assertSame('MARKET SHOCK', $presented['badge']);
        $this->assertSame('bolt', $presented['icon']);
        $this->assertSame(-4.50, $presented['changePercent']);
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
            'type' => 'RATING_UPGRADE',
            'description' => 'Credit rating upgraded from BBB to A',
        ];
        $presentedUp = $this->presenter->present($upgrade);
        $this->assertSame('RATING UPGRADE', $presentedUp['badge']);
        $this->assertSame('credit_score', $presentedUp['icon']);

        $downgrade = [
            'type' => 'RATING_DOWNGRADE',
            'description' => 'Credit rating downgraded from A to BBB',
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
}
