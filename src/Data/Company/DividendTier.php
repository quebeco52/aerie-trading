<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * A firm's standing on the exchange's dividend roll, read off its streak of consecutive yearly dividend increases
 * (the tiers of Fish's Champions, Contenders and Challengers list, with the informal Kings above). A longer record
 * costs more to break, so its board smooths its dividend harder, and a champion holds it through a loss (Lintner
 * 1956; Brav, Graham, Harvey & Michaely 2005).
 */
enum DividendTier: string
{
    // --- Streak Thresholds (years of consecutive increases) ---
    /** Challenger: five years of increases. */
    public const CHALLENGER_YEARS = 5;
    /** Contender: ten years. */
    public const CONTENDER_YEARS = 10;
    /** Champion: twenty-five years. */
    public const CHAMPION_YEARS = 25;
    /** King, informally: fifty years. */
    public const KING_YEARS = 50;

    // --- Smoothing (multiplier on the board's dividend adjustment speed) ---
    /** A challenger smooths a little harder than an unlisted payer (0.85x the speed). */
    public const CHALLENGER_SMOOTHING = 0.85;
    /** A contender, harder still (0.70x). */
    public const CONTENDER_SMOOTHING = 0.70;
    /** A champion or king moves its dividend at a little over half the usual speed (0.55x). */
    public const CHAMPION_SMOOTHING = 0.55;

    case None = 'none';
    case Challenger = 'challenger';
    case Contender = 'contender';
    case Champion = 'champion';
    case King = 'king';

    public static function fromStreak(int $years): self
    {
        return match (true) {
            $years >= self::KING_YEARS => self::King,
            $years >= self::CHAMPION_YEARS => self::Champion,
            $years >= self::CONTENDER_YEARS => self::Contender,
            $years >= self::CHALLENGER_YEARS => self::Challenger,
            default => self::None,
        };
    }

    /** Whether the firm is on the roll at all. */
    public function isListed(): bool
    {
        return $this !== self::None;
    }

    /**
     * Whether its board closes a flat year with a token raise to keep its record: a contender or above, whose decade
     * of increases is a standing worth a token to keep; a challenger needs a real raise.
     */
    public function protectsRecord(): bool
    {
        return $this === self::Contender || $this === self::Champion || $this === self::King;
    }

    /** Multiplier on the board's dividend adjustment speed: the longer the record, the smoother the dividend. */
    public function smoothing(): float
    {
        return match ($this) {
            self::None => 1.0,
            self::Challenger => self::CHALLENGER_SMOOTHING,
            self::Contender => self::CONTENDER_SMOOTHING,
            self::Champion, self::King => self::CHAMPION_SMOOTHING,
        };
    }

    /** A champion does not cut by choice, even when the year's earnings no longer cover the dividend. */
    public function holdsThroughLosses(): bool
    {
        return $this === self::Champion || $this === self::King;
    }

    /** The tier as the District's pages name it. */
    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Challenger => 'Challenger',
            self::Contender => 'Contender',
            self::Champion => 'Champion',
            self::King => 'King',
        };
    }
}
