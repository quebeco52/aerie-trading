<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro\Recorder;

use App\Data\MacroFieldRegistry;
use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class MacroSnapshotRecorderTest extends TestCase
{
    /**
     * Columns whose name does not follow from the field name by the wire-key convention, written out
     * by hand so the alignment is checked against something other than the registry that produced it.
     *
     * @var array<string, string>
     */
    private const ANCHOR_COLUMNS = [
        'inflation' => 'inflation',
        'output_gap' => 'outputGap',
        'policy_rate' => 'policyRate',
        'yield2y' => 'yield2y',
        'yield2y_ema' => 'yield2yEma',
        'yield5y' => 'yield5y',
        'yield10y' => 'yield10y',
        'yield30y' => 'yield30y',
        'term_premium10y' => 'termPremium10y',
        'term_premium10y_ema' => 'termPremium10yEma',
        'risk_neutral10y' => 'riskNeutral10y',
        'ns_curvature2' => 'nsCurvature2',
        'manufacturing_pmi' => 'manufacturingPmi',
        'money_supply_growth_ema' => 'moneySupplyGrowthEma',
        'restrictive_duration' => 'restrictiveDuration',
    ];

    /**
     * Captures the statement and parameters the recorder would execute.
     *
     * @return array{sql: string, params: list<mixed>}
     */
    private function capture(MacroStateDTO $dto): array
    {
        $captured = [];

        $connMock = $this->createMock(Connection::class);
        $connMock->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params) use (&$captured): int {
                $captured = ['sql' => $sql, 'params' => $params];

                return 1;
            });

        (new MacroSnapshotRecorder())->recordSnapshot($dto, $connMock);

        return $captured;
    }

    /**
     * Splits the generated statement into its column list and asserts the placeholder count matches.
     *
     * @return list<string>
     */
    private function columnsOf(string $sql): array
    {
        $this->assertMatchesRegularExpression('/^INSERT INTO macro_report \(.+\) VALUES \(.+\)$/', $sql);
        $this->assertSame(1, preg_match('/\((.*?)\) VALUES \((.*?)\)/', $sql, $matches));

        $columns = array_map('trim', explode(',', $matches[1]));
        $placeholders = array_map('trim', explode(',', $matches[2]));

        $this->assertSame(
            count($columns),
            count($placeholders),
            'The statement names a different number of columns than it binds placeholders.'
        );
        $this->assertSame(['?'], array_values(array_unique($placeholders)), 'Every value must be bound, never inlined.');

        return $columns;
    }

    /**
     * Builds a snapshot in which every field carries a value unique to that field, so a value landing
     * in the wrong column cannot coincidentally match what belongs there.
     *
     * @return array{0: MacroStateDTO, 1: array<string, float>} The snapshot and field => sentinel.
     */
    private function sentinelSnapshot(): array
    {
        $state = new MacroState();
        $sentinels = [];

        $offset = 0;
        foreach ((new \ReflectionClass($state))->getProperties() as $property) {
            ++$offset;
            if ((string) $property->getType() !== 'float') {
                continue;
            }
            $value = 1000.0 + $offset;
            $property->setValue($state, $value);
            $sentinels[$property->getName()] = $value;
        }

        return [MacroStateDTO::fromMacroState($state), $sentinels];
    }

    public function testRecordSnapshotExecutesInsertStatement(): void
    {
        $dto = MacroStateDTO::fromMacroState(new MacroState());
        $captured = $this->capture($dto);

        $columns = $this->columnsOf($captured['sql']);

        $this->assertCount(count($columns), $captured['params']);
        $this->assertSame('recorded_at', $columns[0], 'The timestamp must lead the statement.');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $captured['params'][0]);
    }

    /**
     * Every persisted field must reach its own column. The statement used to name its columns, its
     * placeholders and its values in three hand-maintained lists, where one misplaced entry shifted
     * every later value into its neighbour's column without anything failing.
     */
    public function testEveryValueLandsInItsOwnColumn(): void
    {
        [$dto, $sentinels] = $this->sentinelSnapshot();
        $captured = $this->capture($dto);

        $columns = $this->columnsOf($captured['sql']);
        $byColumn = array_combine($columns, $captured['params']);

        foreach (MacroFieldRegistry::persistedColumns() as $field => $column) {
            $this->assertArrayHasKey($column, $byColumn, "Column {$column} is missing from the statement.");
            $this->assertSame(
                $sentinels[$field],
                $byColumn[$column],
                "Field {$field} did not land in column {$column}."
            );
        }
    }

    /**
     * The same alignment, checked against column names written out by hand rather than against the
     * registry that generated them — including the curve columns whose name drops the underscore the
     * wire key keeps (`yield2y` on the table, `yield_2y` on the wire).
     */
    public function testNamedColumnsCarryTheFieldTheyAreNamedFor(): void
    {
        [$dto, $sentinels] = $this->sentinelSnapshot();
        $captured = $this->capture($dto);

        $byColumn = array_combine($this->columnsOf($captured['sql']), $captured['params']);

        foreach (self::ANCHOR_COLUMNS as $column => $field) {
            $this->assertArrayHasKey($column, $byColumn, "Expected macro_report to have a {$column} column.");
            $this->assertSame($sentinels[$field], $byColumn[$column], "Column {$column} does not carry {$field}.");
        }
    }

    /**
     * The statement is built once and reused, so a second snapshot must bind the same columns.
     */
    public function testStatementIsStableAcrossSnapshots(): void
    {
        $dto = MacroStateDTO::fromMacroState(new MacroState());

        $first = $this->capture($dto);
        $second = $this->capture($dto);

        $this->assertSame($first['sql'], $second['sql']);
        $this->assertCount(count($first['params']), $second['params']);
    }
}
