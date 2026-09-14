<?php

declare(strict_types=1);

namespace App\Service\District;

use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the segment-revenue panel shown when a district building is selected: for each stream a
 * tenant earns from, its reported revenue, its share of the quarter, how that share and that
 * revenue moved sequentially and against the year-ago quarter, what it contributed to the firm's
 * own growth, and the named drivers behind the move — read off the CorporateReport rows
 * EarningsReportSubscriber writes per simulated quarter.
 *
 * The panel reads like a segment note because it is built like one. Three conventions carry that:
 *
 *  - Growth rates are quoted both sequentially and year-on-year, so a seasonal stream is not
 *    mistaken for a trending one. Both are `null`, not zero, where the comparison quarter does
 *    not exist — a stream with no prior period has no growth rate, and printing 0.0% there said
 *    "flat" about something brand new.
 *  - Segment growth is separated from segment importance. `contribution` is the stream's share of
 *    the *firm's* growth (MathUtility::calculateGrowthContributions()), and those contributions
 *    sum to `totalQoq`; a small stream doubling moves the header far less than its own rate reads.
 *  - Mix movement is quoted in basis points of share (`shareShiftBps`), the unit segment
 *    reporting actually uses for it.
 *
 * Concentration is the Herfindahl-Hirschman Index of the mix plus the effective segment count it
 * implies — the standard read on how much of a firm rests on one line of business.
 *
 * History is capped at HISTORY_QUARTERS reports per tenant, which is what a year-on-year compare
 * and a five-point trend need and no more. It stays one query for the whole street: CorporateReport
 * carries an (stock_id, recorded_at) index (see its class attributes), so this is a single index
 * scan whose rows are sliced per ticker in PHP rather than one query per tenant.
 *
 * The `drivers[]` this passes through name the same macro variables the district's conduits draw
 * (App\Service\Model\Strategy\OperatingStrategyInterface::getOperatingMacroFields(), resolved by
 * App\Service\District\DistrictConduitResolver) — that attribution is what ties the revenue panel
 * back to the conduit layer, and its correctness for every financial business model is what
 * StreamMacroDriverRoutingTest pins.
 */
class DistrictRevenueFeed
{
    // --- History Window ---

    /** Reports kept per tenant: the current quarter, three between, and the year-ago compare. */
    public const HISTORY_QUARTERS = 5;

