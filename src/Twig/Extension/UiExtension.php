<?php

namespace App\Twig\Extension;

use App\Data\District\Institutions;
use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The shared pieces every page formats a figure with, so a move, a percentage or a source line looks the same on
 * every page. assets/js/utils/formatters.js signedClass() and formatPercent() are the live-update twins of the first
 * two and must stay in step with them. The house rules are in .agents/FRONTEND.md.
 */
class UiExtension extends AbstractExtension
{
    /** Rendered in place of a figure that is missing or not finite; formatters.js NOT_AVAILABLE. */
    public const NOT_AVAILABLE = '—';

    public function getFilters(): array
    {
        return [
            new TwigFilter('signed_class', [self::class, 'signedClass']),
            new TwigFilter('pct', [self::class, 'percent']),
            new TwigFilter('money', [self::class, 'money']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            // Not 'source': Twig's own source() inlines a template's text (the web profiler draws its icons with it),
            // and an extension function of the same name replaces it.
            new TwigFunction('source_line', [self::class, 'source']),
        ];
    }

    /**
     * The text colour for a signed figure: up is secondary, down is tertiary, unchanged is the neutral tone and an
     * unknown figure the faint one, as exchange boards show an unchanged price in grey.
     */
    public static function signedClass(int|float|string|null $value): string
    {
        $number = self::finite($value);

        return match (true) {
            $number === null => 'text-on-surface-faint',
            $number > 0 => 'text-secondary',
            $number < 0 => 'text-tertiary',
            default => 'text-on-surface-variant',
        };
    }

    /**
     * A fraction as a percentage (0.0523 → "5.23%"); with $signed a rise carries "+". A missing value prints the dash
     * rather than a false "0.00%".
     */
    public static function percent(int|float|string|null $fraction, int $decimals = 2, bool $signed = false): string
    {
        $number = self::finite($fraction);
        if ($number === null) {
            return self::NOT_AVAILABLE;
        }

        $rounded = round($number * 100, $decimals);

        return ($signed && $rounded > 0 ? '+' : '') . number_format($rounded, $decimals) . '%';
    }

    /**
     * A dollar amount with the sign outside the symbol ("-$1,234.56"), as formatters.js formatCurrency() repaints it on
     * the first market frame; with $signed a gain carries "+".
     */
    public static function money(int|float|string|null $amount, int $decimals = 2, bool $signed = false): string
    {
        $number = self::finite($amount);
        if ($number === null) {
            return self::NOT_AVAILABLE;
        }

        $rounded = round($number, $decimals);
        $sign = $rounded < 0 ? '-' : ($signed && $rounded > 0 ? '+' : '');

        return $sign . '$' . number_format(abs($rounded), $decimals);
    }

    /**
     * "Source: Statistical Office; Credit Registry" from Institutions::PUBLISHERS ids, so a page cannot cite a body
     * by a name the rest of the site does not use.
     */
    public static function source(string ...$publishers): string
    {
        $names = array_map(static function (string $id): string {
            return Institutions::PUBLISHERS[$id] ?? throw new RuntimeError(sprintf('Unknown publisher "%s"; add it to Institutions::PUBLISHERS.', $id));
        }, $publishers);

        return 'Source: ' . implode('; ', $names);
    }

    private static function finite(int|float|string|null $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }
}
