<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\Politics\AerieCouncil;
use App\Data\Politics\AerieNames;
use App\Service\Math\Distributions;
use App\Service\Math\MathUtility;

/**
 * How the Council fills a seat, its own or a department's: a shortlist of three candidates, and the one whose stances
 * stand nearest the appointer's named.
 *
 * Everyone the Council seats or appoints holds a stance on each question a department of the Council answers, on a
 * scale from -1 to +1: on money (App\Service\Politics\MonetaryAuthority), a dove at -1 or a hawk at +1; on the banks
 * (App\Service\Politics\FinancialRegulator), light-touch at -1 or strict at +1; on the reserves
 * (App\Service\Politics\SovereignReserveFund), cautious at -1 or bold at +1. A candidate's stances are drawn from the
 * record of the real office-holders each department is modelled on, and their age from the Federal Reserve Board's.
 * A vacant Council seat goes to the candidate nearest the sitting members' median on every question together (the
 * coordinate-wise median, as a majority vote picks on each), from a shortlist the Council Appointment Board puts
 * forward: the three applicants nearest the board's own median, out of the field that applied. The board's members are
 * named by outside bodies, never by the Council, so a Council cannot renew itself in its own image for good, as it did
 * when it drew its own shortlists. A department head goes to the candidate nearest the whole Council's median on that
 * department's question, from a shortlist drawn as it comes.
 *
 * Every draw is hashed from a salt the Council draws once, the vacancy and the candidate's place on the shortlist, so
 * the appointments replay exactly and take nothing more from the politics engine's random stream.
 */
final class CouncilAppointments
{
    // --- The Questions ---
    /** The question of money: the Monetary Authority's. */
    public const AXIS_MONEY = 'money';
    /** The question of the banks: the Financial Regulator's. */
    public const AXIS_REGULATION = 'regulation';
    /** The question of the reserves: the Sovereign Reserve Fund's. */
    public const AXIS_FUND = 'fund';

    // --- Candidates ---
    /** Candidates on each shortlist: from three, a dovish appointer names a dove 60% of the time (else a swing vote leaning dovish, or no one leaning their way), as the Democratic presidents' nominees to the Federal Reserve Board were 65% doves, 17 of 26 (Bordo & Istrefi 2023, Fig. 4). */
    public const SHORTLIST = 3;
    /** Mean age at appointment, in years: the Federal Reserve Board's 101 governors at their first oath, 1914-2026. */
    public const APPOINTMENT_AGE_MEAN = 52.56;
    /** Standard deviation of age at appointment; the Board's percentiles fit a normal (10th 43.7, median 52.9, 90th 60.5). */
    public const APPOINTMENT_AGE_SD = 7.32;
    /** Youngest age the charter allows at appointment, where the draw is truncated: forty, as for a judge of Germany's Federal Constitutional Court (BVerfGG s. 3); the Board's record runs from 35.9, its 10th percentile 43.7. */
    public const APPOINTMENT_AGE_MIN = 40.0;
    /** Oldest age the charter allows at appointment, so no councillor sits past 72; the Board's record runs to 71.7, its 90th percentile 60.5. */
    public const APPOINTMENT_AGE_MAX = 60.0;

    // --- The Council Appointment Board (after Canada's Independent Advisory Board for Supreme Court Appointments) ---
    /** Members of the board, each named by an outside body: seven, as on Canada's advisory board. */
    public const BOARD_SEATS = 7;
    /** Length of a board member's term, in years, staggered across the seats: Canada's board members serve terms of up to five years (Terms of Reference, quoted in the board's 2019-2023 reports). */
    public const BOARD_TERM_YEARS = 5.0;
    /** Applicants for each Council seat, from whom the board puts forward SHORTLIST: Canada's board received 12 to 18 applications a vacancy, 2017-2023, median 13.5 (2016's first call drew 31). */
    public const BOARD_FIELD = 14;

    // --- Vacancies ---
    /** Gompertz level of the yearly death hazard at age 0, fitted with MORTALITY_SLOPE through ages 50 and 70 of the US life table, both sexes averaged (q 0.0053 and 0.0222; CDC/NCHS, United States Life Tables 2021, NVSR 72-12, Tables 2-3). */
    public const MORTALITY_LEVEL = 1.457e-4;
    /** Gompertz slope: the death hazard's growth per year of age, 7.2% (doubling every 9.6 years), from the same fit; it reads 0.0109 at 60 against the table's 0.0116. */
    public const MORTALITY_SLOPE = 0.0720;
    /** Yearly hazard a councillor resigns before their term ends, at any age: the ECB Executive Board's 6 early departures in 169 member-years, 1998-2026 (to other posts, in protest, or by political deal), none by death. */
    public const RESIGNATION_RATE = 0.035;

