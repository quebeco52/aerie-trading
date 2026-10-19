<?php

declare(strict_types=1);

namespace App\Service\Politics\Department;

use App\Data\Politics\AerieCouncil;
use App\Service\Math\MathUtility;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\PoliticsState;

/**
 * The Coast Guard: the District's maritime police, holding its waters from the sounds to the shelf while the port's
 * quays are policed under concession. The Charter allows no navy, but it is armed and trained to a navy's standard and
 * answers to the Council alone. It sets no rule the economy reads. The Council appoints a commandant for one fixed
 * term, naming the candidate nearest its median on its own questions; the commandant's tenure is lore.
 */
final class CoastGuard
{
    // --- The Commandant ---
    /** Length of the commandant's single term, in years: the five most Council department heads serve. */
    public const COMMANDANT_TERM_YEARS = 5.0;
    /** When the term of the commandant sitting at Year 1 ends, in years after Year 1 began. */
    public const OPENING_COMMANDANT_TERM_END = 2.5;

    /**
     * One tick, after the Council's own: seats a commandant when the state has none and at each term's end.
     *
     * @param PoliticsState $state Advanced in place; its totalTime already this tick's.
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
            $math,
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
