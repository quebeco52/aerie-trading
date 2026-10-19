<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\Politics\AerieDiet;
use App\Service\Math\LinearAlgebra;

/**
 * A minority cabinet's budget as a package deal: the budget closest to its programme that every support party likes at
 * least as well as the levers in force, the win set of the status quo (Romer & Rosenthal 1978; Tsebelis 2002). A
 * supporter weighs the whole budget, so it can give ground on one lever for what it gains on another, except on the
 * question it is defined by: a party owns that question (Budge & Farlie 1983) and votes no lever on it further from
 * its own than the one in force. A policy stays put only while the levers in force are already a compromise among the
 * supporters on every question at once, or sit on a supporter's red line.
 *
 * Parties weigh a lever in units of its span between the parties at the ends of its question, and each question once
 * however many levers carry it: a budget's distance from a programme is then how far apart the two stand on the
 * questions the Diet is divided by.
 */
final class BudgetBargain
{
    // --- The Package Deal's Solution ---
    /** Sweeps over the supporters' and the bounds' terms before the budget is taken as found. */
    private const MAX_SWEEPS = 2000;
    /** Change in a term, against the sum of the supporters', at which the budget is taken as found. */
    private const TOLERANCE = 1e-13;
    /** Overshoot of a supporter's indifference, as a share of it, left to the sweeps' rounding. */
    private const INDIFFERENCE_SLACK = 1e-12;
    /**
     * How far the parties' pulls, each of unit length and weighed to sum to one, may stop short of a standstill at the
     * levers in force and still be one: a standstill rounds to about 1e-14, and a budget that moves misses it by 1e-4
     * or more.
     */
    private const STANDSTILL_TOLERANCE = 1e-10;
    /**
     * Distance from the levers in force, in the parties' weighing, within which a supporter's programme is in force: a
     * programme recomputed from the positions that enacted it lands within rounding (about 1e-16), and a ball smaller
     * than the rounding of the sweeps' sums has no budget on it the sweeps can find.
     */
    private const PROGRAMME_IN_FORCE_TOLERANCE = 1e-12;

    /** @var array<string, float>|null The weight each lever carries, memoized from the platforms. */
    private static ?array $scales = null;

    /**
     * The budget closest to the cabinet's programme that no supporter likes less than the levers in force, that crosses
     * no supporter's red line, and that keeps within any caps. Each supporter's acceptable budgets are a ball around
     * its own programme reaching to the levers in force, and its red lines and the caps bound single levers, so the
     * budget is the cabinet's programme projected onto all of them at once: found on the projection's dual, where each
     * supporter's and each lever's term has a closed-form best value given the others'.
     *
     * @param array<string, float>                                                 $proposal   The cabinet's
     *                                                                                         programme, keyed as
     *                                                                                         PoliticsEngine::LEVER_FIELDS.
     * @param array<string, float>                                                 $standing   The levers in force,
     *                                                                                         keyed alike; a lever
     *                                                                                         over its cap lapses to
     *                                                                                         it.
     * @param list<array{programme: array<string, float>, redLines: list<string>}> $supporters Each support party's
     *                                                                                         programme and the levers
     *                                                                                         of the question it owns.
     * @param array<string, float>                                                 $caps       Ceilings on levers,
     *                                                                                         which bind every budget
     *                                                                                         alike.
     * @return array<string, float> The budget, keyed as the proposal.
     */
    public static function winSetPoint(array $proposal, array $standing, array $supporters, array $caps = []): array
    {
        $levers = array_keys($proposal);
        $inForce = [];
        $low = [];
        $high = [];
        foreach ($levers as $lever) {
            $inForce[$lever] = min($standing[$lever], $caps[$lever] ?? INF);
            $low[$lever] = -INF;
            $high[$lever] = $caps[$lever] ?? INF;
        }
        // On the question it owns a supporter votes no lever further from its own than the one in force: the lever in
        // force bounds the side away from its programme, and its mirror past the programme the other.
        foreach ($supporters as $supporter) {
            foreach ($supporter['redLines'] as $lever) {
                $own = $supporter['programme'][$lever];
                $low[$lever] = max($low[$lever], $own >= $inForce[$lever] ? $inForce[$lever] : (2.0 * $own) - $inForce[$lever]);
                $high[$lever] = min($high[$lever], $own >= $inForce[$lever] ? (2.0 * $own) - $inForce[$lever] : $inForce[$lever]);
            }
        }

        $scales = self::scales();
        $scaled = static fn(array $budget): array => array_map(static fn(string $lever): float => $budget[$lever] * $scales[$lever], $levers);
        $p = $scaled($proposal);
        $q = $scaled($inForce);
        $centres = array_map(static fn(array $supporter): array => $scaled($supporter['programme']), $supporters);
        $radii = array_map(static fn(array $centre): float => self::distance($q, $centre), $centres);
        $bounds = [];
        foreach ($levers as $i => $lever) {
            if (is_finite($low[$lever]) || is_finite($high[$lever])) {
                $bounds[$i] = [$low[$lever] * $scales[$lever], $high[$lever] * $scales[$lever]];
            }
        }

        // A supporter whose own programme is in force, to rounding, accepts nothing else.
        foreach ($radii as $radius) {
            if ($radius <= self::PROGRAMME_IN_FORCE_TOLERANCE) {
                return $inForce;
            }
        }

        // The programme held to the red lines and the caps is the budget nearest it within them, so when every
        // supporter likes it no less than the levers in force it is the budget, and no supporter's term binds.
        $x = $p;
        foreach ($bounds as $i => [$floor, $ceiling]) {
            $x[$i] = max($floor, min($ceiling, $p[$i]));
        }
        if (self::overshoots($x, $centres, $radii)) {
            $x = self::standsStill($p, $q, $centres, $bounds) ? $q : self::project($p, $q, $centres, $radii, $bounds);
        }

        // A lever the bargain left at the programme, at the levers in force or on a bound is handed back unrounded.
        $budget = [];
        foreach ($levers as $i => $lever) {
            $budget[$lever] = match ($x[$i]) {
                $p[$i] => $proposal[$lever],
                $q[$i] => $inForce[$lever],
                $bounds[$i][0] ?? NAN => $low[$lever],
                $bounds[$i][1] ?? NAN => $high[$lever],
                default => $x[$i] / $scales[$lever],
            };
        }

        return $budget;
    }

