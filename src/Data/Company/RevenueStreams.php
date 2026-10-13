<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * Display names for the revenue-stream keys the business models report under.
 */
final class RevenueStreams
{
    /** Keys whose words misdescribe the figure. The bank's stream is gross interest earned; funding cost comes off below it. */
    private const LABELS = [
        'net_interest_income' => 'Interest income',
    ];

    /** Sentence-case name of a stream key ("aftermarket_services" reads "Aftermarket services"). */
    public static function label(string $streamKey): string
    {
        return self::LABELS[$streamKey] ?? ucfirst(str_replace('_', ' ', $streamKey));
    }
}
