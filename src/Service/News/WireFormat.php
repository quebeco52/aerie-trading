<?php

declare(strict_types=1);

namespace App\Service\News;

use App\Twig\Extension\UiExtension;

/**
 * Figures in the news a desk writes: the page's own formats, so a story and the page beside it print one amount the
 * same way, and a small amount reads in millions rather than as "$0.04B".
 */
final class WireFormat
{
    /** A dollar amount abbreviated to T, B or M: 4.0e7 → "$40.00M"; with $signed a gain carries "+". */
    public static function money(float $amount, bool $signed = false): string
    {
        return UiExtension::large($amount, '$', $signed);
    }

    /** A share count, abbreviated from a million up: "410.29M", "45,000". */
    public static function shares(float $shares): string
    {
        return $shares >= 1_000_000 ? UiExtension::large($shares) : number_format($shares);
    }
}
