<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Company\Segments;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Repository\CorporateReportRepository;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Segment\SegmentBooks;

/**
 * A company's segment note, as an annual report gives it: each segment's industry, that industry's demand outlook, its
 * revenue in the last quarterly report, its operating margin and its share of the company's assets, main segment first.
 * The segments' margins are their own, scaled to the margin the company reported, so at the mix its plant sells their
 * profits add up to its operating profit. Every revenue line lands in exactly one segment, so the segments add up to
 * the lines; a segment sold is gone from the note, and one the company is still building into a new industry shows
 * with no revenue yet. Null for a company that sells into a single market.
 *
 * @phpstan-type SegmentRow array{label: string, industry: string, outlook: float|null, revenue: float|null, share: float|null, margin: float|null, assets: float|null}
 */
class SegmentNoteBuilder
{
    public function __construct(
        private readonly CorporateReportRepository $reports,
        private readonly IndustryShareLedger $ledger,
    ) {}

    /** @return array{segments: list<SegmentRow>|null} */
    public function build(Stock $stock): array
    {
        if (!SegmentBooks::keepsBooks($stock)) {
            return ['segments' => null];
        }

        $outlooks = [];
        foreach (SegmentBooks::industries($stock) as $industry) {
            $outlooks[$industry] = $this->ledger->describeOutlook($industry)['level'] ?? null;
        }

        return ['segments' => self::fromReport($stock, $this->reports->findLatestFor($stock), $outlooks)];
    }

    /**
     * A segment with no revenue yet (a line that has not opened) prints as unknown rather than as a zero, as does an
     * industry with no outlook.
     *
     * @param array<string, float|null> $outlooks Industry => its expected demand level (1 when trading opened).
     * @return list<SegmentRow>|null
     */
    public static function fromReport(Stock $stock, ?CorporateReport $report, array $outlooks = []): ?array
    {
        if (!SegmentBooks::keepsBooks($stock)) {
            return null;
        }

        // A segment the company has sold holds no plant and is no longer reported; nor is a main business that holds
        // none (a conglomerate whose own business is its cash, which earns below the operating line).
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $plant = SegmentBooks::plant($momentum);
        $pending = SegmentBooks::pending($momentum);
        $segments = [];
        foreach (SegmentBooks::industries($stock) as $key => $industry) {
            if ($plant === null || ($plant[$key] ?? 0.0) > 0.0 || ($pending[$key] ?? 0.0) > 0.0) {
                $segments[$key] = ['label' => SegmentBooks::label($stock, $key), 'industry' => $industry, 'revenue' => 0.0];
            }
        }
        // A company in one market whose books are open only for what it entered, all of which it has sold since.
        if (count($segments) < 2 && Segments::forTicker($stock->getTicker()) === null) {
            return null;
        }
        foreach ($report?->getRevenueStreams() ?? [] as $stream => $revenue) {
            $key = Segments::segmentOf($stock->getTicker(), (string) $stream);
            if (isset($segments[$key])) {
                $segments[$key]['revenue'] += (float) $revenue;
            }
        }

        $total = array_sum(array_column($segments, 'revenue'));
        // Margins and assets are known once the company keeps its plant by segment with economics of its own.
        $economics = SegmentBooks::companyEconomics($stock->getEarningsMomentumZ() ?? []);
        $reported = $report?->getOperatingMargin();
        $scale = $economics !== null && $reported !== null && $economics['margin'] > 0.0 ? (float) $reported / $economics['margin'] : null;
        $rows = [];
        foreach ($segments as $key => $segment) {
            $rows[] = [
                'label' => $segment['label'],
                'industry' => $segment['industry'],
                'outlook' => $outlooks[$segment['industry']] ?? null,
                'revenue' => $segment['revenue'] > 0.0 ? $segment['revenue'] : null,
                'share' => $total > 0.0 && $segment['revenue'] > 0.0 ? $segment['revenue'] / $total : null,
                'margin' => $scale !== null && isset($economics['margins'][$key]) ? $economics['margins'][$key] * $scale : null,
                'assets' => $plant[$key] ?? null,
            ];
        }

        return $rows;
    }
}
