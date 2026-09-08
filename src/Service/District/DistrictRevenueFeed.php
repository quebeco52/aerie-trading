<?php

declare(strict_types=1);

namespace App\Service\District;

use App\Entity\CorporateReport;
use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the current revenue-mix summary shown when a district building is selected: each stream
 * a tenant earns from, its share of the latest quarter's revenue, its quarter-on-quarter move,
 * and the named drivers behind that move — as written by EarningsReportSubscriber onto
 * CorporateReport::$revenueStreams / $streamDetails per simulated quarter.
 *
 * This is a snapshot of the latest report only, not a time series — App\Service\Corporate's own
 * /api/earnings-flow (App\Controller\StockController::earningsFlow()) already serves a single
 * ticker's waterfall and assets/js/stock/fundamental-charts.js already renders the historical
 * stacked-bar view across quarters; this service exists so the ward can show the same latest-
 * quarter mix for all of its tenants in one query pass instead of 17 separate requests.
 *
 * The `drivers[]` this returns name the same macro variables the district's conduits draw
 * (App\Data\DistrictMap::CONDUITS) — that attribution is what ties the revenue panel back to the
 * conduit layer, and its correctness for every financial business model is what
 * StreamMacroDriverRoutingTest pins.
 */
class DistrictRevenueFeed
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param  Stock[] $stocks
     * @return array<string, array{totalRevenue: float, streams: list<array<string, mixed>>}> ticker => revenue mix
     */
    public function latestRevenueMixByTicker(array $stocks): array
    {
        $repository = $this->entityManager->getRepository(CorporateReport::class);
        $mixByTicker = [];

        foreach ($stocks as $stock) {
            $report = $repository->findOneBy(
                ['stock' => $stock],
                ['recordedAt' => 'DESC'],
            );

            $mixByTicker[$stock->getTicker()] = $report instanceof CorporateReport
                ? $this->buildMix($report)
                : ['totalRevenue' => 0.0, 'streams' => []];
        }

        return $mixByTicker;
    }

    /**
     * @return array{totalRevenue: float, streams: list<array<string, mixed>>}
     */
    private function buildMix(CorporateReport $report): array
    {
        $revenueStreams = $report->getRevenueStreams() ?? [];
        $streamDetails = $report->getStreamDetails() ?? [];
        $totalRevenue = (float) ($report->getRevenue() ?? 0.0);

        $streams = [];
        foreach ($revenueStreams as $streamKey => $revenue) {
            $details = $streamDetails[$streamKey] ?? [];

            $streams[] = [
                'key' => $streamKey,
                'label' => $this->prettifyStreamKey((string) $streamKey),
                'revenue' => (float) $revenue,
                'share' => (float) ($details['share'] ?? 0.0),
                'qoqDelta' => (float) ($details['qoq_delta'] ?? 0.0),
                'drivers' => $details['drivers'] ?? [],
                'event' => $details['event'] ?? null,
            ];
        }

        // Largest contributor first — the mix is a rank, not the order streams happened to be
        // computed in.
        usort($streams, static fn (array $a, array $b): int => $b['share'] <=> $a['share']);

        return [
            'totalRevenue' => $totalRevenue,
            'streams' => $streams,
        ];
    }

    /** Matches the "Foo Bar" formatting StockController::earningsFlow() already gives stream nodes. */
    private function prettifyStreamKey(string $streamKey): string
    {
        return ucwords(str_replace('_', ' ', $streamKey));
    }
}
