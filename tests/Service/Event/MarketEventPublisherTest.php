<?php

declare(strict_types=1);

namespace App\Tests\Service\Event;

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
}
