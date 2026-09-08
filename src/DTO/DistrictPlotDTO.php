<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Render-ready state for a single authored plot on a district ward elevation.
 *
 * Geometry (x, width) is authored cartography; the facade dimensions and condition are
 * derived purely for display from live company fundamentals.
 */
class DistrictPlotDTO
{
    public function __construct(
        public readonly string $plotId,
        public readonly int $x,
        public readonly int $width,
        public readonly float $height,
        public readonly float $y,
        public readonly int $floors,
        public readonly ?string $ticker = null,
        public readonly ?string $name = null,
        public readonly ?string $industry = null,
        public readonly ?string $systemicImportance = null,
        public readonly ?string $creditRating = null,
        public readonly string $condition = 'vacant',
        public readonly float $price = 0.0,
        public readonly float $marketCap = 0.0,
        public readonly float $returnOnCapital = 0.0,
        public readonly ?string $blurb = null,
        /** @var list<string> institution ids this tenant draws a macro conduit from */
        public readonly array $conduits = [],
    ) {}

    /** True when the plot carries a listed company rather than being held vacant for a future listing. */
    public function isOccupied(): bool
    {
        return $this->ticker !== null;
    }
}
