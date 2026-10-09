<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Service\Math\TimeSeries;

/**
 * Changes of mind on money. The Fed watchers' swing votes are not members who sit between the camps but members seen
 * as a hawk in some years and a dove in others, "situational hawks or doves" who switch camps for some years or for
 * good (Bordo & Istrefi 2023; Istrefi 2019). Hawks and doves keep their camp; each swing vote, councillor, governor,
 * committee member or member of the Council Appointment Board, leans to one camp at a time and, once a year as the watchers reclassify them, may cross to the
 * other.
 *
 * The draws are hashed from the Council's salt, the year and the seat, so they replay and take nothing from the
 * politics engine's random stream.
 */
final class StanceRevision
{
    // --- Changes of Mind (Bordo & Istrefi 2023; Istrefi 2019) ---
    /** Yearly rate at which a swing vote changes camp: one switch over a swing vote's median FOMC tenure, 10.8 years, 1960-2015 (Bordo & Istrefi 2023, Table 1); some switch more than once (Istrefi 2019, fn. 25). */
    public const SWITCH_RATE = 1.0 / 10.8;

    /**
     * The year's review, on the tick a year turns: each sitting swing vote crosses to the other camp with the chance
     * SWITCH_RATE gives over a year, in either direction alike.
     *
     * @param PoliticsState $state The politics, revised in place; its totalTime already set to this tick's.
     * @param float         $dt    Time increment in years.
     * @return bool Whether the governor or a committee member changed camp, so the committee's balance needs refreshing.
     */
    public static function revise(PoliticsState $state, float $dt): bool
    {
        if (!TimeSeries::crossedSimulatedBoundary($state->totalTime, $dt, 1.0)) {
            return false;
        }

        $salt = (int) $state->authoritySalt;
        $year = (int) floor($state->totalTime);
        $chance = 1.0 - exp(-self::SWITCH_RATE);
        $switches = static fn(string $seat): bool => CouncilAppointments::uniform($salt, "switch:{$year}:{$seat}") < $chance;

        foreach ($state->councilSwingers as $seat => $swing) {
            if ($swing > 0.0 && isset($state->councilStances[$seat]) && $switches("council:{$seat}")) {
                $state->councilStances[$seat] = -$state->councilStances[$seat];
            }
        }

        foreach ($state->boardMembers as $seat => $member) {
            if (($member['swing'] ?? 0.0) > 0.0 && $switches("board:{$seat}")) {
                $state->boardMembers[$seat]['stance'] = -$member['stance'];
            }
        }

        $committee = false;
        if ($state->governorSwinger > 0.0 && $switches('governor')) {
            $state->governorStance = -$state->governorStance;
            $committee = true;
        }
        foreach ($state->memberSwingers as $member => $swing) {
            if ($swing > 0.0 && isset($state->memberStances[$member]) && $switches("member:{$member}")) {
                $state->memberStances[$member] = -$state->memberStances[$member];
                $committee = true;
            }
        }

        return $committee;
    }
}