    /**
     * Whether the levers in force are the budget: when the cabinet's pull away from them and each supporter's toward
     * its own programme can be weighed so that together they cancel on every lever free to move and press only against
     * the floor or ceiling holding each of the rest (the Fritz John conditions at the levers in force). That covers a
     * win set of the levers in force alone, where the sweeps' terms would grow without end.
     *
     * @param list<float>                           $p       The cabinet's programme.
     * @param list<float>                           $q       The levers in force.
     * @param list<list<float>>                     $centres Each supporter's programme.
     * @param array<int, array{0: float, 1: float}> $bounds  Floor and ceiling of each bounded lever.
     */
    private static function standsStill(array $p, array $q, array $centres, array $bounds): bool
    {
        // A lever held at its floor can only be pressed down, one at its ceiling only up, one held at both either way.
        $held = [];
        foreach ($q as $i => $value) {
            $atFloor = isset($bounds[$i]) && $value <= $bounds[$i][0];
            $atCeiling = isset($bounds[$i]) && $value >= $bounds[$i][1];
            if (!($atFloor && $atCeiling)) {
                $held[$i] = $atFloor ? -1.0 : ($atCeiling ? 1.0 : 0.0);
            }
        }

        // Each pull is the gradient of a party's distance at the levers in force, scaled to unit length, with a last
        // entry that weighs the pulls to one; each bound pressed takes up its lever's part.
        $columns = [];
        foreach ([$p, ...$centres] as $towards) {
            $gradient = [];
            foreach (array_keys($held) as $i) {
                $gradient[] = $q[$i] - $towards[$i];
            }
            $length = sqrt(self::dot($gradient, $gradient));
            $columns[] = [...array_map(static fn(float $value): float => $length > 0.0 ? $value / $length : 0.0, $gradient), 1.0];
        }
        $row = 0;
        foreach ($held as $side) {
            if ($side !== 0.0) {
                $pressed = array_fill(0, count($held) + 1, 0.0);
                $pressed[$row] = $side;
                $columns[] = $pressed;
            }
            $row++;
        }
        [, $shortfall] = LinearAlgebra::nonNegativeLeastSquares($columns, [...array_fill(0, count($held), 0.0), 1.0]);

        return $shortfall <= self::STANDSTILL_TOLERANCE;
    }

