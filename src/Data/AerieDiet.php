<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The Aerie Diet: the District's legislature, its four parties and where they stand.
 *
 * Each party is defined by one question -- the size of the state, or how open the District is -- and holds a fixed
 * position on it; on the other question it drifts between elections (App\Service\Macro\Subsystem\DistrictPoliticsSubsystem).
 * The Diet seated at the founding is the lore's. Every party-keyed map in the macro state is keyed and ordered by PARTIES.
 */
final class AerieDiet
{
    // --- Parties ---
    /** Big government: public spending as the engine of the economy. */
    public const CIVIC = 'civic';
    /** Small government: privatise, cut the tax take. */
    public const VANGUARD = 'vanguard';
    /** Closed economy: tariffs, immigration quotas, curbs on foreign buyers. */
    public const IRON_HARBOR = 'iron_harbor';
    /** Open economy: free trade, open borders to capital and labour. */
    public const EXCHANGE = 'exchange';
    /** The parties in the Diet, in the order every party-keyed map is written. */
    public const PARTIES = [self::CIVIC, self::VANGUARD, self::IRON_HARBOR, self::EXCHANGE];
    /** Display names. */
    public const PARTY_NAMES = [
        self::CIVIC => 'Civic Front',
        self::VANGUARD => 'The Vanguard',
        self::IRON_HARBOR => 'Iron Harbor Coalition',
        self::EXCHANGE => 'Exchange Party',
    ];

    // --- Policy Space ---
    /** Size-of-state axis: +1 is the largest state, -1 the smallest. */
    public const AXIS_STATE = 'state';
    /** Openness axis: +1 is fully open to trade, capital and labour, -1 fully closed. */
    public const AXIS_OPENNESS = 'openness';
    /** The axis each party is defined by; it never moves there. */
    public const PRIMARY_AXIS = [
        self::CIVIC => self::AXIS_STATE,
        self::VANGUARD => self::AXIS_STATE,
        self::IRON_HARBOR => self::AXIS_OPENNESS,
        self::EXCHANGE => self::AXIS_OPENNESS,
    ];
    /** Each party's fixed position on its defining axis. */
    public const PRIMARY_POSITION = [
        self::CIVIC => 0.8,
        self::VANGUARD => -0.8,
        self::IRON_HARBOR => -0.8,
        self::EXCHANGE => 0.8,
    ];
    /** Each party's position on its other axis at the founding: the Front very slightly closed, the Harbor slightly big-state, the other two neutral. */
    public const SEED_SECONDARY_POSITIONS = [
        self::CIVIC => -0.1,
        self::VANGUARD => 0.0,
        self::IRON_HARBOR => 0.1,
        self::EXCHANGE => 0.0,
    ];

    // --- The Chamber ---
    /** Seats in the Diet. */
    public const SEATS = 250;
    /** Seats that pass a budget or a law. */
    public const MAJORITY_SEATS = 126;
    /** Seats that can remove a councillor, which the Diet has never assembled: three quarters of the Diet. */
    public const SUPERMAJORITY_SEATS = 188;
    /** Seats at the founding. */
    public const SEED_SEATS = [
        self::CIVIC => 85.0,
        self::VANGUARD => 95.0,
        self::IRON_HARBOR => 35.0,
        self::EXCHANGE => 35.0,
    ];
    /** Vote shares at the founding: the founding seats over the Diet. */
    public const SEED_VOTE_SHARES = [
        self::CIVIC => 0.34,
        self::VANGUARD => 0.38,
        self::IRON_HARBOR => 0.14,
        self::EXCHANGE => 0.14,
    ];
    /** The founding government, 1.0 for a member: what DistrictPoliticsSubsystem::formCoalition() makes of the founding Diet. */
    public const SEED_COALITION = [
        self::CIVIC => 0.0,
        self::VANGUARD => 1.0,
        self::IRON_HARBOR => 0.0,
        self::EXCHANGE => 1.0,
    ];

    /**
     * A party's place in the policy space: fixed on its defining axis, the given position on the other.
     *
     * @return array{state: float, openness: float}
     */
    public static function position(string $party, float $secondary): array
    {
        $primary = self::PRIMARY_POSITION[$party];

        return self::PRIMARY_AXIS[$party] === self::AXIS_STATE
            ? [self::AXIS_STATE => $primary, self::AXIS_OPENNESS => $secondary]
            : [self::AXIS_STATE => $secondary, self::AXIS_OPENNESS => $primary];
    }

    /**
     * The parties a membership map marks as governing, in party order.
     *
     * @param array<string, float> $coalition 1.0 for a governing party.
     * @return list<string>
     */
    public static function governingParties(array $coalition): array
    {
        return array_values(array_filter(self::PARTIES, static fn(string $party): bool => ($coalition[$party] ?? 0.0) > 0.5));
    }
}
