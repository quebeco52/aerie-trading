<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Macro\MacroEngine;

/**
 * Where consumer sentiment sits for a given output gap with no mood in it, for fixtures that need a confidence reading
 * consistent with the cycle they set up.
 */
final class SentimentOnTheCycle
{
    /** Index points per unit of output gap the engine's sentiment carries through its fundamentals (slope of the sentiment EMA on the gap EMA, 48 seeds, no fund). */
    public const POINTS_PER_GAP = 288.0;

    public static function at(float $outputGap): float
    {
        return MacroEngine::SENTIMENT_TREND_LEVEL + (self::POINTS_PER_GAP * $outputGap);
    }
}