    /**
     * The programme projected onto every supporter's ball and every bound at once, in the parties' weighing.
     *
     * @param list<float>                           $p       The cabinet's programme.
     * @param list<float>                           $q       The levers in force.
     * @param list<list<float>>                     $centres Each supporter's programme.
     * @param list<float>                           $radii   Each supporter's distance from the levers in force.
     * @param array<int, array{0: float, 1: float}> $bounds  Floor and ceiling of each bounded lever.
     * @return array<int, float>
     */
    private static function project(array $p, array $q, array $centres, array $radii, array $bounds): array
    {
        // The projection is (p + sum lambda_s o_s - sum beta_i e_i) / (1 + sum lambda_s): each supporter's term pulls
        // the budget toward its own programme until the budget sits on its ball, each bounded lever's term holds it at
        // its bound, and either is zero where it does not bind.
        $lambda = array_fill(0, count($centres), 0.0);
        $beta = array_fill_keys(array_keys($bounds), 0.0);
        $sum = $p;
        $total = 0.0;
        for ($sweep = 0; $sweep < self::MAX_SWEEPS; $sweep++) {
            $largestStep = 0.0;
            foreach ($centres as $s => $centre) {
                $others = $total - $lambda[$s];
                $gap = 0.0;
                foreach ($sum as $i => $value) {
                    $gap += ($value - ($lambda[$s] * $centre[$i]) - ((1.0 + $others) * $centre[$i])) ** 2;
                }
                $next = max(0.0, (sqrt($gap) / $radii[$s]) - 1.0 - $others);
                $step = $next - $lambda[$s];
                foreach ($sum as $i => $value) {
                    $sum[$i] = $value + ($step * $centre[$i]);
                }
                $total += $step;
                $lambda[$s] = $next;
                $largestStep = max($largestStep, abs($step));
            }
            foreach ($bounds as $i => [$floor, $ceiling]) {
                $free = $sum[$i] + $beta[$i];
                $held = (1.0 + $total) * max($floor, min($ceiling, $free / (1.0 + $total)));
                $largestStep = max($largestStep, abs(($free - $held) - $beta[$i]));
                $beta[$i] = $free - $held;
                $sum[$i] = $held;
            }
            if ($largestStep <= self::TOLERANCE * (1.0 + $total)) {
                break;
            }
        }
        $x = array_map(static fn(float $value): float => $value / (1.0 + $total), $sum);
        foreach ($bounds as $i => [$floor, $ceiling]) {
            $x[$i] = max($floor, min($ceiling, $x[$i]));
        }

        // Every supporter's ball holds the levers in force on its surface, and the levers in force sit within every
        // bound, so should the sweeps stop short, drawing the budget back toward them along the line between keeps it
        // inside all of them.
        $move = array_map(static fn(float $a, float $b): float => $a - $b, $x, $q);
        $length = self::dot($move, $move);
        if (self::overshoots($x, $centres, $radii) && $length > 0.0) {
            $reach = 1.0;
            foreach ($centres as $centre) {
                $reach = min($reach, 2.0 * self::dot($move, array_map(static fn(float $a, float $b): float => $a - $b, $centre, $q)) / $length);
            }
            $reach = max(0.0, $reach);
            $x = array_map(static fn(float $a, float $b): float => $b + ($reach * $a), $move, $q);
        }

        return $x;
    }

    /**
     * Whether a budget lies past any supporter's indifference, beyond the rounding left to the sweeps.
     *
     * @param array<int, float> $x
     * @param list<list<float>> $centres
     * @param list<float>       $radii
     */
    private static function overshoots(array $x, array $centres, array $radii): bool
    {
        foreach ($centres as $s => $centre) {
            if (self::distance($x, $centre) > $radii[$s] * (1.0 + self::INDIFFERENCE_SLACK)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The levers of the questions a party is defined by (AerieDiet::FIXED_POSITIONS), on which it draws its red lines.
     *
     * @return list<string>
     */
    public static function redLines(string $party): array
    {
        $owned = AerieDiet::FIXED_POSITIONS[$party] ?? [];

        return array_keys(array_filter(PoliticsEngine::LEVER_AXES, static fn(string $axis): bool => isset($owned[$axis])));
    }

    /**
     * The weight a party gives each lever: one over its span between the ends of its question, times the square root of
     * the share of that question it carries, so a whole question crossed end to end counts one.
     *
     * @return array<string, float>
     */
    public static function scales(): array
    {
        if (self::$scales !== null) {
            return self::$scales;
        }

        $ends = [];
        foreach (AerieDiet::FIXED_POSITIONS as $fixed) {
            foreach ($fixed as $axis => $value) {
                $ends[$axis] = [min($ends[$axis][0] ?? 0.0, $value), max($ends[$axis][1] ?? 0.0, $value)];
            }
        }
        $perAxis = array_count_values(PoliticsEngine::LEVER_AXES);
        $targets = array_column(AerieDiet::DEBT_PLANKS, 'target');
        $paces = array_column(AerieDiet::DEBT_PLANKS, 'pace');

        $scales = [];
        foreach (PoliticsEngine::LEVER_AXES as $lever => $axis) {
            $span = match ($lever) {
                'debtTarget' => max($targets) - min($targets),
                'debtPace' => max($paces) - min($paces),
                default => abs(PoliticsEngine::platform([$axis => $ends[$axis][1]])[$lever] - PoliticsEngine::platform([$axis => $ends[$axis][0]])[$lever]),
            };
            $scales[$lever] = sqrt(1.0 / $perAxis[$axis]) / $span;
        }

        return self::$scales = $scales;
    }

    /**
     * How far apart two budgets stand in the parties' weighing.
     *
     * @param array<string, float> $a
     * @param array<string, float> $b
     */
    public static function separation(array $a, array $b): float
    {
        $scales = self::scales();
        $squares = 0.0;
        foreach ($scales as $lever => $scale) {
            $squares += (($a[$lever] - $b[$lever]) * $scale) ** 2;
        }

        return sqrt($squares);
    }

    /**
     * @param array<int, float> $a
     * @param array<int, float> $b
     */
    private static function distance(array $a, array $b): float
    {
        $squares = 0.0;
        foreach ($a as $i => $value) {
            $squares += ($value - $b[$i]) ** 2;
        }

        return sqrt($squares);
    }

    /**
     * @param array<int, float> $a
     * @param array<int, float> $b
     */
    private static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $i => $value) {
            $sum += $value * $b[$i];
        }

        return $sum;
    }
}
