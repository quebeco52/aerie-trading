<?php

declare(strict_types=1);

namespace App\Tests\Service\Event;

use App\DTO\MacroStateDTO;
use App\Entity\Etf;
use App\Entity\EtfEvent;
use App\Service\Event\MarketEventPublisher;
use App\Service\Event\NarrativeEngine;
use App\Service\Event\ShockEvent;
use App\Service\Event\SystemicEventReporter;
use App\Service\Market\PriceChangeFeed;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class SystemicEventReporterTest extends TestCase
{
    /** @var list<EtfEvent> */
    private array $persisted = [];

    protected function setUp(): void
    {
        // The publisher echoes SHOCK headlines to the terminal; keep the test output clean.
        ob_start();
    }

    protected function tearDown(): void
    {
        ob_end_clean();
    }

    /** The card carries what the benchmark did over the month its chart buffer holds, not a size assumed for the event. */
    public function testTheHeadlineCarriesTheBenchmarksRealMonthMove(): void
    {
        $headline = $this->reporter(bufferedPrice: 100.0)->report($this->macroWith(ShockEvent::BANKING_CRISIS), $this->benchmarkAt('94'));

        $this->assertNotNull($headline);
        $this->assertSame('SHOCK', $headline['type']);
        $this->assertEqualsWithDelta(-6.0, $headline['change_percent'], 1e-9);
        $this->assertSame('-6', $this->persisted[0]->getChangePercent());
    }

    /** A rescue is reported with the move the market made, so a programme launched into a falling market reads as one. */
    public function testALaunchIntoAFallingMarketIsNotLabelledARally(): void
    {
        $headline = $this->reporter(bufferedPrice: 100.0)->report($this->macroWith(ShockEvent::TITAN_INTERVENTION), $this->benchmarkAt('91.5'));

        $this->assertNotNull($headline);
        $this->assertLessThan(0.0, $headline['change_percent']);
    }

    public function testWithoutBufferedHistoryTheHeadlineCarriesNoNumber(): void
    {
        $headline = $this->reporter(bufferedPrice: null)->report($this->macroWith(ShockEvent::ELECTION_HELD), $this->benchmarkAt('100'));

        $this->assertNotNull($headline);
        $this->assertNull($headline['change_percent']);
        $this->assertNull($this->persisted[0]->getChangePercent());
    }

    public function testATickWithoutAnEventPublishesNothing(): void
    {
        $this->assertNull($this->reporter(bufferedPrice: 100.0)->report(new MacroStateDTO(), $this->benchmarkAt('100')));
        $this->assertSame([], $this->persisted);
    }

    private function reporter(?float $bufferedPrice): SystemicEventReporter
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('lIndex')->willReturn($bufferedPrice === null ? false : json_encode(['price' => $bufferedPrice, 'recorded_at' => '2026-09-01 00:00']));

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->assertInstanceOf(EtfEvent::class, $entity);
            $this->persisted[] = $entity;
        });

        return new SystemicEventReporter(
            new NarrativeEngine(),
            new MarketEventPublisher($entityManager, $this->createStub(LoggerInterface::class), $redis),
            new PriceChangeFeed($redis, $entityManager, 3600),
        );
    }

    private function macroWith(string $eventType): MacroStateDTO
    {
        return new MacroStateDTO(eventType: $eventType);
    }

    private function benchmarkAt(string $price): Etf
    {
        return (new Etf())->setTicker('LBI')->setPrice($price);
    }
}
