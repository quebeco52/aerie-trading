<?php

declare(strict_types=1);

namespace App\Service\Market\Pricing;

use App\DTO\MacroStateDTO;

/**
 * One tick of the board as StockTracker steps it: what every name reads the same (the economy, the step, the order
 * flow drained once for the whole book) and what the names add up to, which the ticker hands the macro, the funds and
 * the wire.
 */
final class BoardTick
{
    /** @var list<array<string, mixed>> Each name's display row, in board order. */
    public array $updates = [];
    /** @var list<array{stock_id: int|null, price: float, ticker: string}> */
    public array $history = [];
    /** @var list<mixed> What the tick published: deals, shocks, reports, warnings, splits, revisions. */
    public array $events = [];
    public float $totalCap = 0.0;
    /** @var array<string, float> What a passive fund could buy of each name, which an index weights on. */
    public array $floatCaps = [];
    /** @var array<string, float> What it costs to get in and out of each name, per ticker. */
    public array $halfSpreads = [];
    /** @var array<string, float> Float-adjusted dividend cash per ticker, for the index points. */
    public array $dividendPoints = [];

    // --- The board as the fund reads it next tick ---
    /** Float cap at the start of the tick of the names the price return is weighted over. */
    public float $floatCapAtStart = 0.0;
    /** Float-weighted price gain over the tick, measured before any split. */
    public float $priceGain = 0.0;
    /** The companies' own issuance net of buybacks, as float at this tick's price, which an index holder takes up. */
    public float $netIssuance = 0.0;
    /** What the board traded this tick, which the District's stamp duty is charged on, buyer and seller each. */
    public float $tradedValue = 0.0;
    /** What the board's banks owe a year in bank levy at the rate in force, which the budget books as revenue. */
    public float $bankLevy = 0.0;
    /** Annual property and casualty premium the board's underwriters write, which their rate levels are weighted by. */
    public float $insurancePremium = 0.0;
    /** The same premium, each underwriter's at the rate level it earns. */
    public float $insuranceRatedPremium = 0.0;
    /**
     * Cash the District's strategic stakes pay it: dividends, and its share of buybacks less its share of issues, since
     * it keeps its percentage by tendering and subscribing pro rata. Off the float; paid into the fund.
     */
    public float $strategicStakeCash = 0.0;

    /**
     * @param array<string, float> $netOrderFlow     Everything that traded since the last tick, netted per ticker.
     * @param array<string, float> $passiveOwnership Assets tracking each index, per constituent; empty without an
     *                                                index.
     */
    public function __construct(
        public readonly MacroStateDTO $macro,
        public readonly float $dt,
        public readonly int $tickCount,
        public readonly int $ticksPerYear,
        /** Market vol of the last tick priced, at which every stored variance was built; null before the first. */
        public readonly ?float $priorMarketVol,
        public readonly array $netOrderFlow,
        public readonly array $passiveOwnership,
    ) {}
}
