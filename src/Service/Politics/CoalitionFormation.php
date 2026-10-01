<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\AerieDiet;
use App\Service\Math\MathUtility;

/**
 * Forming a government after a vote: how many attempts the talks take, who leads each, and the cabinet they produce.
 *
 * Every cabinet the Diet could seat is weighed on Martin & Stevenson's (2010) conditional logit -- minority status,
 * minimal winning status, the number of parties, whether it holds the largest party, its ideological range, whether it
 * is the outgoing cabinet, whether its parties campaigned as one bloc or ruled each other out, and how far its parties
 * stand from the constitutional middle -- over the same choice set they estimated it on: every set of parties, not only
 * those holding a party chosen to lead (Diermeier & Merlo (2004) find no support for handing the lead out in order of
 * size). In each attempt a Gumbel draw on every cabinet picks the one tried, which is drawn in proportion to its odds,
 * and the attempt succeeds if its draw beats the bar of no deal at all, a bar that falls with every attempt that fails
 * as the parties' patience runs out. The party leading an attempt is the largest in the cabinet it tries. Each attempt
 * lasts an exponential time. The bar, its fall and the attempt length are fitted to how often a first attempt fails
 * (Golder 2010) and how long formations take and how widely that varies (Bäck, Hellström, Lindvall & Teorell 2023).
 *
 * The Diet votes in two blocs, as the Scandinavian parliaments do. Before each vote every party declares for the bloc
 * whose centre stands nearer on the economic questions, a centre being its members' positions weighted by their seats,
 * where a government of the bloc would set policy; the blocs of the last vote are where the declarations start, and they
 * are counted again until no party would change sides (Lloyd's (1982) two-means). Each bloc is led by its largest party,
 * its candidate for the premiership, and the two leaders rule each other out. A government whose parties, cabinet and
 * supporters alike, all come from one bloc is a pre-electoral pact, for what a Scandinavian party promises before the
 * vote is to put its bloc's leader in office, not to sit in the cabinet; one holding both leaders is an anti-pact. A
 * party that stands far from
 * the Diet's middle on the Council axis, the question of the constitutional order itself, is hard to take into cabinet
 * and usually sustains one from outside instead: Martin & Stevenson's anti-system term.
 *
 * A minority cabinet's support parties are the outsiders that bring it to a majority, the set spanning the narrowest
 * range with the cabinet (de Swaan 1973), and never a party that ruled out governing with one of the cabinet's: the
 * range the cabinet is weighed on is its majority's, supporters included, for they vote its budgets. A party that
 * alone holds a majority governs alone, with no talks.
 */
