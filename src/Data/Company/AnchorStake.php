<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * How firmly a permanent-capital sphere holds one of its listed companies.
 *
 * The conviction is the interesting fact, not the percentage: effectively a subsidiary, a company governed
 * from the board, or one it merely has a seat at. Naming it keeps that visible and bounds what can be declared:
 * nothing opens above CONTROL. A stake then grows only as the company retires its float, and stops where
 * AnchorHoldings::MIN_TRADABLE_FLOAT is left trading.
 */
enum AnchorStake: string
{
    case Control = 'control';
    case Anchor = 'anchor';
    case Minority = 'minority';

    /**
     * Fraction of the held company the stake opens at, at this conviction. Control opens at a half, and stays a stake
     * carried at its price past it, as an investment company carries what it holds: a listed company is never
     * consolidated into another's books.
     */
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
