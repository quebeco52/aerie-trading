<?php

declare(strict_types=1);

namespace App\Service\Politics\Department;

use App\Data\Politics\AerieCouncil;
use App\Service\Math\MathUtility;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\PoliticsState;

/**
 * The State Guard: the District's small standing land force, for hurricane relief, armed attacks beyond the police's
 * means and the defence of its institutions. The Charter allows no army, so in law it is a guard, and only the Council
 * can call it out. It sets no rule the economy reads. The Council appoints a commander for one fixed term, naming the
 * candidate nearest its median on its own questions; the commander's tenure is lore.
 */
final class StateGuard
{
    // --- The Commander ---
    /** Length of the commander's single term, in years: the five most Council department heads serve. */
    public const COMMANDER_TERM_YEARS = 5.0;
    /** When the term of the commander sitting at Year 1 ends, in years after Year 1 began. */
    public const OPENING_COMMANDER_TERM_END = 3.5;

    /**
     * One tick, after the Council's own: seats a commander when the state has none and at each term's end.
     *
     * @param PoliticsState $state Advanced in place; its totalTime already this tick's.
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
            $math,
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