final class CoalitionFormation
{
    // --- Forming a Government (Martin & Stevenson 2010, Table 1 Model 1; Golder 2010; Bäck et al. 2023) ---
    /** Log-odds of a cabinet without a majority of its own (Martin & Stevenson 2010, Table 1 Model 1: 256 formations in 17 West European democracies). */
    public const FORMATION_MINORITY_UTILITY = -1.188;
    /** Log-odds of a minimal winning cabinet, every member needed for its majority (Martin & Stevenson 2010). */
    public const FORMATION_MINIMAL_WINNING_UTILITY = 0.683;
    /** Log-odds per party in the cabinet (Martin & Stevenson 2010). */
    public const FORMATION_PARTY_UTILITY = -0.485;
    /** Log-odds of a cabinet holding the Diet's largest party (Martin & Stevenson 2010). */
    public const FORMATION_LARGEST_PARTY_UTILITY = 1.575;
    /** Manifesto left-right points a unit of the Diet's axes spans, the scale Martin & Stevenson read range on; fitted so 36.5% of cabinets after a hung vote are minority cabinets (ParlGov: 315 in Western Europe since 1945; var/harness/politics/formation_fit.py). Benoit & Laver (2007) put a unit, half an expert scale, at 30 points (3.19 per point of their 1-20 scale). */
    public const FORMATION_MANIFESTO_POINTS_PER_UNIT = 40.0;
    /** Log-odds per unit of the cabinet's ideological range: -0.027 per manifesto left-right point (Martin & Stevenson 2010). */
    public const FORMATION_RANGE_UTILITY = -0.027 * self::FORMATION_MANIFESTO_POINTS_PER_UNIT;
    /** Log-odds of the outgoing cabinet re-forming (Martin & Stevenson 2010: the status quo government). */
    public const FORMATION_STATUS_QUO_UTILITY = 1.984;
    /** Log-odds of a government whose parties, cabinet and supporters, all declared for the same bloc before the vote (Martin & Stevenson 2010: a pre-electoral pact associated with the coalition). */
    public const FORMATION_PACT_UTILITY = 3.429;
    /** Log-odds of a cabinet holding two parties that ruled out governing together, the two bloc leaders (Martin & Stevenson 2010: an anti-pact). */
    public const FORMATION_ANTIPACT_UTILITY = -2.877;
    /** Log-odds per unit a cabinet party stands from the Diet's median on the Council axis, the question of the constitutional order: Martin & Stevenson's (2010) anti-system term, its manifesto measure replaced by that distance and its strength fitted so the parties at the axis's ends sit in cabinet as seldom as Scandinavia's radical parties (var/harness/politics/formation_fit.py). */
    public const FORMATION_ANTISYSTEM_UTILITY = -2.91;
    /** Log-odds the cabinet the first attempt tries must clear, the value of no deal at all; fitted so 32% of formations need more than one attempt (Golder 2010: 'nearly a third', 16 West European democracies 1944-1998; var/harness/politics/formation_fit.py). */
    public const FORMATION_RESERVATION = 2.17;
    /** How far the bar of no deal falls with each attempt that fails, as the parties' patience runs out; fitted so formations are as spread as the record's, sd 33.9 days on a mean of 33.7 (Bäck, Hellström, Lindvall & Teorell 2023), which cuts the stalemates a fixed bar would leave running for years. */
    public const FORMATION_RESERVATION_STEP = 0.68;
    /** Mean length of one attempt in days, each drawn exponential (a constant hazard: the formation record's spread about equals its mean); fitted so formations average Bäck, Hellström, Lindvall & Teorell's (2023) 33.7 days, Western Europe 1945-2019. */
    public const FORMATION_ATTEMPT_DAYS = 22.8;
    /** Mean days from a vote to the government it forms, single-party majorities included: what the talks model averages at the fitted constants. */
    public const FORMATION_MEAN_DAYS = 33.7;

    // --- Safeguards ---
    /** Attempts after which the cabinet tried takes office regardless: a safeguard, since the falling bar ends every talks in the harness within a dozen attempts. */
    private const MAX_ATTEMPTS = 1000;
    /** Rounds of declarations after which the blocs stand as they are: a safeguard, since two-means settles within a few. */
    private const MAX_BLOC_ROUNDS = 100;
    /** Distances equal to within this are ties. */
    private const RANGE_TOLERANCE = 1e-9;

    /**
     * The talks after a vote, or after a cabinet falls between votes: the cabinet that fell cannot be seated again as it
     * was, since its parties have just parted.
     *
     * @param array<string, int|float>            $seats     Seats by party.
     * @param array<string, float>                $shares    Vote shares by party, which order parties tied on seats.
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @param list<string>                        $statusQuo The outgoing cabinet.
     * @param array<string, string>               $blocs     The leader of the bloc each party declared for (declareBlocs()).
     * @param list<string>                        $fallen    The cabinet that fell, in party order; none after a vote.
     * @return array{cabinet: list<string>, support: list<string>, days: float, log: list<array{day: float, formateur: string, formed: bool, cabinet: list<string>, support: list<string>}>}
     *         The cabinet and its support parties, the days the talks took, and each attempt: the day it ended, the
     *         party that led it, whether it formed a government, and the cabinet it tried.
     */
    public static function talks(array $seats, array $shares, array $positions, array $statusQuo, array $blocs, MathUtility $draws, array $fallen = []): array
    {
        $order = self::bySize($seats, $shares);
        $largest = $order[0];
        if (($seats[$largest] ?? 0) >= AerieDiet::MAJORITY_SEATS) {
            return ['cabinet' => [$largest], 'support' => [], 'days' => 0.0, 'log' => []];
        }

        $options = array_values(array_filter(self::options($seats, $positions, $blocs), static fn(array $option): bool => $option['cabinet'] !== $fallen));
        $utilities = array_map(
            static fn(array $option): float => self::utility($option['cabinet'], $option['support'], $seats, $positions, $statusQuo, $largest, $blocs),
            $options
        );

        $log = [];
        $day = 0.0;
        for ($attempt = 1; ; ++$attempt) {
            $day += self::FORMATION_ATTEMPT_DAYS * $draws->generateExponential();
            $tried = $options[self::drawOption($utilities, $draws)];
            $bar = self::FORMATION_RESERVATION - (self::FORMATION_RESERVATION_STEP * ($attempt - 1));
            $formed = $draws->generateUniform() < self::successProbability($utilities, $bar) || $attempt >= self::MAX_ATTEMPTS;

            $log[] = ['day' => $day, 'formateur' => self::leader($tried['cabinet'], $order), 'formed' => $formed] + $tried;
            if ($formed) {
                return $tried + ['days' => $day, 'log' => $log];
            }
        }
    }

