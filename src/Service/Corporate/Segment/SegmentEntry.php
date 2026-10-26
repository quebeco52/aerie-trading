<?php

declare(strict_types=1);

namespace App\Service\Corporate\Segment;

/**
 * A company entering an industry it is not in: how a business it builds there learns the trade. A segment entered in
 * play (Segments::enteredKey()) is booked like any other (SegmentBooks); what is its own is the plant it still has
 * under construction and the margin it has yet to learn.
 */
final class SegmentEntry
{
    // --- Learning the Trade ---
    /**
     * Years over which a business built into a new industry closes its gap to the margin it will earn once it has
     * learned the trade, e-folding: 3.5, so a venture starting 2.5-5 times that margin short loses money for three to
     * six years and earns ~85-90% of it by year twelve (Biggadike 1979: 68 corporate ventures lost money for about four
     * years, took eight to profit and ten to twelve to match their parents' returns).
     */
    public const LEARNING_YEARS = 3.5;

    /** The share of its learned margin a business still falls short by after dt more years of learning. */
    public static function learningLeft(float $gap, float $dt): float
    {
        return $gap * exp(-$dt / self::LEARNING_YEARS);
    }
}
