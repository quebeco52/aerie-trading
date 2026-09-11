<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Render-ready state for a single macro-publishing institution on a district ward elevation.
 *
 * `x`/`width` are derived per ward by App\Service\District\DistrictMapBuilder from the
 * institutions actually wired to that ward's tenants — see App\Data\DistrictMap::INSTITUTIONS
 * for why an institution carries no geometry of its own.
 */
class DistrictInstitutionDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        /** Abbreviated name printed on the structure itself — the full label overruns it by 50-100%. */
        public readonly string $shortLabel,
        public readonly int $x,
        public readonly int $width,
        /** Absolute y of the conduit lane this institution owns — see DistrictMap::CONDUIT_LANE_PITCH. */
        public readonly float $laneY,
        /** @var list<string> */
        public readonly array $fields,
        /** @var list<array{field: string, label: string, unit: string}> */
        public readonly array $readouts,
        /** @var list<array{field: string, op: string, value: float}> */
        public readonly array $stressRules,
    ) {}
}
