<?php

declare(strict_types=1);

namespace App\Data\Politics;

use App\Data\District\Institutions;

/**
 * The Aerie Council: the District's executive, thirteen leaders on staggered twelve-year terms.
 *
 * A councillor does not run policy. Each holds a stance on how the District should lean on each question a department
 * answers, a hawk's or a dove's on money, a light or a strict hand on the banks, a cautious or a bold one with the
 * reserves, and the Council's work is its appointments. A councillor whose term ends is replaced by the candidate the
 * sitting members elect from a shortlist of three that the Council Appointment Board puts forward, its members named by
 * the District's bar, judges, universities and the Aerie Exchange, after Canada's advisory board for its Supreme Court
 * (App\Service\Politics\CouncilAppointments::boardShortlist()); so the Council renews itself one seat at a time, no
 * election touches it, and it cannot fill itself with its own likeness for good. The
 * seats are staggered so one falls vacant a little under once a year, as the Federal Reserve Board's seven
 * fourteen-year seats fall one every two years; the councillors sitting at Year 1 took their seats before the District's
 * records begin. A councillor who dies or resigns mid-term is succeeded the same way, the successor serving out the
 * term (App\Service\Politics\CouncilAppointments::departure()). Like the election calendar, the seats' terms are
 * derived from simulation time and never stored; who holds them, chosen from the shortlists, is kept in the politics
 * state.
 *
 * The Council appoints the heads of the District's departments, which then act independently of it: the Monetary
 * Authority's governor, who picks the rate committee (App\Service\Politics\MonetaryAuthority), the Financial
 * Regulator's head, who sets the banks' capital requirement (App\Service\Politics\FinancialRegulator), the
 * Sovereign Reserve Fund's head, who sets its share in equities (App\Service\Politics\SovereignReserveFund), the
 * Exchequer's head, who sets the debt's maturity (App\Service\Politics\Exchequer), the Fair Trading Board's head, who
 * sets the screens merger review applies (App\Service\Politics\FairTradingBoard), and the Securities Commission's
 * head, who sets the margin investors put up and what listed firms must disclose
 * (App\Service\Politics\SecuritiesCommission). It also names the Security Bureau's director, who sets no rule
 * (App\Service\Politics\SecurityBureau). The budget is the Diet's alone. No party sits on the Council.
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
    /** The Exchequer's head sitting at Year 1, named before it. */
    public const OPENING_EXCHEQUER_HEAD = 'Carol Hollis';
    /** The Fair Trading Board's head sitting at Year 1, named before it. */
    public const OPENING_FAIR_TRADING_HEAD = 'Martin Ingram';
    /** The Securities Commission's head sitting at Year 1, named before it. */
    public const OPENING_SECURITIES_HEAD = 'Denise Carver';
    /** The Security Bureau's director sitting at Year 1, named before it. */
    public const OPENING_SECURITY_DIRECTOR = 'Raymond Okafor';

    // --- Departments ---
    /**
     * The departments the Council staffs, each with its mandate and the page that shows its work.
     *
     * @var list<array{name: string, mandate: string, href: string|null}>
     */
    public const DEPARTMENTS = [
        ['name' => Institutions::MONETARY_AUTHORITY, 'mandate' => 'Sets the policy rate by its published rule. Its governor, named for one term, picks the rate committee. Independent of the Diet by charter.', 'href' => '/authority'],
        ['name' => Institutions::SOVEREIGN_RESERVE_FUND, 'mandate' => 'Invests the reserves and pays the budget its rule draw. Its head, named for one term, sets how much of the fund is in shares. Holds the second key: no draw on the reserves passes without it.', 'href' => '/reserve'],
        ['name' => Institutions::FINANCIAL_REGULATOR, 'mandate' => 'Sets the core capital banks must hold against their loans and the most a home buyer may borrow against the property. Its head, named for one term, decides both.', 'href' => '/regulator'],
        ['name' => Institutions::EXCHEQUER, 'mandate' => 'Executes the budget the Diet passes and funds it. Its head, named for one term, decides how long the District borrows for.', 'href' => '/exchequer'],
        ['name' => Institutions::FAIR_TRADING_BOARD, 'mandate' => 'Reviews the mergers of the District\'s firms. Its head, named for one term, sets how concentrated a market may become and how much of it one firm may hold after a deal.', 'href' => '/fair-trading'],
        ['name' => Institutions::SECURITIES_COMMISSION, 'mandate' => 'Sets the rules of the Aerie Exchange. Its head, named for one term, decides the margin an investor must put up to buy or short on credit, and how hard it pursues listed companies that dress up their results.', 'href' => '/securities'],
        ['name' => Institutions::SECURITY_BUREAU, 'mandate' => 'The District\'s investigative police. A centre of world finance with friends on the mainland and enemies abroad draws organised crime and foreign agents as no ordinary city does, and the Bureau exists to keep them out. Its director is named for a single ten-year term, twice as long as any other head\'s.', 'href' => '/security-bureau'],
        ['name' => Institutions::TRADE_MIGRATION_OFFICE, 'mandate' => 'Administers the tariff schedule, the migration quotas and the tax on foreign home buyers the Diet legislates.', 'href' => '/trade-office'],
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
