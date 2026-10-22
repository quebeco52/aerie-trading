<?php

declare(strict_types=1);

namespace App\Service\View;

/**
 * How a builder asks for a figure to be printed. The `|figure` Twig filter (UiExtension::figure) and formatFigure() in
 * assets/js/utils/formatters.js print each kind the same way. Rates, shares and changes arrive as fractions.
 */
enum FigureFormat: string
{
    /** A fraction as a percentage: 0.052 → "5.20%". */
    case Percent = 'percent';
    /** A change as a signed percentage: 0.052 → "+5.20%". */
    case SignedPercent = 'signed_percent';
    /** A change in a rate or share, in percentage points: 0.0123 → "+1.23pp". */
    case SignedPoints = 'signed_points';
    /** A spread in basis points: 0.0123 → "123 bps". */
    case Bps = 'bps';
    /** Dollars abbreviated to M, B or T: 1.54e12 → "$1.54T". */
    case LargeMoney = 'large_money';
    /** A ratio quoted as a multiple: 1.234 → "1.23x". */
    case Multiple = 'multiple';
    /** A span in quarters: 2.5 → "2.5 qtrs". */
    case Quarters = 'quarters';
    /** A span in years: 6.25 → "6.3 yrs". */
    case Years = 'years';
    /** An index level: 1.0423 → "1.042". */
    case Index = 'index';
    /** A change in an index level: 0.0123 → "+0.012". */
    case SignedIndex = 'signed_index';

    /** Decimals printed when the page asks for none. */
    public function decimals(): int
    {
        return match ($this) {
            self::Bps => 0,
            self::Quarters, self::Years => 1,
            self::Index, self::SignedIndex => 3,
            default => 2,
        };
    }

    /** Whether the figure is a change, which carries "+" on a rise and takes the up/down colour. */
    public function isSigned(): bool
    {
        return match ($this) {
            self::SignedPercent, self::SignedPoints, self::SignedIndex => true,
            default => false,
        };
    }
}
