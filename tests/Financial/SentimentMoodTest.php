<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * The mood consumer sentiment carries beside its fundamentals is a zero-mean process with the spread and persistence
 * its constants give it, and the index is fundamentals plus mood.
 */
class SentimentMoodTest extends TestCase
{
    public function testMoodHasItsSpreadAndPersistence(): void
    {
        mt_srand(20261012);
        $subsystem = new AssetMarketSubsystem(new MathUtility());
        $state = new MacroState();
        $dt = 1.0 / 12.0;

        $moods = [];
        for ($month = 0; $month < 24000; $month++) {
            $subsystem->calculateConsumerSentiment($state, $dt);
            $this->assertEqualsWithDelta(
                max(AssetMarketSubsystem::SENTIMENT_FLOOR, min(AssetMarketSubsystem::SENTIMENT_CEILING, MacroConstants::SENTIMENT_TREND_LEVEL + $state->consumerSentimentMood)),
                $state->consumerSentimentIndex,
                1e-9,
                'With neutral fundamentals the index is the trend level plus the mood, inside the survey\'s range.',
            );
            $moods[] = $state->consumerSentimentMood;
        }

        $n = count($moods);
        $mean = array_sum($moods) / $n;
        $variance = array_sum(array_map(static fn(float $m): float => ($m - $mean) ** 2, $moods)) / $n;
        $lagged = 0.0;
        for ($i = 1; $i < $n; $i++) {
            $lagged += ($moods[$i] - $mean) * ($moods[$i - 1] - $mean);
        }
        $autocorrelation = $lagged / ($n - 1) / $variance;

        // 2,000 years of months, an effective sample of ~3,000: standard errors ~0.15 points on the mean and the sd.
        $this->assertEqualsWithDelta(0.0, $mean, 0.6);
        $this->assertEqualsWithDelta(AssetMarketSubsystem::SENTIMENT_MOOD_SD, sqrt($variance), 0.5);
        $this->assertEqualsWithDelta(exp(-AssetMarketSubsystem::SENTIMENT_MOOD_MEAN_REVERSION / 12.0), $autocorrelation, 0.02);
    }
}
