<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Company\RevenueStreams;
use App\Data\Company\Sectors;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Repository\CorporateReportRepository;
use App\Service\Model\BusinessModelInterface;
use App\Service\Model\StreamContext;

/**
 * The company page's "How the business works" map: what outside the firm moved it last quarter, how its operations
 * ran, and the revenue lines and cost of sale that came out, which is where the earnings-flow chart takes over.
 *
 * Every figure is read off the last report. Demand lines are the engine's own measurement, each stream's revenue
 * against what it would have been with one macro input at neutral (CorporateReport::$streamDetails). Cost lines
 * come from the cost side the quarter filed with its KPIs (EarningsEngine::recordCostKpis): the input price gaps it
 * bought at, the input-cost gap not yet passed on, the variable cost ratio and the fixed cost base. Financial firms
 * get the demand side only until their own layouts exist; their cost of sale is not a production cost.
 *
 * The map is three columns of cards and a list of lines between neighbouring columns: [from, to, sign, weight],
 * where sign is +1 helped, -1 hurt, 0 no material effect, and weight sizes the line (an effect on revenue or the
 * margin for a line out of the economy, a share of revenue for a line within the firm).
 */
final class BusinessMapBuilder
{
    // --- Display ---
    /** Outside demand cards shown, strongest first; a model reads more fields, but past eight the column outgrows the rest. */
    public const MAX_DEMAND_CARDS = 8;

    /** Execution reading at or beyond which a stream's operations read as strong, or as running into headwinds (the report's own band). */
    public const EXECUTION_BAND_Z = 1.0;

    /** Names for the input markets of StandardOperatingPhysicsTrait::resolveInputPriceDeviations(). */
    private const INPUT_LABELS = [
        'energy' => 'Fuel and energy',
        'gas' => 'Natural gas',
        'electricity' => 'Electricity',
        'metals' => 'Industrial metals',
        'agri' => 'Farm commodities',
        'freight' => 'Freight rates',
        'ppi' => 'Wholesale goods prices',
        'labor' => 'Pay against productivity',
    ];

    public function __construct(private readonly CorporateReportRepository $reports) {}

