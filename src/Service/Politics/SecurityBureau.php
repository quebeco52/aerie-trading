<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\Politics\AerieCouncil;
use App\Service\Math\MathUtility;

/**
 * The Security Bureau as a department of the Council: the District's own state police, which pursues organised
 * crime and the foreign agents a financial centre of its weight draws, while the streets, ports and borders are policed
 * under concession. Its director is the Council's appointee for a single ten-year term, twice any other head's, as the
 * FBI director's term outlasts the president who names them (Crime Control Act of 1976, Pub. L. 94-503).
 *
 * The Bureau sets no rule the economy reads. The Council names the candidate nearest its median on its own questions,
 * someone the sitting members trust, and the director's tenure is lore: who holds the post, and when it changes hands.
 */
final class SecurityBureau
{
    // --- The Director ---
    /** Length of the director's single term, in years: the FBI director's ten. */
    public const DIRECTOR_TERM_YEARS = 10.0;
    /** When the term of the director sitting at Year 1 ends, in years after Year 1 began. */
    public const OPENING_DIRECTOR_TERM_END = 6.0;

    /**
     * One tick, after the Council's own: the director seated on the first read of a state without one, and a new
     * director at each term's end, from a shortlist, the candidate nearest the Council's median on its own questions.
     *
     * @param PoliticsState $state The politics, advanced in place; its totalTime already set to this tick's.
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
            $math
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
