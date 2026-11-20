<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * How capital-light or capital-heavy a firm's business is relative to its industry: five steps spaced geometrically by
 * STEP_FACTOR (1.25x sales per dollar of invested capital per step). Lighter means higher turnover and a lower opening
 * operating margin for the same return on capital.
 */
enum CapitalIntensity: string
{
    // --- Turnover Step ---
    /**
     * Multiplicative turnover factor per step on the ladder: one step lighter turns over 1.25x the sales per unit of
     * capital (five steps span 2.44x).
     */
    public const STEP_FACTOR = 1.25;

    case VeryLight = 'very_light';
    case Light = 'light';
    case Typical = 'typical';
    case Heavy = 'heavy';
    case VeryHeavy = 'very_heavy';

    /** Signed steps from the industry norm (positive = lighter, higher turnover). */
    public function steps(): int
    {
        return match ($this) {
            self::VeryLight => 2,
            self::Light => 1,
            self::Typical => 0,
            self::Heavy => -1,
            self::VeryHeavy => -2,
        };
    }

    /** Multiplier applied to the industry's opening turnover (revenue / invested capital). */
    public function turnoverMultiplier(): float
    {
        return self::STEP_FACTOR ** $this->steps();
    }

    /** The step as the District's pages name it. */
    public function label(): string
    {
        return match ($this) {
            self::VeryLight => 'Very light',
            self::Light => 'Light',
            self::Typical => 'Typical',
            self::Heavy => 'Heavy',
            self::VeryHeavy => 'Very heavy',
        };
    }
}
