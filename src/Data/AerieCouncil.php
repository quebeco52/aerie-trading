<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The Aerie Council: the District's executive, thirteen technocrats on staggered twenty-year terms.
 *
 * A councillor whose term ends is replaced by one the sitting members elect, so the Council renews itself one seat
 * at a time and no election touches it. The founding seats were staggered so one falls vacant about every year and a
 * half, as the Federal Reserve Board's seven fourteen-year seats fall one every two years. Like the election
 * calendar, the roster is derived from simulation time and never stored: seat k's holder at any moment follows from
 * the date alone.
 *
 * The Council appoints the heads of the District's departments, which then act independently of it. It holds a veto
 * over the Diet's budgets and laws that it almost never casts, and the Diet can remove a councillor with three
 * quarters of its seats, which it never has. No party sits on the Council.
 */
final class AerieCouncil
{
    // --- Composition ---
    /** Seats on the Council. */
    public const SEATS = 13;
    /** Length of a full term, in years. */
    public const TERM_YEARS = 20.0;

    /** The founding councillors, by seat. */
    public const FOUNDING_MEMBERS = [
        'Adelaide Voss',
        'Tobias Renwick',
        'Mireille Castellane',
        'Harlan Oduya',
        'Seraphine Kalder',
        'Emory Lindqvist',
        'Beatrix Hallorann',
        'Cassius Mbeki-Rowe',
        'Ottoline Fairweather',
        'Lucan Treadwell',
        'Imogen Sarkis',
        'Percival Achterberg',
        'Rosalind Okafor',
    ];

    /** Successors the Council co-opts, taken in turn as seats fall vacant. */
    public const SUCCESSORS = [
        'Anselm Drakeford', 'Clementine Ashgrove', 'Dorian Velasquez-Hart', 'Eudora Pryce', 'Fenwick Sato',
        'Genevieve Marchetti', 'Horatio Blackwood', 'Isolde Varga', 'Jasper Quenneville', 'Katarina Wilde',
        'Leopold Aske', 'Marguerite Oyelaran', 'Nathaniel Crewe', 'Octavia Brandt', 'Ptolemy Hargreave',
        'Quilla Dunmore', 'Rafferty Lowe', 'Sabine Castellanos', 'Thaddeus Mirren', 'Ursula Kincaid',
        'Valentin Soren', 'Wilhelmina Tate', 'Xavier Aldous', 'Yevgenia Marsh', 'Zephyr Calloway',
        'Augustin Pell', 'Bettina Rourke', 'Cyprian Holt', 'Delphine Arkwright', 'Evander Nakamura',
        'Felicity Graves', 'Gideon Ostrova', 'Henrietta Vale', 'Ignatius Farrow', 'Juno Whitlock',
        'Kasimir Deane', 'Lavinia Stroud', 'Magnus Everly', 'Noemi Castel',
    ];

    // --- Departments ---
    /**
     * The departments the Council staffs, each with its mandate and the page that shows its work.
     *
     * @var list<array{name: string, mandate: string, href: string|null}>
     */
    public const DEPARTMENTS = [
        ['name' => 'Monetary Authority', 'mandate' => 'Sets the policy rate by its published rule. Independent of the Diet by charter.', 'href' => '/economy'],
        ['name' => 'Sovereign Reserve Fund', 'mandate' => 'Invests the reserves and pays the budget its rule draw. Holds the second key: no draw on the reserves passes without it.', 'href' => '/reserve'],
        ['name' => 'Financial Regulator', 'mandate' => 'Sets bank capital and the countercyclical buffer.', 'href' => null],
        ['name' => 'Treasury', 'mandate' => 'Executes the budget the Diet passes and manages the District\'s debt.', 'href' => null],
        ['name' => 'Trade & Migration Office', 'mandate' => 'Administers the tariff schedule and the migration quotas the Diet legislates.', 'href' => null],
    ];

    /**
     * When seat k's founding term ends: the founders' terms were staggered evenly over one full term.
     */
    public static function foundingTermEnd(int $seat): float
    {
        return ($seat + 1) * self::TERM_YEARS / self::SEATS;
    }

    /**
     * The Council at a moment: each seat's holder, when they took it, and when their term ends.
     *
     * @return list<array{seat: int, name: string, since: float, termEnds: float, founding: bool}>
     */
    public static function roster(float $simTime): array
    {
        $roster = [];
        for ($seat = 0; $seat < self::SEATS; ++$seat) {
            $firstEnd = self::foundingTermEnd($seat);
            // Terms completed on this seat, the founder's included.
            $completed = $simTime < $firstEnd ? 0 : 1 + (int) floor(($simTime - $firstEnd) / self::TERM_YEARS);

            $roster[] = [
                'seat' => $seat + 1,
                'name' => $completed === 0
                    ? self::FOUNDING_MEMBERS[$seat]
                    : self::SUCCESSORS[(($completed - 1) * self::SEATS + $seat) % count(self::SUCCESSORS)],
                'since' => $completed === 0 ? 0.0 : $firstEnd + (($completed - 1) * self::TERM_YEARS),
                'termEnds' => $firstEnd + ($completed * self::TERM_YEARS),
                'founding' => $completed === 0,
            ];
        }

        return $roster;
    }

    /**
     * The next seat to fall vacant after a moment.
     *
     * @return array{seat: int, name: string, since: float, termEnds: float, founding: bool}
     */
    public static function nextVacancy(float $simTime): array
    {
        $roster = self::roster($simTime);
        usort($roster, static fn(array $a, array $b): int => $a['termEnds'] <=> $b['termEnds']);

        return $roster[0];
    }
}
