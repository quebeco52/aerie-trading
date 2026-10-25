<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Company\Segments;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Repository\CorporateReportRepository;

/**
 * A company's segment note, as an annual report gives it: each segment's industry and its revenue in the last quarterly
 * report, main segment first. Every revenue line lands in exactly one segment, so the segments add up to the lines.
 * Null for a company that sells into a single market.
 *
 * @phpstan-type SegmentRow array{label: string, industry: string, revenue: float|null, share: float|null}
 */
class SegmentNoteBuilder
{
    public function __construct(private readonly CorporateReportRepository $reports) {}

    /** @return array{segments: list<SegmentRow>|null} */
    public function build(Stock $stock): array
    {
        return ['segments' => Segments::forTicker($stock->getTicker()) === null ? null : self::fromReport($stock, $this->reports->findLatestFor($stock))];
    }

    /**
     * A segment with no revenue yet (a line that has not opened) prints as unknown rather than as a zero.
     *
     * @return list<SegmentRow>|null
     */
    public static function fromReport(Stock $stock, ?CorporateReport $report): ?array
    {
        $plan = Segments::forTicker($stock->getTicker());
        if ($plan === null) {
            return null;
        }

        $segments = [Segments::MAIN => ['label' => $plan['main'], 'industry' => (string) $stock->getIndustry(), 'revenue' => 0.0]];
        foreach ($plan['segments'] as $key => $segment) {
            $segments[$key] = ['label' => $segment['label'], 'industry' => $segment['industry'], 'revenue' => 0.0];
        }
        foreach ($report?->getRevenueStreams() ?? [] as $stream => $revenue) {
            $key = Segments::segmentOf($stock->getTicker(), (string) $stream);
            if (isset($segments[$key])) {
                $segments[$key]['revenue'] += (float) $revenue;
            }
        }

        $total = array_sum(array_column($segments, 'revenue'));
        $rows = [];
        foreach ($segments as $segment) {
            $rows[] = [
                'label' => $segment['label'],
                'industry' => $segment['industry'],
                'revenue' => $segment['revenue'] > 0.0 ? $segment['revenue'] : null,
                'share' => $total > 0.0 && $segment['revenue'] > 0.0 ? $segment['revenue'] / $total : null,
            ];
        }

        return $rows;
    }
}
