<?php

declare(strict_types=1);

namespace App\Service\Corporate\Segment;

use App\Data\Company\Segments;
use App\Service\Corporate\EarningsEngine;

/**
 * A company in several markets (Segments) reads each segment's industry outlook. Its demand is expected to grow as the
 * blend of its segments' expected levels at their last-reported revenue shares, the figure its market size, its plant
 * plan and its valuation read; and each segment's lines lean in its plan by how far their industry's outlook runs
 * ahead of that blend, so the same planned revenue is split toward the markets expected to grow. Plant stays shared.
 */
final class SegmentOutlook
{
    // --- Carried State ---
    /** Carried stream-state key prefix: a segment's share of the company's revenue at its last report. */
    public const STATE_SHARE = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_share:';
    /**
     * Carried stream-state key prefix: how far a revenue line leans in the plan, its segment's expected level over the
     * blend (1 for a company in one market); keyed by line, the main segment's under Segments::MAIN.
     */
    public const STATE_TILT = EarningsEngine::CARRIED_STATE_PREFIX . 'segment_tilt:';

    /**
     * Each segment's share of a report's revenue, main segment included; empty for a company in one market or a report
     * with no revenue.
     *
     * @param array<string, float|int|string> $streamRevenue
     * @return array<string, float>
     */
    public static function shares(string $ticker, array $streamRevenue): array
    {
        if (Segments::forTicker($ticker) === null) {
            return [];
        }

        $revenue = [];
        foreach ($streamRevenue as $stream => $amount) {
            $key = Segments::segmentOf($ticker, (string) $stream);
            $revenue[$key] = ($revenue[$key] ?? 0.0) + max(0.0, (float) $amount);
        }
        $total = array_sum($revenue);
        if ($total <= 0.0) {
            return [];
        }

        return array_map(static fn(float $amount): float => $amount / $total, $revenue);
    }

    /**
     * The company's expected level in log: the log of its segments' expected levels averaged at their shares. With no
     * share filed yet it is the main segment's.
     *
     * @param array<string, float> $shares  Segment => share of revenue.
     * @param array<string, float> $beliefs Segment => its industry's expected level, in log.
     */
    public static function blend(array $shares, array $beliefs): float
    {
        $weight = 0.0;
        $level = 0.0;
        $selling = [];
        foreach ($shares as $key => $share) {
            if ($share > 0.0 && isset($beliefs[$key])) {
                $weight += $share;
                $level += $share * exp($beliefs[$key]);
                $selling[] = $key;
            }
        }

        // One segment selling (a line not yet open) is that segment's outlook exactly.
        return match (count($selling)) {
            0 => $beliefs[Segments::MAIN] ?? 0.0,
            1 => $beliefs[$selling[0]],
            default => log($level / $weight),
        };
    }

    /**
     * The lean of each revenue line, keyed for STATE_TILT: its segment's expected level over the blend.
     *
     * @param array<string, float> $beliefs Segment => its industry's expected level, in log.
     * @return array<string, float>
     */
    public static function tilts(string $ticker, array $beliefs, float $blend): array
    {
        $plan = Segments::forTicker($ticker);
        if ($plan === null) {
            return [];
        }

        $tilts = [Segments::MAIN => exp(($beliefs[Segments::MAIN] ?? 0.0) - $blend)];
        foreach ($plan['segments'] as $key => $segment) {
            foreach ($segment['streams'] as $stream) {
                $tilts[$stream] = exp(($beliefs[$key] ?? 0.0) - $blend);
            }
        }

        return $tilts;
    }

    /**
     * The lean the company's last report filed for a revenue line: 1 for a line of a company in one market.
     *
     * @param array<string, mixed> $momentum
     */
    public static function tilt(array $momentum, string $stream): float
    {
        return (float) ($momentum[self::STATE_TILT . $stream] ?? $momentum[self::STATE_TILT . Segments::MAIN] ?? 1.0);
    }

    /**
     * The segment shares the company's last report filed.
     *
     * @param array<string, mixed> $momentum
     * @return array<string, float>
     */
    public static function filedShares(array $momentum): array
    {
        $shares = [];
        foreach ($momentum as $key => $value) {
            if (str_starts_with((string) $key, self::STATE_SHARE)) {
                $shares[substr((string) $key, strlen(self::STATE_SHARE))] = (float) $value;
            }
        }

        return $shares;
    }
}
