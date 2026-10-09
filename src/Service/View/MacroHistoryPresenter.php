<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\District\DistrictCalendar;
use App\Data\Macro\OutputGapChannels;

/**
 * The macro_report rows as the economy page's history charts read them: every observable column, each row dated by the
 * quarter it closes, and the output gap's change over that quarter split into the groups the page names.
 *
 * The probe records some thirty channels by their place in the engine; a reader wants to know whether rates, credit,
 * the government or the mainland moved the economy, so the channels are summed into the admin panel's families here,
 * once, rather than on every browser. The groups sum to the quarter's change in the gap: what the drift does not
 * explain (the period's random draw, the bounds, the residual) is a group of its own, never dropped.
 */
final class MacroHistoryPresenter
{
    // --- Row Hygiene ---
    /** Columns that are not observables: the probes' nested accounts and the run identity (Admin\MacroController serves the accounts). */
    private const INTERNAL_COLUMNS = ['gap_channels', 'quarter_diagnostics', 'config_fingerprint', 'ticks_per_year'];

    // --- Quarter Dating ---
    /** Half a quarter, in years: a row is written as its quarter closes, so it is dated half a quarter back to land inside the quarter it describes. */
    private const HALF_QUARTER_YEARS = 0.125;

    // --- Output Gap Groups ---
    /**
     * Family key (App\Data\Macro\OutputGapChannels, whose test keeps the channels current) => the label the page prints, in
     * the families' palette order, then 'other' for what the drift does not explain.
     */
    public const GAP_GROUP_LABELS = [
        'monetary' => 'Interest rates',
        'fiscal' => 'Government and the Fund',
        'disturbance' => 'Demand surprises',
        'credit' => 'Credit and lending',
        'wealth' => 'Markets and wealth',
        'supply' => 'Supply shocks',
        'external' => 'Mainland demand',
        'capacity' => 'Capacity and inventories',
        'other' => 'Other',
    ];

    /**
     * @param list<array<string, mixed>> $rows macro_report rows, oldest first, as the database returns them.
     *
     * @return list<array<string, mixed>> The same rows without the internal columns, each with `quarter_label` and
     *                                    `gap_breakdown` (group => share of potential output, or null).
     */
    public function present(array $rows): array
    {
        $presented = [];
        foreach ($rows as $row) {
            $channels = $row['gap_channels'] ?? null;
            foreach (self::INTERNAL_COLUMNS as $column) {
                unset($row[$column]);
            }

            $time = $row['total_time'] ?? null;
            $row['quarter_label'] = is_numeric($time) ? DistrictCalendar::quarter(max(0.0, (float) $time - self::HALF_QUARTER_YEARS)) : null;
            $row['gap_breakdown'] = $this->gapBreakdown(is_string($channels) ? json_decode($channels, true) : $channels);
            $presented[] = $row;
        }

        return $presented;
    }

    /**
     * One closed quarter's gap change by group, every figure a share of potential output over the quarter.
     *
     * @param mixed $window OutputGapProbe's closed window, decoded, or null where the probe closed none.
     *
     * @return array<string, float>|null
     */
    public function gapBreakdown(mixed $window): ?array
    {
        if (!is_array($window) || !is_array($window['contributions'] ?? null)) {
            return null;
        }

        $familyOf = OutputGapChannels::familyOf();
        $groups = array_fill_keys(array_keys(self::GAP_GROUP_LABELS), 0.0);
        foreach ($window['contributions'] as $channel => $contribution) {
            $groups[$familyOf[$channel] ?? 'other'] += (float) $contribution;
        }
        // The diffusion is not a channel (OutputGapChannels keeps it out of the families); it joins the bounds, the
        // accounting residual and any move between windows as what is left.
        $groups['other'] += (float) ($window['diffusion'] ?? 0.0) + (float) ($window['clamp'] ?? 0.0)
            + (float) ($window['unexplained'] ?? 0.0) + (float) ($window['external'] ?? 0.0);

        return $groups;
    }
}
