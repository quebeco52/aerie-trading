<?php

namespace App\Twig\Extension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class NumberFormatExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('format_large', [$this, 'formatLargeNumber']),
        ];
    }

    /**
     * Abbreviates a magnitude to T/B/M, carrying the sign outside the currency prefix ('-$1.20B').
     *
     * This MUST stay byte-identical in output to formatLarge() in assets/js/utils/formatters.js.
     * Several figures are rendered here on page load and then repainted by that function on the first
     * market frame (#stat-mkt-cap, #stat-equity); any divergence shows up as the number reformatting
     * itself under the reader. Scaling on the absolute value is what makes a negative equity read as
     * -$3.20B rather than as a full-width -$3,200,000,000.00 beside its peers.
     *
     * @param float|string $number The magnitude to abbreviate.
     * @param string       $prefix Optional currency prefix, applied inside the sign.
     */
    public function formatLargeNumber(float|string $number, string $prefix = ''): string
    {
        $num = (float) $number;
        $isNegative = $num < 0;
        $abs = abs($num);

        if ($abs >= 1_000_000_000_000) {
            $formatted = number_format($abs / 1_000_000_000_000, 2) . 'T';
        } elseif ($abs >= 1_000_000_000) {
            $formatted = number_format($abs / 1_000_000_000, 2) . 'B';
        } elseif ($abs >= 1_000_000) {
            $formatted = number_format($abs / 1_000_000, 2) . 'M';
        } else {
            $formatted = number_format($abs, 2);
        }

        return $isNegative ? '-' . $prefix . $formatted : $prefix . $formatted;
    }
}
