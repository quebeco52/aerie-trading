<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * Display names for the revenue-stream keys the business models report under.
 */
final class RevenueStreams
{
    /**
     * Keys whose words misdescribe the figure. The bank's stream is gross interest earned; funding cost comes off below
     * it.
     */
    private const LABELS = [
        'net_interest_income' => 'Interest income',
    ];

    /** Streams that carry a lender's interest on its book: a bank's, a card lender's, a mortgage lender's. */
    public const LENDER_INTEREST_STREAMS = ['net_interest_income', 'lending', 'direct_lending'];

    /** Sentence-case name of a stream key ("aftermarket_services" reads "Aftermarket services"). */
    public static function label(string $streamKey): string
    {
        $entered = Segments::enteredIndustry($streamKey);

        return self::LABELS[$streamKey] ?? ($entered === null ? ucfirst(str_replace('_', ' ', $streamKey)) : IndustryAdjacency::label($entered) ?? $entered);
    }
}
