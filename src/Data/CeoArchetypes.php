<?php

namespace App\Data;

class CeoArchetypes
{
    public const OPPORTUNIST = 'opportunist';
    public const EMPIRE_BUILDER = 'empire_builder';
    public const CANNIBAL = 'cannibal';
    public const YIELD_KING = 'yield_king';
    public const CONSERVATIVE = 'conservative';

    public const ARCHETYPES = [
        self::OPPORTUNIST,
        self::EMPIRE_BUILDER,
        self::CANNIBAL,
        self::YIELD_KING,
        self::CONSERVATIVE,
    ];

    /**
     * Returns a random CEO archetype, heavily weighted towards Opportunist (the standard CEO).
     */
    public static function getRandomArchetype(): string
    {
        $pool = [
            self::OPPORTUNIST, self::OPPORTUNIST, self::OPPORTUNIST, self::OPPORTUNIST, self::OPPORTUNIST,
            self::EMPIRE_BUILDER, self::CANNIBAL, self::YIELD_KING, self::CONSERVATIVE, self::CONSERVATIVE
        ];
        
        return $pool[array_rand($pool)];
    }

    /**
     * Returns a human-readable title for the archetype.
     */
    public static function getTitle(string $archetype): string
    {
        return match ($archetype) {
            self::OPPORTUNIST => 'an Opportunist',
            self::EMPIRE_BUILDER => 'an Empire Builder',
            self::CANNIBAL => 'a Cannibal',
            self::YIELD_KING => 'a Yield King',
            self::CONSERVATIVE => 'a Conservative',
            default => 'an Opportunist',
        };
    }

    /**
     * Tooltip descriptions of how this archetype manages capital and operations.
     */
    public const DESCRIPTIONS = [
        self::OPPORTUNIST => 'The Opportunist follows standard data-driven capital allocation, balancing growth with shareholder returns based on market conditions.',
        self::EMPIRE_BUILDER => 'The Empire Builder ignores market saturation and aggressively pursues organic CapEx and massive Leveraged Buyouts (M&A) at any cost.',
        self::CANNIBAL => 'The Cannibal is obsessed with inflating EPS. They ignore valuation caps and mechanically execute massive share buybacks down to their last dollar.',
        self::YIELD_KING => 'The Yield King treats the dividend as sacred. They force a massive minimum payout ratio and refuse to cut the dividend unless facing imminent bankruptcy.',
        self::CONSERVATIVE => 'The Conservative is paranoid about debt. They hoard massive cash reserves and trigger aggressive deleveraging sweeps to pay down debt early.',
    ];

    /**
     * Factory method to return the concrete strategy object for an archetype.
     */
    public static function getStrategy(string $archetype): \App\Service\Archetype\ArchetypeInterface
    {
        return match ($archetype) {
            self::OPPORTUNIST => new \App\Service\Archetype\OpportunistArchetype(),
            self::EMPIRE_BUILDER => new \App\Service\Archetype\EmpireBuilderArchetype(),
            self::CANNIBAL => new \App\Service\Archetype\CannibalArchetype(),
            self::YIELD_KING => new \App\Service\Archetype\YieldKingArchetype(),
            self::CONSERVATIVE => new \App\Service\Archetype\ConservativeArchetype(),
            default => new \App\Service\Archetype\OpportunistArchetype(),
        };
    }
}
