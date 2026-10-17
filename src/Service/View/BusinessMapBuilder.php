<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Company\RevenueStreams;
use App\Data\Company\Sectors;
use App\Data\Macro\MacroFieldCatalog;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Repository\CorporateReportRepository;
use App\Service\Event\ShockEvent;
use App\Service\Model\BusinessModelInterface;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\HealthInsurerBusinessModel;
use App\Service\Model\Sector\InsuranceBusinessModel;
use App\Service\Model\Sector\ReitBusinessModel;
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
    /**
     * Outside demand cards shown, strongest first; a model reads more fields, but past eight the column outgrows the
     * rest.
     */
    public const MAX_DEMAND_CARDS = 8;

    /**
     * Execution reading at or beyond which a stream's operations read as strong, or as running into headwinds (the
     * report's own band).
     */
    public const EXECUTION_BAND_Z = 1.0;

    /** Unrecovered input-cost drag below which the cost note stays silent: 0.05 pt, under the one decimal it prints. */
    public const DRAG_NOTE_FLOOR = 0.0005;

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
        'imports' => 'Imported parts and goods',
    ];

    /** A lender's events by the card they concern (ShockEvent names); any other event goes under the map's title. */
    private const LENDER_EVENT_CARDS = [
        ShockEvent::RESERVE_RELEASE => 'reserve',
        ShockEvent::MASSIVE_CREDIT_PROVISION => 'reserve',
        ShockEvent::ELEVATED_LOAN_DEFAULTS => 'reserve',
        ShockEvent::CREDIT_UNSECURED_PROVISIONS => 'reserve',
        ShockEvent::BANK_RUN => 'funding',
        ShockEvent::CUSTOMER_DEPOSIT_FLIGHT => 'funding',
        ShockEvent::CAPTURED_NEW_DEPOSITS => 'funding',
        ShockEvent::CAPITAL_BELOW_MINIMUM => 'capital',
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
        $details = array_map(
            static fn(array $detail): array => ['drivers' => MacroFieldCatalog::relabelDrivers($detail['drivers'] ?? [])] + $detail,
            $report->getStreamDetails() ?? [],
        );
        $kpis = $report->getReportedKpis() ?? [];
        $state = $stock->getEarningsMomentumZ() ?? [];

        // The total the earnings-flow chart opens with: sales plus interest earned on cash (EarningsFlowStatement).
        // Every share on the map is of this total, so the cards add up to the figure the hand-off names.
        $interest = max(0.0, (float) $report->getInterestIncome());
        $total = $revenue + $interest;

        $map = ['outside' => [], 'inside' => [], 'produced' => [], 'edges' => []];
        $this->addDemandSide($map, $streams, $details, $kpis, $state, $total);
        if (!$model->isFinancial()) {
            $this->addCostSide($map, $model, $kpis, $report, $previous, $revenue);
        }
        // The businesses whose inside is a structurally different machine get its cards: a lender's book and
        // funding, an underwriter's capacity cycle and float, a landlord's lease ladder, a health plan's pricing.
        match (true) {
            $model instanceof CommercialBankBusinessModel => $this->addLenderSide($map, $streams, $state, $report, $previous),
            $model instanceof InsuranceBusinessModel => $this->addInsurerSide($map, $streams, $kpis, $state, $report, $previous),
            $model instanceof ReitBusinessModel => $this->addLeaseLadder($map, $streams, $kpis),
            $model instanceof HealthInsurerBusinessModel => $this->addHealthPlanPricing($map, $state),
            default => null,
        };
        $this->addOtherInterest($map, $interest, $total, $previous);
        $this->placeEvent($map, $details, $model);
        self::orderAgainstMiddle($map);

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
                        static fn(array $edge): string => mb_strtolower($labels[$edge[1]] ?? ''),
                        array_filter($map['edges'], static fn(array $edge): bool => $edge[0] === $card['id']),
                    )));
                }
            }
        }

        $previousTotal = $previous !== null ? (float) $previous->getRevenue() + max(0.0, (float) $previous->getInterestIncome()) : 0.0;
        $map['handoff'] = [
            'revenue' => $total,
            'change' => $previousTotal > 0.0 ? $total / $previousTotal - 1.0 : null,
        ];

        return $map;
    }

    /**
     * One outside card per macro input that moved a stream, one operations card and one result card per stream, its
     * share taken of the hand-off total.
     *
     * @param array<string, mixed> $map
     * @param array<string, mixed> $streams
     * @param array<string, mixed> $details
     * @param array<string, mixed> $kpis
     * @param array<string, mixed> $state
     */
    private function addDemandSide(array &$map, array $streams, array $details, array $kpis, array $state, float $total): void
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
        uasort($drivers, static fn(array $a, array $b): int => max(array_map('abs', $b['effects'])) <=> max(array_map('abs', $a['effects'])));

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
            [$label, $value] = $reading !== null && isset($reading['field'])
                ? MacroFieldCatalog::signedReading((string) $reading['field'], (float) $reading['value'])
                : [$driver['label'], $reading !== null ? (float) $reading['value'] : null];
            $format = $reading !== null ? self::readingFormat((string) $reading['unit']) : 'text';
            $map['outside'][] = [
                'id' => $id,
                'label' => $label,
                'value' => $value,
                'format' => $format,
                // A bare index level means nothing without its name; a label that carries its own unit says it already.
                'unit' => $format === 'index' && !str_contains($label, '(') ? 'index' : null,
                'note' => null,
                'chips' => $chips,
            ];
        }

        foreach ($streams as $key => $streamRevenue) {
            $key = (string) $key;
            $detail = $details[$key] ?? [];
            $share = (float) $streamRevenue / $total;
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
     * How one revenue line ran: its order book where it sells from a backlog, and its execution.
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
                    number_format((float) ($kpis['backlog_quarters'] ?? $state[StreamContext::BACKLOG_STATE_PREFIX . $key]), 1),
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
                $measured = $kpis['input_drag_' . $channel] ?? null;
                $map['outside'][] = [
                    'id' => 'input:' . $channel,
                    'label' => $label,
                    'value' => $gap,
                    'format' => 'signed_pct',
                    'unit' => 'against normal',
                    'note' => sprintf('%s%% of production costs.', number_format($exposure * 100.0, 1)),
                    'chips' => [is_numeric($measured) ? self::dragChip((float) $measured) : self::chip($gap > 0.0 ? 'Costs up' : 'Costs down', $gap > 0.0 ? 'down' : 'up')],
                ];
                // The input's own part of the quarter's drag, lags and what is passed on included; a report filed
                // before the split is sized at the full move on the cost ratio instead.
                $map['edges'][] = is_numeric($measured)
                    ? self::dragEdge('input:' . $channel, (float) $measured)
                    : ['input:' . $channel, 'recover', -($gap <=> 0.0), $exposure * abs($gap) * $costRatio];
            }
            $earlier = $kpis['input_drag_earlier'] ?? null;
            if (is_numeric($earlier) && abs((float) $earlier) >= self::DRAG_NOTE_FLOOR) {
                $map['outside'][] = [
                    'id' => 'input:earlier',
                    'label' => 'Earlier input price moves',
                    'value' => null,
                    'format' => 'text',
                    'unit' => null,
                    'note' => 'Cost changes from past quarters still working through.',
                    'chips' => [self::dragChip((float) $earlier)],
                ];
                $map['edges'][] = self::dragEdge('input:earlier', (float) $earlier);
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
                    number_format(abs($recovery) * 100.0, 1),
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
            'note' => $hasRecovery && abs($drag) >= self::DRAG_NOTE_FLOOR
                ? sprintf($drag > 0.0 ? 'Including %s pt of input costs not yet passed on.' : 'Helped by %s pt of input-cost savings not yet passed on to customers.', number_format(abs($drag) * 100.0, 1))
                : null,
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

    /**
     * A lender: the book its interest stream earns on, the deposits and borrowing that fund it, the reserve held
     * against it and the capital under it; the interest paid, credit losses and net interest margin that come out.
     *
     * @param array<string, mixed> $map
     * @param array<string, mixed> $streams
     * @param array<string, mixed> $state
     */
    private function addLenderSide(array &$map, array $streams, array $state, CorporateReport $report, ?CorporateReport $previous): void
    {
        $interestStream = null;
        foreach (RevenueStreams::LENDER_INTEREST_STREAMS as $key) {
            if (isset($streams[$key])) {
                $interestStream = $key;
                break;
            }
        }
        $book = (float) $report->getEarningAssets();
        if ($interestStream === null || $book <= 0.0) {
            return;
        }

        $yields = array_filter([
            'Mortgages' => $state[CommercialBankBusinessModel::STATE_BOOK_YIELD_RESIDENTIAL] ?? null,
            'fixed-rate loans' => $state[CommercialBankBusinessModel::STATE_BOOK_YIELD_FIXED] ?? null,
            'bonds' => $state[CommercialBankBusinessModel::STATE_BOOK_YIELD_SECURITIES] ?? null,
        ], 'is_numeric');
        $note = $yields === [] ? null : implode(', ', array_map(
            static fn(string $label, $yield): string => sprintf('%s %s%%', $label, number_format((float) $yield * 100.0, 2)),
            array_keys($yields),
            $yields,
        )) . ' on the book.';
        if (is_numeric($state[CommercialBankBusinessModel::STATE_FLOATING_LOAN_SHARE] ?? null)) {
            $note .= sprintf(' %s%% of loans float with the policy rate.', number_format((float) $state[CommercialBankBusinessModel::STATE_FLOATING_LOAN_SHARE] * 100.0, 0));
        }
        $this->replaceCard($map['inside'], 'ops:' . $interestStream, [
            'label' => 'Loans and securities', 'value' => $book, 'format' => 'money', 'unit' => null, 'note' => $note === null ? null : ucfirst(ltrim($note)),
        ]);

        $deposits = (float) $report->getCustomerDeposits();
        $depositRate = $report->getDepositApy();
        $map['inside'][] = [
            'id' => 'funding',
            'label' => $deposits > 0.0 ? 'Deposits and borrowing' : 'Borrowing',
            'value' => $deposits > 0.0 ? $deposits : null,
            'format' => 'money',
            'unit' => $deposits > 0.0 ? 'in deposits' : null,
            'note' => $depositRate !== null ? sprintf('Deposits pay %s%%.', number_format((float) $depositRate * 100.0, 2)) : 'Funded in the wholesale market.',
            'chips' => [],
        ];
        $allowance = (float) $report->getCreditLossAllowance();
        $map['inside'][] = [
            'id' => 'reserve',
            'label' => 'Loss reserve',
            'value' => $allowance,
            'format' => 'money',
            'unit' => null,
            'note' => sprintf('%s%% of the book. %s written off this quarter.', number_format($allowance / $book * 100.0, 2), self::large((float) $report->getNetChargeOffs())),
            'chips' => [],
        ];
        $cet1 = $report->getCet1Ratio();
        $mark = (float) $report->getUnrealizedSecuritiesMark();
        if ($cet1 !== null) {
            $map['inside'][] = [
                'id' => 'capital',
                'label' => 'Capital',
                'value' => (float) $cet1,
                'format' => 'pct',
                'unit' => 'CET1',
                'note' => 'Common equity against risk-weighted assets.',
                'chips' => $mark !== 0.0 ? [self::chip(sprintf('%s bond book %s', self::large(abs($mark)), $mark < 0.0 ? 'unrealised loss' : 'unrealised gain'), $mark < 0.0 ? 'down' : 'up')] : [],
            ];
        }

        $map['produced'][] = ['id' => 'paid', 'label' => 'Interest paid', 'value' => (float) $report->getInterestExpense(), 'format' => 'money', 'unit' => null, 'note' => 'On deposits and borrowing.', 'chips' => []];
        $map['produced'][] = ['id' => 'losses', 'label' => 'Credit losses', 'value' => (float) $report->getCreditLossProvision(), 'format' => 'money', 'unit' => null, 'note' => 'Set aside this quarter.', 'chips' => []];
        $map['edges'][] = ['funding', 'paid', 0, 0.3];
        $map['edges'][] = ['reserve', 'losses', 0, 0.1];

        $margin = $report->getNetInterestMargin();
        if ($margin !== null) {
            $previousMargin = $previous?->getNetInterestMargin();
            $map['produced'][] = [
                'id' => 'nim',
                'label' => 'Net interest margin',
                'value' => (float) $margin,
                'format' => 'pct',
                'unit' => null,
                'note' => 'Interest earned less interest paid, on the book.',
                'chips' => $previousMargin !== null ? [self::pointsChip(((float) $margin - (float) $previousMargin) * 100.0, 'on the quarter')] : [],
            ];
            $map['edges'][] = ['ops:' . $interestStream, 'nim', 0, 0.3];
            $map['edges'][] = ['funding', 'nim', 0, 0.3];
        }

        // Funding sits beside the book it funds; orderAgainstMiddle then lines up what they produce.
        self::moveAfter($map['inside'], ['funding'], 'ops:' . $interestStream);
    }

    /**
     * An underwriter: the capital it can write against the business on offer, where the pricing cycle stands, and the
     * float it invests until claims are paid; the combined ratio and investment income that come out.
     *
     * @param array<string, mixed> $map
     * @param array<string, mixed> $streams
     * @param array<string, mixed> $kpis
     * @param array<string, mixed> $state
     */
    private function addInsurerSide(array &$map, array $streams, array $kpis, array $state, CorporateReport $report, ?CorporateReport $previous): void
    {
        $observed = $state[InsuranceBusinessModel::STATE_OBSERVED_CAPACITY] ?? null;
        if (is_numeric($observed)) {
            $written = $state[InsuranceBusinessModel::STATE_WRITTEN_SHARE] ?? null;
            $map['inside'][] = [
                'id' => 'capacity',
                'label' => 'Underwriting capacity',
                'value' => (float) $observed,
                'format' => 'multiple',
                'unit' => 'capital against demand',
                'note' => 'Above 1 the market has more capital than business and rates soften; below 1 they harden.'
                    . (is_numeric($written) ? sprintf(' Writes %s%% of the business on offer.', number_format((float) $written * 100.0, 0)) : ''),
                'chips' => [],
            ];
            foreach (array_keys($streams) as $key) {
                $map['edges'][] = ['capacity', 'rev:' . $key, 0, 0.2];
            }
        }

        $hardQuarters = (int) round((float) ($state[StreamContext::REGIME_STATE_PREFIX . InsuranceBusinessModel::REGIME_HARD_MARKET] ?? 0.0));
        $map['inside'][] = [
            'id' => 'cycle',
            'label' => 'Pricing cycle',
            'value' => null,
            'format' => 'text',
            'unit' => null,
            'note' => $hardQuarters > 0
                ? sprintf('A hard market, %d %s in: after heavy losses, rates rise while capital rebuilds.', $hardQuarters, $hardQuarters === 1 ? 'quarter' : 'quarters')
                : 'A soft market: rates are set by competition for business.',
            'chips' => [$hardQuarters > 0 ? self::chip('Hard market', 'up') : self::chip('Soft market', 'neutral')],
        ];

        $float = (float) $report->getCustomerDeposits();
        if ($float > 0.0) {
            $map['inside'][] = ['id' => 'float', 'label' => 'Float', 'value' => $float, 'format' => 'money', 'unit' => null, 'note' => 'Premiums held as reserves and invested until claims are paid.', 'chips' => []];
            $map['produced'][] = ['id' => 'investment', 'label' => 'Investment income', 'value' => (float) $report->getInterestIncome(), 'format' => 'money', 'unit' => null, 'note' => 'Earned on the float this quarter.', 'chips' => []];
            $map['edges'][] = ['float', 'investment', 0, 0.2];
        }

        if (is_numeric($kpis['loss_ratio'] ?? null) && is_numeric($kpis['expense_ratio'] ?? null)) {
            $combined = (float) $kpis['loss_ratio'] + (float) $kpis['expense_ratio'];
            $before = $previous?->getReportedKpis() ?? [];
            $previousCombined = is_numeric($before['loss_ratio'] ?? null) && is_numeric($before['expense_ratio'] ?? null)
                ? (float) $before['loss_ratio'] + (float) $before['expense_ratio'] : null;
            $map['produced'][] = [
                'id' => 'combined',
                'label' => 'Combined ratio',
                'value' => $combined,
                'format' => 'pct',
                'unit' => 'of premiums',
                'note' => sprintf('Claims %s%% and expenses %s%%. Under 100%% the book makes an underwriting profit.', number_format((float) $kpis['loss_ratio'] * 100.0, 1), number_format((float) $kpis['expense_ratio'] * 100.0, 1)),
                // A lower ratio is the better quarter, so a fall carries the up tone.
                'chips' => $previousCombined !== null ? [self::pointsChip(($combined - $previousCombined) * 100.0, 'on the quarter', invert: true)] : [],
            ];
            $map['edges'][] = ['cycle', 'combined', 0, 0.2];
        }
    }

    /**
     * A landlord: the lease ladder its rent stream earns on, how long it is contracted for and what expiring space
     * re-lets at against what it earns now.
     *
     * @param array<string, mixed> $map
     * @param array<string, mixed> $streams
     * @param array<string, mixed> $kpis
     */
    private function addLeaseLadder(array &$map, array $streams, array $kpis): void
    {
        if (!isset($streams['lease']) || !is_numeric($kpis['walt_years'] ?? null)) {
            return;
        }
        $spread = (float) ($kpis['releasing_spread'] ?? 0.0);
        $inPlace = (float) ($kpis['in_place_rent_index'] ?? 0.0);
        $this->replaceCard($map['inside'], 'ops:lease', [
            'label' => 'Lease book',
            'value' => (float) $kpis['walt_years'],
            'format' => 'index',
            'unit' => 'years left on the average lease',
            'note' => sprintf(
                'Rents in place run %s%% %s normal. Expiring space re-lets %s%% %s what it paid.',
                number_format(abs($inPlace) * 100.0, 1),
                $inPlace >= 0.0 ? 'above' : 'below',
                number_format(abs($spread) * 100.0, 1),
                $spread >= 0.0 ? 'above' : 'below',
            ),
        ], [self::chip(sprintf('Re-leasing %s', $spread >= 0.0 ? 'up' : 'down'), $spread >= 0.0 ? 'up' : 'down')]);
    }

    /**
     * A health plan: premiums are priced once a year on a medical cost trend, so the gap between the costs it priced
     * and the costs it is paying runs straight into the cost of each sale until the book reprices.
     *
     * @param array<string, mixed> $map
     * @param array<string, mixed> $state
     */
    private function addHealthPlanPricing(array &$map, array $state): void
    {
        $trend = $state[HealthInsurerBusinessModel::STATE_PRICED_TREND] ?? null;
        $gap = $state[HealthInsurerBusinessModel::STATE_LOSS_RATIO_GAP] ?? null;
        if (!is_numeric($trend) || !is_numeric($gap)) {
            return;
        }
        $excess = exp((float) $gap) - 1.0;
        $map['inside'][] = [
            'id' => 'pricing',
            'label' => 'Premium pricing',
            'value' => (float) $trend,
            'format' => 'pct',
            'unit' => 'medical cost trend priced in',
            'note' => sprintf('Premiums reprice once a year. Medical costs run %s%% %s what the book was priced for.', number_format(abs($excess) * 100.0, 1), $excess >= 0.0 ? 'above' : 'below'),
            'chips' => [],
        ];
        if ($this->find($map['produced'], 'cost')) {
            $map['edges'][] = ['pricing', 'cost', -($excess <=> 0.0), abs($excess)];
        }
    }

    /**
     * Interest earned on cash, which the hand-off total counts beside sales: its own card, so the shares add up to that
     * total. An underwriter's investment income is this same line and takes the share instead.
     *
     * @param array<string, mixed> $map
     */
    private function addOtherInterest(array &$map, float $interest, float $total, ?CorporateReport $previous): void
    {
        if ($interest <= 0.0) {
            return;
        }
        $before = max(0.0, (float) $previous?->getInterestIncome());
        $line = ['share' => $interest / $total, 'change' => $before > 0.0 ? $interest / $before - 1.0 : null];
        foreach ($map['produced'] as $i => $card) {
            if (($card['id'] ?? null) === 'investment') {
                $map['produced'][$i] = $line + $card;

                return;
            }
        }
        $last = 0;
        foreach ($map['produced'] as $i => $card) {
            if (str_starts_with((string) ($card['id'] ?? ''), 'rev:')) {
                $last = $i + 1;
            }
        }
        array_splice($map['produced'], $last, 0, [$line + [
            'id' => 'other_interest',
            'label' => 'Other interest income',
            'value' => $interest,
            'format' => 'money',
            'unit' => null,
            'note' => 'On cash and holdings outside the main business.',
            'chips' => [],
        ]]);
    }

    /**
     * The quarter's event, once. The engine files a firm's event on every revenue line, but it is one event: a lender's
     * credit, funding or capital event goes on the card it concerns, anything else under the map's title.
     *
     * @param array<string, mixed> $map
     * @param array<string, mixed> $details
     */
    private function placeEvent(array &$map, array $details, BusinessModelInterface $model): void
    {
        $event = null;
        foreach ($details as $detail) {
            if (is_string($detail['event'] ?? null) && $detail['event'] !== '') {
                $event = $detail['event'];
                break;
            }
        }
        if ($event === null) {
            return;
        }
        $chip = self::chip(ucfirst(mb_strtolower($event)), 'warn');
        $target = $model instanceof CommercialBankBusinessModel
            ? self::LENDER_EVENT_CARDS[str_replace(' ', '_', mb_strtolower($event))] ?? null
            : null;
        foreach ($map['inside'] as $i => $card) {
            if ($target !== null && ($card['id'] ?? null) === $target) {
                $map['inside'][$i]['chips'][] = $chip;

                return;
            }
        }
        $map['event'] = $chip;
    }

    /**
     * Orders the outer columns against the middle one by the barycentre heuristic for layered graphs (Sugiyama, Tagawa
     * and Toda 1981): each card moves to the mean position of the middle cards it is linked to, so lines run across
     * rather than crossing. A card with no link keeps its own position; cards stay under their group heading.
     *
     * @param array<string, mixed> $map
     */
    private static function orderAgainstMiddle(array &$map): void
    {
        $middle = self::positions($map['inside']);
        foreach (['outside' => [0, 1], 'produced' => [1, 0]] as $column => [$own, $far]) {
            $positions = self::positions($map[$column]);
            $centre = [];
            foreach ($positions as $id => $position) {
                $linked = [];
                foreach ($map['edges'] as $edge) {
                    if ($edge[$own] === $id && isset($middle[$edge[$far]])) {
                        $linked[] = $middle[$edge[$far]];
                    }
                }
                $centre[$id] = $linked === [] ? $position : array_sum($linked) / count($linked);
            }

            $ordered = [];
            $group = [];
            $flush = static function () use (&$ordered, &$group, $centre): void {
                usort($group, static fn(array $a, array $b): int => $centre[$a['id']] <=> $centre[$b['id']]);
                array_push($ordered, ...$group);
                $group = [];
            };
            foreach ($map[$column] as $card) {
                if (isset($card['id'])) {
                    $group[] = $card;
                    continue;
                }
                $flush();
                $ordered[] = $card;
            }
            $flush();
            $map[$column] = $ordered;
        }
    }

    /**
     * Each card's place down its column, from 0 at the top to 1 at the bottom.
     *
     * @param list<array<string, mixed>> $cards
     * @return array<string, float>
     */
    private static function positions(array $cards): array
    {
        $ids = array_values(array_filter(array_map(static fn(array $card): ?string => $card['id'] ?? null, $cards)));
        $positions = [];
        foreach ($ids as $i => $id) {
            $positions[$id] = ($i + 0.5) / count($ids);
        }

        return $positions;
    }

    /**
     * Swaps a generic operations card for a business-specific one, keeping its id (the demand lines point at it) and
     * its execution and event tags.
     *
     * @param list<array<string, mixed>> $cards
     * @param array<string, mixed> $replacement
     * @param list<array<string, mixed>> $leadingChips
     */
    private function replaceCard(array &$cards, string $id, array $replacement, array $leadingChips = []): void
    {
        foreach ($cards as $i => $card) {
            if (($card['id'] ?? null) === $id) {
                $cards[$i] = ['id' => $id, 'chips' => [...$leadingChips, ...($card['chips'] ?? [])]] + $replacement;

                return;
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $cards
     */
    private function find(array $cards, string $id): bool
    {
        foreach ($cards as $card) {
            if (($card['id'] ?? null) === $id) {
                return true;
            }
        }

        return false;
    }

    /** @return array{text: string, figure: null, format: null, tone: string} */
    private static function chip(string $text, string $tone): array
    {
        return ['text' => $text, 'figure' => null, 'format' => null, 'tone' => $tone];
    }

    /**
     * A change in percentage points; $invert for a ratio where lower is better.
     *
     * @return array{text: string, figure: float, format: string, tone: string}
     */
    private static function pointsChip(float $points, string $text, bool $invert = false): array
    {
        $good = $invert ? $points <= 0.0 : $points >= 0.0;

        return ['text' => $text, 'figure' => $points, 'format' => 'signed_points', 'tone' => $good ? 'up' : 'down'];
    }

    /** The margin effect of one input's part of the drag: a drag on the cost ratio is a loss of margin. */
    private static function dragChip(float $drag): array
    {
        return abs($drag) < self::DRAG_NOTE_FLOOR
            ? self::chip('Under 0.05 pt on margin', 'neutral')
            : self::pointsChip(-$drag * 100.0, 'on margin');
    }

    /** @return array{0: string, 1: string, 2: int, 3: float} */
    private static function dragEdge(string $from, float $drag): array
    {
        return [$from, 'recover', abs($drag) < self::DRAG_NOTE_FLOOR ? 0 : -($drag <=> 0.0), abs($drag)];
    }

    /**
     * Moves the cards with $ids, in that order, to just after the card $anchor; a missing card is skipped.
     *
     * @param list<array<string, mixed>> $cards
     * @param list<string> $ids
     */
    private static function moveAfter(array &$cards, array $ids, string $anchor): void
    {
        $moved = [];
        foreach ($ids as $id) {
            foreach ($cards as $card) {
                if (($card['id'] ?? null) === $id) {
                    $moved[] = $card;
                }
            }
        }
        $result = [];
        $placed = false;
        foreach ($cards as $card) {
            if (in_array($card['id'] ?? null, $ids, true)) {
                continue;
            }
            $result[] = $card;
            if (($card['id'] ?? null) === $anchor) {
                array_push($result, ...$moved);
                $placed = true;
            }
        }
        $cards = $placed ? $result : [...$result, ...$moved];
    }

    /** A compact dollar figure for a note ("$1.54T"), as the template's format_large prints a card value. */
    private static function large(float $amount): string
    {
        return (new \App\Twig\Extension\NumberFormatExtension())->formatLargeNumber($amount, '$');
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
