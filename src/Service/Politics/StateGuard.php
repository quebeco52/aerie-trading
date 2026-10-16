<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\Politics\AerieCouncil;
use App\Service\Math\MathUtility;

/**
 * The State Guard as a department of the Council: the District's standing land force, a small corps of full-time
 * professionals for hurricane relief, armed attacks beyond the police's means and the defence of the District's
 * institutions. The Charter allows no army, so in law it is a guard, as Bermuda's regiment is the local force of a
 * territory whose defence is another state's; only the Council can call it out.
 *
 * The State Guard sets no rule the economy reads. The Council names the candidate nearest its median on its own
 * questions, as it does the Security Bureau's director, and the commander's tenure is lore.
 */
final class StateGuard
{
    // --- The Commander ---
    /** Length of the commander's single term, in years: the five every other head serves. */
    public const COMMANDER_TERM_YEARS = 5.0;
    /** When the term of the commander sitting at Year 1 ends, in years after Year 1 began. */
    public const OPENING_COMMANDER_TERM_END = 3.5;

    /**
     * One tick, after the Council's own: the commander seated on the first read of a state without one, and a new
     * commander at each term's end, from a shortlist, the candidate nearest the Council's median on its own questions.
     *
     * @param PoliticsState $state The politics, advanced in place; its totalTime already set to this tick's.
     */
    public static function advance(PoliticsState $state, MathUtility $math): void
    {
        $time = $state->totalTime;
        $term = CouncilAppointments::termStart($time, self::OPENING_COMMANDER_TERM_END, self::COMMANDER_TERM_YEARS);
        if ($state->stateGuardCommanderName !== '' && abs($state->stateGuardCommanderTermStart - $term) < 1e-9) {
            return;
        }

        $opening = $state->stateGuardCommanderName === '';
        [$chosen, $state->stateGuardCommanderPassedOver] = CouncilAppointments::appoint(
            (int) $state->authoritySalt,
            'state-guard',
            $term,
            CouncilAppointments::councilMedians($state),
            CouncilAppointments::reservedNames($state),
            $math
        );
        if ($term < 0.0) {
            $chosen['name'] = AerieCouncil::OPENING_STATE_GUARD_COMMANDER;
            $state->stateGuardCommanderPassedOver = [];
        }

        CouncilAppointments::retire($state, $state->stateGuardCommanderName);
        $state->stateGuardCommanderName = $chosen['name'];
        $state->stateGuardCommanderBirth = $chosen['birth'];
        $state->stateGuardCommanderTermStart = $term;
        if (!$opening) {
            $state->lastStateGuardCommanderAppointedAt = $time;
        }
    }

    /** When the commander in office at a moment leaves. */
    public static function commanderTermEnd(float $time): float
    {
        return CouncilAppointments::termStart($time, self::OPENING_COMMANDER_TERM_END, self::COMMANDER_TERM_YEARS) + self::COMMANDER_TERM_YEARS;
    }
}
