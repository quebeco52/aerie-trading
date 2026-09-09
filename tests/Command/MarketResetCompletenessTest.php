<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Stock;
use Doctrine\ORM\Mapping\Column;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Keeps app:market-reset honest against the Stock entity.
 *
 * The reset writes the balance sheet by hand-listed SQL, so a column added to the entity is silently
 * excluded from it and carries the OLD market into the new one — stale ledgers, stale learned
 * parameters, stale accumulators. That is the failure the reset's own comment warns about, and it has
 * already happened more than once, because nothing checked. This does.
 *
 * When this fails, the fix is almost always to add the column to the UPDATE in MarketResetCommand with
 * whatever its unseeded value is (NULL for a ledger the engine re-seeds, the field default for an
 * accumulator). Add it to INTENTIONALLY_PRESERVED only if it genuinely must survive a reset.
 */
final class MarketResetCompletenessTest extends TestCase
{
    /**
     * Columns the reset deliberately leaves alone, with the reason it may.
     *
     * @var array<string, string>
     */
    private const INTENTIONALLY_PRESERVED = [
        'id'     => 'Primary key; the reset updates rows in place rather than recreating them.',
        'ticker' => 'Row identity — it is the WHERE key the reset matches on.',
    ];

    /**
     * @return array<string, string> column name => property name
     */
    private function stockColumns(): array
    {
        $columns = [];

        foreach ((new ReflectionClass(Stock::class))->getProperties() as $property) {
            foreach ($property->getAttributes(Column::class) as $attribute) {
                $arguments = $attribute->getArguments();
                $columns[$arguments['name'] ?? $this->toSnakeCase($property->getName())] = $property->getName();
            }
        }

        ksort($columns);

        return $columns;
    }

    private function toSnakeCase(string $property): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property));
    }

    /**
     * @return list<string>
     */
    private function resetAssignedColumns(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Command/MarketResetCommand.php');

        $this->assertSame(
            1,
            preg_match('/UPDATE stocks SET(.*?)WHERE ticker = :ticker/s', $source, $statement),
            'Could not find the stock reset UPDATE — if the reset was restructured, update this test with it.'
        );

        preg_match_all('/^\s*([a-z_]+)\s*=/m', $statement[1], $assignments);

        return $assignments[1];
    }

    public function testResetWritesEveryPersistedStockColumn(): void
    {
        $missing = array_diff(
            array_keys($this->stockColumns()),
            $this->resetAssignedColumns(),
            array_keys(self::INTENTIONALLY_PRESERVED)
        );

        $this->assertSame([], array_values($missing), sprintf(
            "app:market-reset does not clear these Stock columns, so they survive a reset and carry the old market forward: %s",
            implode(', ', $missing)
        ));
    }

    /** A column listed as preserved must actually still exist, or the exemption is hiding a typo. */
    public function testPreservedColumnsStillExist(): void
    {
        $columns = array_keys($this->stockColumns());

        foreach (array_keys(self::INTENTIONALLY_PRESERVED) as $preserved) {
            $this->assertContains($preserved, $columns, sprintf('"%s" is exempted from the reset but is no longer a Stock column.', $preserved));
        }
    }

    /** The reset must not assign a column the entity no longer maps, which would fail at runtime. */
    public function testResetDoesNotWriteColumnsTheEntityNoLongerHas(): void
    {
        $stale = array_diff($this->resetAssignedColumns(), array_keys($this->stockColumns()));

        $this->assertSame([], array_values($stale), sprintf(
            'app:market-reset assigns columns that are not mapped on Stock and would fail at runtime: %s',
            implode(', ', $stale)
        ));
    }
}