    /**
     * The cabinets the Diet could seat: every set of parties, a majority on its own or a minority with the support
     * parties that would carry it; a minority no eligible outsiders can carry is not on offer.
     *
     * @param array<string, int|float>            $seats     Seats by party.
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @param array<string, string>               $blocs     The leader of the bloc each party declared for (declareBlocs()).
     * @return list<array{cabinet: list<string>, support: list<string>}>
     */
    public static function options(array $seats, array $positions, array $blocs): array
    {
        $distances = self::distances($positions);
        $options = [];
        foreach (self::subsets(AerieDiet::PARTIES) as $cabinet) {
            if (self::coalitionSeats($cabinet, $seats) >= AerieDiet::MAJORITY_SEATS) {
                $options[] = ['cabinet' => $cabinet, 'support' => []];
                continue;
            }
            $support = self::supportFor($cabinet, $seats, $positions, $blocs, $distances);
            if ($support !== null) {
                $options[] = ['cabinet' => $cabinet, 'support' => $support];
            }
        }

        return $options;
    }

    /**
     * The party that leads the talks for a cabinet: its largest member.
     *
     * @param list<string> $cabinet The cabinet's parties.
     * @param list<string> $bySize  The Diet's parties from the most seats to the fewest (bySize()).
     */
    public static function leader(array $cabinet, array $bySize): string
    {
        foreach ($bySize as $party) {
            if (in_array($party, $cabinet, true)) {
                return $party;
            }
        }

        throw new \InvalidArgumentException('A cabinet needs at least one party.');
    }

    /**
     * A minority cabinet's support parties: of the sets of outsiders that give it a majority, the one spanning the
     * narrowest range with the cabinet, ties to fewer parties, leaving out any party that ruled out governing with one of
     * the cabinet's. That set needs every one of its members: dropping a supporter the majority does not need never
     * widens the range.
     *
     * @param list<string>                               $cabinet   The cabinet's parties.
     * @param array<string, int|float>                   $seats     Seats by party.
     * @param array<string, array<string, float>>        $positions Positions by party and axis.
     * @param array<string, string>                      $blocs     The leader of the bloc each party declared for (declareBlocs()).
     * @param array<string, array<string, float>>|null   $distances Distances between parties (distances()), if already worked out.
     * @return list<string>|null The support parties, in party order; null when the outsiders who may support it fall short.
     */
    public static function supportFor(array $cabinet, array $seats, array $positions, array $blocs, ?array $distances = null): ?array
    {
        $distances ??= self::distances($positions);
        $outsiders = array_values(array_filter(
            array_diff(AerieDiet::PARTIES, $cabinet),
            static fn(string $party): bool => array_filter($cabinet, static fn(string $member): bool => self::rulesOut($party, $member, $blocs)) === []
        ));
        $cabinetSeats = self::coalitionSeats($cabinet, $seats);

        $best = null;
        $bestRange = INF;
        foreach (self::subsets($outsiders) as $support) {
            $total = $cabinetSeats + self::coalitionSeats($support, $seats);
            if ($total < AerieDiet::MAJORITY_SEATS) {
                continue;
            }

            $range = self::rangeOf(array_merge($cabinet, $support), $distances);
            if ($range < $bestRange - self::RANGE_TOLERANCE
                || (abs($range - $bestRange) <= self::RANGE_TOLERANCE && count($support) < count($best ?? []))) {
                $best = $support;
                $bestRange = $range;
            }
        }

        return $best;
    }

