<?php

declare(strict_types=1);

namespace App\Service\Market\Ticker;

use App\DTO\BoardReadingDTO;
use App\DTO\GovernmentPolicyDTO;
use App\Entity\Bond;
use App\Entity\Etf;
use App\Entity\Stock;
use App\Service\Market\Chart\WireFrame;
use App\Service\Market\Option\OptionDeskService;

/**
 * One run of the market ticker as MarketTickerCommand steps it: what carries from one tick to the next (the clock,
 * the working set, and the board and the laws the economy reads one tick behind), the cadences that gate the
 * periodic work, and the run's own buffers and profiler.
 */
final class TickerRun
{
    /** Length of one tick in simulated years. */
    public readonly float $dt;

    // --- Cadences, in ticks ---
    /** The operator's failure sweep, twice a simulated month. */
    public readonly int $operatorInterval;
    /** Portfolio snapshots, the cash sweep, the margin sweep and the season's week, once a simulated week. */
    public readonly int $snapshotInterval;
    /** The quarter's macro report. */
    public readonly int $quarterlyInterval;
    /** One staggered slice of the option chain. */
    public readonly int $optionSweepInterval;

    // --- The working set, held in RAM between ticks and reloaded after every EntityManager clear ---
    /** @var array<int, Stock> */
    public array $stocks = [];
    /** @var array<string, Etf> The fund behind each published index, keyed by ticker. */
    public array $indexFunds = [];
    /** @var array<int, Bond> The active bond ladder. */
    public array $bonds = [];

    /** What the economy reads of the board, one tick behind. */
    public BoardReadingDTO $board;

    public readonly StockBarBook $bars;
    public readonly TickProfiler $profiler;
    public readonly WireFrame $wireFrame;

    public function __construct(
        /** The loop position: what every cadence is keyed on. */
        public int $tickCount,
        /** The economic clock: what dated output, the option grid and every retention cutoff are measured against. */
        public float $simTime,
        int $ticksPerYear,
        /** What the government hands the economy, on the board's lag: the levers in force and the election pulse. */
        public GovernmentPolicyDTO $policy,
        public readonly ChartBufferWriter $chartBuffers,
    ) {
        $this->dt = 1.0 / $ticksPerYear;
        $this->operatorInterval = (int) max(1, $ticksPerYear / 24);
        $this->snapshotInterval = (int) max(1, $ticksPerYear / 52);
        $this->quarterlyInterval = (int) max(1, $ticksPerYear / 4);
        $this->optionSweepInterval = OptionDeskService::sweepIntervalTicks($ticksPerYear);
        $this->board = new BoardReadingDTO();
        $this->bars = new StockBarBook();
        $this->profiler = new TickProfiler();
        $this->wireFrame = new WireFrame();
    }
}
