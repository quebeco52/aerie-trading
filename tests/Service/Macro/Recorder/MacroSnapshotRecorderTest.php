<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro\Recorder;

use App\Data\MacroFieldRegistry;
use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Recorder\QuarterRecord;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
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
        // The regime flags, whose bool sentinels cannot tell one from the other on their own.
        'qe_active' => 'qeActive',
        'qt_active' => 'qtActive',
        'equity_market_cap' => 'equityMarketCap',
        'demand_shock' => 'demandShock',
        'total_time' => 'totalTime',
    ];

    /**
     * Captures the statement and parameters the recorder would execute.
     *
     * @param  array<string, mixed>|null       $gapChannels Decomposition to record alongside the vector.
     * @return array{sql: string, params: list<mixed>, types: list<ParameterType|string>}
     */
    private function capture(MacroStateDTO $dto, ?array $gapChannels = null, ?QuarterRecord $quarter = null): array
    {
        $captured = [];

        $connMock = $this->createMock(Connection::class);
        $connMock->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params, array $types) use (&$captured): int {
                $captured = ['sql' => $sql, 'params' => $params, 'types' => $types];

                return 1;
            });

        (new MacroSnapshotRecorder())->recordSnapshot($dto, $connMock, $quarter ?? new QuarterRecord(gapChannels: $gapChannels));

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

        $this->assertCount(
            count($columns),
            $placeholders,
            'The statement names a different number of columns than it binds placeholders.'
        );
        $this->assertSame(['?'], array_values(array_unique($placeholders)), 'Every value must be bound, never inlined.');

        return $columns;
    }

    /**
     * Builds a snapshot in which every field carries a value unique to that field, so a value landing
     * in the wrong column cannot coincidentally match what belongs there.
     *
     * Bools get the one value that is not their default rather than a distinct one, because two of them
     * exist and a bool has no third state. That is weaker than the float sentinels — it catches a column
     * that is never written, not two bools swapped with each other — so the regime flags are also named in
     * ANCHOR_COLUMNS, where the column they must land in is spelled out by hand.
     *
     * @return array{0: MacroStateDTO, 1: array<string, float|bool>} The snapshot and field => sentinel.
     */
    private function sentinelSnapshot(): array
    {
        $state = new MacroState();
        $sentinels = [];

        $offset = 0;
        foreach ((new \ReflectionClass($state))->getProperties() as $property) {
            ++$offset;
            $type = (string) $property->getType();

            if ($type === 'bool') {
                $property->setValue($state, true);
                $sentinels[$property->getName()] = true;

                continue;
            }

            if ($type !== 'float') {
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
     * A parameter bound without a type binds as a string, and PHP renders false as '', which MySQL in
     * strict mode rejects for the TINYINT a bool column is: the ticker died on "Incorrect integer value:
     * '' for column qe_active" on the first quarter recorded with the balance sheet idle.
     */
    public function testRegimeFlagsAreBoundAsBooleansNotStrings(): void
    {
        $dto = MacroStateDTO::fromMacroState(new MacroState());
        $this->assertFalse($dto->qeActive, 'The bind only fails on false, which is the opening value.');

        $captured = $this->capture($dto);
        $byColumn = array_combine($this->columnsOf($captured['sql']), $captured['types']);

        $this->assertSame(Types::BOOLEAN, $byColumn['qe_active']);
        $this->assertSame(Types::BOOLEAN, $byColumn['qt_active']);
        $this->assertSame(ParameterType::STRING, $byColumn['output_gap'], 'Only the bools need a named type.');
    }

    /**
     * Every placeholder has to carry a type, because DBAL falls back to a string bind for the ones that
     * do not — a bool added to the vector later must not reach the driver untyped.
     */
    public function testEveryParameterIsBoundWithTheTypeOfItsValue(): void
    {
        [$dto] = $this->sentinelSnapshot();
        $captured = $this->capture($dto);

        $this->assertCount(count($captured['params']), $captured['types']);
        $this->assertSame(array_keys($captured['params']), array_keys($captured['types']));

        $columns = $this->columnsOf($captured['sql']);
        foreach ($captured['params'] as $index => $value) {
            $expected = match (true) {
                is_bool($value) => Types::BOOLEAN,
                $columns[$index] === 'ticks_per_year' => ParameterType::INTEGER,
                default => ParameterType::STRING,
            };
            $this->assertSame($expected, $captured['types'][$index], "Parameter {$index} is bound with a type its value cannot survive.");
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

    /**
     * Simulated time is what makes a row addressable. Without it the table is a sequence of levels whose
     * only key is a surrogate id that pruning makes non-contiguous, and no episode can be located in it.
     */
    public function testSimulatedTimeIsPersisted(): void
    {
        [$dto, $sentinels] = $this->sentinelSnapshot();
        $captured = $this->capture($dto);

        $byColumn = array_combine($this->columnsOf($captured['sql']), $captured['params']);

        $this->assertArrayHasKey('total_time', $byColumn, 'macro_report must carry the simulated time of the row.');
        $this->assertSame($sentinels['totalTime'], $byColumn['total_time']);
    }

    /**
     * The decomposition rides in the same row as the state it explains, so the two can never fall out of
     * alignment. It is encoded rather than bound as an array, because the column is JSON.
     */
    public function testGapDecompositionIsRecordedAlongsideTheVector(): void
    {
        $dto = MacroStateDTO::fromMacroState(new MacroState());
        $decomposition = [
            'contributions' => ['monetaryDrag' => -0.0041, 'fiscalStimulus' => 0.0018],
            'diffusion' => 0.0009,
            'clamp' => -0.0002,
            'unexplained' => 0.00004,
            'closing_gap' => -0.0312,
        ];

        $captured = $this->capture($dto, $decomposition);
        $byColumn = array_combine($this->columnsOf($captured['sql']), $captured['params']);

        $this->assertArrayHasKey('gap_channels', $byColumn);
        $this->assertSame($decomposition, json_decode((string) $byColumn['gap_channels'], true));
    }

    /**
     * A channel contributes on the order of 1e-5 of potential output over a quarter, which the DECIMAL(10, 4)
     * the rest of the table uses would round to zero. The JSON column exists to keep that resolution, so a
     * value small enough to be the whole point must survive the round trip.
     */
    public function testSmallChannelContributionsSurviveTheRoundTrip(): void
    {
        $dto = MacroStateDTO::fromMacroState(new MacroState());
        $decomposition = ['contributions' => ['catastropheSupplyDrag' => -1.7e-6]];

        $captured = $this->capture($dto, $decomposition);
        $byColumn = array_combine($this->columnsOf($captured['sql']), $captured['params']);

        $restored = json_decode((string) $byColumn['gap_channels'], true);
        $this->assertEqualsWithDelta(-1.7e-6, $restored['contributions']['catastropheSupplyDrag'], 1e-12);
    }

    /**
     * A ticker that starts mid-quarter has no whole quarter to decompose. The statement must still name the
     * column and bind NULL, because it is prepared once per process: a shape that depended on the row would
     * bind the first row's shape to every later one.
     */
    public function testStatementShapeDoesNotDependOnWhetherAQuarterWasDecomposed(): void
    {
        $dto = MacroStateDTO::fromMacroState(new MacroState());

        $without = $this->capture($dto);
        $with = $this->capture($dto, ['contributions' => ['monetaryDrag' => -0.004]]);

        $this->assertSame($without['sql'], $with['sql']);
        $this->assertCount(count($with['params']), $without['params']);

        $byColumn = array_combine($this->columnsOf($without['sql']), $without['params']);
        $this->assertNull($byColumn['gap_channels'], 'An undecomposed quarter must record NULL, never zeros.');
    }

    /** The diagnostics window and the run's identity ride in the same row, typed as the columns are. */
    public function testDiagnosticsAndRunIdentityAreRecordedAlongsideTheVector(): void
    {
        $dto = MacroStateDTO::fromMacroState(new MacroState());
        $diagnostics = ['inflation' => ['average' => 0.021, 'terms' => ['target' => 0.02, 'demandPull' => 0.001]]];

        $captured = $this->capture($dto, quarter: new QuarterRecord(
            gapChannels: ['contributions' => ['monetaryDrag' => -0.004]],
            diagnostics: $diagnostics,
            configFingerprint: 'a1b2c3d4e5f6',
            ticksPerYear: 3600,
        ));
        $columns = $this->columnsOf($captured['sql']);
        $byColumn = array_combine($columns, $captured['params']);
        $typeOf = array_combine($columns, $captured['types']);

        $this->assertSame($diagnostics, json_decode((string) $byColumn['quarter_diagnostics'], true));
        $this->assertSame('a1b2c3d4e5f6', $byColumn['config_fingerprint']);
        $this->assertSame(3600, $byColumn['ticks_per_year']);
        $this->assertSame(ParameterType::INTEGER, $typeOf['ticks_per_year']);
    }

    /** A quarter recorded without a QuarterRecord leaves every recorder-owned column NULL. */
    public function testAVectorAloneLeavesTheRecorderColumnsNull(): void
    {
        $captured = [];
        $connMock = $this->createMock(Connection::class);
        $connMock->expects($this->once())->method('executeStatement')->willReturnCallback(function (string $sql, array $params) use (&$captured): int {
            $captured = ['sql' => $sql, 'params' => $params];

            return 1;
        });

        (new MacroSnapshotRecorder())->recordSnapshot(MacroStateDTO::fromMacroState(new MacroState()), $connMock);
        $byColumn = array_combine($this->columnsOf($captured['sql']), $captured['params']);

        foreach (['gap_channels', 'quarter_diagnostics', 'config_fingerprint', 'ticks_per_year'] as $column) {
            $this->assertNull($byColumn[$column], $column);
        }
    }

    /** The quarter records the Sahm reading the labour market struck, not one recomputed from the recorded unemployment. */
    public function testTheSahmReadingIsRecordedAsTheEngineStruckIt(): void
    {
        $dt = 1.0 / 360.0;
        $state = new MacroState();
        $labour = new LaborMarketSubsystem();
        foreach (array_merge(array_fill(0, 15, 0.040), [0.043, 0.047, 0.052]) as $month => $rate) {
            $state->unemploymentRate = $rate;
            for ($tick = 0; $tick < 30; ++$tick) {
                $state->totalTime = (($month * 30) + $tick + 1) * $dt;
                $labour->recordSahmIndicator($state, $dt);
            }
        }
        $this->assertGreaterThan(0.0, $state->sahmRecessionIndicator);

        $captured = $this->capture(MacroStateDTO::fromMacroState($state));
        $byColumn = array_combine($this->columnsOf($captured['sql']), $captured['params']);

        $this->assertArrayHasKey('sahm_recession_indicator', $byColumn);
        $this->assertSame($state->sahmRecessionIndicator, $byColumn['sahm_recession_indicator']);
    }
}
