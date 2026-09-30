<?php

declare(strict_types=1);

namespace App\Service\Macro\Subsystem;

use App\Data\AerieDiet;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;

/**
 * Forming a government after a vote: who leads the talks, how many attempts they take, and the cabinet they produce.
 *
 * The largest party leads first. It seeks a cabinet with a majority of its own, and failing that a minority cabinet
 * other parties support from outside; the second-largest party then has the same two rounds, and after that the lead
 * passes back and forth between the two until a government forms. In each attempt the formateur weighs every cabinet
 * it could lead on Martin & Stevenson's (2010) conditional logit -- minority status, minimal winning status, the number
 * of parties, whether it holds the largest party, its ideological range, whether it is the outgoing cabinet -- with a
 * Gumbel draw on each, and the best takes office if it clears the bar of no deal at all. What widens as the talks go on
 * is what may be offered: from the second round a minority cabinet with support, which almost always clears the bar.
 * Each attempt lasts an exponential time. The bar and the attempt length are fitted to how often a first attempt fails
 * (Golder 2010) and how long formations take (Bäck, Hellström, Lindvall & Teorell 2023).
 *
 * A minority cabinet's support parties are the outsiders that bring it to a majority with every one of them needed,
 * the set spanning the narrowest range with the cabinet (de Swaan 1973). A party that alone holds a majority governs
 * alone, with no talks.
 */
final class CoalitionFormation
{
    /** Attempts after which the formateur's likeliest cabinet takes office regardless; a supported minority cabinet clears the bar long before. */
    private const MAX_ATTEMPTS = 1000;
    /** Distances equal to within this are ties. */
    private const RANGE_TOLERANCE = 1e-9;

    /**
     * The talks after a vote.
     *
     * @param array<string, int|float>            $seats     Seats by party.
     * @param array<string, float>                $shares    Vote shares by party, which order parties tied on seats.
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @param list<string>                        $statusQuo The outgoing cabinet.
     * @return array{cabinet: list<string>, support: list<string>, days: float, log: list<array{day: float, formateur: string, round: int, formed: bool, cabinet: list<string>, support: list<string>}>}
     *         The cabinet and its support parties, the days the talks took, and each attempt: the day it ended, who led
     *         it, its round, and the cabinet that formed or, when none did, the one the formateur was likeliest to form.
     */
    public static function talks(array $seats, array $shares, array $positions, array $statusQuo, MathUtility $draws): array
    {
        $order = self::bySize($seats, $shares);
        $largest = $order[0];
        if (($seats[$largest] ?? 0) >= AerieDiet::MAJORITY_SEATS) {
            return ['cabinet' => [$largest], 'support' => [], 'days' => 0.0, 'log' => []];
        }

        $log = [];
        $day = 0.0;
        for ($attempt = 1; ; ++$attempt) {
            [$formateur, $round] = self::lead($attempt, $order[0], $order[1]);
            $options = self::options($formateur, $round, $seats, $positions);
            $utilities = array_map(
                static fn(array $option): float => self::utility($option['cabinet'], $seats, $positions, $statusQuo, $largest),
                $options
            );

            $day += MacroEngine::FORMATION_ATTEMPT_DAYS * $draws->generateExponential();
            $formed = $draws->generateUniform() < self::successProbability($utilities, MacroEngine::FORMATION_RESERVATION);
            $chosen = $formed ? self::drawOption($utilities, $draws) : self::likeliest($utilities);
            $formed = $formed || $attempt >= self::MAX_ATTEMPTS;

            $log[] = ['day' => $day, 'formateur' => $formateur, 'round' => $round, 'formed' => $formed] + $options[$chosen];
            if ($formed) {
                return $options[$chosen] + ['days' => $day, 'log' => $log];
            }
        }
    }

    /**
     * The formateur and round of an attempt: the largest party's two rounds, the second-largest's two, then the two
     * in turn.
     *
     * @return array{0: string, 1: int}
     */
    public static function lead(int $attempt, string $first, string $second): array
    {
        return match (true) {
            $attempt === 1 => [$first, AerieDiet::ROUND_MAJORITY],
            $attempt === 2 => [$first, AerieDiet::ROUND_SUPPORT],
            $attempt === 3 => [$second, AerieDiet::ROUND_MAJORITY],
            $attempt === 4 => [$second, AerieDiet::ROUND_SUPPORT],
            default => [$attempt % 2 === 1 ? $first : $second, AerieDiet::ROUND_RUNOFF],
        };
    }

