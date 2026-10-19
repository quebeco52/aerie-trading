<?php

declare(strict_types=1);

namespace App\Service\Politics\Department;

use App\Data\Politics\AerieCouncil;
use App\Service\Math\MathUtility;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\PoliticsState;

/**
 * The Security Bureau: the District's state police, pursuing organised crime and foreign agents while the streets,
 * ports and borders are policed under concession. It sets no rule the economy reads. The Council appoints a director
 * for one ten-year term, naming the candidate nearest its median on its own questions; the director's tenure is lore.
 */
final class SecurityBureau
{
    // --- The Director ---
    /** Length of the director's single term, in years: ten (the FBI director's). */
    public const DIRECTOR_TERM_YEARS = 10.0;
    /** When the term of the director sitting at Year 1 ends, in years after Year 1 began. */
    public const OPENING_DIRECTOR_TERM_END = 6.0;

    /**
     * One tick, after the Council's own: seats a director when the state has none and at each term's end.
     *
     * @param PoliticsState $state Advanced in place; its totalTime already this tick's.
     */
    public static function advance(PoliticsState $state, MathUtility $math): void
    {
        $time = $state->totalTime;
        $term = CouncilAppointments::termStart($time, self::OPENING_DIRECTOR_TERM_END, self::DIRECTOR_TERM_YEARS);
        if ($state->securityDirectorName !== '' && abs($state->securityDirectorTermStart - $term) < 1e-9) {
            return;
        }

        $opening = $state->securityDirectorName === '';
        [$chosen, $state->securityDirectorPassedOver] = CouncilAppointments::appoint(
            (int) $state->authoritySalt,
            'security',
            $term,
            CouncilAppointments::councilMedians($state),
            CouncilAppointments::reservedNames($state),
            $math,
        );
        if ($term < 0.0) {
            $chosen['name'] = AerieCouncil::OPENING_SECURITY_DIRECTOR;
            $state->securityDirectorPassedOver = [];
        }

        CouncilAppointments::retire($state, $state->securityDirectorName);
        $state->securityDirectorName = $chosen['name'];
        $state->securityDirectorBirth = $chosen['birth'];
        $state->securityDirectorTermStart = $term;
        if (!$opening) {
            $state->lastSecurityDirectorAppointedAt = $time;
        }
    }

    /** When the director in office at a moment leaves. */
    public static function directorTermEnd(float $time): float
    {
        return CouncilAppointments::termStart($time, self::OPENING_DIRECTOR_TERM_END, self::DIRECTOR_TERM_YEARS) + self::DIRECTOR_TERM_YEARS;
    }
}