    /**
     * One tick for the Council itself: drawn on the first read of a state without one, then each seat whose term has
     * ended refilled. A state whose councillors predate a question has their stances on it drawn once.
     *
     * @param PoliticsState $state The politics, advanced in place; its totalTime already set to this tick's.
     */
    public static function advance(PoliticsState $state, MathUtility $math): void
    {
        $time = $state->totalTime;
        if ($state->authoritySalt < 0.0) {
            self::open($state, $time, $math);

            return;
        }
        $salt = (int) $state->authoritySalt;
        $roster = AerieCouncil::roster($time);
        self::backfillStances($state, $salt);

        // A charter that changed the term moves the sitting councillors onto the new schedule: the same people, their
        // seats dated as the new terms would have run, so the change itself fills no seat.
        if ($state->councilTermYears !== AerieCouncil::TERM_YEARS) {
            foreach ($roster as $seat => $holder) {
                if (isset($state->councilNames[$seat])) {
                    $state->councilSince[$seat] = $holder['since'];
                    unset($state->councilSeatedAt[$seat]);
                }
            }
            $state->councilTermYears = AerieCouncil::TERM_YEARS;
        }
        self::backfillDepartures($state, $salt, $roster, $time);

        // Seats due this tick, a board member's term, a councillor's term or a vacancy, filled in the order they fell due,
        // so a long tick fills them as a short one would.
        $due = [];
        foreach (self::boardTermStarts($time) as $seat => $since) {
            if (abs(($state->boardSince[$seat] ?? -INF) - $since) > 1e-9) {
                $due[] = ['kind' => 'board', 'seat' => $seat, 'at' => $since];
            }
        }
        foreach ($roster as $seat => $holder) {
            if (abs(($state->councilSince[$seat] ?? -INF) - $holder['since']) > 1e-9) {
                $due[] = ['kind' => 'term', 'seat' => $seat, 'at' => $holder['since']];
            } elseif (($leaves = $state->councilLeavesAt[$seat] ?? -1.0) >= 0.0 && $leaves <= $time) {
                $due[] = ['kind' => 'vacancy', 'seat' => $seat, 'at' => $leaves];
            }
        }
        usort($due, static fn(array $a, array $b): int => [$a['at'], $a['kind'] === 'board' ? 0 : 1, $a['seat']] <=> [$b['at'], $b['kind'] === 'board' ? 0 : 1, $b['seat']]);

        foreach ($due as ['kind' => $kind, 'seat' => $seat, 'at' => $at]) {
            if ($kind === 'board') {
                self::seatBoardMember($state, $salt, $seat, $at, $math);
                continue;
            }
            $holder = $roster[$seat];
            if ($kind === 'vacancy') {
                // A seat left vacant mid-term goes to a successor who serves out the rest of the term.
                $state->lastVacancyName = $state->councilNames[$seat];
                $state->lastVacancyCause = self::departureCause($salt, $seat, $state->councilSeatedAt[$seat] ?? $holder['since'], $at - $state->councilBirths[$seat]);
                $state->lastCouncilVacancyAt = $time;
            }
            $shortlist = self::boardShortlist($salt, "council:{$seat}", $at, self::boardMedians($state), self::reservedNames($state), $math);
            [$chosen, $state->councillorPassedOver] = self::choose($shortlist, self::councilMedians($state, $seat));
            self::seatCouncillor($state, $salt, $seat, $chosen, $holder['since'], $at, $holder['termEnds'], $at);
            $state->lastCouncillorSeatedAt = $time;
        }
    }

