<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The Aerie Council: the District's executive, thirteen leaders on staggered twelve-year terms.
 *
 * A councillor does not run policy. Each holds a stance on how the District should lean on each question a department
 * answers, a hawk's or a dove's on money, a light or a strict hand on the banks, a cautious or a bold one with the
 * reserves, and the Council's work is its appointments. A councillor whose term ends is replaced by the candidate on a
 * shortlist the sitting members elect, so the Council renews itself one seat at a time and no election touches it. The
 * seats are staggered so one falls vacant a little under once a year, as the Federal Reserve Board's seven
 * fourteen-year seats fall one every two years; the councillors sitting at Year 1 took their seats before the District's
 * records begin. Like the election calendar, the seats' terms are derived from simulation time and never stored; who
 * holds them, chosen from the shortlists, is kept in the politics state.
 *
 * The Council appoints the heads of the District's departments, which then act independently of it: the Monetary
 * Authority's governor, who picks the rate committee (App\Service\Politics\MonetaryAuthority), the Financial
 * Regulator's head, who sets the banks' capital requirement (App\Service\Politics\FinancialRegulator), and the
 * Sovereign Reserve Fund's head, who sets its share in equities (App\Service\Politics\SovereignReserveFund). It holds a veto
 * over the Diet's budgets and laws that it almost never casts, and the Diet can remove a councillor with three
 * quarters of its seats, which it never has. No party sits on the Council.
 */
final class AerieCouncil
{
    // --- Composition ---
    /** Seats on the Council. */
    public const SEATS = 13;
    /** Length of a councillor's single term, in years: the twelve years a judge of Germany's Federal Constitutional Court serves (BVerfGG s. 4), the unelected body answerable to a parliament the Council most resembles. */
    public const TERM_YEARS = 12.0;

    /** The councillors sitting at Year 1, by seat, seated before the District's records begin. */
    public const OPENING_MEMBERS = [
        'George Duncan',
        'Ruben Velasco',
        'Richard Bradford',
        'Jon Keyes',
        'Joyce Hall',
        'Peter Scott',
        'Nancy Tellez',
        'Barbara Barnes',
        'Johnny McLaughlin',
        'Daniel Langston',
        'Ronald Wynn',
        'Joseph Dunn',
        'David Hoffman',
    ];

    /** The Monetary Authority's governor sitting at Year 1, named before it. */
    public const OPENING_GOVERNOR = 'Patricia Lewis';
    /** The Financial Regulator's head sitting at Year 1, named before it. */
    public const OPENING_REGULATOR = 'Linda Clements';
    /** The Sovereign Reserve Fund's head sitting at Year 1, named before it. */
    public const OPENING_FUND_HEAD = 'Richard Bland';

    // --- Departments ---
    /**
     * The departments the Council staffs, each with its mandate and the page that shows its work.
     *
     * @var list<array{name: string, mandate: string, href: string|null}>
     */
    public const DEPARTMENTS = [
        ['name' => 'Monetary Authority', 'mandate' => 'Sets the policy rate by its published rule. Its governor, named for one term, picks the rate committee. Independent of the Diet by charter.', 'href' => '/economy'],
        ['name' => 'Sovereign Reserve Fund', 'mandate' => 'Invests the reserves and pays the budget its rule draw. Its head, named for one term, sets how much of the fund is in shares. Holds the second key: no draw on the reserves passes without it.', 'href' => '/reserve'],
        ['name' => 'Financial Regulator', 'mandate' => 'Sets the core capital banks must hold against their loans. Its head, named for one term, decides how much.', 'href' => null],
        ['name' => 'Treasury', 'mandate' => 'Executes the budget the Diet passes and manages the District\'s debt.', 'href' => null],
        ['name' => 'Trade & Migration Office', 'mandate' => 'Administers the tariff schedule and the migration quotas the Diet legislates.', 'href' => null],
    ];

    /**
     * When the term of seat k's councillor sitting at Year 1 ends: the seats fall vacant evenly over one full term, half
     * a spacing apart from Year 1, so every councillor sitting at it took their seat before it.
     */
    public static function openingTermEnd(int $seat): float
    {
        return ($seat + 0.5) * self::TERM_YEARS / self::SEATS;
    }

    /**
     * The Council's seats at a moment: when each holder took theirs (before Year 1, negative, for those sitting at it),
     * and when their term ends. Who holds them is the politics state's (App\DTO\PoliticsStateDTO::$councilNames).
     *
     * @return list<array{seat: int, since: float, termEnds: float, beforeYearOne: bool}>
     */
    public static function roster(float $simTime): array
    {
        $roster = [];
        for ($seat = 0; $seat < self::SEATS; ++$seat) {
            $firstEnd = self::openingTermEnd($seat);
            // Terms completed on this seat since Year 1, the sitting councillor's included.
            $completed = $simTime < $firstEnd ? 0 : 1 + (int) floor(($simTime - $firstEnd) / self::TERM_YEARS);

            $roster[] = [
                'seat' => $seat + 1,
                'since' => $firstEnd + (($completed - 1) * self::TERM_YEARS),
                'termEnds' => $firstEnd + ($completed * self::TERM_YEARS),
                'beforeYearOne' => $completed === 0,
            ];
        }

        return $roster;
    }

    /**
     * The next seat to fall vacant after a moment.
     *
     * @return array{seat: int, since: float, termEnds: float, beforeYearOne: bool}
     */
    public static function nextVacancy(float $simTime): array
    {
        $roster = self::roster($simTime);
        usort($roster, static fn(array $a, array $b): int => $a['termEnds'] <=> $b['termEnds']);

        return $roster[0];
    }
}