    /**
     * A cabinet's log-odds on Martin & Stevenson's (2010) conditional logit.
     *
     * @param list<string>                        $cabinet   The cabinet's parties.
     * @param list<string>                        $support   Its support parties, whose positions count toward its range.
     * @param array<string, int|float>            $seats     Seats by party.
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @param list<string>                        $statusQuo The outgoing cabinet.
     * @param string                              $largest   The Diet's largest party.
     * @param array<string, string>               $blocs     The leader of the bloc each party declared for (declareBlocs()).
     */
    public static function utility(array $cabinet, array $support, array $seats, array $positions, array $statusQuo, string $largest, array $blocs): float
    {
        $minority = self::coalitionSeats($cabinet, $seats) < AerieDiet::MAJORITY_SEATS;
        $sorted = $cabinet;
        sort($sorted);
        $outgoing = $statusQuo;
        sort($outgoing);
        $government = array_merge($cabinet, $support);
        $oneBloc = count($government) > 1 && count(array_unique(array_map(static fn(string $party): string => $blocs[$party], $government))) === 1;
        $leaders = array_filter($cabinet, static fn(string $party): bool => $blocs[$party] === $party);
        $median = self::councilMedian($seats, $positions);
        $challenge = 0.0;
        foreach ($cabinet as $party) {
            $challenge += abs(AerieDiet::position($party, $positions)[AerieDiet::AXIS_COUNCIL] - $median);
        }

        return ($minority ? self::FORMATION_MINORITY_UTILITY : 0.0)
            + (self::isMinimalWinning($cabinet, $seats) ? self::FORMATION_MINIMAL_WINNING_UTILITY : 0.0)
            + (self::FORMATION_PARTY_UTILITY * count($cabinet))
            + (in_array($largest, $cabinet, true) ? self::FORMATION_LARGEST_PARTY_UTILITY : 0.0)
            + (self::FORMATION_RANGE_UTILITY * self::ideologicalRange($government, $positions))
            + ($sorted === $outgoing ? self::FORMATION_STATUS_QUO_UTILITY : 0.0)
            + ($oneBloc ? self::FORMATION_PACT_UTILITY : 0.0)
            + (count($leaders) > 1 ? self::FORMATION_ANTIPACT_UTILITY : 0.0)
            + (self::FORMATION_ANTISYSTEM_UTILITY * $challenge);
    }

    /**
     * The blocs the parties campaign in, declared before a vote: starting from the blocs of the last vote, every party
     * declares for the bloc whose centre, its members' positions on the economic questions weighted by their seats,
     * stands nearer, staying where it is on a tie, and the centres are struck again until no party would change sides
     * (Lloyd 1982). A Diet with no blocs yet, or one whose blocs have merged, splits around its two largest parties. Each
     * bloc is led by its largest party.
     *
     * @param array<string, int|float>            $seats     Seats by party going into the vote, which weight the centres and name the leaders.
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @param array<string, string>               $previous  The leader of the bloc each party declared for at the last vote; none at the founding.
     * @return array<string, string> The leader of each party's bloc, by party, in party order.
     */
    public static function declareBlocs(array $seats, array $positions, array $previous): array
    {
        $points = [];
        foreach (AerieDiet::PARTIES as $party) {
            $points[$party] = AerieDiet::position($party, $positions);
        }
        $bySize = self::bySize($seats, []);
        $split = static fn(): array => self::nearestCentre($points, [$bySize[0] => $points[$bySize[0]], $bySize[1] => $points[$bySize[1]]], [$bySize[0] => $bySize[0], $bySize[1] => $bySize[1]]);

        $sides = count(array_unique($previous)) === 2 ? $previous : $split();
        for ($round = 0; $round < self::MAX_BLOC_ROUNDS; ++$round) {
            $centres = [];
            foreach (array_unique($sides) as $side) {
                $members = array_keys($sides, $side, true);
                $weight = self::coalitionSeats($members, $seats);
                foreach (AerieDiet::BLOC_AXES as $axis) {
                    $centres[$side][$axis] = $weight > 0.0
                        ? array_sum(array_map(static fn(string $party): float => (float) ($seats[$party] ?? 0) * $points[$party][$axis], $members)) / $weight
                        : array_sum(array_map(static fn(string $party): float => $points[$party][$axis], $members)) / count($members);
                }
            }
            $next = self::nearestCentre($points, $centres, $sides);
            if (count(array_unique($next)) < 2) {
                $next = $split();
            }
            if ($next === $sides) {
                break;
            }
            $sides = $next;
        }

        $blocs = [];
        foreach (array_unique($sides) as $side) {
            $members = array_keys($sides, $side, true);
            $leader = self::leader($members, $bySize);
            foreach ($members as $party) {
                $blocs[$party] = $leader;
            }
        }

        $ordered = [];
        foreach (AerieDiet::PARTIES as $party) {
            $ordered[$party] = $blocs[$party];
        }

        return $ordered;
    }