    /**
     * The Council as it stood at Year 1, or as it stands when a state that predates it is first read: the salt drawn,
     * and the councillors sitting drawn as candidates, each at an age drawn at their seating, the ones seated before
     * Year 1 under their own names.
     */
    private static function open(PoliticsState $state, float $time, MathUtility $math): void
    {
        $state->authoritySalt = floor($math->generateUniform() * (2 ** 31));
        $state->councilTermYears = AerieCouncil::TERM_YEARS;
        $salt = (int) $state->authoritySalt;
        $state->councilNames = $state->councilBirths = $state->councilSince = $state->councilSeatedAt = $state->councilLeavesAt = $state->councilSwingers = $state->councilStances = $state->councilRegulationStances = $state->councilFundStances = [];
        $state->memberNames = $state->memberBirths = $state->memberSince = $state->memberStances = [];
        $state->governorName = '';
        $state->regulatorName = '';
        $state->fundHeadName = '';
        $state->formerNames = [];
        $state->boardMembers = $state->boardSince = [];
        self::seatBoard($state, $salt, $time, $math);

        foreach (AerieCouncil::roster($time) as $seat => $holder) {
            $drawn = self::candidate($salt, self::vacancyKey("council:{$seat}", $holder['since']), 0, $holder['since'], self::reservedNames($state), $math);
            if ($holder['beforeYearOne']) {
                $drawn['name'] = AerieCouncil::OPENING_MEMBERS[$seat];
            }
            self::seatCouncillor($state, $salt, $seat, $drawn, $holder['since'], $holder['since'], $holder['termEnds'], $time);
        }
        $state->councillorPassedOver = [];
    }

    /**
     * A vacancy filled: a shortlist drawn, and the candidate whose stances stand nearest the appointer's on the questions
     * the target names named, by straight-line distance; the first drawn of any tied.
     *
     * @param string               $seat   The office and seat, e.g. "council:4", "governor", "member:2", "regulator", "fund".
     * @param float                $since  When the term begins.
     * @param array<string, float> $target The appointer's stance on each question it weighs, by axis.
     * @param list<string>         $taken  Names already sitting, which no candidate shares.
     * @return array{0: array{name: string, birth: float, stance: float, regulation: float, fund: float, swing?: float}, 1: list<array{name: string, birth: float, stance: float, regulation: float, fund: float, swing?: float}>} The one named, and the ones passed over.
     */
    public static function appoint(int $salt, string $seat, float $since, array $target, array $taken, MathUtility $math): array
    {
        return self::choose(self::field($salt, self::vacancyKey($seat, $since), self::SHORTLIST, $since, $taken, $math), $target);
    }

    /**
     * The appointer's pick from a shortlist: the candidate nearest their stances on the questions the target names, by
     * straight-line distance; the first drawn of any tied.
     *
     * @param list<array{name: string, birth: float, stance: float, regulation: float, fund: float, swing?: float}> $shortlist
     * @param array<string, float> $target
     * @return array{0: array{name: string, birth: float, stance: float, regulation: float, fund: float, swing?: float}, 1: list<array{name: string, birth: float, stance: float, regulation: float, fund: float, swing?: float}>} The one named, and the ones passed over.
     */
    public static function choose(array $shortlist, array $target): array
    {
        $chosen = 0;
        foreach ($shortlist as $slot => $candidate) {
            if (self::distance($candidate, $target) < self::distance($shortlist[$chosen], $target) - 1e-12) {
                $chosen = $slot;
            }
        }
        $named = $shortlist[$chosen];
        unset($shortlist[$chosen]);

        return [$named, array_values($shortlist)];
    }

    /**
     * The Council Appointment Board's shortlist for a Council seat: of the BOARD_FIELD who applied, the SHORTLIST nearest
     * the board's median on every question, the first drawn of any tied, in the order they applied.
     *
     * @param array<string, float> $boardMedians
     * @param list<string>         $taken
     * @return list<array{name: string, birth: float, stance: float, regulation: float, fund: float, swing?: float}>
     */
    public static function boardShortlist(int $salt, string $seat, float $since, array $boardMedians, array $taken, MathUtility $math): array
    {
        $field = self::field($salt, self::vacancyKey($seat, $since), self::BOARD_FIELD, $since, $taken, $math);
        $order = array_keys($field);
        usort($order, static fn(int $a, int $b): int => [round(self::distance($field[$a], $boardMedians), 12), $a] <=> [round(self::distance($field[$b], $boardMedians), 12), $b]);
        $kept = array_slice($order, 0, self::SHORTLIST);
        sort($kept);

        return array_map(static fn(int $slot): array => $field[$slot], $kept);
    }

    /**
     * The board's median on every question: what a majority of its members would put forward on each.
     *
     * @return array<string, float>
     */
    public static function boardMedians(PoliticsState|\App\DTO\PoliticsStateDTO $state): array
    {
        return [
            self::AXIS_MONEY => self::median(array_column($state->boardMembers, 'stance')),
            self::AXIS_REGULATION => self::median(array_column($state->boardMembers, 'regulation')),
            self::AXIS_FUND => self::median(array_column($state->boardMembers, 'fund')),
        ];
    }

