<?php

declare(strict_types=1);

namespace App\Data\Politics;

/**
 * The names the District's public figures go by, drawn as US officeholders are named (App\Data\Politics\UsNameFrequencies): a
 * surname by its 2010 Census frequency and its bearer's race or ethnicity group by the surname's shares, post-stratified
 * so the groups come in the US Congress's mix (Holt & Smith 1979); then a given name of the person's sex from the Social
 * Security applicants born in their decade, weighted by that group's share of the name's bearers (Bayes' rule, given name
 * and surname independent within a group, as in the BIFSG proxy; Voicu 2018).
 *
 * Every draw is a uniform the caller hashes from the game and the vacancy (App\Service\Politics\CouncilAppointments::uniform()),
 * so every candidate for the Council, the Authority, the Regulator and the Fund, and every party leader, is someone.
 */
final class AerieNames
{
    // --- Calendar ---
    /** The mainland year Year 1 begins in (2009Q1): someone born t years after Year 1 began was born in 2009 + t. */
    public const YEAR_ONE_CALENDAR_YEAR = 2009;

    // --- Officeholders ---
    /** Voting members of the 119th Congress (Jan 2025, Pew Research) in UsNameFrequencies::GROUPS order, each once: its 3 Black Hispanic members as Hispanic, its 2 Black and Asian members as two or more races, as the Census places them. */
    public const OFFICEHOLDERS = [394, 61, 19, 4, 2, 53];

    // --- Gender Balance ---
    /** Share of the District's public figures who are men (70%). */
    public const MALE_SHARE = 0.70;

    /** @var array{names: list<string>, cumulative: list<float>, groups: list<list<float>>}|null */
    private static ?array $surnames = null;

    /** @var array<string, array{names: list<string>, cumulative: list<float>}> Keyed by decade, sex and group. */
    private static array $given = [];

    /** @var array<string, int>|null */
    private static ?array $famous = null;

    /**
     * A name for someone born at $birth that no one in $taken holds and no famous person bears, drawn again on fresh
     * uniforms until one is.
     *
     * @param float                   $birth   Birth date, in years after Year 1 began (negative before it).
     * @param \Closure(string): float $uniform A uniform on (0, 1) for each key asked of it.
     * @param list<string>            $taken
     */
    public static function draw(float $birth, \Closure $uniform, array $taken): string
    {
        for ($attempt = 0; ; ++$attempt) {
            $name = self::pick($birth, $uniform("surname:{$attempt}"), $uniform("group:{$attempt}"), $uniform("sex:{$attempt}"), $uniform("given:{$attempt}"));
            if (!self::isFamous($name) && !in_array($name, $taken, true)) {
                return $name;
            }
        }
    }

    /**
     * A full name for someone born at $birth (years after Year 1 began), from four uniforms on [0, 1): the surname, its
     * bearer's group, their sex and their given name.
     */
    public static function pick(float $birth, float $surnameDraw, float $groupDraw, float $sexDraw, float $givenDraw): string
    {
        [$surname, $group] = self::surname($surnameDraw, $groupDraw);
        $sex = self::clamp($sexDraw) < self::MALE_SHARE ? 'M' : 'F';

        return self::givenName(self::birthDecade($birth), $sex, $group, $givenDraw) . ' ' . $surname;
    }

    /**
     * A surname, and its bearer's group as an index into UsNameFrequencies::GROUPS: each surname-group cell weighted by
     * its bearers, times the group's share of OFFICEHOLDERS over its share among the listed surnames' bearers.
     *
     * @return array{0: string, 1: int}
     */
    public static function surname(float $surnameDraw, float $groupDraw): array
    {
        $table = self::surnameTable();
        $index = self::search($table['cumulative'], $surnameDraw);

        return [$table['names'][$index], self::search($table['groups'][$index], $groupDraw)];
    }

    /**
     * A given name for someone of $sex ('M' or 'F') and $group born in $decade: each name among the decade's applicants
     * weighted by its applicants times the group's share of its bearers.
     */
    public static function givenName(int $decade, string $sex, int $group, float $draw): string
    {
        $key = "{$decade}:{$sex}:{$group}";
        if (!isset(self::$given[$key])) {
            $names = $cumulative = [];
            $sum = 0.0;
            foreach (UsNameFrequencies::GIVEN[$decade][$sex] as $name => $applicants) {
                $sum += $applicants * (UsNameFrequencies::GIVEN_SHARES[$name] ?? UsNameFrequencies::GIVEN_UNLISTED_SHARES)[$group];
                $names[] = (string) $name;
                $cumulative[] = $sum;
            }
            self::$given[$key] = ['names' => $names, 'cumulative' => $cumulative];
        }

        return self::$given[$key]['names'][self::search(self::$given[$key]['cumulative'], $draw)];
    }

    /** The decade whose applicants name someone born at $birth: their own, or the nearest the tables hold. */
    public static function birthDecade(float $birth): int
    {
        $decade = (int) (floor((self::YEAR_ONE_CALENDAR_YEAR + $birth) / 10.0) * 10);

        return max(array_key_first(UsNameFrequencies::GIVEN), min(array_key_last(UsNameFrequencies::GIVEN), $decade));
    }

    /** Whether a famous person holds the name. */
    public static function isFamous(string $name): bool
    {
        self::$famous ??= array_flip(UsNameFrequencies::FAMOUS);

        return isset(self::$famous[$name]);
    }

    /** @return array{names: list<string>, cumulative: list<float>, groups: list<list<float>>} */
    private static function surnameTable(): array
    {
        if (self::$surnames !== null) {
            return self::$surnames;
        }

        $listed = array_fill(0, count(UsNameFrequencies::GROUPS), 0.0);
        foreach (UsNameFrequencies::SURNAMES as $row) {
            foreach (array_slice($row, 1) as $group => $share) {
                $listed[$group] += $row[0] * $share;
            }
        }
        $officeholders = array_sum(self::OFFICEHOLDERS);
        $bearers = array_sum($listed);
        $strata = [];
        foreach ($listed as $group => $weight) {
            $strata[$group] = (self::OFFICEHOLDERS[$group] / $officeholders) / ($weight / $bearers);
        }

        $names = $cumulative = $groups = [];
        $sum = 0.0;
        foreach (UsNameFrequencies::SURNAMES as $surname => $row) {
            $cells = [];
            $cell = 0.0;
            foreach (array_slice($row, 1) as $group => $share) {
                $cell += $row[0] * $share * $strata[$group];
                $cells[] = $cell;
            }
            $sum += $cell;
            $names[] = (string) $surname;
            $cumulative[] = $sum;
            $groups[] = $cells;
        }

        return self::$surnames = ['names' => $names, 'cumulative' => $cumulative, 'groups' => $groups];
    }

    /**
     * The first entry whose running weight passes the draw's share of the total: an inverse-CDF draw, entries of no
     * weight never chosen.
     *
     * @param list<float> $cumulative Running weights, non-decreasing.
     */
    private static function search(array $cumulative, float $draw): int
    {
        $target = self::clamp($draw) * $cumulative[count($cumulative) - 1];
        $low = 0;
        $high = count($cumulative) - 1;
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($cumulative[$middle] > $target) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }

        return $low;
    }

    private static function clamp(float $draw): float
    {
        return max(0.0, min(1.0 - PHP_FLOAT_EPSILON, $draw));
    }
}