    /**
     * Each party's nearer centre on the economic questions, its present side on a tie.
     *
     * @param array<string, array<string, float>> $points  Each party's point.
     * @param array<string, array<string, float>> $centres Each side's centre on the bloc axes, by side.
     * @param array<string, string>               $sides   The side each party is on now; a party on none takes the first centre on a tie.
     * @return array<string, string> The side each party is nearer, by party.
     */
    private static function nearestCentre(array $points, array $centres, array $sides): array
    {
        $nearest = [];
        foreach ($points as $party => $point) {
            $best = null;
            $bestDistance = INF;
            foreach ($centres as $side => $centre) {
                $distance = sqrt(array_sum(array_map(static fn(string $axis): float => ($point[$axis] - $centre[$axis]) ** 2, AerieDiet::BLOC_AXES)));
                if ($distance < $bestDistance - self::RANGE_TOLERANCE
                    || (abs($distance - $bestDistance) <= self::RANGE_TOLERANCE && $side === ($sides[$party] ?? null))) {
                    $best = $side;
                    $bestDistance = $distance;
                }
            }
            $nearest[$party] = (string) $best;
        }

        return $nearest;
    }

    /**
     * Whether two parties have ruled out governing together: the two bloc leaders.
     *
     * @param array<string, string> $blocs The leader of the bloc each party declared for (declareBlocs()).
     */
    public static function rulesOut(string $a, string $b, array $blocs): bool
    {
        return $a !== $b && ($blocs[$a] ?? null) === $a && ($blocs[$b] ?? null) === $b;
    }

    /**
     * The Diet's median on the Council axis: the position of the party holding the middle seat when the parties are
     * lined up from the populist end.
     *
     * @param array<string, int|float>            $seats     Seats by party.
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     */
    public static function councilMedian(array $seats, array $positions): float
    {
        $council = [];
        foreach (AerieDiet::PARTIES as $party) {
            $council[$party] = AerieDiet::position($party, $positions)[AerieDiet::AXIS_COUNCIL];
        }
        asort($council);
        $half = self::coalitionSeats(AerieDiet::PARTIES, $seats) / 2.0;
        $counted = 0.0;
        foreach ($council as $party => $position) {
            $counted += (float) ($seats[$party] ?? 0);
            if ($counted >= $half) {
                return $position;
            }
        }

        return 0.0;
    }

    /**
     * The chance an attempt forms a government: its best cabinet, Gumbel draws on every one, beats the bar of no deal.
     *
     * @param list<float> $utilities The log-odds of the cabinets on offer.
     */
    public static function successProbability(array $utilities, float $bar): float
    {
        $top = max($utilities);
        $inclusive = $top + log(array_sum(array_map(static fn(float $utility): float => exp($utility - $top), $utilities)));

        return 1.0 / (1.0 + exp($bar - $inclusive));
    }

