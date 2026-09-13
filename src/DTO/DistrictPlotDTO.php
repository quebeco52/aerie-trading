<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Render-ready state for a single plot on the district street elevation.
 *
 * Geometry is derived, not authored (see App\Service\District\DistrictWardComposer); the facade
 * dimensions and condition come purely from live company fundamentals, for display only.
 */
class DistrictPlotDTO
{
    public function __construct(
        public readonly string $plotId,
        public readonly int $x,
        public readonly int $width,
        public readonly float $height,
        public readonly float $y,
        /** Which frontage row this plot stands on, 0 being the upper one. A grouping key only — every coordinate here is already absolute. */
        public readonly int $row,
        /** Absolute y of this row's kerb line. Carried rather than re-derived so nothing downstream has to consult the canvas. */
        public readonly float $groundLine,
        public readonly int $floors,
        /** Window columns that fit across the facade — see DistrictMap::WINDOW_PITCH. */
        public readonly int $windowColumns,
        /** Inset from the facade's west edge to the first window column, centring the band. */
        public readonly float $windowInset,
        /** Which windows are lit, indexed [floor][column]; see DistrictMap::WINDOW_LIT_SHARE_AT_BASELINE. @var list<list<bool>> */
        public readonly array $litWindows,
        /** Each window's lighting priority in [0, 1), indexed [floor][column]; lit when it falls under the lit share. Shipped so a live tick can relight the facade client-side. @var list<list<float>> */
        public readonly array $windowKeys,
        /** Flicker phase in [0, 1) for each window that twinkles while lit, null where it burns steady, indexed [floor][column] — see DistrictMap::WINDOW_TWINKLE_SHARE. @var list<list<float|null>> */
        public readonly array $twinklePhases,
        /** Fraction of windows lit, the figure the lighting was drawn from. */
        public readonly float $litShare,
        /** Live payload field the lit share is read from on a tick: current_roe for financials, current_roic otherwise. */
        public readonly string $returnField,
        /** Rooftop symbol for the tenant's business model — see DistrictMap::ROOF_FURNITURE. */
        public readonly string $roofFurniture,
        public readonly string $ticker,
        public readonly string $name,
        /** Market-cap position on the street, 1 being the largest tenant. */
        public readonly int $rank,
        public readonly ?string $sector = null,
        public readonly ?string $industry = null,
        public readonly ?string $systemicImportance = null,
        public readonly ?string $creditRating = null,
        public readonly string $condition = 'sound',
        public readonly float $price = 0.0,
        public readonly float $marketCap = 0.0,
        public readonly float $returnOnCapital = 0.0,
        /** The return the tenant is expected to earn at rest (baseline ROIC, or ROE for financials); what lights the windows. */
        public readonly float $baselineReturnOnCapital = 0.0,
        /** Fractional price change over the district's lookback window, or null when no history is buffered yet. */
        public readonly ?float $changePercent = null,
        public readonly ?string $blurb = null,
        /** @var list<string> institution ids this tenant draws a macro conduit from */
        public readonly array $conduits = [],
        /** Absolute x where each conduit's drop lands on the roof, keyed by institution id; spread across the roof so they never overlay. @var array<string, float> */
        public readonly array $conduitDropX = [],
    ) {}
}