    /**
     * The cabinets a formateur can offer in a round: every set of parties holding it with a majority, and from the
     * second round every minority set too, each with the support parties that would carry it.
     *
     * @param array<string, int|float>            $seats     Seats by party.
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @return list<array{cabinet: list<string>, support: list<string>}>
     */
    public static function options(string $formateur, int $round, array $seats, array $positions): array
    {
        $options = [];
        foreach (self::subsets(AerieDiet::PARTIES) as $cabinet) {
            if (!in_array($formateur, $cabinet, true)) {
                continue;
            }
            if (self::coalitionSeats($cabinet, $seats) >= AerieDiet::MAJORITY_SEATS) {
                $options[] = ['cabinet' => $cabinet, 'support' => []];
            } elseif ($round !== AerieDiet::ROUND_MAJORITY) {
                $options[] = ['cabinet' => $cabinet, 'support' => self::supportFor($cabinet, $seats, $positions)];
            }
        }

        return $options;
    }

    /**
     * A minority cabinet's support parties: of the sets of outsiders that give it a majority, the one spanning the
     * narrowest range with the cabinet, ties to fewer parties. That set needs every one of its members: dropping a
     * supporter the majority does not need never widens the range.
     *
     * @param list<string>                        $cabinet   The cabinet's parties.
     * @param array<string, int|float>            $seats     Seats by party.
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @return list<string> The support parties, in party order.
     */
    public static function supportFor(array $cabinet, array $seats, array $positions): array
    {
        $outsiders = array_values(array_diff(AerieDiet::PARTIES, $cabinet));
        $cabinetSeats = self::coalitionSeats($cabinet, $seats);

        $best = [];
        $bestRange = INF;
        foreach (self::subsets($outsiders) as $support) {
            $total = $cabinetSeats + self::coalitionSeats($support, $seats);
            if ($total < AerieDiet::MAJORITY_SEATS) {
                continue;
            }

            $range = self::ideologicalRange(array_merge($cabinet, $support), $positions);
            if ($range < $bestRange - self::RANGE_TOLERANCE
                || (abs($range - $bestRange) <= self::RANGE_TOLERANCE && count($support) < count($best))) {
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
     * @param array<string, int|float>            $seats     Seats by party.
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @param list<string>                        $statusQuo The outgoing cabinet.
     * @param string                              $largest   The Diet's largest party.
     */
    public static function utility(array $cabinet, array $seats, array $positions, array $statusQuo, string $largest): float
    {
        $minority = self::coalitionSeats($cabinet, $seats) < AerieDiet::MAJORITY_SEATS;
        $sorted = $cabinet;
        sort($sorted);
        $outgoing = $statusQuo;
        sort($outgoing);

        return ($minority ? MacroEngine::FORMATION_MINORITY_UTILITY : 0.0)
            + (self::isMinimalWinning($cabinet, $seats) ? MacroEngine::FORMATION_MINIMAL_WINNING_UTILITY : 0.0)
            + (MacroEngine::FORMATION_PARTY_UTILITY * count($cabinet))
            + (in_array($largest, $cabinet, true) ? MacroEngine::FORMATION_LARGEST_PARTY_UTILITY : 0.0)
            + (MacroEngine::FORMATION_RANGE_UTILITY * self::ideologicalRange($cabinet, $positions))
            + ($sorted === $outgoing ? MacroEngine::FORMATION_STATUS_QUO_UTILITY : 0.0);
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
        $range = 0.0;
        foreach ($members as $i => $a) {
            $pa = AerieDiet::position($a, $positions);
            foreach (array_slice($members, $i + 1) as $b) {
                $pb = AerieDiet::position($b, $positions);
                $squared = 0.0;
                foreach (AerieDiet::AXES as $axis) {
                    $squared += ($pa[$axis] - $pb[$axis]) ** 2;
                }
                $range = max($range, sqrt($squared));
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
     * The index of the cabinet with the highest log-odds, the first on a tie.
     *
     * @param list<float> $utilities
     */
    public static function likeliest(array $utilities): int
    {
        return (int) array_search(max($utilities), $utilities, true);
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
