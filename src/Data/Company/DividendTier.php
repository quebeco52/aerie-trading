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

    // --- Opening Records (the board's long-run distribution of streaks) ---
    /** Share of payers with a record of at least one year of increases (68%). */
    public const OPENING_RECORD_SHARE = 0.68;
    /**
     * Chance a record of one to four years survives another year (74%): a young record ends in about one year in four.
     */
    public const YOUNG_RECORD_SURVIVAL = 0.74;
    /** A challenger's record survives a year 79% of the time. */
    public const CHALLENGER_RECORD_SURVIVAL = 0.79;
    /** A contender's 91%: its token raise carries it through a flat year its policy still supports. */
    public const CONTENDER_RECORD_SURVIVAL = 0.91;
    /** A champion's or king's 97%: about one in thirty ends each year. */
    public const CHAMPION_RECORD_SURVIVAL = 0.97;
    /** Longest record a board opens on (70 years), about the longest any real exchange lists. */
    public const LONGEST_OPENING_RECORD = 70;

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

    /**
     * The record a payer opens on, at a quantile of the board's long-run distribution: with records starting at a
     * steady rate and each surviving a year at its tier's rate, the number at m years is the product of the survivals
     * below m, so the share at m years or more is the tail of those products. A quantile under the share with no record
     * opens at zero.
     */
    public static function openingStreak(float $quantile): int
    {
        $counts = [];
        $count = 1.0;
        for ($years = 1; $years <= self::LONGEST_OPENING_RECORD; $years++) {
            $counts[$years] = $count;
            $count *= self::recordSurvival($years);
        }
        $remaining = array_sum($counts);
        $total = $remaining;
        $streak = 0;
        foreach ($counts as $years => $atYears) {
            if ($quantile >= self::OPENING_RECORD_SHARE * $remaining / $total) {
                break;
            }
            $streak = $years;
            $remaining -= $atYears;
        }

        return $streak;
    }

    /** The chance a record of this many years survives another year, by the tier it stands in. */
    private static function recordSurvival(int $years): float
    {
        return match (self::fromStreak($years)) {
            self::None => self::YOUNG_RECORD_SURVIVAL,
            self::Challenger => self::CHALLENGER_RECORD_SURVIVAL,
            self::Contender => self::CONTENDER_RECORD_SURVIVAL,
            self::Champion, self::King => self::CHAMPION_RECORD_SURVIVAL,
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