    /**
     * @return array{businessMap: ?array<string, mixed>}
     */
    public function build(Stock $stock): array
    {
        if ($stock->isBankrupt()) {
            return ['businessMap' => null];
        }
        $reports = $this->reports->findForStocksNewestFirst([$stock], 2);

        return ['businessMap' => $this->fromReports($stock, $reports[0] ?? null, $reports[1] ?? null)];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fromReports(Stock $stock, ?CorporateReport $report, ?CorporateReport $previous): ?array
    {
        $streams = $report?->getRevenueStreams() ?? [];
        $revenue = (float) $report?->getRevenue();
        if ($report === null || $streams === [] || $revenue <= 0.0) {
            return null;
        }

        $model = Sectors::strategyFor($stock->getIndustry());
        $details = $report->getStreamDetails() ?? [];
        $kpis = $report->getReportedKpis() ?? [];
        $state = $stock->getEarningsMomentumZ() ?? [];

        $map = ['outside' => [], 'inside' => [], 'produced' => [], 'edges' => []];
        $this->addDemandSide($map, $streams, $details, $kpis, $state, $revenue);
        if (!$model->isFinancial()) {
            $this->addCostSide($map, $model, $kpis, $report, $previous, $revenue);
        }

        // On a phone the lines give way to text: each card names what it feeds.
        $labels = [];
        foreach ([...$map['outside'], ...$map['inside'], ...$map['produced']] as $card) {
            if (isset($card['id'])) {
                $labels[$card['id']] = $card['label'];
            }
        }
        foreach (['outside', 'inside', 'produced'] as $column) {
            foreach ($map[$column] as $i => $card) {
                if (isset($card['id'])) {
                    $map[$column][$i]['feeds'] = array_values(array_unique(array_map(
                        static fn (array $edge): string => mb_strtolower($labels[$edge[1]] ?? ''),
                        array_filter($map['edges'], static fn (array $edge): bool => $edge[0] === $card['id'])
                    )));
                }
            }
        }

        // The total the earnings-flow chart opens with: sales plus interest earned on cash (EarningsFlowStatement).
        $total = $revenue + max(0.0, (float) $report->getInterestIncome());
        $previousTotal = $previous !== null ? (float) $previous->getRevenue() + max(0.0, (float) $previous->getInterestIncome()) : 0.0;
        $map['handoff'] = [
            'revenue' => $total,
            'change' => $previousTotal > 0.0 ? $total / $previousTotal - 1.0 : null,
        ];

        return $map;
    }

    /**
     * One outside card per macro input that moved a stream, one operations card and one result card per stream.
     *
     * @param array<string, mixed> $map
     * @param array<string, mixed> $streams
     * @param array<string, mixed> $details
     * @param array<string, mixed> $kpis
     * @param array<string, mixed> $state
     */
    private function addDemandSide(array &$map, array $streams, array $details, array $kpis, array $state, float $revenue): void
    {
        $drivers = [];
        foreach ($streams as $key => $streamRevenue) {
            foreach ($details[$key]['drivers'] ?? [] as $driver) {
                if (($driver['type'] ?? null) !== 'macro' || !is_numeric($driver['impact'] ?? null)) {
                    continue;
                }
                $reading = $driver['readings'][0] ?? null;
                $id = 'macro:' . ($reading['field'] ?? $driver['label']);
                $drivers[$id] ??= ['label' => (string) ($reading['label'] ?? $driver['label']), 'reading' => $reading, 'effects' => []];
                $drivers[$id]['effects'][(string) $key] = (float) $driver['impact'];
            }
        }
        uasort($drivers, static fn (array $a, array $b): int => max(array_map('abs', $b['effects'])) <=> max(array_map('abs', $a['effects'])));

        if ($drivers !== []) {
            $map['outside'][] = ['group' => 'Demand'];
        }
        foreach (array_slice($drivers, 0, self::MAX_DEMAND_CARDS, true) as $id => $driver) {
            $chips = [];
            foreach ($driver['effects'] as $key => $effect) {
                $chips[] = ['text' => 'on ' . mb_strtolower(RevenueStreams::label($key)), 'figure' => $effect, 'format' => 'signed_pct', 'tone' => $effect > 0.0 ? 'up' : 'down'];
                $map['edges'][] = [$id, 'ops:' . $key, $effect <=> 0.0, abs($effect)];
            }
            $reading = $driver['reading'];
            $map['outside'][] = [
                'id' => $id,
                'label' => $driver['label'],
                'value' => $reading !== null ? (float) $reading['value'] : null,
                'format' => $reading !== null ? self::readingFormat((string) $reading['unit']) : 'text',
                'note' => null,
                'chips' => $chips,
            ];
        }

        foreach ($streams as $key => $streamRevenue) {
            $key = (string) $key;
            $detail = $details[$key] ?? [];
            $share = (float) $streamRevenue / $revenue;
            $map['inside'][] = $this->operationsCard($key, $detail, $kpis, $state);
            $map['produced'][] = [
                'id' => 'rev:' . $key,
                'label' => RevenueStreams::label($key),
                'value' => (float) $streamRevenue,
                'format' => 'money',
                'share' => $share,
                'change' => is_numeric($detail['qoq_delta'] ?? null) ? (float) $detail['qoq_delta'] : null,
                'note' => null,
                'chips' => [],
            ];
            $map['edges'][] = ['ops:' . $key, 'rev:' . $key, 0, $share];
        }
    }

    /**
     * How one revenue line ran: its order book where it sells from a backlog, its execution, and any event it took.
     *
     * @param array<string, mixed> $detail
     * @param array<string, mixed> $kpis
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function operationsCard(string $key, array $detail, array $kpis, array $state): array
    {
        $z = null;
        foreach ($detail['drivers'] ?? [] as $driver) {
            if (($driver['type'] ?? null) === 'momentum' && is_numeric($driver['z'] ?? null)) {
                $z = (float) $driver['z'];
            }
        }
        $chips = [];
        if ($z !== null) {
            $chips[] = match (true) {
                $z >= self::EXECUTION_BAND_Z => ['text' => 'Strong execution', 'figure' => null, 'format' => null, 'tone' => 'up'],
                $z <= -self::EXECUTION_BAND_Z => ['text' => 'Operational headwinds', 'figure' => null, 'format' => null, 'tone' => 'down'],
                default => ['text' => $z > 0.0 ? 'Running a little ahead' : 'Running a little behind', 'figure' => null, 'format' => null, 'tone' => 'neutral'],
            };
        }
        if (isset($detail['event'])) {
            $chips[] = ['text' => ucfirst(mb_strtolower((string) $detail['event'])), 'figure' => null, 'format' => null, 'tone' => 'warn'];
        }

        $hasBacklog = isset($state[StreamContext::BACKLOG_STATE_PREFIX . $key]) && is_numeric($kpis['book_to_bill'] ?? null);
        if ($hasBacklog) {
            $bookToBill = (float) $kpis['book_to_bill'];

            return [
                'id' => 'ops:' . $key,
                'label' => 'Order book, ' . mb_strtolower(RevenueStreams::label($key)),
                'value' => $bookToBill,
                'format' => 'multiple',
                'unit' => 'book-to-bill',
                'note' => sprintf(
                    'Orders ran %s%% %s shipments. The backlog holds %s quarters of sales.',
                    number_format(abs($bookToBill - 1.0) * 100.0, 0),
                    $bookToBill >= 1.0 ? 'ahead of' : 'behind',
                    number_format((float) ($kpis['backlog_quarters'] ?? $state[StreamContext::BACKLOG_STATE_PREFIX . $key]), 1)
                ),
                'chips' => $chips,
            ];
        }

        return [
            'id' => 'ops:' . $key,
            'label' => RevenueStreams::label($key),
            'value' => null,
            'format' => 'text',
            'unit' => null,
            'note' => null,
            'chips' => $chips,
        ];
    }

    /**
     * The input markets the firm buys in, the share of their moves it passes on, its fixed costs, and the cost of sale
     * and operating margin they come to. Skipped when the report predates the filed cost side.
     *
     * @param array<string, mixed> $map
     * @param array<string, mixed> $kpis
     */
    private function addCostSide(array &$map, BusinessModelInterface $model, array $kpis, CorporateReport $report, ?CorporateReport $previous, float $revenue): void
    {
        if (!is_numeric($kpis['variable_cost_ratio'] ?? null)) {
            return;
        }
        $costRatio = (float) $kpis['variable_cost_ratio'];
        $exposures = method_exists($model, 'getInputCostExposures') ? $model->getInputCostExposures() : [];
        $hasRecovery = is_numeric($kpis['input_cost_level'] ?? null);
        $drag = (float) ($kpis['input_cost_drag'] ?? 0.0);

        $inputs = [];
        foreach (self::INPUT_LABELS as $channel => $label) {
            $gap = $kpis['input_gap_' . $channel] ?? null;
            $exposure = (float) ($exposures[$channel] ?? 0.0);
            if (is_numeric($gap) && $exposure > 0.0) {
                $inputs[] = [$channel, $label, (float) $gap, $exposure];
            }
        }
        if ($inputs !== [] && $hasRecovery) {
            $map['outside'][] = ['group' => 'Input markets'];
            foreach ($inputs as [$channel, $label, $gap, $exposure]) {
                $map['outside'][] = [
                    'id' => 'input:' . $channel,
                    'label' => $label,
                    'value' => $gap,
                    'format' => 'signed_pct',
                    'unit' => 'against normal',
                    'note' => sprintf('%s%% of production costs.', number_format($exposure * 100.0, 1)),
                    'chips' => [$gap > 0.0
                        ? ['text' => 'Costs up', 'figure' => null, 'format' => null, 'tone' => 'down']
                        : ['text' => 'Costs down', 'figure' => null, 'format' => null, 'tone' => 'up']],
                ];
                // Sized at the full move on the cost ratio, before the buying lag and what is passed on.
                $map['edges'][] = ['input:' . $channel, 'recover', -($gap <=> 0.0), $exposure * abs($gap) * $costRatio];
            }
        }

        if ($hasRecovery) {
            $level = (float) $kpis['input_cost_level'];
            $recovery = (float) ($kpis['input_cost_recovery'] ?? 0.0);
            $map['inside'][] = [
                'id' => 'recover',
                'label' => 'Passing on costs',
                'value' => is_numeric($kpis['input_cost_recovered_share'] ?? null) ? (float) $kpis['input_cost_recovered_share'] : null,
                'format' => 'pct',
                'unit' => 'recovered in prices',
                'note' => sprintf(
                    'Input costs run %s%% %s normal; prices have caught up %s%%.',
                    number_format(abs($level) * 100.0, 1),
                    $level >= 0.0 ? 'above' : 'below',
                    number_format(abs($recovery) * 100.0, 1)
                ),
                'chips' => [['text' => 'on margin', 'figure' => -$drag * 100.0, 'format' => 'signed_points', 'tone' => $drag > 0.0 ? 'down' : 'up']],
            ];
            $map['edges'][] = ['recover', 'cost', -($drag <=> 0.0), abs($drag)];
        }

        $fixedCosts = (float) ($kpis['fixed_costs'] ?? 0.0);
        $variableCosts = $costRatio * $revenue;
        if ($fixedCosts > 0.0) {
            $map['inside'][] = [
                'id' => 'structure',
                'label' => 'Cost structure',
                'value' => $fixedCosts / ($fixedCosts + $variableCosts),
                'format' => 'pct',
                'unit' => 'of costs fixed',
                'note' => 'Plant, staff and overhead hold still when sales move, so a change in sales moves the margin faster.',
                'chips' => [],
            ];
        }

        $map['produced'][] = [
            'id' => 'cost',
            'label' => 'Cost of each sale',
            'value' => $costRatio,
            'format' => 'pct',
            'unit' => 'of revenue',
            'note' => $hasRecovery ? sprintf('Including %s pt of input costs not yet passed on.', number_format($drag * 100.0, 1)) : null,
            'chips' => [],
        ];

        $margin = $report->getOperatingMargin();
        if ($margin !== null) {
            $previousMargin = $previous?->getOperatingMargin();
            $change = $previousMargin !== null ? (float) $margin - (float) $previousMargin : null;
            $map['produced'][] = [
                'id' => 'margin',
                'label' => 'Operating margin',
                'value' => (float) $margin,
                'format' => 'pct',
                'unit' => null,
                'note' => $previousMargin !== null ? sprintf('%s%% last quarter.', number_format((float) $previousMargin * 100.0, 1)) : null,
                'chips' => $change !== null ? [['text' => 'on the quarter', 'figure' => $change * 100.0, 'format' => 'signed_points', 'tone' => $change >= 0.0 ? 'up' : 'down']] : [],
            ];
            $map['edges'][] = ['cost', 'margin', 0, $costRatio];
            if ($fixedCosts > 0.0) {
                $map['edges'][] = ['structure', 'margin', 0, $fixedCosts / $revenue];
            }
        }
    }

    /** The template format for a MacroFieldCatalog reading unit. */
    private static function readingFormat(string $unit): string
    {
        return match ($unit) {
            'pct' => 'pct',
            'bps' => 'bps',
            'level' => 'money',
            default => 'index',
        };
    }
}
