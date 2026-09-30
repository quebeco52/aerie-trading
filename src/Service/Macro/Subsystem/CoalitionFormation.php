<?php

declare(strict_types=1);

namespace App\Service\Macro\Subsystem;

use App\Data\AerieDiet;
use App\Service\Macro\MacroEngine;
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
 * The Diet votes in two blocs, as the Scandinavian parliaments do: the two bloc leaders, rivals for the premiership,
 * rule each other out, and every other party declares before the vote for the bloc whose core -- the leader and the
 * party fixed beside it -- stands nearer on the economic questions. A government
 * whose parties, cabinet and supporters alike, all come from one bloc is a pre-electoral pact, for what a Scandinavian
 * party promises before the vote is to put its bloc's leader in office, not to sit in the cabinet; one holding both
 * leaders is an anti-pact. A party that stands far from
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
    /** Attempts after which the cabinet tried takes office regardless: a safeguard, since the falling bar ends every talks in the harness within a dozen attempts. */
    private const MAX_ATTEMPTS = 1000;
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
     * @param list<string>                        $fallen    The cabinet that fell, in party order; none after a vote.
     * @return array{cabinet: list<string>, support: list<string>, days: float, log: list<array{day: float, formateur: string, formed: bool, cabinet: list<string>, support: list<string>}>}
     *         The cabinet and its support parties, the days the talks took, and each attempt: the day it ended, the
     *         party that led it, whether it formed a government, and the cabinet it tried.
     */
    public static function talks(array $seats, array $shares, array $positions, array $statusQuo, MathUtility $draws, array $fallen = []): array
    {
        $order = self::bySize($seats, $shares);
        $largest = $order[0];
        if (($seats[$largest] ?? 0) >= AerieDiet::MAJORITY_SEATS) {
            return ['cabinet' => [$largest], 'support' => [], 'days' => 0.0, 'log' => []];
        }

        $options = array_values(array_filter(self::options($seats, $positions), static fn(array $option): bool => $option['cabinet'] !== $fallen));
        $utilities = array_map(
            static fn(array $option): float => self::utility($option['cabinet'], $option['support'], $seats, $positions, $statusQuo, $largest),
            $options
        );

        $log = [];
        $day = 0.0;
        for ($attempt = 1; ; ++$attempt) {
            $day += MacroEngine::FORMATION_ATTEMPT_DAYS * $draws->generateExponential();
            $tried = $options[self::drawOption($utilities, $draws)];
            $bar = MacroEngine::FORMATION_RESERVATION - (MacroEngine::FORMATION_RESERVATION_STEP * ($attempt - 1));
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
     * @return list<array{cabinet: list<string>, support: list<string>}>
     */
    public static function options(array $seats, array $positions): array
    {
        $distances = self::distances($positions);
        $options = [];
        foreach (self::subsets(AerieDiet::PARTIES) as $cabinet) {
            if (self::coalitionSeats($cabinet, $seats) >= AerieDiet::MAJORITY_SEATS) {
                $options[] = ['cabinet' => $cabinet, 'support' => []];
                continue;
            }
            $support = self::supportFor($cabinet, $seats, $positions, $distances);
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
     * @param array<string, array<string, float>>|null   $distances Distances between parties (distances()), if already worked out.
     * @return list<string>|null The support parties, in party order; null when the outsiders who may support it fall short.
     */
    public static function supportFor(array $cabinet, array $seats, array $positions, ?array $distances = null): ?array
    {
        $distances ??= self::distances($positions);
        $outsiders = array_values(array_filter(
            array_diff(AerieDiet::PARTIES, $cabinet),
            static fn(string $party): bool => array_filter($cabinet, static fn(string $member): bool => self::rulesOut($party, $member)) === []
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
     */
    public static function utility(array $cabinet, array $support, array $seats, array $positions, array $statusQuo, string $largest): float
    {
        $minority = self::coalitionSeats($cabinet, $seats) < AerieDiet::MAJORITY_SEATS;
        $sorted = $cabinet;
        sort($sorted);
        $outgoing = $statusQuo;
        sort($outgoing);
        $blocs = self::blocs($positions);
        $government = array_merge($cabinet, $support);
        $oneBloc = count($government) > 1 && count(array_unique(array_map(static fn(string $party): string => $blocs[$party], $government))) === 1;
        $leaders = array_intersect(array_keys(AerieDiet::BLOC_CORES), $cabinet);
        $median = self::councilMedian($seats, $positions);
        $challenge = 0.0;
        foreach ($cabinet as $party) {
            $challenge += abs(AerieDiet::position($party, $positions)[AerieDiet::AXIS_COUNCIL] - $median);
        }

        return ($minority ? MacroEngine::FORMATION_MINORITY_UTILITY : 0.0)
            + (self::isMinimalWinning($cabinet, $seats) ? MacroEngine::FORMATION_MINIMAL_WINNING_UTILITY : 0.0)
            + (MacroEngine::FORMATION_PARTY_UTILITY * count($cabinet))
            + (in_array($largest, $cabinet, true) ? MacroEngine::FORMATION_LARGEST_PARTY_UTILITY : 0.0)
            + (MacroEngine::FORMATION_RANGE_UTILITY * self::ideologicalRange($government, $positions))
            + ($sorted === $outgoing ? MacroEngine::FORMATION_STATUS_QUO_UTILITY : 0.0)
            + ($oneBloc ? MacroEngine::FORMATION_PACT_UTILITY : 0.0)
            + (count($leaders) > 1 ? MacroEngine::FORMATION_ANTIPACT_UTILITY : 0.0)
            + (MacroEngine::FORMATION_ANTISYSTEM_UTILITY * $challenge);
    }

    /**
     * The bloc each party campaigns in, by the leader it declared for: a core party belongs to its own leader's bloc, and
     * every other party declares for the bloc whose core stands nearer on the economic questions, the first bloc on a
     * tie.
     *
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @return array<string, string> The leader of each party's bloc, by party.
     */
    public static function blocs(array $positions): array
    {
        $centres = [];
        foreach (AerieDiet::BLOC_CORES as $leader => $core) {
            foreach (AerieDiet::BLOC_AXES as $axis) {
                $centres[$leader][$axis] = array_sum(array_map(static fn(string $party): float => AerieDiet::position($party, $positions)[$axis], $core)) / count($core);
            }
        }

        $blocs = [];
        foreach (AerieDiet::PARTIES as $party) {
            $nearest = null;
            $nearestDistance = INF;
            foreach (AerieDiet::BLOC_CORES as $leader => $core) {
                if (in_array($party, $core, true)) {
                    $nearest = $leader;
                    break;
                }
                $point = AerieDiet::position($party, $positions);
                $distance = sqrt(array_sum(array_map(static fn(string $axis): float => ($point[$axis] - $centres[$leader][$axis]) ** 2, AerieDiet::BLOC_AXES)));
                if ($distance < $nearestDistance - self::RANGE_TOLERANCE) {
                    $nearest = $leader;
                    $nearestDistance = $distance;
                }
            }
            $blocs[$party] = (string) $nearest;
        }

        return $blocs;
    }

    /**
     * Whether two parties have ruled out governing together: the two bloc leaders.
     */
    public static function rulesOut(string $a, string $b): bool
    {
        return $a !== $b && isset(AerieDiet::BLOC_CORES[$a], AerieDiet::BLOC_CORES[$b]);
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
