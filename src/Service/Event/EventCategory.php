<?php

declare(strict_types=1);

namespace App\Service\Event;

/**
 * Which card an event type is presented as.
 *
 * The engines publish free-form type strings: a deal is typed by the business model that made it
 * (StrategyInterface::getAcquisitionType), a rating change by the debt engine. The page, the live feed and
 * the district all route through this one table, so a type an engine starts publishing is either listed
 * here or visibly general, never styled one way on load and another way live.
 */
final class EventCategory
{
    // --- Routing ---

    /** Every type the engines publish, keyed to the card it renders as; anything else is general news. */
    private const TYPES = [
        'EARNINGS' => 'earnings',
        'SHOCK' => 'shock',
        'SPLIT' => 'split',
        'REVERSE_SPLIT' => 'split',
        'REVSPLIT' => 'split',
        'CREDIT_UPGRADE' => 'debt',
        'CREDIT_DOWNGRADE' => 'debt',
        'RATING_UPGRADE' => 'debt',
        'RATING_DOWNGRADE' => 'debt',
        'DEBT' => 'debt',
        'ACQUISITION' => 'mna',
        'STRATEGIC ACQUISITION' => 'mna',
        'CONGLOMERATE EXPANSION' => 'mna',
        'LEVERAGED BUYOUT' => 'mna',
        'HOSTILE TAKEOVER' => 'mna',
        'MERGER' => 'mna',
        'STOCK-FOR-STOCK MERGER' => 'mna',
        'DIVESTITURE' => 'mna',
        'ANALYST' => 'analyst',
        'GUIDANCE' => 'analyst',
        'BANKRUPTCY' => 'bankruptcy',
        'REORGANIZATION' => 'reorganization',
        'DISTRICT' => 'district',
        'INDEX' => 'index',
        'DIVIDEND' => 'income',
        'MANAGEMENT CHANGE' => 'governance',
        'ECONOMY' => 'economy',
        'GOVERNMENT' => 'government',
    ];

    /** The card a published type renders as; 'general' for a type no presenter claims. */
    public static function forType(string $type): string
    {
        return self::TYPES[strtoupper(trim($type))] ?? 'general';
    }

    /**
     * Every published type that renders as one of the given cards, for selecting stories by card in a query.
     *
     * @param  list<string> $categories
     * @return list<string>
     */
    public static function typesIn(array $categories): array
    {
        return array_keys(array_filter(self::TYPES, static fn(string $category): bool => in_array($category, $categories, true)));
    }
}
