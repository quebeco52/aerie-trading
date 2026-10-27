<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Entity\StockEvent;
use App\Service\News\EventPresenter;
use App\Service\View\CompanyTimelineBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A company's milestones: grouped by year, a rating change only when it was a headline, the newest kept past the cap.
 */
#[Group('unit')]
final class CompanyTimelineBuilderTest extends TestCase
{
    public function testMilestonesAreGroupedByYearAndRoutineRatingMovesLeftOut(): void
    {
        $timeline = CompanyTimelineBuilder::fromEvents([
            $this->event('STRATEGIC ACQUISITION', 2.3, 'Bought a rival.'),
            $this->event('CREDIT_DOWNGRADE', 2.6, 'Cut to BBB.'),
            $this->event('CREDIT_DOWNGRADE', 2.8, 'Cut to BB, below investment grade.', true),
            $this->event('MANAGEMENT CHANGE', 5.1, 'Named a new chief executive.'),
        ], new EventPresenter());

        $this->assertSame(['Year 3', 'Year 6'], array_column($timeline['years'], 'year'));
        $this->assertSame(
            ['Bought a rival.', 'Cut to BB, below investment grade.'],
            array_map(static fn(array $m): string => $m['card']->headline, $timeline['years'][0]['milestones']),
        );
        $this->assertSame(0, $timeline['earlier']);
    }

    public function testALongHistoryKeepsItsNewestMilestones(): void
    {
        $events = [];
        for ($i = 0; $i < CompanyTimelineBuilder::MAX_MILESTONES + 3; ++$i) {
            $events[] = $this->event('MANAGEMENT CHANGE', 1.0 + $i * 0.3, "Change {$i}.");
        }

        $timeline = CompanyTimelineBuilder::fromEvents($events, new EventPresenter());

        $this->assertSame(3, $timeline['earlier']);
        $milestones = array_merge(...array_column($timeline['years'], 'milestones'));
        $this->assertCount(CompanyTimelineBuilder::MAX_MILESTONES, $milestones);
        $this->assertSame('Change 3.', $milestones[0]['card']->headline);
        $this->assertSame('Change ' . (CompanyTimelineBuilder::MAX_MILESTONES + 2) . '.', end($milestones)['card']->headline);
    }

    private function event(string $type, float $simTime, string $description, bool $headline = false): StockEvent
    {
        return (new StockEvent())->setEventType($type)->setDescription($description)->setFacts([])
            ->setRecordedAt(new \DateTime('2026-01-01'))->setSimTime($simTime)->setHeadline($headline);
    }
}
