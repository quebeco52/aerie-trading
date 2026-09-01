<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\Etf;
use App\Entity\EtfHistory;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\EtfTracker;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class EtfTrackerTest extends TestCase
{
    private EntityManagerInterface&MockObject $emMock;
    private MarketEventPublisher&Stub $marketEventMock;
    private \Redis&MockObject $redisMock;
    private Connection&MockObject $connectionMock;
    /** @var AbstractSchemaManager<\Doctrine\DBAL\Platforms\AbstractPlatform>&Stub */
    private AbstractSchemaManager&Stub $schemaManagerStub;
    /** @var EntityRepository<Etf>&Stub */
    private EntityRepository&Stub $etfRepoStub;
    private EtfTracker $tracker;

    protected function setUp(): void
    {
        $this->emMock = $this->createMock(EntityManagerInterface::class);
        $this->marketEventMock = $this->createStub(MarketEventPublisher::class);
        $this->redisMock = $this->createMock(\Redis::class);
        $this->connectionMock = $this->createMock(Connection::class);
        $this->schemaManagerStub = $this->createStub(AbstractSchemaManager::class);
        $this->etfRepoStub = $this->createStub(EntityRepository::class);

        $this->connectionMock->method('createSchemaManager')->willReturn($this->schemaManagerStub);
        $this->emMock->method('getConnection')->willReturn($this->connectionMock);
        $this->emMock->method('getRepository')->willReturnCallback(function (string $entityClass) {
            if ($entityClass === Etf::class) {
                return $this->etfRepoStub;
            }
            return $this->createStub(EntityRepository::class);
        });

        $this->tracker = new EtfTracker(
            $this->emMock,
            $this->marketEventMock,
            $this->redisMock
        );
    }

    public function testUpdateIndexInitializesDivisorAndCalculatesPrice(): void
    {
        $etf = new Etf();
        $etf->setTicker('LBI');
        $etf->setName('Lakebird Index');
        $etf->setPrice('100.00');

        // Redis has no divisor initially
        $this->redisMock->expects($this->once())->method('get')->with('market_index_divisor')->willReturn(false);
        $this->redisMock->expects($this->once())
            ->method('set')
            ->with('market_index_divisor', $this->callback(fn(mixed $val) => is_string($val)));

        $totalMarketCap = 1_000_000_000.0; // $1B
        $result = $this->tracker->updateIndex($totalMarketCap, false, 'LBI', $etf);

        $this->assertSame('LBI', $result['ticker']);
        $this->assertSame(100.0, $result['price']);
        $this->assertTrue($result['is_etf']);
        $this->assertSame('100', $etf->getPrice());
    }

    public function testUpdateIndexPersistsHistoryWhenRequested(): void
    {
        $etf = new Etf();
        $etf->setTicker('LBI');
        $etf->setName('Lakebird Index');
        $etf->setPrice('100.00');

        $this->redisMock->expects($this->once())->method('get')->with('market_index_divisor')->willReturn('10000000'); // 10M divisor

        $this->emMock->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (mixed $entity) use ($etf) {
                return $entity instanceof EtfHistory
                    && $entity->getEtf() === $etf
                    && $entity->getPrice() === '110';
            }));

        $totalMarketCap = 1_100_000_000.0; // $1.1B -> $110 price
        $result = $this->tracker->updateIndex($totalMarketCap, true, 'LBI', $etf);

        $this->assertSame(110.0, $result['price']);
    }

    public function testUpdateIndexExecutesForwardSplitWhenPriceExceeds400(): void
    {
        $etf = new Etf();
        $etf->setTicker('LBI');
        $etf->setName('Lakebird Index');
        $etf->setPrice('100.00');

        // Divisor is 1M -> with $800M cap, price would be $800 >= 400 -> triggers 4:1 forward split
        $this->redisMock->expects($this->once())->method('get')->with('market_index_divisor')->willReturn('1000000');
        $this->schemaManagerStub->method('tablesExist')->willReturn(true);

        $this->connectionMock->expects($this->once())->method('beginTransaction');
        $this->connectionMock->expects($this->once())->method('commit');

        // user_etfs and etf_history updates
        $this->connectionMock->expects($this->exactly(2))
            ->method('executeStatement')
            ->with($this->stringContains('UPDATE'));

        $totalMarketCap = 800_000_000.0;
        $result = $this->tracker->updateIndex($totalMarketCap, false, 'LBI', $etf);

        // $800 / 4 = $200
        $this->assertSame(200.0, $result['price']);
    }

    public function testUpdateIndexExecutesReverseSplitWhenPriceFallsBelow25(): void
    {
        $etf = new Etf();
        $etf->setTicker('LBI');
        $etf->setName('Lakebird Index');
        $etf->setPrice('100.00');

        // Divisor is 10M -> with $100M cap, price would be $10 < 25 -> triggers 1-for-4 reverse split
        $this->redisMock->expects($this->once())->method('get')->with('market_index_divisor')->willReturn('10000000');
        $this->schemaManagerStub->method('tablesExist')->willReturn(true);

        $this->connectionMock->expects($this->once())->method('beginTransaction');
        $this->connectionMock->expects($this->once())->method('commit');

        // Cashout + user_etfs quantity + delete 0 quantity + history update = 4 statements
        $this->connectionMock->expects($this->exactly(4))
            ->method('executeStatement');

        $totalMarketCap = 100_000_000.0;
        $result = $this->tracker->updateIndex($totalMarketCap, false, 'LBI', $etf);

        // $10 * 4 = $40
        $this->assertSame(40.0, $result['price']);
    }
}
