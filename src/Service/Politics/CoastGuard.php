<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\Politics\AerieCouncil;
use App\Service\Math\MathUtility;

/**
 * The Coast Guard as a department of the Council: it holds the District's waters, the sounds behind the barrier
 * islands, the free port's approaches and the shelf, while the port's quays are policed under concession. The Charter
 * leaves defence to the mainland and allows the District no navy, so in law it is a maritime police, as Japan's Coast
 * Guard Law (art. 25) keeps that service outside the armed forces; it is armed and trained to a navy's standard all the
 * same, and it takes its orders from the Council alone.
 *
 * The Coast Guard sets no rule the economy reads. The Council names the candidate nearest its median on its own
 * questions, as it does the Security Bureau's director, and the commandant's tenure is lore.
 */
final class CoastGuard
{
    // --- The Commandant ---
    /** Length of the commandant's single term, in years: the five every other head serves. */
    public const COMMANDANT_TERM_YEARS = 5.0;
    /** When the term of the commandant sitting at Year 1 ends, in years after Year 1 began. */
    public const OPENING_COMMANDANT_TERM_END = 2.5;

    /**
     * One tick, after the Council's own: the commandant seated on the first read of a state without one, and a new
     * commandant at each term's end, from a shortlist, the candidate nearest the Council's median on its own questions.
     *
     * @param PoliticsState $state The politics, advanced in place; its totalTime already set to this tick's.
     */
    public static function advance(PoliticsState $state, MathUtility $math): void
    {
        $time = $state->totalTime;
        $term = CouncilAppointments::termStart($time, self::OPENING_COMMANDANT_TERM_END, self::COMMANDANT_TERM_YEARS);
        if ($state->coastGuardCommandantName !== '' && abs($state->coastGuardCommandantTermStart - $term) < 1e-9) {
            return;
        }

        $opening = $state->coastGuardCommandantName === '';
        [$chosen, $state->coastGuardCommandantPassedOver] = CouncilAppointments::appoint(
            (int) $state->authoritySalt,
            'coast-guard',
            $term,
            CouncilAppointments::councilMedians($state),
            CouncilAppointments::reservedNames($state),
            $math
        );
        if ($term < 0.0) {
            $chosen['name'] = AerieCouncil::OPENING_COAST_GUARD_COMMANDANT;
            $state->coastGuardCommandantPassedOver = [];
        }

        CouncilAppointments::retire($state, $state->coastGuardCommandantName);
        $state->coastGuardCommandantName = $chosen['name'];
        $state->coastGuardCommandantBirth = $chosen['birth'];
        $state->coastGuardCommandantTermStart = $term;
        if (!$opening) {
            $state->lastCoastGuardCommandantAppointedAt = $time;
        }
    }

    /** When the commandant in office at a moment leaves. */
    public static function commandantTermEnd(float $time): float
    {
        return CouncilAppointments::termStart($time, self::OPENING_COMMANDANT_TERM_END, self::COMMANDANT_TERM_YEARS) + self::COMMANDANT_TERM_YEARS;
    }
}
