<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Chart;

use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Repository\StockEventRepository;
use App\Service\Market\Chart\ChartEventMarkers;
use App\Service\News\EventPresenter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The stories a price chart pins: dated by age behind the newest history row, routine results left out, and past the
 * cap only headlines and splits kept.
 */
#[Group('unit')]
final class ChartEventMarkersTest extends TestCase
{
    public function testAStoryIsDatedByItsAgeAndCarriesTheNewsCard(): void
    {
        $markers = $this->markersFor([$this->event('CREDIT_DOWNGRADE', 9.75, 'Rated BB by the agency.')], 10.0);

        $this->assertCount(1, $markers);
        $this->assertEqualsWithDelta(0.25, $markers[0]['age'], 1e-12);
        $this->assertSame('RATING DOWNGRADE', $markers[0]['badge']);
        $this->assertSame('debt', $markers[0]['card'], 'The chart labels a story by its kind of news.');
        $this->assertSame('down', $markers[0]['tone']);
        $this->assertSame('Rated BB by the agency.', $markers[0]['headline']);
    }

    /** A beat or a miss is pinned; results in line with forecasts are routine and are not. */
    public function testOnlyResultsThatSurpriseArePinned(): void
    {
        $markers = $this->markersFor([
            $this->event('EARNINGS', 9.0, '', ['eps' => 1.20, 'surprise' => 0.10]),
            $this->event('EARNINGS', 9.25, '', ['eps' => 1.20, 'surprise' => 0.0]),
            $this->event('EARNINGS', 9.5, '', ['eps' => 1.20, 'surprise' => -0.10]),
        ], 10.0);

        $this->assertSame(['EARNINGS BEAT', 'EARNINGS MISS'], array_column($markers, 'badge'));
    }

    /** Past the cap a long range keeps the headlines the desk ran and the splits, not the routine stories. */
    public function testPastTheCapOnlyHeadlinesAndSplitsAreKept(): void
    {
        $events = [];
        for ($i = 0; $i < ChartEventMarkers::MAX_MARKERS + 5; ++$i) {
            $events[] = $this->event('MANAGEMENT CHANGE', $i * 0.1, "Change {$i}.");
        }
        $events[] = $this->event('STRATEGIC ACQUISITION', 2.0, 'Bought a rival.', [], true);
        $events[] = $this->event('SPLIT', 3.0, '2-for-1 split.');

        $markers = $this->markersFor($events, 10.0);

        $this->assertSame(['STRATEGIC ACQUISITION', 'STOCK SPLIT'], array_column($markers, 'badge'));
        $this->assertTrue($markers[0]['major']);
    }

    /**
     * @param  list<StockEvent>          $events
     * @return list<array<string, mixed>>
     */
    private function markersFor(array $events, float $newest): array
    {
        usort($events, static fn(StockEvent $a, StockEvent $b): int => $a->getSimTime() <=> $b->getSimTime());
        $repository = $this->createStub(StockEventRepository::class);
        $repository->method('findBetween')->willReturn($events);

        return (new ChartEventMarkers($repository, new EventPresenter()))->between(new Stock(), null, $newest);
    }

    /** @param array<string, mixed> $facts */
    private function event(string $type, float $simTime, string $description, array $facts = [], bool $headline = false): StockEvent
    {
        return (new StockEvent())
            ->setEventType($type)
            ->setDescription($description)
            ->setFacts($facts)
            ->setRecordedAt(new \DateTime('2026-01-01'))
            ->setSimTime($simTime)
            ->setHeadline($headline);
    }
}
