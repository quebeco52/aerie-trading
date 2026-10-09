<?php

declare(strict_types=1);

namespace App\Service\Math;

/**
 * Decimal strings for DECIMAL columns: no exponent notation, and NaN or infinity stored as zero.
 */
final class Decimal
{
    /**
     * Formats a float into a non-scientific decimal string suitable for database storage.
     * Prevents exponential notation (e.g., 1.0E-5) and guards against NaN or infinite values.
     *
     * @param float $value The numeric value to format.
     * @param int   $scale The number of decimal places (default 4).
     * @return string Formatted decimal string.
     */
    public static function format(float $value, int $scale = 4): string
    {
        if (is_nan($value) || is_infinite($value)) {
            return number_format(0.0, $scale, '.', '');
        }

        return number_format($value, $scale, '.', '');
    }
}
