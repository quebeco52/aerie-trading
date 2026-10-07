<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/** `seconds|duration_words`: a real-time span as a reader says it ("3 days", "5 hours", "20 minutes"). */
final class DurationExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('duration_words', self::words(...))];
    }

    public static function words(float|int $seconds): string
    {
        $seconds = max(0.0, (float) $seconds);

        foreach ([[86400, 'day'], [3600, 'hour'], [60, 'minute']] as [$unit, $name]) {
            if ($seconds >= $unit) {
                $n = (int) round($seconds / $unit);

                return $n . ' ' . $name . ($n === 1 ? '' : 's');
            }
        }

        return 'a minute';
    }
}
