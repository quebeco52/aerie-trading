<?php

declare(strict_types=1);

namespace App\Service\Politics\Council;

use App\Data\Politics\AerieCouncil;
use App\Data\Politics\AerieNames;
use App\Service\Math\Distributions;
use App\Service\Math\MathUtility;
use App\Service\Politics\Department\Exchequer;
use App\Service\Politics\Department\FairTradingBoard;
use App\Service\Politics\Department\FinancialRegulator;
use App\Service\Politics\Department\MonetaryAuthority;
use App\Service\Politics\Department\Office;
use App\Service\Politics\Department\SecuritiesCommission;
use App\Service\Politics\Department\SovereignReserveFund;
use App\Service\Politics\PoliticsState;

/**
 * How the Council fills a seat, its own or a department's: from a shortlist of three, the candidate whose stances (-1
 * to +1 on money, the banks, the reserves, the debt, competition and the markets) stand nearest the appointer's. A
 * Council seat goes to the one nearest the sitting members' coordinate-wise median on the first three questions, from
 * the applicants nearest the median of the Council Appointment Board, named by outside bodies so a Council cannot
 * renew itself in its own image; a department head, to the one nearest the whole Council's median on its question.
 * Draws are hashed from the Council's salt, so appointments replay exactly and take nothing from the engine's stream.
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
    /** The question of the debt: the Exchequer's. */
    public const AXIS_DEBT = 'debt';
    /** The question of competition: the Fair Trading Board's. */
    public const AXIS_COMPETITION = 'competition';
    /** The question of the markets: the Securities Commission's. */
    public const AXIS_MARKETS = 'markets';

    // --- Candidates ---
    /** Candidates per shortlist: from three, a dovish appointer picks a dove 60% of the time (Bordo & Istrefi 2023). */
    public const SHORTLIST = 3;
    /** Mean age at appointment, in years (Federal Reserve Board governors at their first oath). */
    public const APPOINTMENT_AGE_MEAN = 52.56;
    /** Standard deviation of age at appointment, in years; the Board's record fits a normal. */
    public const APPOINTMENT_AGE_SD = 7.32;
    /** Youngest age the charter allows at appointment, where the age draw is truncated (as Germany's BVerfGG s. 3). */
    public const APPOINTMENT_AGE_MIN = 40.0;
    /** Oldest age the charter allows at appointment, where the age draw is truncated, so no councillor sits past 72. */
    public const APPOINTMENT_AGE_MAX = 60.0;

    // --- The Council Appointment Board ---
    /** Members of the board, each named by an outside body: seven, as on Canada's Supreme Court advisory board. */
    public const BOARD_SEATS = 7;
    /** Length of a board member's term, in years, staggered across the seats (as on Canada's advisory board). */
    public const BOARD_TERM_YEARS = 5.0;
    /** Applicants for each Council seat, from whom the board puts forward SHORTLIST (Canada's board: 12-18). */
    public const BOARD_FIELD = 14;

    // --- Vacancies ---
    /** Gompertz yearly death hazard at age 0, fitted with MORTALITY_SLOPE to ages 50 and 70 (US life table 2021). */
    public const MORTALITY_LEVEL = 1.457e-4;
    /** Gompertz slope: the death hazard's growth per year of age, 7.2% (doubling every 9.6 years). */
    public const MORTALITY_SLOPE = 0.0720;
    /** Yearly hazard a councillor resigns before their term ends, at any age (ECB Executive Board early departures). */
    public const RESIGNATION_RATE = 0.035;

    /**
     * One tick for the Council itself: drawn on the first read of a state without one, then each seat whose term has
     * ended refilled. A state whose councillors predate a question has their stances on it drawn once.
     *
     * @param PoliticsState $state Advanced in place; its totalTime already set to this tick's.
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

        // A charter change of term redates the sitting seats to the new schedule, so the change itself fills no seat.
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

        // Seats due this tick, filled in the order they fell due so a long tick fills them as a short one would.
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
                self::seatBoardMember($state, $salt, $seat, $at);
                continue;
            }
            $holder = $roster[$seat];
            if ($kind === 'vacancy') {
                // A seat left vacant mid-term goes to a successor who serves out the rest of the term.
                $state->lastVacancyName = $state->councilNames[$seat];
                $state->lastVacancyCause = self::departureCause($salt, $seat, $state->councilSeatedAt[$seat] ?? $holder['since'], $at - $state->councilBirths[$seat]);
                $state->lastCouncilVacancyAt = $time;
            }
            $shortlist = self::boardShortlist($salt, "council:{$seat}", $at, self::boardMedians($state), self::reservedNames($state));
            [$chosen, $state->councillorPassedOver] = self::choose($shortlist, self::councilMedians($state, $seat));
            self::seatCouncillor($state, $salt, $seat, $chosen, $holder['since'], $at, $holder['termEnds'], $at);
            $state->lastCouncillorSeatedAt = $time;
        }
    }

    /**
     * The Council at Year 1, or when a state that predates it is first read: the salt drawn and the sitting councillors
     * drawn as candidates, those seated before Year 1 under their own names.
     */
    private static function open(PoliticsState $state, float $time, MathUtility $math): void
    {
        $state->authoritySalt = floor($math->generateUniform() * (2 ** 31));
        $state->councilTermYears = AerieCouncil::TERM_YEARS;
        $salt = (int) $state->authoritySalt;
        $state->councilNames = $state->councilBirths = $state->councilSince = $state->councilSeatedAt = $state->councilLeavesAt = $state->councilSwingers = $state->councilStances = $state->councilRegulationStances = $state->councilFundStances = $state->councilDebtStances = $state->councilCompetitionStances = $state->councilMarketsStances = [];
        $state->memberNames = $state->memberBirths = $state->memberSince = $state->memberStances = [];
        foreach (array_keys($state->heads) as $office) {
            $state->heads[$office]['name'] = '';
        }
        $state->formerNames = [];
        $state->boardMembers = $state->boardSince = [];
        self::seatBoard($state, $salt, $time);

        foreach (AerieCouncil::roster($time) as $seat => $holder) {
            $drawn = self::candidate($salt, self::vacancyKey("council:{$seat}", $holder['since']), 0, $holder['since'], self::reservedNames($state));
            if ($holder['beforeYearOne']) {
                $drawn['name'] = AerieCouncil::OPENING_MEMBERS[$seat];
            }
            self::seatCouncillor($state, $salt, $seat, $drawn, $holder['since'], $holder['since'], $holder['termEnds'], $time);
        }
        $state->councillorPassedOver = [];
    }

    /**
     * A vacancy filled: a shortlist of SHORTLIST drawn as it comes, and the one nearest the target named (choose()).
     *
     * @param string               $seat   The office and seat, e.g. "council:4", "governor", "member:2", "regulator".
     * @param float                $since  When the term begins.
     * @param array<string, float> $target The appointer's stance on each question it weighs, by axis.
     * @param list<string>         $taken  Names no candidate may share.
     * @return array{0: array{name: string, birth: float, stance: float, regulation: float, fund: float, debt: float, competition: float, markets: float, swing?: float}, 1: list<array{name: string, birth: float, stance: float, regulation: float, fund: float, debt: float, competition: float, markets: float, swing?: float}>} The one named, and the ones passed over.
     */
    public static function appoint(int $salt, string $seat, float $since, array $target, array $taken): array
    {
        return self::choose(self::field($salt, self::vacancyKey($seat, $since), self::SHORTLIST, $since, $taken), $target);
    }

    /**
     * The appointer's pick: the candidate nearest the target on the questions it names; the first drawn of any tied.
     *
     * @param list<array{name: string, birth: float, stance: float, regulation: float, fund: float, debt: float, competition: float, markets: float, swing?: float}> $shortlist
     * @param array<string, float> $target
     * @return array{0: array{name: string, birth: float, stance: float, regulation: float, fund: float, debt: float, competition: float, markets: float, swing?: float}, 1: list<array{name: string, birth: float, stance: float, regulation: float, fund: float, debt: float, competition: float, markets: float, swing?: float}>} The one named, and the ones passed over.
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
     * The Council Appointment Board's shortlist for a Council seat: of the BOARD_FIELD who applied, the SHORTLIST
     * nearest the board's median on every question, the first drawn of any tied, in the order they applied.
     *
     * @param array<string, float> $boardMedians
     * @param list<string>         $taken
     * @return list<array{name: string, birth: float, stance: float, regulation: float, fund: float, debt: float, competition: float, markets: float, swing: float}>
     */
    public static function boardShortlist(int $salt, string $seat, float $since, array $boardMedians, array $taken): array
    {
        $field = self::field($salt, self::vacancyKey($seat, $since), self::BOARD_FIELD, $since, $taken);
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
     * @return list<array{name: string, birth: float, stance: float, regulation: float, fund: float, debt: float, competition: float, markets: float, swing: float}>
     */
    private static function field(int $salt, string $vacancy, int $count, float $since, array $taken): array
    {
        $field = [];
        for ($slot = 0; $slot < $count; ++$slot) {
            $field[] = $candidate = self::candidate($salt, $vacancy, $slot, $since, $taken);
            $taken[] = $candidate['name'];
        }

        return $field;
    }

    /**
     * Straight-line distance between a candidate's stances and a target's on the questions the target names.
     *
     * @param array{name: string, birth: float, stance: float, regulation: float, fund: float, debt: float, competition: float, markets: float, swing?: float} $candidate
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
     * When each board seat's term in progress began: vacancies fall evenly over a term, half a spacing off Year 1.
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
    private static function seatBoard(PoliticsState $state, int $salt, float $time): void
    {
        foreach (self::boardTermStarts($time) as $seat => $since) {
            self::seatBoardMember($state, $salt, $seat, $since);
        }
    }

    /** A board seat filled for a term: its outside body names one person, drawn as any candidate is. */
    private static function seatBoardMember(PoliticsState $state, int $salt, int $seat, float $since): void
    {
        self::retire($state, $state->boardMembers[$seat]['name'] ?? '');
        $state->boardMembers[$seat] = self::candidate($salt, self::vacancyKey("board:{$seat}", $since), 0, $since, self::reservedNames($state));
        $state->boardSince[$seat] = $since;
        ksort($state->boardMembers);
        ksort($state->boardSince);
    }

    /**
     * A candidate for a vacancy: a stance on each question from its department's drawStance() (a swing vote on money
     * leaning to a camp, MonetaryAuthority::swingLean()), an age at the term's start from a truncated normal, and a
     * name for their birth decade that no one in $taken holds.
     *
     * @param list<string> $taken
     * @return array{name: string, birth: float, stance: float, regulation: float, fund: float, debt: float, competition: float, markets: float, swing: float}
     */
    public static function candidate(int $salt, string $vacancy, int $slot, float $since, array $taken): array
    {
        $age = Distributions::truncatedNormalInverse(MathUtility::hashedUniform($salt, "{$vacancy}:{$slot}:age"), self::APPOINTMENT_AGE_MEAN, self::APPOINTMENT_AGE_SD, self::APPOINTMENT_AGE_MIN, self::APPOINTMENT_AGE_MAX);
        $birth = $since - $age;
        $type = MonetaryAuthority::drawStance(MathUtility::hashedUniform($salt, "{$vacancy}:{$slot}:stance"));
        $swing = $type === MonetaryAuthority::STANCES['swing'] ? 1.0 : 0.0;

        return [
            'name' => AerieNames::draw($birth, static fn(string $part): float => MathUtility::hashedUniform($salt, "{$vacancy}:{$slot}:name:{$part}"), $taken),
            'birth' => $birth,
            'stance' => $swing > 0.0 ? MonetaryAuthority::swingLean(MathUtility::hashedUniform($salt, "{$vacancy}:{$slot}:lean")) : $type,
            'regulation' => FinancialRegulator::drawStance(MathUtility::hashedUniform($salt, "{$vacancy}:{$slot}:regulation")),
            'fund' => SovereignReserveFund::drawStance(MathUtility::hashedUniform($salt, "{$vacancy}:{$slot}:fund")),
            'debt' => Exchequer::drawStance(MathUtility::hashedUniform($salt, "{$vacancy}:{$slot}:debt")),
            'competition' => FairTradingBoard::drawStance(MathUtility::hashedUniform($salt, "{$vacancy}:{$slot}:competition")),
            'markets' => SecuritiesCommission::drawStance(MathUtility::hashedUniform($salt, "{$vacancy}:{$slot}:markets")),
            'swing' => $swing,
        ];
    }

    /**
     * The sitting Council's median on every question, a vacant seat's holder left out.
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
     * Everyone sitting: councillors, every department's head (the governor among them), the rate committee, and the
     * Appointment Board.
     *
     * @return list<string>
     */
    public static function sittingNames(PoliticsState $state): array
    {
        return array_values(array_filter(
            array_merge($state->councilNames, array_map(static fn(Office $office): string => $state->head($office)['name'], Office::cases()), $state->memberNames, array_column($state->boardMembers, 'name')),
            static fn(string $name): bool => $name !== '',
        ));
    }

    /**
     * Every name a new appointee or party leader may not take: everyone sitting or leading a party, everyone who ever
     * did, and the people seated before Year 1.
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
            array_map(static fn(Office $office): string => $office->openingHolder(), Office::cases()),
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

    /**
     * The key a candidate's stance on a question is kept under.
     *
     * @return 'stance'|'regulation'|'fund'|'debt'|'competition'|'markets'
     */
    private static function stanceKey(string $axis): string
    {
        return match ($axis) {
            self::AXIS_MONEY => 'stance',
            self::AXIS_REGULATION, self::AXIS_FUND, self::AXIS_DEBT, self::AXIS_COMPETITION, self::AXIS_MARKETS => $axis,
            default => throw new \InvalidArgumentException("No stance is kept on '{$axis}'."),
        };
    }

    /**
     * When someone seated at $seatedAt leaves before $termEnds, by death or resignation, or -1 if they serve the term
     * out: one hashed draw of the wait under a Gompertz-Makeham hazard (mortality plus a flat resignation rate),
     * counted from $aliveAt, when they are known to sit.
     */
    public static function departure(int $salt, int $seat, float $seatedAt, float $birth, float $aliveAt, float $termEnds): float
    {
        $leaves = $aliveAt + Distributions::gompertzMakehamWait(
            MathUtility::hashedUniform($salt, self::vacancyKey("council:{$seat}", $seatedAt) . ':leaves'),
            $aliveAt - $birth,
            self::MORTALITY_LEVEL,
            self::MORTALITY_SLOPE,
            self::RESIGNATION_RATE,
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

        return MathUtility::hashedUniform($salt, self::vacancyKey("council:{$seat}", $seatedAt) . ':cause') < $mortality / ($mortality + self::RESIGNATION_RATE) ? 'died' : 'resigned';
    }

    /**
     * @param array{name: string, birth: float, stance: float, regulation: float, fund: float, debt: float, competition: float, markets: float, swing?: float} $person
     * @param float $since    When the seat's term began.
     * @param float $seatedAt When this holder took it.
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
        $state->councilDebtStances[$seat] = $person['debt'];
        $state->councilCompetitionStances[$seat] = $person['competition'];
        $state->councilMarketsStances[$seat] = $person['markets'];
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
     * vacancy and name, so a running game keeps its Council and gains the question.
     */
    private static function backfillStances(PoliticsState $state, int $salt): void
    {
        foreach ($state->councilNames as $seat => $name) {
            $vacancy = self::vacancyKey("council:{$seat}", $state->councilSince[$seat] ?? 0.0);
            if (!isset($state->councilRegulationStances[$seat])) {
                $state->councilRegulationStances[$seat] = FinancialRegulator::drawStance(MathUtility::hashedUniform($salt, "{$vacancy}:{$name}:regulation"));
            }
            if (!isset($state->councilFundStances[$seat])) {
                $state->councilFundStances[$seat] = SovereignReserveFund::drawStance(MathUtility::hashedUniform($salt, "{$vacancy}:{$name}:fund"));
            }
            if (!isset($state->councilDebtStances[$seat])) {
                $state->councilDebtStances[$seat] = Exchequer::drawStance(MathUtility::hashedUniform($salt, "{$vacancy}:{$name}:debt"));
            }
            if (!isset($state->councilCompetitionStances[$seat])) {
                $state->councilCompetitionStances[$seat] = FairTradingBoard::drawStance(MathUtility::hashedUniform($salt, "{$vacancy}:{$name}:competition"));
            }
            if (!isset($state->councilMarketsStances[$seat])) {
                $state->councilMarketsStances[$seat] = SecuritiesCommission::drawStance(MathUtility::hashedUniform($salt, "{$vacancy}:{$name}:markets"));
            }
            // A councillor held at the middle on money becomes a swing vote leaning to a camp.
            if (!isset($state->councilSwingers[$seat])) {
                $state->councilSwingers[$seat] = ($state->councilStances[$seat] ?? 0.0) === MonetaryAuthority::STANCES['swing'] ? 1.0 : 0.0;
                if ($state->councilSwingers[$seat] > 0.0) {
                    $state->councilStances[$seat] = MonetaryAuthority::swingLean(MathUtility::hashedUniform($salt, "{$vacancy}:{$name}:lean"));
                }
            }
        }
        ksort($state->councilSwingers);
        ksort($state->councilRegulationStances);
        ksort($state->councilFundStances);
        ksort($state->councilDebtStances);
        ksort($state->councilCompetitionStances);
        ksort($state->councilMarketsStances);
    }
}
