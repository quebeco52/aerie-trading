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
        /** Absolute y of this row's kerb line. Carried rather than re-derived so nothing downstream has to index DistrictMap::ROW_GROUND_LINES. */
        public readonly float $groundLine,
        public readonly int $floors,
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
        /** Fractional price change over the district's lookback window, or null when no history is buffered yet. */
        public readonly ?float $changePercent = null,
        public readonly ?string $blurb = null,
        /** @var list<string> institution ids this tenant draws a macro conduit from */
        public readonly array $conduits = [],
    ) {}
}
