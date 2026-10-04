<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\AerieCouncil;
use App\Data\AerieNames;
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
 * coordinate-wise median, as a majority vote picks on each); a department head to the candidate nearest the whole
 * Council's median on that department's question. A short list does not always offer a like mind, so the Council's
 * leans drift as its seats turn over.
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
    /** Candidates on each shortlist: from three, a dovish appointer names a dove 69% of the time, as the Democratic presidents' nominees to the Federal Reserve Board were 65% doves, 17 of 26 (Bordo & Istrefi 2023, Fig. 4). */
    public const SHORTLIST = 3;
    /** Mean age at appointment, in years: the Federal Reserve Board's 101 governors at their first oath, 1914-2026. */
    public const APPOINTMENT_AGE_MEAN = 52.56;
    /** Standard deviation of age at appointment; the Board's percentiles fit a normal (10th 43.7, median 52.9, 90th 60.5). */
    public const APPOINTMENT_AGE_SD = 7.32;
    /** Youngest age the charter allows at appointment, where the draw is truncated: forty, as for a judge of Germany's Federal Constitutional Court (BVerfGG s. 3); the Board's record runs from 35.9, its 10th percentile 43.7. */
    public const APPOINTMENT_AGE_MIN = 40.0;
    /** Oldest age the charter allows at appointment, so no councillor sits past 72; the Board's record runs to 71.7, its 90th percentile 60.5. */
    public const APPOINTMENT_AGE_MAX = 60.0;

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
        self::backfillStances($state, $salt);

        // A charter that changed the term moves the sitting councillors onto the new schedule: the same people, their
        // seats dated as the new terms would have run, so the change itself fills no seat.
        if ($state->councilTermYears !== AerieCouncil::TERM_YEARS) {
            foreach (AerieCouncil::roster($time) as $seat => $holder) {
                if (isset($state->councilNames[$seat])) {
                    $state->councilSince[$seat] = $holder['since'];
                }
            }
            $state->councilTermYears = AerieCouncil::TERM_YEARS;
        }

        foreach (AerieCouncil::roster($time) as $seat => $holder) {
            if (abs(($state->councilSince[$seat] ?? -INF) - $holder['since']) > 1e-9) {
                [$chosen, $state->councillorPassedOver] = self::appoint($salt, "council:{$seat}", $holder['since'], self::councilMedians($state, $seat), self::sittingNames($state), $math);
                self::seatCouncillor($state, $seat, $chosen, $holder['since']);
                $state->lastCouncillorSeatedAt = $time;
            }
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
        $state->councilNames = $state->councilBirths = $state->councilSince = $state->councilStances = $state->councilRegulationStances = $state->councilFundStances = [];
        $state->memberNames = $state->memberBirths = $state->memberSince = $state->memberStances = [];
        $state->governorName = '';
        $state->regulatorName = '';
        $state->fundHeadName = '';

        foreach (AerieCouncil::roster($time) as $seat => $holder) {
            $drawn = self::candidate($salt, self::vacancyKey("council:{$seat}", $holder['since']), 0, $holder['since'], self::sittingNames($state), $math);
            if ($holder['beforeYearOne']) {
                $drawn['name'] = AerieCouncil::OPENING_MEMBERS[$seat];
            }
            self::seatCouncillor($state, $seat, $drawn, $holder['since']);
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
     * @return array{0: array{name: string, birth: float, stance: float, regulation: float, fund: float}, 1: list<array{name: string, birth: float, stance: float, regulation: float, fund: float}>} The one named, and the ones passed over.
     */
    public static function appoint(int $salt, string $seat, float $since, array $target, array $taken, MathUtility $math): array
    {
        $vacancy = self::vacancyKey($seat, $since);
        $shortlist = [];
        for ($slot = 0; $slot < self::SHORTLIST; ++$slot) {
            $shortlist[] = $candidate = self::candidate($salt, $vacancy, $slot, $since, $taken, $math);
            $taken[] = $candidate['name'];
        }

        $distance = static function (array $candidate) use ($target): float {
            $squares = 0.0;
            foreach ($target as $axis => $stance) {
                $squares += ($candidate[self::stanceKey($axis)] - $stance) ** 2;
            }

            return sqrt($squares);
        };

        $chosen = 0;
        foreach ($shortlist as $slot => $candidate) {
            if ($distance($candidate) < $distance($shortlist[$chosen]) - 1e-12) {
                $chosen = $slot;
            }
        }
        $named = $shortlist[$chosen];
        unset($shortlist[$chosen]);

        return [$named, array_values($shortlist)];
    }

    /**
     * A candidate for a vacancy: their stance on money (App\Service\Politics\MonetaryAuthority::drawStance()), on the
     * banks (App\Service\Politics\FinancialRegulator::drawStance()) and on the reserves
     * (App\Service\Politics\SovereignReserveFund::drawStance()), their age at the term's start from the Board's record
     * (a normal truncated to the youngest and oldest on it, by inverse transform), and a name no one sitting holds.
     *
     * @param list<string> $taken
     * @return array{name: string, birth: float, stance: float, regulation: float, fund: float}
     */
    public static function candidate(int $salt, string $vacancy, int $slot, float $since, array $taken, MathUtility $math): array
    {
        $low = $math->calculateNormalCDF((self::APPOINTMENT_AGE_MIN - self::APPOINTMENT_AGE_MEAN) / self::APPOINTMENT_AGE_SD);
        $high = $math->calculateNormalCDF((self::APPOINTMENT_AGE_MAX - self::APPOINTMENT_AGE_MEAN) / self::APPOINTMENT_AGE_SD);
        $quantile = $low + (self::uniform($salt, "{$vacancy}:{$slot}:age") * ($high - $low));
        $age = self::APPOINTMENT_AGE_MEAN + (self::APPOINTMENT_AGE_SD * $math->calculateInverseNormalCDF($quantile));

        for ($attempt = 0; ; ++$attempt) {
            $name = AerieNames::pick(
                self::uniform($salt, "{$vacancy}:{$slot}:tradition:{$attempt}"),
                self::uniform($salt, "{$vacancy}:{$slot}:given:{$attempt}"),
                self::uniform($salt, "{$vacancy}:{$slot}:family:{$attempt}")
            );
            if (!in_array($name, $taken, true)) {
                break;
            }
        }

        return [
            'name' => $name,
            'birth' => $since - $age,
            'stance' => MonetaryAuthority::drawStance(self::uniform($salt, "{$vacancy}:{$slot}:stance")),
            'regulation' => FinancialRegulator::drawStance(self::uniform($salt, "{$vacancy}:{$slot}:regulation")),
            'fund' => SovereignReserveFund::drawStance(self::uniform($salt, "{$vacancy}:{$slot}:fund")),
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
     * Everyone sitting: councillors, the governor, the rate committee, and the heads of the Financial Regulator and the
     * Sovereign Reserve Fund.
     *
     * @return list<string>
     */
    public static function sittingNames(PoliticsState $state): array
    {
        return array_values(array_filter(
            array_merge($state->councilNames, [$state->governorName], $state->memberNames, [$state->regulatorName, $state->fundHeadName]),
            static fn(string $name): bool => $name !== ''
        ));
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

    /** @param array{name: string, birth: float, stance: float, regulation: float, fund: float} $person */
    private static function seatCouncillor(PoliticsState $state, int $seat, array $person, float $since): void
    {
        $state->councilNames[$seat] = $person['name'];
        $state->councilBirths[$seat] = $person['birth'];
        $state->councilSince[$seat] = $since;
        $state->councilStances[$seat] = $person['stance'];
        $state->councilRegulationStances[$seat] = $person['regulation'];
        $state->councilFundStances[$seat] = $person['fund'];
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
        }
        ksort($state->councilRegulationStances);
        ksort($state->councilFundStances);
    }
}