    /**
     * Candidates for a vacancy, drawn one after another with names none of the others or anyone in $taken holds.
     *
     * @param list<string> $taken
     * @return list<array{name: string, birth: float, stance: float, regulation: float, fund: float, swing?: float}>
     */
    private static function field(int $salt, string $vacancy, int $count, float $since, array $taken, MathUtility $math): array
    {
        $field = [];
        for ($slot = 0; $slot < $count; ++$slot) {
            $field[] = $candidate = self::candidate($salt, $vacancy, $slot, $since, $taken, $math);
            $taken[] = $candidate['name'];
        }

        return $field;
    }

    /**
     * Straight-line distance between a candidate's stances and a target's on the questions the target names.
     *
     * @param array{name: string, birth: float, stance: float, regulation: float, fund: float, swing?: float} $candidate
     * @param array<string, float> $target
     */
    private static function distance(array $candidate, array $target): float
    {
        $squares = 0.0;
        foreach ($target as $axis => $stance) {
            $squares += ($candidate[self::stanceKey($axis)] - $stance) ** 2;
        }

        return sqrt($squares);
    }

    /**
     * When each board seat's term in progress began: the seats fall vacant evenly over one term, half a spacing off Year
     * 1, as the Council's do.
     *
     * @return list<float>
     */
    public static function boardTermStarts(float $time): array
    {
        $starts = [];
        for ($seat = 0; $seat < self::BOARD_SEATS; ++$seat) {
            $starts[] = self::termStart($time, ($seat + 0.5) * self::BOARD_TERM_YEARS / self::BOARD_SEATS, self::BOARD_TERM_YEARS);
        }

        return $starts;
    }

    /** Every board seat filled for the term in progress, as when the Council is first read. */
    private static function seatBoard(PoliticsState $state, int $salt, float $time, MathUtility $math): void
    {
        foreach (self::boardTermStarts($time) as $seat => $since) {
            self::seatBoardMember($state, $salt, $seat, $since, $math);
        }
    }

    /**
     * A board seat filled for a term: its outside body names its own member, one person drawn from the record as any
     * candidate is, with no shortlist and no say for the Council.
     */
    private static function seatBoardMember(PoliticsState $state, int $salt, int $seat, float $since, MathUtility $math): void
    {
        self::retire($state, $state->boardMembers[$seat]['name'] ?? '');
        $state->boardMembers[$seat] = self::candidate($salt, self::vacancyKey("board:{$seat}", $since), 0, $since, self::reservedNames($state), $math);
        $state->boardSince[$seat] = $since;
        ksort($state->boardMembers);
        ksort($state->boardSince);
    }

    /**
     * A candidate for a vacancy: their stance on money (App\Service\Politics\MonetaryAuthority::drawStance(), a swing vote
     * leaning to a camp drawn alike either way, MonetaryAuthority::swingLean()), on the
     * banks (App\Service\Politics\FinancialRegulator::drawStance()) and on the reserves
     * (App\Service\Politics\SovereignReserveFund::drawStance()), their age at the term's start from the Board's record
     * (a normal truncated to the youngest and oldest on it, by inverse transform), and a name for their birth decade
     * (App\Data\Politics\AerieNames) that no one in $taken holds.
     *
     * @param list<string> $taken
     * @return array{name: string, birth: float, stance: float, regulation: float, fund: float, swing?: float}
     */
    public static function candidate(int $salt, string $vacancy, int $slot, float $since, array $taken, MathUtility $math): array
    {
        $age = Distributions::truncatedNormalInverse(self::uniform($salt, "{$vacancy}:{$slot}:age"), self::APPOINTMENT_AGE_MEAN, self::APPOINTMENT_AGE_SD, self::APPOINTMENT_AGE_MIN, self::APPOINTMENT_AGE_MAX);
        $birth = $since - $age;
        $type = MonetaryAuthority::drawStance(self::uniform($salt, "{$vacancy}:{$slot}:stance"));
        $swing = $type === MonetaryAuthority::STANCES['swing'] ? 1.0 : 0.0;

        return [
            'name' => AerieNames::draw($birth, static fn(string $part): float => self::uniform($salt, "{$vacancy}:{$slot}:name:{$part}"), $taken),
            'birth' => $birth,
            'stance' => $swing > 0.0 ? MonetaryAuthority::swingLean(self::uniform($salt, "{$vacancy}:{$slot}:lean")) : $type,
            'regulation' => FinancialRegulator::drawStance(self::uniform($salt, "{$vacancy}:{$slot}:regulation")),
            'fund' => SovereignReserveFund::drawStance(self::uniform($salt, "{$vacancy}:{$slot}:fund")),
            'swing' => $swing,
        ];
    }

