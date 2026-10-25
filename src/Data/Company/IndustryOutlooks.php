<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * How unsettled each industry's long-run demand is (IndustryOutlook): how fast its true level drifts from the path its
 * model expects, and how fast the public learns where it has gone. An industry not listed takes the defaults.
 */
final class IndustryOutlooks
{
    // --- Outlook Uncertainty ---
    /** Annual volatility of an industry's true long-run demand around its expected path, in log: none yet. */
    public const DEFAULT_DRIFT_VOL = 0.0;
    /** Years the settled public estimate takes to close half its gap to the truth (2 years). */
    public const DEFAULT_LEARNING_HALF_LIFE_YEARS = 2.0;

    /** @var array<string, array{drift_vol?: float, learning_half_life?: float}> Industry => its own settings. */
    private const BY_INDUSTRY = [];

    public static function driftVol(string $industry): float
    {
        return self::BY_INDUSTRY[$industry]['drift_vol'] ?? self::DEFAULT_DRIFT_VOL;
    }

    public static function learningHalfLife(string $industry): float
    {
        return self::BY_INDUSTRY[$industry]['learning_half_life'] ?? self::DEFAULT_LEARNING_HALF_LIFE_YEARS;
    }
}