    /** Offset from the latest report to the year-ago quarter, on four simulated quarters a year. */
    private const YEAR_AGO_OFFSET = 4;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MathUtility $mathUtility,
    ) {}

    /**
     * @param  Stock[] $stocks
     * @return array<string, array<string, mixed>> ticker => revenue mix
     */
    public function latestRevenueMixByTicker(array $stocks): array
    {
        $mixByTicker = [];
        foreach ($stocks as $stock) {
            $mixByTicker[$stock->getTicker()] = $this->emptyMix();
        }

        if ($stocks === []) {
            return $mixByTicker;
        }

        // Rows arrive grouped by stock and newest-first within a stock, so the first
        // HISTORY_QUARTERS rows seen for a ticker are its history, newest first; the rest are skipped.
        $rows = $this->entityManager->getRepository(CorporateReport::class)
            ->findForStocksNewestFirst($stocks);

        /** @var array<string, list<CorporateReport>> $historyByTicker */
        $historyByTicker = [];
        foreach ($rows as $report) {
            $ticker = $report->getStock()->getTicker();
            if (count($historyByTicker[$ticker] ?? []) >= self::HISTORY_QUARTERS) {
                continue;
            }

            $historyByTicker[$ticker][] = $report;
        }

        foreach ($historyByTicker as $ticker => $history) {
            $mixByTicker[$ticker] = $this->buildMix($history);
        }

        return $mixByTicker;
    }

    /** @return array<string, mixed> */
    private function emptyMix(): array
    {
        return [
            'totalRevenue' => 0.0,
            'streams' => [],
            'periodLabel' => null,
            'reportedAt' => null,
            'totalQoq' => null,
            'totalYoy' => null,
            'ttmRevenue' => null,
            'quartersOnFile' => 0,
            'concentration' => null,
        ];
    }

    /**
     * @param  list<CorporateReport> $history newest-first, at most HISTORY_QUARTERS long
     * @return array<string, mixed>
     */
    private function buildMix(array $history): array
    {
        $latest = $history[0];
        $previous = $history[1] ?? null;
        $yearAgo = $history[self::YEAR_AGO_OFFSET] ?? null;

        $currentStreams = $this->streamRevenues($latest);
        $previousStreams = $previous !== null ? $this->streamRevenues($previous) : [];
        $yearAgoStreams = $yearAgo !== null ? $this->streamRevenues($yearAgo) : [];

        $streamDetails = $latest->getStreamDetails() ?? [];
        $totalRevenue = (float) ($latest->getRevenue() ?? 0.0);
        $previousTotal = $previous !== null ? (float) ($previous->getRevenue() ?? 0.0) : 0.0;
        $yearAgoTotal = $yearAgo !== null ? (float) ($yearAgo->getRevenue() ?? 0.0) : 0.0;

        $contributions = $this->mathUtility->calculateGrowthContributions($currentStreams, $previousStreams);
        $previousTotalStreams = array_sum($previousStreams);

        $streams = [];
        $shares = [];
        foreach ($currentStreams as $streamKey => $revenue) {
            $details = $streamDetails[$streamKey] ?? [];
            $share = $totalRevenue > 0.0 ? $revenue / $totalRevenue : 0.0;
            $shares[$streamKey] = $share;

            $priorRevenue = $previousStreams[$streamKey] ?? null;
            $priorShare = ($priorRevenue !== null && $previousTotalStreams > 0.0)
                ? $priorRevenue / $previousTotalStreams
                : null;

            $streams[] = [
                'key' => $streamKey,
                'label' => $this->prettifyStreamKey((string) $streamKey),
                'revenue' => $revenue,
                'share' => round($share, 4),
                // Share movement in basis points is the unit a segment note quotes mix shift in.
                'shareShiftBps' => $priorShare !== null ? round(($share - $priorShare) * 10000.0, 1) : null,
                'qoqDelta' => $this->growthRate($revenue, $priorRevenue),
                'yoyDelta' => $this->growthRate($revenue, $yearAgoStreams[$streamKey] ?? null),
                'contribution' => isset($contributions[$streamKey]) ? round($contributions[$streamKey], 5) : null,
                // Oldest-first, so the sparkline reads left to right like every other chart here.
                'history' => $this->streamHistory($history, (string) $streamKey),
                'drivers' => $details['drivers'] ?? [],
                'event' => $details['event'] ?? null,
            ];
        }

        // Largest contributor first — the mix is a rank, not the order streams happened to be
        // computed in.
        usort($streams, static fn (array $a, array $b): int => $b['share'] <=> $a['share']);

        $hhi = $this->mathUtility->calculateHerfindahlIndex($shares);

        return [
            'totalRevenue' => $totalRevenue,
            'streams' => $streams,
            'periodLabel' => $this->periodLabel($latest),
            'reportedAt' => $latest->getRecordedAt()->format(\DATE_ATOM),
            'totalQoq' => $this->growthRate($totalRevenue, $previous !== null ? $previousTotal : null),
            'totalYoy' => $this->growthRate($totalRevenue, $yearAgo !== null ? $yearAgoTotal : null),
            'ttmRevenue' => $this->trailingTwelveMonths($history),
            'quartersOnFile' => count($history),
            'concentration' => $shares === [] ? null : [
                'hhi' => round($hhi, 4),
                'effectiveStreams' => round($this->mathUtility->calculateEffectiveSegmentCount($hhi), 2),
            ],
        ];
    }

    /**
     * Period-over-period growth rate, or null where it is not meaningful — no comparison quarter,
     * or a base of zero or less, against which a percentage change has no defined value.
     */
    private function growthRate(float $current, ?float $base): ?float
    {
        if ($base === null || $base <= 0.0) {
            return null;
        }

        return round(($current - $base) / $base, 4);
    }

    /**
     * Trailing four quarters of total revenue, or null until four quarters are on file — a TTM
     * figure built from fewer quarters is not a TTM figure.
     *
     * @param list<CorporateReport> $history newest-first
     */
    private function trailingTwelveMonths(array $history): ?float
    {
        if (count($history) < 4) {
            return null;
        }

        $ttm = 0.0;
        foreach (array_slice($history, 0, 4) as $report) {
            $ttm += (float) ($report->getRevenue() ?? 0.0);
        }

        return round($ttm, 2);
    }

    /**
     * One stream's revenue across the window, oldest-first, with quarters the stream did not
     * exist in left as null so a sparkline breaks rather than drawing a false zero.
     *
     * @param  list<CorporateReport> $history newest-first
     * @return list<float|null>
     */
    private function streamHistory(array $history, string $streamKey): array
    {
        $series = [];
        foreach (array_reverse($history) as $report) {
            $streams = $report->getRevenueStreams() ?? [];
            $series[] = isset($streams[$streamKey]) ? round((float) $streams[$streamKey], 2) : null;
        }

        return $series;
    }

    /**
     * @return array<string, float> stream key => revenue for one report
     */
    private function streamRevenues(CorporateReport $report): array
    {
        $streams = [];
        foreach ($report->getRevenueStreams() ?? [] as $key => $revenue) {
            $streams[(string) $key] = (float) $revenue;
        }

        return $streams;
    }

    /**
     * Calendar quarter the report was filed in. The simulation has no fiscal calendar of its own,
     * so the filing timestamp is the period — the same stamp the report is ordered by.
     */
    private function periodLabel(CorporateReport $report): string
    {
        $recordedAt = $report->getRecordedAt();
        $quarter = (int) ceil(((int) $recordedAt->format('n')) / 3);

        return sprintf('Q%d %s', $quarter, $recordedAt->format('Y'));
    }

    /** Matches the "Foo Bar" formatting StockController::earningsFlow() already gives stream nodes. */
    private function prettifyStreamKey(string $streamKey): string
    {
        return ucwords(str_replace('_', ' ', $streamKey));
    }
}
