<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The Aerie Diet: the District's legislature, its six parties and where they stand.
 *
 * Each party is defined by one question -- the size of the state, how open the District is, or how far the Council's
 * technocrats should be trusted -- and holds a fixed position on it; on the other two it drifts between elections
 * (App\Service\Macro\Subsystem\DistrictPoliticsSubsystem). The Diet seated at the founding is the lore's. Every
 * party-keyed map in the macro state is keyed and ordered by PARTIES.
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
    /** Technocratic governance: the Council's loyalists in the Diet, against the populists. */
    public const CHARTISTS = 'chartists';
    /** Financial populism: the retail shareholder's party, against the cartels and the technocrats who keep them. */
    public const COMMON_LOT = 'common_lot';
    /** The parties in the Diet, in the order every party-keyed map is written. */
    public const PARTIES = [self::CIVIC, self::VANGUARD, self::IRON_HARBOR, self::EXCHANGE, self::CHARTISTS, self::COMMON_LOT];
    /** Display names. */
    public const PARTY_NAMES = [
        self::CIVIC => 'Civic Front',
        self::VANGUARD => 'The Vanguard',
        self::IRON_HARBOR => 'Iron Harbor Coalition',
        self::EXCHANGE => 'Exchange Party',
        self::CHARTISTS => 'The Chartists',
        self::COMMON_LOT => 'The Common Lot',
    ];
    /** Parties that never vote to remove a councillor: the Chartists are the Council's own. */
    public const COUNCIL_LOYALISTS = [self::CHARTISTS];

    // --- Policy Space ---
    /** Size-of-state axis: +1 is the largest state, -1 the smallest. */
    public const AXIS_STATE = 'state';
    /** Openness axis: +1 is fully open to trade, capital and labour, -1 fully closed. */
    public const AXIS_OPENNESS = 'openness';
    /** Council axis, the people-vs-elite scale of the Chapel Hill expert survey: +1 defers to the technocrats, -1 is populist. */
    public const AXIS_COUNCIL = 'council';
    /** The axes of the policy space, in the order every position is written. */
    public const AXES = [self::AXIS_STATE, self::AXIS_OPENNESS, self::AXIS_COUNCIL];
    /** The axis each party is defined by; it never moves there. */
    public const PRIMARY_AXIS = [
        self::CIVIC => self::AXIS_STATE,
        self::VANGUARD => self::AXIS_STATE,
        self::IRON_HARBOR => self::AXIS_OPENNESS,
        self::EXCHANGE => self::AXIS_OPENNESS,
        self::CHARTISTS => self::AXIS_COUNCIL,
        self::COMMON_LOT => self::AXIS_COUNCIL,
    ];
    /** Each party's fixed position on its defining axis: the two big parties moderate, the others at the ends of theirs. */
    public const PRIMARY_POSITION = [
        self::CIVIC => 0.6,
        self::VANGUARD => -0.6,
        self::IRON_HARBOR => -0.8,
        self::EXCHANGE => 0.8,
        self::CHARTISTS => 0.8,
        self::COMMON_LOT => -0.8,
    ];
    /** Each party's place at the founding: the big two establishment, the Harbor populist and slightly big-state, the Chartists slightly open, the Common Lot slightly big-state. */
    public const SEED_POSITIONS = [
        self::CIVIC => [self::AXIS_STATE => 0.6, self::AXIS_OPENNESS => -0.1, self::AXIS_COUNCIL => 0.3],
        self::VANGUARD => [self::AXIS_STATE => -0.6, self::AXIS_OPENNESS => 0.0, self::AXIS_COUNCIL => 0.3],
        self::IRON_HARBOR => [self::AXIS_STATE => 0.1, self::AXIS_OPENNESS => -0.8, self::AXIS_COUNCIL => -0.4],
        self::EXCHANGE => [self::AXIS_STATE => 0.0, self::AXIS_OPENNESS => 0.8, self::AXIS_COUNCIL => 0.4],
        self::CHARTISTS => [self::AXIS_STATE => 0.0, self::AXIS_OPENNESS => 0.2, self::AXIS_COUNCIL => 0.8],
        self::COMMON_LOT => [self::AXIS_STATE => 0.3, self::AXIS_OPENNESS => 0.0, self::AXIS_COUNCIL => -0.8],
    ];

    // --- Forming a Government ---
    /** A round in which the party leading the talks seeks a cabinet with a majority of its own. */
    public const ROUND_MAJORITY = 1;
    /** A round in which it may also offer a minority cabinet that other parties support from outside. */
    public const ROUND_SUPPORT = 2;
    /** The rounds after both big parties have had their two: the lead passing between them, either cabinet on offer. */
    public const ROUND_RUNOFF = 3;

    // --- The Chamber ---
    /** Seats in the Diet. */
    public const SEATS = 300;
    /** Seats that pass a budget or a law. */
    public const MAJORITY_SEATS = 151;
    /** Seats that can remove a councillor, which the Diet has never assembled: three quarters of the Diet. */
    public const SUPERMAJORITY_SEATS = 225;
    /** Seats at the founding. */
    public const SEED_SEATS = [
        self::CIVIC => 90.0,
        self::VANGUARD => 95.0,
        self::IRON_HARBOR => 40.0,
        self::EXCHANGE => 40.0,
        self::CHARTISTS => 20.0,
        self::COMMON_LOT => 15.0,
    ];
    /** Vote shares at the founding: the founding seats over the Diet. */
    public const SEED_VOTE_SHARES = [
        self::CIVIC => 90.0 / 300.0,
        self::VANGUARD => 95.0 / 300.0,
        self::IRON_HARBOR => 40.0 / 300.0,
        self::EXCHANGE => 40.0 / 300.0,
        self::CHARTISTS => 20.0 / 300.0,
        self::COMMON_LOT => 15.0 / 300.0,
    ];
    /** The founding cabinet, 1.0 for a member: the likeliest government of the Vanguard's first bid for a majority (CoalitionFormation). */
    public const SEED_COALITION = [
        self::CIVIC => 0.0,
        self::VANGUARD => 1.0,
        self::IRON_HARBOR => 0.0,
        self::EXCHANGE => 1.0,
        self::CHARTISTS => 1.0,
        self::COMMON_LOT => 0.0,
    ];
    /** The founding cabinet governs with a majority of its own: no party supports it from outside. */
    public const SEED_SUPPORT = [
        self::CIVIC => 0.0,
        self::VANGUARD => 0.0,
        self::IRON_HARBOR => 0.0,
        self::EXCHANGE => 0.0,
        self::CHARTISTS => 0.0,
        self::COMMON_LOT => 0.0,
    ];

    /**
     * A party's place in the policy space: fixed on its defining axis, where the given positions put it on the others,
     * and at its founding place on any axis they leave out.
     *
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @return array{state: float, openness: float, council: float}
     */
    public static function position(string $party, array $positions): array
    {
        $point = [];
        foreach (self::AXES as $axis) {
            $point[$axis] = (float) ($positions[$party][$axis] ?? self::SEED_POSITIONS[$party][$axis]);
        }
        $point[self::PRIMARY_AXIS[$party]] = self::PRIMARY_POSITION[$party];

        return $point;
    }

    /**
     * The parties a membership map marks, in party order.
     *
     * @param array<string, float> $coalition 1.0 for a member.
     * @return list<string>
     */
    public static function governingParties(array $coalition): array
    {
        return array_values(array_filter(self::PARTIES, static fn(string $party): bool => ($coalition[$party] ?? 0.0) > 0.5));
    }

    /**
     * A membership map for a list of parties: 1.0 for each member, 0.0 for every other party.
     *
     * @param list<string> $members Parties.
     * @return array<string, float>
     */
    public static function membership(array $members): array
    {
        $map = [];
        foreach (self::PARTIES as $party) {
            $map[$party] = in_array($party, $members, true) ? 1.0 : 0.0;
        }

        return $map;
    }
}
