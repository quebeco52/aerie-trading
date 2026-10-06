<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The Aerie Diet: the District's legislature, its eight parties, where they stand, and the two blocs they form.
 *
 * Each party is defined by one question -- the size of the state, how open the District is, how far the Council's
 * technocrats should be trusted, or whether the environment comes before growth -- or, for New Horizon, by the first
 * two together, and holds a fixed position on it; on the others it strays between elections and is pulled back toward
 * its home
 * (App\Service\Politics\PoliticsEngine). The Diet seated at the founding is the lore's. Every
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
    /** Nature and coastal commons: protect the foreshores, headlands, salt marshes and public parkland. */
    public const TIDELINE = 'tideline';
    /** New money and innovation: FinTech, quantitative trading desks and venture capital against legacy cartels. */
    public const NEW_HORIZON = 'new_horizon';
    /** The parties in the Diet, in the order every party-keyed map is written. */
    public const PARTIES = [self::CIVIC, self::VANGUARD, self::IRON_HARBOR, self::EXCHANGE, self::CHARTISTS, self::COMMON_LOT, self::TIDELINE, self::NEW_HORIZON];
    /** Display names. */
    public const PARTY_NAMES = [
        self::CIVIC => 'Civic Front',
        self::VANGUARD => 'The Vanguard',
        self::IRON_HARBOR => 'Iron Harbor Coalition',
        self::EXCHANGE => 'Exchange Party',
        self::CHARTISTS => 'The Chartists',
        self::COMMON_LOT => 'The Common Lot',
        self::TIDELINE => 'The Tideline Accord',
        self::NEW_HORIZON => 'New Horizon',
    ];
    /** Parties that never vote to remove a councillor: the Chartists are the Council's own. */
    public const COUNCIL_LOYALISTS = [self::CHARTISTS];
    /** The questions the blocs divide on, the economic ones: the Council question cuts across both blocs, as populism does across Scandinavia's red and blue. */
    public const BLOC_AXES = [self::AXIS_STATE, self::AXIS_OPENNESS];

    // --- Policy Space ---
    /** Size-of-state axis: +1 is the largest state, -1 the smallest. */
    public const AXIS_STATE = 'state';
    /** Openness axis: +1 is fully open to trade, capital and labour, -1 fully closed. */
    public const AXIS_OPENNESS = 'openness';
    /** Council axis, the people-vs-elite scale of the Chapel Hill expert survey: +1 defers to the technocrats, -1 is populist. */
    public const AXIS_COUNCIL = 'council';
    /** Environment axis, the Chapel Hill expert survey's environment-versus-growth scale: +1 puts the environment first, -1 growth. */
    public const AXIS_ENVIRONMENT = 'environment';
    /** The axes of the policy space, in the order every position is written. */
    public const AXES = [self::AXIS_STATE, self::AXIS_OPENNESS, self::AXIS_COUNCIL, self::AXIS_ENVIRONMENT];
    /** Each party's fixed position on the axes it is defined by, where it never moves: the two big parties moderate, the others at the ends of theirs, New Horizon on the diagonal between them. */
    public const FIXED_POSITIONS = [
        self::CIVIC => [self::AXIS_STATE => 0.6],
        self::VANGUARD => [self::AXIS_STATE => -0.6],
        self::IRON_HARBOR => [self::AXIS_OPENNESS => -0.8],
        self::EXCHANGE => [self::AXIS_OPENNESS => 0.8],
        self::CHARTISTS => [self::AXIS_COUNCIL => 0.8],
        self::COMMON_LOT => [self::AXIS_COUNCIL => -0.8],
        self::TIDELINE => [self::AXIS_ENVIRONMENT => 0.8],
        self::NEW_HORIZON => [self::AXIS_STATE => -0.5, self::AXIS_OPENNESS => 0.7],
    ];
    /** Each party's home, where it stood at the founding and is pulled back toward between elections; openness and the Council axis run together as they do in Western Europe (Chapel Hill 2014-2024: corr +0.84), so the Chartists stand open and the Common Lot, the Harbor and the Accord lean populist. On the environment each party stands where its other three places put it in Western Europe (Chapel Hill 2014-2024, 378 parties against their country's mean: environment = 0.694 state + 0.355 openness - 0.075 Council, R-squared 0.67). */
    public const HOME_POSITIONS = [
        self::CIVIC => [self::AXIS_STATE => 0.7, self::AXIS_OPENNESS => -0.1, self::AXIS_COUNCIL => -0.1, self::AXIS_ENVIRONMENT => 0.34],
        self::VANGUARD => [self::AXIS_STATE => -0.7, self::AXIS_OPENNESS => 0.1, self::AXIS_COUNCIL => 0.3, self::AXIS_ENVIRONMENT => -0.48],
        self::IRON_HARBOR => [self::AXIS_STATE => 0.1, self::AXIS_OPENNESS => -0.8, self::AXIS_COUNCIL => -0.4, self::AXIS_ENVIRONMENT => -0.23],
        self::EXCHANGE => [self::AXIS_STATE => -0.1, self::AXIS_OPENNESS => 0.8, self::AXIS_COUNCIL => 0.4, self::AXIS_ENVIRONMENT => -0.02],
        self::CHARTISTS => [self::AXIS_STATE => -0.4, self::AXIS_OPENNESS => 0.6, self::AXIS_COUNCIL => 0.8, self::AXIS_ENVIRONMENT => -0.2],
        self::COMMON_LOT => [self::AXIS_STATE => 0.3, self::AXIS_OPENNESS => -0.5, self::AXIS_COUNCIL => -0.8, self::AXIS_ENVIRONMENT => 0.04],
        self::TIDELINE => [self::AXIS_STATE => 0.4, self::AXIS_OPENNESS => -0.2, self::AXIS_COUNCIL => -0.3, self::AXIS_ENVIRONMENT => 0.8],
        self::NEW_HORIZON => [self::AXIS_STATE => -0.5, self::AXIS_OPENNESS => 0.7, self::AXIS_COUNCIL => 0.3, self::AXIS_ENVIRONMENT => -0.17],
    ];

    // --- The Chamber ---
    /** Seats in the Diet. */
    public const SEATS = 300;
    /** Seats that pass a budget or a law. */
    public const MAJORITY_SEATS = 151;
    /** Seats that can remove a councillor, which the Diet has never assembled: three quarters of the Diet. */
    public const SUPERMAJORITY_SEATS = 225;
    /** Seats at the founding. */
    public const SEED_SEATS = [
        self::CIVIC => 94.0,
        self::VANGUARD => 95.0,
        self::IRON_HARBOR => 25.0,
        self::EXCHANGE => 25.0,
        self::CHARTISTS => 20.0,
        self::COMMON_LOT => 8.0,
        self::TIDELINE => 8.0,
        self::NEW_HORIZON => 25.0,
    ];
    /** Vote shares at the founding, the founding seats over the Diet: each party's normal vote, the share its lasting support is pulled back toward (Converse 1966). */
    public const SEED_VOTE_SHARES = [
        self::CIVIC => 94.0 / 300.0,
        self::VANGUARD => 95.0 / 300.0,
        self::IRON_HARBOR => 25.0 / 300.0,
        self::EXCHANGE => 25.0 / 300.0,
        self::CHARTISTS => 20.0 / 300.0,
        self::COMMON_LOT => 8.0 / 300.0,
        self::TIDELINE => 8.0 / 300.0,
        self::NEW_HORIZON => 25.0 / 300.0,
    ];
    /** The blocs the founding Diet votes in, by the leader of each party's bloc: the parties split around the two largest (App\Service\Politics\CoalitionFormation::declareBlocs). */
    public const SEED_BLOCS = [
        self::CIVIC => self::CIVIC,
        self::VANGUARD => self::VANGUARD,
        self::IRON_HARBOR => self::CIVIC,
        self::EXCHANGE => self::VANGUARD,
        self::CHARTISTS => self::VANGUARD,
        self::COMMON_LOT => self::CIVIC,
        self::TIDELINE => self::CIVIC,
        self::NEW_HORIZON => self::VANGUARD,
    ];
    /** The founding cabinet, 1.0 for a member: the likeliest government of the founding Diet (CoalitionFormation), the Vanguard alone, its bloc carrying it from outside. */
    public const SEED_COALITION = [
        self::CIVIC => 0.0,
        self::VANGUARD => 1.0,
        self::IRON_HARBOR => 0.0,
        self::EXCHANGE => 0.0,
        self::CHARTISTS => 0.0,
        self::COMMON_LOT => 0.0,
        self::TIDELINE => 0.0,
        self::NEW_HORIZON => 0.0,
    ];
    /** The founding cabinet's support parties, 1.0 for a supporter: the rest of the Vanguard's bloc. */
    public const SEED_SUPPORT = [
        self::CIVIC => 0.0,
        self::VANGUARD => 0.0,
        self::IRON_HARBOR => 0.0,
        self::EXCHANGE => 1.0,
        self::CHARTISTS => 1.0,
        self::COMMON_LOT => 0.0,
        self::TIDELINE => 0.0,
        self::NEW_HORIZON => 1.0,
    ];

    /**
     * A party's place in the policy space: fixed on the axes it is defined by, where the given positions put it on the
     * others, and at its home on any axis they leave out.
     *
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @return array{state: float, openness: float, council: float, environment: float}
     */
    public static function position(string $party, array $positions): array
    {
        $point = [];
        foreach (self::AXES as $axis) {
            $point[$axis] = (float) (self::FIXED_POSITIONS[$party][$axis] ?? $positions[$party][$axis] ?? self::HOME_POSITIONS[$party][$axis]);
        }

        return $point;
    }

    /**
     * Whether a party is defined by an axis, and so never moves on it.
     */
    public static function isFixed(string $party, string $axis): bool
    {
        return isset(self::FIXED_POSITIONS[$party][$axis]);
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
