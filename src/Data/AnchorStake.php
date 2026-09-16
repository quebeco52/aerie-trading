<?php

declare(strict_types=1);

namespace App\Data;

/**
 * How firmly a permanent-capital sphere holds one of its listed companies.
 *
 * The conviction is the interesting fact, not the percentage: effectively a subsidiary, a company governed
 * from the board, or one it merely has a seat at. Naming it keeps that visible and bounds ownership by
 * construction — nothing can be declared above CONTROL, so no stake grows until a holding has no float.
 */
enum AnchorStake: string
{
    case Control = 'control';
    case Anchor = 'anchor';
    case Minority = 'minority';

    /** Fraction of the held company owned at this conviction. Control stops at a half: past that a holding is consolidated line by line and stops being a stake at all. */
    public function fraction(): float
    {
        return match ($this) {
            self::Control => 0.50,
            self::Anchor => 0.35,
            self::Minority => 0.20,
        };
    }

    /** How a factsheet would describe the holding. */
    public function label(): string
    {
        return match ($this) {
            self::Control => 'Control',
            self::Anchor => 'Anchor',
            self::Minority => 'Minority',
        };
    }
}
