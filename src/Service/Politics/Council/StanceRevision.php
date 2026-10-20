<?php

declare(strict_types=1);

namespace App\Service\Politics\Council;

use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Politics\Department\Office;
use App\Service\Politics\PoliticsState;

/**
 * Changes of mind on money. A swing vote on the rate committee, the governor included, leans to one camp at a time and
 * once a year may cross to the other, as the Fed watchers' "situational hawks or doves" do (Bordo & Istrefi 2023);
 * hawks and doves keep their camp. Councillors' and the board's leans matter only when they appoint, so are not
 * revised. Draws are hashed from the Council's salt, the year and the seat, so they replay exactly and take nothing
 * from the engine's stream.
 */
final class StanceRevision
{
    // --- Changes of Mind ---
    /** Yearly rate a swing vote changes camp: once per median FOMC tenure, 10.8 years (Bordo & Istrefi 2023). */
    public const SWITCH_RATE = 1.0 / 10.8;

    /**
     * The year's review, on the tick a year turns: each sitting swing vote crosses to the other camp with the chance
     * SWITCH_RATE gives over a year, either way alike.
     *
     * @param PoliticsState $state Revised in place; its totalTime already set to this tick's.
     * @param float         $dt    Time increment in years.
     * @return bool Whether the governor or a member changed camp, so the committee's balance needs refreshing.
     */
    public static function revise(PoliticsState $state, float $dt): bool
    {
        if (!TimeSeries::crossedSimulatedBoundary($state->totalTime, $dt, 1.0)) {
            return false;
        }

        $salt = (int) $state->authoritySalt;
        $year = (int) floor($state->totalTime);
        $chance = 1.0 - exp(-self::SWITCH_RATE);
        $switches = static fn(string $seat): bool => MathUtility::hashedUniform($salt, "switch:{$year}:{$seat}") < $chance;

        $committee = false;
        if ($state->governorSwinger > 0.0 && $switches('governor')) {
            $state->heads[Office::Governor->value]['stance'] = -$state->head(Office::Governor)['stance'];
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
