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
     * @return numeric-string Formatted decimal string, as bcmath and a DECIMAL column take it.
     */
    public static function format(float $value, int $scale = 4): string
    {
        $formatted = number_format(is_finite($value) ? $value : 0.0, $scale, '.', '');
        if (!is_numeric($formatted)) {
            throw new \LogicException("number_format produced a non-numeric {$formatted}.");
        }

        return $formatted;
    }

    /**
     * Any reading of a DECIMAL value as the string bcmath takes: a number is formatted at the scale, a numeric string
     * kept as written (exponent notation, which is_numeric accepts and bcmath rejects, rendered out), and an empty
     * value read as zero.
     *
     * @return numeric-string
     *
     * @throws \InvalidArgumentException When the string is not a number: bcmath would reject it a step later.
     */
    public static function of(int|float|string|null $value, int $scale = 4): string
    {
        if ($value === null || $value === '') {
            return self::format(0.0, $scale);
        }
        if (!is_string($value)) {
            return self::format((float) $value, $scale);
        }
        $trimmed = trim($value);
        if (!is_numeric($trimmed)) {
            throw new \InvalidArgumentException("Not a decimal: '{$value}'.");
        }

        return stripos($trimmed, 'e') !== false ? self::format((float) $trimmed, $scale) : $trimmed;
    }
}