    /**
     * The sitting Council's median on every question, a vacant seat's holder left out: what a majority of the members
     * would pick on each.
     *
     * @return array<string, float>
     */
    public static function councilMedians(PoliticsState|\App\DTO\PoliticsStateDTO $state, ?int $vacant = null): array
    {
        $money = $state->councilStances;
        $regulation = $state->councilRegulationStances;
        $fund = $state->councilFundStances;
        if ($vacant !== null) {
            unset($money[$vacant], $regulation[$vacant], $fund[$vacant]);
        }

        return [self::AXIS_MONEY => self::median($money), self::AXIS_REGULATION => self::median($regulation), self::AXIS_FUND => self::median($fund)];
    }

    /** @param list<float>|array<int, float> $values */
    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        $values = array_values($values);
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2.0;
    }

    /** Where the term in progress at a moment began, for a seat whose first term after Year 1 ends at $firstEnd. */
    public static function termStart(float $time, float $firstEnd, float $term): float
    {
        return $time < $firstEnd ? $firstEnd - $term : $firstEnd + ($term * floor((($time - $firstEnd) / $term) + 1e-12));
    }

    /**
     * Everyone sitting: councillors, the governor, the rate committee, the heads of the Financial Regulator and the
     * Sovereign Reserve Fund, and the Council Appointment Board.
     *
     * @return list<string>
     */
    public static function sittingNames(PoliticsState $state): array
    {
        return array_values(array_filter(
            array_merge($state->councilNames, [$state->governorName], $state->memberNames, [$state->regulatorName, $state->fundHeadName], array_column($state->boardMembers, 'name')),
            static fn(string $name): bool => $name !== ''
        ));
    }

    /**
     * Every name a new appointee or party leader may not take: everyone sitting on the Council, at the Monetary
     * Authority, the Financial Regulator and the Sovereign Reserve Fund or leading a party, everyone who ever did, and
     * the people seated before Year 1.
     *
     * @return list<string>
     */
    public static function reservedNames(PoliticsState $state): array
    {
        $names = array_merge(
            self::sittingNames($state),
            array_values($state->leaderNames),
            $state->formerNames,
            AerieCouncil::OPENING_MEMBERS,
            [AerieCouncil::OPENING_GOVERNOR, AerieCouncil::OPENING_REGULATOR, AerieCouncil::OPENING_FUND_HEAD]
        );
        foreach ($state->leaderHistory as $leaders) {
            foreach ($leaders as $leader) {
                $names[] = $leader['name'];
            }
        }

        return $names;
    }

    /** An outgoing holder of a Council seat or a department post, kept out of every later draw (reservedNames()). */
    public static function retire(PoliticsState $state, string $outgoing): void
    {
        if ($outgoing !== '' && !in_array($outgoing, $state->formerNames, true)) {
            $state->formerNames[] = $outgoing;
        }
    }

    /** A vacancy's key: the seat and when its term begins, to the microyear. */
    public static function vacancyKey(string $seat, float $since): string
    {
        return $seat . ':' . (int) round($since * 1e6);
    }

    /** A uniform on (0, 1) hashed from the salt and a key. */
    public static function uniform(int $salt, string $key): float
    {
        return (hexdec(substr(hash('sha256', "{$salt}:{$key}"), 0, 13)) + 0.5) / (2 ** 52);
    }

    /** The key a candidate's stance on a question is kept under. */
    private static function stanceKey(string $axis): string
    {
        return $axis === self::AXIS_MONEY ? 'stance' : $axis;
    }

    /**
     * When someone seated at $seatedAt leaves before $termEnds, by death or resignation, or -1 if they serve the term out:
     * one hashed draw of the wait under a Gompertz-Makeham hazard (Distributions::gompertzMakehamWait()), age-related
     * mortality plus a resignation rate that does not rise with age, counted from $aliveAt, when they are known to sit.
     */
    public static function departure(int $salt, int $seat, float $seatedAt, float $birth, float $aliveAt, float $termEnds): float
    {
        $leaves = $aliveAt + Distributions::gompertzMakehamWait(
            self::uniform($salt, self::vacancyKey("council:{$seat}", $seatedAt) . ':leaves'),
            $aliveAt - $birth,
            self::MORTALITY_LEVEL,
            self::MORTALITY_SLOPE,
            self::RESIGNATION_RATE
        );

        return $leaves < $termEnds ? $leaves : -1.0;
    }

    /**
     * Why a councillor left early, at age $age: death with the share of the hazard mortality makes up at that age, else
     * resignation. Hashed from the seating, so it replays.
     */
    public static function departureCause(int $salt, int $seat, float $seatedAt, float $age): string
    {
        $mortality = self::MORTALITY_LEVEL * exp(self::MORTALITY_SLOPE * $age);

        return self::uniform($salt, self::vacancyKey("council:{$seat}", $seatedAt) . ':cause') < $mortality / ($mortality + self::RESIGNATION_RATE) ? 'died' : 'resigned';
    }

    /**
     * @param array{name: string, birth: float, stance: float, regulation: float, fund: float, swing?: float} $person
     * @param float $since    When the seat's term began.
     * @param float $seatedAt When this holder took it.
     * @param float $termEnds When the term ends.
     * @param float $aliveAt  From when their departure is drawn: their seating, or the moment a Council is first read.
     */
    private static function seatCouncillor(PoliticsState $state, int $salt, int $seat, array $person, float $since, float $seatedAt, float $termEnds, float $aliveAt): void
    {
        self::retire($state, $state->councilNames[$seat] ?? '');
        $state->councilNames[$seat] = $person['name'];
        $state->councilBirths[$seat] = $person['birth'];
        $state->councilSince[$seat] = $since;
        $state->councilSeatedAt[$seat] = $seatedAt;
        $state->councilLeavesAt[$seat] = self::departure($salt, $seat, $seatedAt, $person['birth'], max($seatedAt, $aliveAt), $termEnds);
        $state->councilStances[$seat] = $person['stance'];
        $state->councilSwingers[$seat] = $person['swing'] ?? 0.0;
        $state->councilRegulationStances[$seat] = $person['regulation'];
        $state->councilFundStances[$seat] = $person['fund'];
    }

    /**
     * Councillors seated before seats could fall vacant mid-term get their seating dated to the term's start and their
     * departure drawn once, counted from now, when they are known to sit.
     *
     * @param list<array{seat: int, since: float, termEnds: float, beforeYearOne: bool}> $roster
     */
    private static function backfillDepartures(PoliticsState $state, int $salt, array $roster, float $time): void
    {
        foreach ($state->councilNames as $seat => $name) {
            if (!isset($state->councilSeatedAt[$seat])) {
                $state->councilSeatedAt[$seat] = $state->councilSince[$seat] ?? $roster[$seat]['since'];
                $state->councilLeavesAt[$seat] = self::departure($salt, $seat, $state->councilSeatedAt[$seat], $state->councilBirths[$seat] ?? $time, max($time, $state->councilSeatedAt[$seat]), $roster[$seat]['termEnds']);
            }
        }
        ksort($state->councilSeatedAt);
        ksort($state->councilLeavesAt);
    }

    /**
     * Councillors seated before the Council answered a question get their stance on it drawn once, from their seat's
     * vacancy and their name, so a running game keeps its Council and gains the new question.
     */
    private static function backfillStances(PoliticsState $state, int $salt): void
    {
        foreach ($state->councilNames as $seat => $name) {
            $vacancy = self::vacancyKey("council:{$seat}", $state->councilSince[$seat] ?? 0.0);
            if (!isset($state->councilRegulationStances[$seat])) {
                $state->councilRegulationStances[$seat] = FinancialRegulator::drawStance(self::uniform($salt, "{$vacancy}:{$name}:regulation"));
            }
            if (!isset($state->councilFundStances[$seat])) {
                $state->councilFundStances[$seat] = SovereignReserveFund::drawStance(self::uniform($salt, "{$vacancy}:{$name}:fund"));
            }
            // A councillor held at the middle on money becomes a swing vote leaning to a camp.
            if (!isset($state->councilSwingers[$seat])) {
                $state->councilSwingers[$seat] = ($state->councilStances[$seat] ?? 0.0) === MonetaryAuthority::STANCES['swing'] ? 1.0 : 0.0;
                if ($state->councilSwingers[$seat] > 0.0) {
                    $state->councilStances[$seat] = MonetaryAuthority::swingLean(self::uniform($salt, "{$vacancy}:{$name}:lean"));
                }
            }
        }
        ksort($state->councilSwingers);
        ksort($state->councilRegulationStances);
        ksort($state->councilFundStances);
    }
}
