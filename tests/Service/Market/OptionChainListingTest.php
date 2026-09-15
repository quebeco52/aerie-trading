<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Service\Market\LiquidityEngine;
use App\Service\Market\OptionChainService;
use App\Service\Math\MathUtility;
use App\Tests\Support\StockBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Column;
use PHPUnit\Framework\TestCase;

/**
 * What listing costs the database.
 *
 * Listing is BURSTY: the expiry grid is monthly and shared by the whole market, so when a serial rolls,
 * every name in a slice wants a fresh expiry on the same pass. A contract each — one ORM INSERT per
 * contract — put the option sweep thirty milliseconds over a twenty-millisecond tick every simulated month.
 * What is pinned here is that the cost of a roll is a handful of statements rather than a few hundred.
 */
class OptionChainListingTest extends TestCase
{
    /** @var list<array{0: string, 1: array<int, mixed>}> */
    private array $sent = [];

    /** @var list<string> Every SQL the listing read its existing symbols with. */
    private array $lookups = [];

    /** @param array<int, string> $existing */
    private function service(array $existing = []): OptionChainService
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturnCallback(
            function (string $sql) use ($existing): array {
                $this->lookups[] = $sql;

                return $existing;
            }
        );
        $connection->method('executeStatement')->willReturnCallback(
            function (string $sql, array $params = []): int {
                $this->sent[] = [$sql, $params];

                return 1;
            }
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return new OptionChainService($em, new LiquidityEngine(new MathUtility()));
    }

    private function listable(string $ticker, int $id, float $price = 100.0): Stock
    {
        $stock = StockBuilder::create($ticker)
            ->withPrice($price)
            ->withSharesOutstanding(50_000_000)
            ->withPublicFloatPercentage(1.0)
            ->build();

        $property = new \ReflectionProperty(Stock::class, 'id');
        $property->setValue($stock, $id);

        return $stock;
    }

    public function testEveryColumnTheTableRequiresIsNamedByTheListingWrite(): void
    {
        $namer = static fn (string $property): string => strtolower(
            (string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $property)
        );

        $required = [];

        foreach ((new \ReflectionClass(OptionContract::class))->getProperties() as $property) {
            if ($property->getName() === 'id') {
                continue;
            }

            foreach ($property->getAttributes(Column::class) as $attribute) {
                $column = $attribute->newInstance();

                if ($column->nullable === false) {
                    $required[] = $column->name ?? $namer($property->getName());
                }
            }

            // The underlying is an association rather than a column, and it is required all the same.
            foreach ($property->getAttributes(\Doctrine\ORM\Mapping\ManyToOne::class) as $_) {
                $required[] = $namer($property->getName()) . '_id';
            }
        }

        sort($required);
        $written = OptionChainService::LISTING_COLUMNS;
        sort($written);

        $this->assertSame($required, $written);
    }

    public function testAWholeSliceIsListedInBulkRatherThanAContractAtATime(): void
    {
        $service = $this->service();
        $stocks = [$this->listable('AAA', 1), $this->listable('BBB', 2), $this->listable('CCC', 3)];

        $listed = $service->listChains($stocks, 2.0);

        // Four serials, a two-sided ladder on each, three names: a few hundred contracts.
        $this->assertGreaterThan(100, $listed);

        $expected = (int) ceil($listed / OptionChainService::LISTINGS_PER_STATEMENT);
        $this->assertCount($expected, $this->sent);

        foreach ($this->sent as $statement) {
            $this->assertStringStartsWith('INSERT INTO option_contracts (ticker, stock_id,', $statement[0]);
            $this->assertSame(0, count($statement[1]) % count(OptionChainService::LISTING_COLUMNS));
        }
    }

    public function testASliceWhoseChainsAreAlreadyOpenWritesNothing(): void
    {
        $stock = $this->listable('AAA', 1);

        $open = [];
        foreach (OptionChainService::listedSerials(2.0) as $serial) {
            foreach (OptionChainService::strikeLadder((float) $stock->getPrice()) as $strike) {
                foreach ([OptionContract::TYPE_CALL, OptionContract::TYPE_PUT] as $type) {
                    $open[] = OptionChainService::contractTicker('AAA', $serial, $type, $strike);
                }
            }
        }

        $this->assertSame(0, $this->service($open)->listChains([$stock], 2.0));
        $this->assertSame([], $this->sent);
    }

    public function testASettledSymbolStillOwnsItsNameAndIsNotListedAgain(): void
    {
        $stock = $this->listable('AAA', 1);
        $serials = OptionChainService::listedSerials(2.0);
        $strikes = OptionChainService::strikeLadder((float) $stock->getPrice());

        // Everything the grid wants already exists in the table. Whether those rows are live or long
        // settled is not the question — the symbol is unique, so the name is taken either way, and a
        // listing that ignored that would abort the tick on a duplicate key rather than skip the row.
        $taken = [];
        foreach ($serials as $serial) {
            foreach ($strikes as $strike) {
                foreach ([OptionContract::TYPE_CALL, OptionContract::TYPE_PUT] as $type) {
                    $taken[] = OptionChainService::contractTicker('AAA', $serial, $type, $strike);
                }
            }
        }

        $service = $this->service($taken);

        $this->assertSame(0, $service->listChains([$stock], 2.0));
        $this->assertSame([], $this->sent);

        // And the reason it saw them: the lookup does not filter on status.
        $this->assertCount(1, $this->lookups);
        $this->assertStringNotContainsString('status', $this->lookups[0]);
    }

    public function testANameThatFailsTheListingStandardIsNeverWritten(): void
    {
        $shell = $this->listable('DEAD', 1);
        $shell->setIsBankrupt(true);

        $this->assertSame(0, $this->service()->listChains([$shell], 2.0));
        $this->assertSame([], $this->sent);
    }

    public function testAnUnpersistedNameHasNoRowToHangAChainOn(): void
    {
        $fresh = StockBuilder::create('NEW')->withPrice(100.0)->withSharesOutstanding(50_000_000)->build();

        $this->assertSame(0, $this->service()->listChains([$fresh], 2.0));
        $this->assertSame([], $this->sent);
    }
}
