<?php

declare(strict_types=1);

namespace App\Tests\Service\Event;

use App\Entity\DistrictNews;
use App\Entity\Etf;
use App\Entity\EtfEvent;
use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Service\Event\MarketEventPublisher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class MarketEventPublisherTest extends TestCase
{
    private EntityManagerInterface&MockObject $emMock;
    private LoggerInterface&Stub $loggerStub;
    private \Redis&MockObject $redisMock;
    private MarketEventPublisher $publisher;

    protected function setUp(): void
    {
        $this->emMock = $this->createMock(EntityManagerInterface::class);
        $this->loggerStub = $this->createStub(LoggerInterface::class);
        $this->redisMock = $this->createMock(\Redis::class);

        $this->publisher = new MarketEventPublisher(
            $this->emMock,
            $this->loggerStub,
            $this->redisMock
        );
    }

    public function testPublishStockEventPersistsAndPushesToRedis(): void
    {
        $stock = new Stock();
        $stock->setTicker('APEX');

        $this->emMock->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (mixed $entity) use ($stock) {
                return $entity instanceof StockEvent
                    && $entity->getStock() === $stock
                    && $entity->getEventType() === 'EARNINGS'
                    && $entity->getDescription() === 'Quarterly earnings beat expectations.'
                    && $entity->getChangePercent() === '5.25';
            }));

        $this->redisMock->expects($this->once())
            ->method('lPush')
            ->with('market_events_list', $this->callback(function (string $json) {
                $data = json_decode($json, true);
                return $data['type'] === 'EARNINGS'
                    && $data['ticker'] === 'APEX'
                    && $data['change_percent'] === 5.25;
            }));

        $this->redisMock->expects($this->once())
            ->method('lTrim')
            ->with('market_events_list', 0, 49);

        $result = $this->publisher->publish($stock, 'EARNINGS', 'Quarterly earnings beat expectations.', 5.25);

        $this->assertSame('EARNINGS', $result['type']);
        $this->assertSame('APEX', $result['ticker']);
        $this->assertSame('Quarterly earnings beat expectations.', $result['description']);
        $this->assertSame(5.25, $result['change_percent']);
    }

    public function testPublishEtfEventPersistsAndPushesToRedis(): void
    {
        $etf = new Etf();
        $etf->setTicker('LBI');

        $this->emMock->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (mixed $entity) use ($etf) {
                return $entity instanceof EtfEvent
                    && $entity->getEtf() === $etf
                    && $entity->getEventType() === 'SPLIT'
                    && $entity->getChangePercent() === '0';
            }));

        $this->redisMock->expects($this->once())->method('lPush');
        $this->redisMock->expects($this->once())->method('lTrim');

        $result = $this->publisher->publish($etf, 'SPLIT', '4-for-1 forward split.', 0.0);

        $this->assertSame('SPLIT', $result['type']);
        $this->assertSame('LBI', $result['ticker']);
    }

    public function testPublishShockEventFormatsTerminalOutput(): void
    {
        $stock = new Stock();
        $stock->setTicker('SHOCK_CORP');

        $this->emMock->expects($this->once())->method('persist');
        $this->redisMock->expects($this->once())->method('lPush');
        $this->redisMock->expects($this->once())->method('lTrim');

        ob_start();
        $result = $this->publisher->publish($stock, 'SHOCK', 'Market shock occurred.', -12.5);
        $output = ob_get_clean();

        $this->assertStringContainsString('MARKET SHOCK on SHOCK_CORP: -12.50%', $output);
        $this->assertSame(-12.5, $result['change_percent']);
    }

    /** News whose price effect is not known is published without a number rather than with a made-up one. */
    public function testAnEventWithoutAKnownMoveCarriesNoNumber(): void
    {
        $etf = new Etf();
        $etf->setTicker('LBI');

        $this->emMock->expects($this->once())
            ->method('persist')
            ->with($this->callback(fn (mixed $entity): bool => $entity instanceof EtfEvent && $entity->getChangePercent() === null));

        ob_start();
        $result = $this->publisher->publish($etf, 'SHOCK', 'Systemic liquidity freeze.', null);
        $output = ob_get_clean();

        $this->assertNull($result['change_percent']);
        $this->assertNull($result['presented']['changePercent']);
        $this->assertStringContainsString('MARKET SHOCK on LBI: n/a', $output);
    }

    /** The live feed renders the card the page renders on load, so the wire copy carries it, JSON-safe. */
    /**
     * Market-wide headlines run past 255 characters (a fallen cabinet named with its supporters reaches about 290), so
     * both event tables store the description as text, never as a bounded string: a VARCHAR(255) on etf_events once
     * stopped the ticker mid-run.
     */
    public function testEventDescriptionsAreStoredAsUnboundedText(): void
    {
        foreach ([StockEvent::class, EtfEvent::class, DistrictNews::class] as $entity) {
            $column = (new \ReflectionProperty($entity, 'description'))->getAttributes(\Doctrine\ORM\Mapping\Column::class)[0]->newInstance();
            $this->assertSame(\Doctrine\DBAL\Types\Types::TEXT, $column->type, $entity);
        }
    }

    public function testTheWireCopyCarriesThePresentedCard(): void
    {
        $stock = new Stock();
        $stock->setTicker('WEAV');

        $result = $this->publisher->publish($stock, 'CONGLOMERATE EXPANSION', 'Weave Holdings executed a $12.0B CONGLOMERATE EXPANSION of Target Co.', 3.0);

        $this->assertSame('mna', $result['presented']['category']);
        $this->assertSame('CONGLOMERATE EXPANSION', $result['presented']['badge']);
        $this->assertIsString($result['presented']['recordedAt']);
        $this->assertEquals($result['presented'], json_decode((string) json_encode($result['presented']), true));
    }

    /** A district story is persisted on its desk, belongs to no instrument, and goes out as a district headline. */
    public function testADistrictStoryIsPublishedOnItsDeskWithNoInstrument(): void
    {
        $this->emMock->expects($this->once())
            ->method('persist')
            ->with($this->callback(fn (mixed $entity): bool => $entity instanceof DistrictNews
                && $entity->getDesk() === DistrictNews::DESK_GOVERNMENT
                && $entity->getTopic() === 'election_held'
                && $entity->getChangePercent() === '-1.25'));
        $this->redisMock->expects($this->once())->method('lPush');

        $result = $this->publisher->publishDistrict(DistrictNews::DESK_GOVERNMENT, 'election_held', 'The Diet has been elected.', -1.25);

        $this->assertNull($result['ticker']);
        $this->assertSame('district', $result['scope']);
        $this->assertSame('district', $result['section']);
        $this->assertTrue($result['headline']);
        $this->assertSame('ELECTION', $result['presented']['badge']);
    }

    /** A company's wire copy is filed and judged against the stock's own volatility, as a page load judges it. */
    public function testACompanyStoryIsFiledAndJudgedOnTheWire(): void
    {
        $stock = (new Stock())->setTicker('WEAV')->setVolatility('0.20');

        $big = $this->publisher->publish($stock, 'ANALYST', 'Sell-side cut its price target on WEAV to $80.00 from $100.00.', -6.0);
        $small = $this->publisher->publish($stock, 'ANALYST', 'Sell-side cut its price target on WEAV to $95.00 from $100.00.', -0.5);

        $this->assertSame(['company', 'companies'], [$big['scope'], $big['section']]);
        $this->assertTrue($big['headline']);
        $this->assertFalse($small['headline']);
    }

    /** Every event published after the ticker stamps a tick carries that tick's simulation time, persisted and on the wire. */
    public function testEventsCarryTheStampedSimulationTime(): void
    {
        $persisted = [];
        $this->emMock->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $unstamped = $this->publisher->publish((new Stock())->setTicker('WEAV'), 'SPLIT', '2-for-1 split.', 0.0);
        $this->publisher->stampSimTime(13.25);
        $stock = $this->publisher->publish((new Stock())->setTicker('WEAV'), 'SPLIT', '2-for-1 split.', 0.0);
        $district = $this->publisher->publishDistrict(DistrictNews::DESK_ECONOMY, 'recession_declared', 'A recession has been declared.', null);

        $this->assertNull($unstamped['sim_time']);
        $this->assertSame([13.25, 13.25], [$stock['sim_time'], $district['sim_time']]);
        $this->assertSame([null, 13.25, 13.25], array_map(fn (object $e): ?float => $e->getSimTime(), $persisted));
        $this->assertSame('1 Apr, Year 14', $district['presented']['dateline']);
    }

    /** A deal's size decides its headline, and the verdict is stored with the row so a page load agrees with the wire. */
    public function testADealsVerdictIsJudgedOnItsSizeAndStored(): void
    {
        $persisted = [];
        $this->emMock->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $stock = (new Stock())->setTicker('WEAV')->setVolatility('0.20');

        $large = $this->publisher->publish($stock, 'STRATEGIC ACQUISITION', 'Weave Holdings executed a $12.0B STRATEGIC ACQUISITION of Target Co.', 0.4, 0.15);
        $small = $this->publisher->publish($stock, 'STRATEGIC ACQUISITION', 'Weave Holdings executed a $1.0B STRATEGIC ACQUISITION of Target Co.', 0.1, 0.02);
        $district = $this->publisher->publishDistrict(DistrictNews::DESK_ECONOMY, 'recession_declared', 'A recession has been declared.', null);

        $this->assertSame([true, false, true], [$large['headline'], $small['headline'], $district['headline']]);
        $this->assertSame([true, false, true], array_map(fn (object $e): ?bool => $e->getHeadline(), $persisted));
    }
}
