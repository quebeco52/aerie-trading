<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * The log10 market-capitalisation window a district street's facade heights are drawn across.
 *
 * Derived per request from the roster actually on the street (see
 * App\Service\District\DistrictMapBuilder::resolveEnvelope()) and shared by every consumer of the
 * height mapping — the facades, the gutter gridlines and the client's live-tick resize — so none
 * of them can disagree about where a given capitalisation stands. Presentation only: nothing here
 * is ever read back into the simulation.
 */
final class DistrictHeightEnvelope
{
    public function __construct(
        /** Log10 capitalisation mapped to the shortest facade. */
        public readonly float $logFloor,
        /** Log10 capitalisation mapped to the tallest facade; always strictly above the floor. */
        public readonly float $logCeiling,
    ) {
        if ($logCeiling <= $logFloor) {
            throw new \InvalidArgumentException(sprintf(
                'Height envelope ceiling (%.3f) must sit above its floor (%.3f).',
                $logCeiling,
                $logFloor,
            ));
        }
    }

    /** Width of the window in log10 units. */
    public function span(): float
    {
        return $this->logCeiling - $this->logFloor;
    }

    /**
     * Where a capitalisation falls in the window, 0 at the floor and 1 at the ceiling, clamped.
     * Linear in log10, so equal ratios of capitalisation are equal distances of height — the
     * property that makes the gutter's rules read as a scale.
     */
    public function normalise(float $marketCap): float
    {
        $logCap = log10(max($marketCap, 1.0));

        return max(0.0, min(1.0, ($logCap - $this->logFloor) / $this->span()));
    }

    /**
     * The fields the client mirror needs to reproduce the mapping — see
     * assets/controllers/district_controller.js resizeFacade().
     *
     * @return array{logFloor: float, logCeiling: float}
     */
    public function toArray(): array
    {
        return ['logFloor' => $this->logFloor, 'logCeiling' => $this->logCeiling];
    }
}
