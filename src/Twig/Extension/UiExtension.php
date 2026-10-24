<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Data\District\Institutions;
use App\Service\View\FigureFormat;
use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The shared pieces every page formats a figure with, so a move, a percentage or a source line looks the same on
 * every page. Each figure filter has a live-update twin in assets/js/utils/formatters.js (signedClass, formatPercent,
 * formatPoints, formatBps, formatCurrency, formatLarge, formatMultiple, formatFigure) and must print what it prints,
 * or a figure reformats itself under the reader when the feed repaints it. The house rules are in .agents/FRONTEND.md.
 */
class UiExtension extends AbstractExtension
{
    /** Rendered in place of a figure that is missing or not finite; formatters.js NOT_AVAILABLE. */
    public const NOT_AVAILABLE = '—';

    /** How many ids ui_id() has handed out on this page. */
    private int $issuedIds = 0;

    public function getFilters(): array
    {
        return [
            new TwigFilter('signed_class', [self::class, 'signedClass']),
            new TwigFilter('pct', [self::class, 'percent']),
            new TwigFilter('pp', [self::class, 'points']),
            new TwigFilter('bps', [self::class, 'bps']),
            new TwigFilter('money', [self::class, 'money']),
            new TwigFilter('format_large', [self::class, 'large']),
            new TwigFilter('multiple', [self::class, 'multiple']),
            new TwigFilter('hhi', [self::class, 'hhi']),
            new TwigFilter('figure', [self::class, 'figure']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            // Not 'source': Twig's own source() inlines a template's text (the web profiler draws its icons with it),
            // and an extension function of the same name replaces it.
            new TwigFunction('source_line', [self::class, 'source']),
            new TwigFunction('ui_id', $this->uniqueId(...)),
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
        return self::scaled($fraction, 100, $decimals, $signed, '%');
    }

    /** A change in a rate or share as percentage points (0.0123 → "1.23pp"); with $signed a rise carries "+". */
    public static function points(int|float|string|null $fraction, int $decimals = 2, bool $signed = false): string
    {
        return self::scaled($fraction, 100, $decimals, $signed, 'pp');
    }

    /** A spread, or a move in a rate, in basis points (0.0123 → "123 bps"); with $signed a rise carries "+". */
    public static function bps(int|float|string|null $fraction, int $decimals = 0, bool $signed = false): string
    {
        return self::scaled($fraction, 10_000, $decimals, $signed, ' bps');
    }

    /** A ratio quoted as a multiple, as P/E and leverage are (1.234 → "1.23x"). */
    public static function multiple(int|float|string|null $ratio, int $decimals = 2): string
    {
        return self::scaled($ratio, 1, $decimals, false, 'x');
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
     * A market's Herfindahl index on the 0-10,000 scale the Fair Trading Board quotes, from its summed squared shares.
     */
    public static function hhi(int|float|string|null $sumOfSquaredShares): string
    {
        return self::scaled($sumOfSquaredShares, 10_000, 0, false, '');
    }

    /**
     * A magnitude abbreviated to T, B or M, with the prefix inside the sign ("-$1.20B"), so a negative equity reads
     * beside its peers rather than in full; with $signed a rise carries "+".
     */
    public static function large(int|float|string|null $amount, string $prefix = '', bool $signed = false): string
    {
        $number = self::finite($amount);
        if ($number === null) {
            return self::NOT_AVAILABLE;
        }

        $abs = abs($number);
        [$divisor, $suffix] = match (true) {
            $abs >= 1_000_000_000_000 => [1_000_000_000_000, 'T'],
            $abs >= 1_000_000_000 => [1_000_000_000, 'B'],
            $abs >= 1_000_000 => [1_000_000, 'M'],
            default => [1, ''],
        };
        $sign = $number < 0 ? '-' : ($signed && $number > 0 ? '+' : '');

        return $sign . $prefix . number_format($abs / $divisor, 2) . $suffix;
    }

    /** A figure in the form its builder asked for (FigureFormat); $decimals overrides the form's own. */
    public static function figure(int|float|string|null $value, FigureFormat|string $format, ?int $decimals = null): string
    {
        $format = $format instanceof FigureFormat ? $format : FigureFormat::from($format);
        $decimals ??= $format->decimals();

        return match ($format) {
            FigureFormat::Percent, FigureFormat::SignedPercent => self::percent($value, $decimals, $format->isSigned()),
            FigureFormat::SignedPoints => self::points($value, $decimals, true),
            FigureFormat::Bps => self::bps($value, $decimals),
            FigureFormat::LargeMoney => self::large($value, '$'),
            FigureFormat::Multiple => self::multiple($value, $decimals),
            FigureFormat::Quarters => self::scaled($value, 1, $decimals, false, ' qtrs'),
            FigureFormat::Years => self::scaled($value, 1, $decimals, false, ' yrs'),
            FigureFormat::Index, FigureFormat::SignedIndex => self::scaled($value, 1, $decimals, $format->isSigned(), ''),
        };
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

    /**
     * An element id no other ui_id() call on the page shares ("tip-3"), for an aria reference between two elements
     * a macro draws, such as a term and its tooltip.
     */
    public function uniqueId(string $prefix): string
    {
        return $prefix . '-' . ++$this->issuedIds;
    }

    /** $value × $scale to $decimals places with its unit; the dash when missing, "+" on a rise when $signed. */
    private static function scaled(int|float|string|null $value, int $scale, int $decimals, bool $signed, string $unit): string
    {
        $number = self::finite($value);
        if ($number === null) {
            return self::NOT_AVAILABLE;
        }

        $rounded = round($number * $scale, $decimals);

        return ($signed && $rounded > 0 ? '+' : '') . number_format($rounded, $decimals) . $unit;
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
