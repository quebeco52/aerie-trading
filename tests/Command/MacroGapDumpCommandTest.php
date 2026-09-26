<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Service\Market\HistoryPruner;
use App\Command\MacroGapDumpCommand;
use App\Command\PruneHistoryCommand;
use App\Service\Macro\Recorder\OutputGapProbe;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Guards the file the dump produces, because it is the input to every calibration decision made off a
 * real run. A dump that silently quantises a series, drops a channel or hands back the wrong stretch of
 * history does not fail — it produces a number, and the number is wrong in a way nothing downstream can
 * detect.
 */
class MacroGapDumpCommandTest extends TestCase
{
    /**
     * Runs one raw row through the command's row mapper.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function record(array $row, int $quarter = 1): array
    {
        $command = new MacroGapDumpCommand($this->createMock(EntityManagerInterface::class), '/tmp');

        $method = (new \ReflectionClass($command))->getMethod('toRecord');

        /** @var array<string, mixed> $record */
        $record = $method->invoke($command, $row, $quarter);

        return $record;
    }

    /**
     * The driver returns DECIMAL columns as strings. Left as they are, "0.0241" reads as a number in some
     * consumers and as a string in others, and a comparison on the string form orders -0.03 above 0.02.
     */
    public function testDecimalColumnsAreCastToNumbers(): void
    {
        $record = $this->record([
            'id' => 7,
            'recorded_at' => '2026-09-21 00:00:00',
            'total_time' => '4.750000',
            'output_gap' => '-0.0312',
            'inflation' => '0.0241',
            'gap_channels' => null,
        ]);

        $this->assertSame(4.75, $record['total_time']);
        $this->assertSame(-0.0312, $record['output_gap']);
        $this->assertSame(0.0241, $record['inflation']);
    }

    /**
     * The surrogate key is not a series and pruning makes it non-contiguous, so a reader that indexed on it
     * would read gaps in the run that the economy never had. The dump numbers its own quarters instead.
     */
    public function testSurrogateKeyAndWallClockAreNotSeries(): void
    {
        $record = $this->record(['id' => 7, 'recorded_at' => '2026-09-21 00:00:00', 'output_gap' => '0.01'], 3);

        $this->assertArrayNotHasKey('id', $record);
        $this->assertArrayNotHasKey('recorded_at', $record);
        $this->assertSame(3, $record['quarter']);
    }

    /**
     * The decomposition is decoded rather than passed through as a JSON string, so a reader gets channel
     * names it can address instead of a blob it has to parse a second time.
     */
    public function testDecompositionIsDecodedIntoTheRecord(): void
    {
        $decomposition = [
            'contributions' => ['monetaryDrag' => -0.0041, 'fiscalStimulus' => 0.0018],
            'diffusion' => 0.0009,
            'unexplained' => 0.00004,
        ];

        $record = $this->record(['gap_channels' => json_encode($decomposition)]);

        $this->assertSame($decomposition, $record['gap_channels']);
    }

    /**
     * A quarter the probe could not close records NULL. It must stay NULL: zeros would read as a quarter in
     * which no channel moved the economy, which is a claim the row is not making.
     */
    public function testAnUndecomposedQuarterStaysNull(): void
    {
        $this->assertNull($this->record(['gap_channels' => null])['gap_channels']);
        $this->assertNull($this->record(['gap_channels' => ''])['gap_channels']);
    }

    /** The diagnostics window is decoded like the decomposition, and the run identity keeps its own types. */
    public function testDiagnosticsAreDecodedAndTheRunIdentityKeepsItsTypes(): void
    {
        $diagnostics = ['averages' => ['outputGap' => -0.0123], 'events' => ['energy' => ['count' => 1, 'sum' => 0.2, 'maxAbs' => 0.2]]];

        $record = $this->record([
            'quarter_diagnostics' => json_encode($diagnostics),
            'config_fingerprint' => '00e1b2c3d4f5',
            'ticks_per_year' => '3600',
        ]);

        $this->assertSame($diagnostics, $record['quarter_diagnostics']);
        $this->assertSame('00e1b2c3d4f5', $record['config_fingerprint'], 'A hash with a leading zero is a label, not a number.');
        $this->assertSame(3600, $record['ticks_per_year']);
        $this->assertNull($this->record(['quarter_diagnostics' => null])['quarter_diagnostics']);
    }

    /** A run recalibrated mid-dump splits where its constants changed, and nowhere else. */
    public function testSegmentsSplitWhereTheConstantsChanged(): void
    {
        $this->assertSame(
            [
                ['fingerprint' => null, 'from' => 1, 'to' => 2],
                ['fingerprint' => 'aaa', 'from' => 3, 'to' => 5],
                ['fingerprint' => 'bbb', 'from' => 6, 'to' => 6],
            ],
            MacroGapDumpCommand::segments([null, null, 'aaa', 'aaa', 'aaa', 'bbb'])
        );
        $this->assertCount(1, MacroGapDumpCommand::segments(['aaa', 'aaa']));
    }

    /**
     * Channel contributions are the reason the column is JSON. A value at 1e-6 is a real reading of a channel
     * that barely fired, and DECIMAL(10, 4) would have recorded it as zero.
     */
    public function testChannelResolutionIsNotQuantised(): void
    {
        $record = $this->record([
            'gap_channels' => json_encode(['contributions' => ['catastropheSupplyDrag' => -1.7e-6]]),
        ]);

        $this->assertEqualsWithDelta(-1.7e-6, $record['gap_channels']['contributions']['catastropheSupplyDrag'], 1e-12);
    }

    /**
     * Retention is what the whole change turns on. The cycle runs about six years here, so a horizon has to
     * hold enough busts to say whether their depth distribution moved — the question the table is read for.
     */
    public function testRetentionCoversEnoughEpisodesToMeasureShape(): void
    {
        $years = HistoryPruner::MACRO_QUARTERS_KEPT / 4;

        $this->assertGreaterThanOrEqual(
            20,
            $years,
            'macro_report retention is shorter than the 20 simulated years a run is dumped for.'
        );
    }

    /**
     * macro_report is the only store of closed quarters. A second copy in Redis used to exist and made the
     * panel and the table disagree after every restart, so the probe must not grow one back.
     */
    public function testTheProbeKeepsNoHistoryOfItsOwn(): void
    {
        $constants = (new \ReflectionClass(OutputGapProbe::class))->getConstants();

        $this->assertSame(
            ['REDIS_KEY'],
            array_keys($constants),
            'The probe publishes only the OPEN window; closed quarters belong to macro_report.'
        );
    }
}