    /**
     * The parties from the most seats to the fewest, ties to the larger vote and then to party order.
     *
     * @param array<string, int|float> $seats  Seats by party.
     * @param array<string, float>     $shares Vote shares by party.
     * @return list<string>
     */
    public static function bySize(array $seats, array $shares): array
    {
        $order = AerieDiet::PARTIES;
        usort($order, static fn(string $a, string $b): int => [$seats[$b] ?? 0, $shares[$b] ?? 0.0, array_search($a, AerieDiet::PARTIES, true)]
            <=> [$seats[$a] ?? 0, $shares[$a] ?? 0.0, array_search($b, AerieDiet::PARTIES, true)]);

        return $order;
    }

    /**
     * Whether a set of parties holds a majority that every member is needed for (Riker 1962).
     *
     * @param list<string>             $members Parties.
     * @param array<string, int|float> $seats   Seats by party.
     */
    public static function isMinimalWinning(array $members, array $seats): bool
    {
        $total = self::coalitionSeats($members, $seats);
        if ($total < AerieDiet::MAJORITY_SEATS) {
            return false;
        }
        foreach ($members as $party) {
            if ($total - ($seats[$party] ?? 0) >= AerieDiet::MAJORITY_SEATS) {
                return false;
            }
        }

        return true;
    }

    /**
     * The largest distance between two members in the policy space (de Swaan 1973, as Martin & Stevenson measure a
     * coalition's ideological divisions); zero for one party.
     *
     * @param list<string>                        $members   Parties.
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     */
    public static function ideologicalRange(array $members, array $positions): float
    {
        return self::rangeOf($members, self::distances($positions));
    }

    /**
     * The distance between every two parties in the policy space.
     *
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @return array<string, array<string, float>>
     */
    private static function distances(array $positions): array
    {
        $points = [];
        foreach (AerieDiet::PARTIES as $party) {
            $points[$party] = AerieDiet::position($party, $positions);
        }
        $distances = [];
        foreach (AerieDiet::PARTIES as $a) {
            foreach (AerieDiet::PARTIES as $b) {
                $squared = 0.0;
                foreach (AerieDiet::AXES as $axis) {
                    $squared += ($points[$a][$axis] - $points[$b][$axis]) ** 2;
                }
                $distances[$a][$b] = sqrt($squared);
            }
        }

        return $distances;
    }

    /**
     * The largest distance between two members, off a table of distances.
     *
     * @param list<string>                        $members   Parties.
     * @param array<string, array<string, float>> $distances Distances between parties (distances()).
     */
    private static function rangeOf(array $members, array $distances): float
    {
        $range = 0.0;
        foreach ($members as $i => $a) {
            foreach (array_slice($members, $i + 1) as $b) {
                $range = max($range, $distances[$a][$b]);
            }
        }

        return $range;
    }

    /**
     * Seats a set of parties holds.
     *
     * @param list<string>             $members Parties.
     * @param array<string, int|float> $seats   Seats by party.
     */
    public static function coalitionSeats(array $members, array $seats): float
    {
        $total = 0.0;
        foreach ($members as $party) {
            $total += (float) ($seats[$party] ?? 0);
        }

        return $total;
    }

    /**
     * Draws a cabinet in proportion to the exponential of its log-odds.
     *
     * @param list<float> $utilities
     */
    private static function drawOption(array $utilities, MathUtility $draws): int
    {
        $top = max($utilities);
        $weights = array_map(static fn(float $utility): float => exp($utility - $top), $utilities);
        $target = $draws->generateUniform() * array_sum($weights);
        foreach ($weights as $index => $weight) {
            $target -= $weight;
            if ($target < 0.0) {
                return $index;
            }
        }

        return array_key_last($weights);
    }

    /**
     * Every non-empty set of the given parties, each in the order given.
     *
     * @param list<string> $parties
     * @return list<list<string>>
     */
    private static function subsets(array $parties): array
    {
        $subsets = [];
        $count = count($parties);
        for ($mask = 1; $mask < (1 << $count); ++$mask) {
            $members = [];
            foreach ($parties as $index => $party) {
                if ($mask & (1 << $index)) {
                    $members[] = $party;
                }
            }
            $subsets[] = $members;
        }

        return $subsets;
    }
}
