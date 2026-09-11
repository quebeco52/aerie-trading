<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * The vertical layout of a district street for one request: where each row's kerb sits, where
 * each institution's conduit lane runs, and how tall the canvas is.
 *
 * Derived by App\Service\District\DistrictMapBuilder::resolveCanvas() from the facades actually on
 * the street, so a row is given the sky it needs rather than the sky the tallest conceivable
 * facade would — see App\Data\DistrictMap::ROW_GAP for the rule. Presentation only.
 */
final class DistrictCanvasDTO
{
    /**
     * @param list<float>          $rowGroundLines   absolute y of each row's kerb line, upper row first
     * @param array<string, float> $laneYByInstitution absolute y of each rendered institution's conduit lane
     * @param float                $laneBandBottom   absolute y below the last lane; the upper row's sky starts here
     * @param float                $viewboxHeight    height in user units of the street's SVG viewBox
     */
    public function __construct(
        public readonly array $rowGroundLines,
        public readonly array $laneYByInstitution,
        public readonly float $laneBandBottom,
        public readonly float $viewboxHeight,
    ) {
        if ($rowGroundLines === []) {
            throw new \InvalidArgumentException('A street canvas needs at least one row.');
        }
    }

    /** The ground line for a row index, clamped to the last row for an out-of-range index. */
    public function groundLineForRow(int $row): float
    {
        return $this->rowGroundLines[$row] ?? $this->rowGroundLines[count($this->rowGroundLines) - 1];
    }

    public function rowCount(): int
    {
        return count($this->rowGroundLines);
    }
}
