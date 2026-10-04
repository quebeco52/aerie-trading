<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\AerieDiet;
use App\DTO\PoliticsStateDTO;
use App\Service\Math\MathUtility;

/**
 * The cabinet leaning on the Monetary Authority. The charter puts the Authority beyond the Diet's reach, but the Council
 * that staffs it still answers to the public, and the Diet is the public's voice: a government that wants cheaper money
 * says so, in public, and now and then the Authority gives ground.
 *
 * Each quarter a cabinet not already pressing begins to with a chance that rises with how populist it is (Gavin & Manger
 * 2023), and one that is pressing keeps on with the chance Binder's (2021) central banks saw pressure run into the next
 * quarter. At the start of an episode the Authority either holds firm or gives in, at the share advanced economies' central
 * banks gave in, and keeps to it until the episode ends; a cabinet that leaves office takes its episode with it. Pressure
 * is nearly always for looser money (91% of Binder's cases), so it is modelled as nothing else.
 *
 * A cabinet's populism is its place on the Diet's Council axis, Chapel Hill's people-versus-elite scale, read onto
 * V-Party's 0-1 populism score as a straight line between the two scales' ends: the Common Lot stands at 0.9, as the
 * Fernandez de Kirchner government did (0.869), the Chartists at 0.1.
 *
 * The economy feels the episodes the Authority gives in to (App\DTO\GovernmentPolicyDTO::$authorityConcession), the
 * held ones only in the news.
 */
final class PoliticalPressure
{
    // --- How Often a Cabinet Leans on the Authority (Binder 2021; Gavin & Manger 2023) ---
    /** Probit slope of a quarter of public pressure on the central bank on the government's populism (V-Party, 0-1): 0.724 (se 0.250), Gavin & Manger (2023, Table 3, model 5), 35 countries' governments; the most populist press about 3.7 times as often as the least. */
    public const POPULISM_PROBIT_SLOPE = 0.724;
    /** Probit intercept, fitted on the Diet's own cabinets (32 games of 100 years, var/harness/pressure) so they press in 3.5% of quarters, the share the 18 advanced economies' central banks spent under pressure, 2010Q1-2019Q1 (Binder's posted data, 23 of 666): 7 episodes a century. */
    public const POPULISM_PROBIT_INTERCEPT = -2.02;
    /** Chance a quarter of pressure is followed by another: 0.60 in Binder's posted data (0.55 after a held quarter, 0.68 after one conceded), an episode of 2.5 quarters on average against her 2.3. */
    public const CONTINUATION = 0.60;

    // --- Giving Ground ---
    /** Share of episodes the Authority gives in to: advanced economies' central banks gave in to 5 of their 23 quarters of pressure (Binder's posted data), and kept to their choice from quarter to quarter (held stays held 84%, conceded stays conceded 89%). */
    public const GIVE_IN_SHARE = 0.22;

    // --- Populism on the Council Axis ---
    /** The Council axis's populist end, read as V-Party's populism score of 1. */
    public const POPULIST_END = -1.0;
    /** The Council axis's technocratic end, read as V-Party's populism score of 0. */
    public const TECHNOCRATIC_END = 1.0;

    /** Length of the period pressure is reckoned over, in years: the quarter Binder and Gavin & Manger code it by. */
    private const QUARTER = 0.25;

    /**
     * One tick: at each quarter's turn, an episode under way ends with its cabinet or by chance, and a cabinet not pressing
     * may begin to, the Authority choosing at once whether to give ground.
     *
     * @param PoliticsState $state The politics, advanced in place; its totalTime already set to this tick's.
     */
    public static function advance(PoliticsState $state, float $dt, MathUtility $math): void
    {
        $time = $state->totalTime;
        if ($state->authoritySalt < 0.0 || !MathUtility::crossedSimulatedBoundary($time, $dt, self::QUARTER)) {
            return;
        }

        $salt = (int) $state->authoritySalt;
        $quarter = (int) round($time / self::QUARTER);
        if ($state->pressureSince >= 0.0) {
            if (abs($state->pressureCabinet - $state->coalitionFormedAt) < 1e-9 && CouncilAppointments::uniform($salt, "pressure-runs:{$quarter}") < self::CONTINUATION) {
                return;
            }
            $state->pressureSince = -1.0;
            $state->pressureGivingIn = 0.0;
        }

        $populism = self::populism(PoliticsEngine::coalitionPosition($state->governingCoalition, $state->dietSeats, $state->partyPositions));
        if (CouncilAppointments::uniform($salt, "pressure:{$quarter}") >= self::onsetChance(self::shareOfQuarters($populism, $math))) {
            return;
        }

        $state->pressureSince = $time;
        $state->pressureCabinet = $state->coalitionFormedAt;
        $state->pressureGivingIn = CouncilAppointments::uniform($salt, "give-in:{$quarter}") < self::GIVE_IN_SHARE ? 1.0 : 0.0;
        $state->lastPressureAt = $time;
    }

    /**
     * A position's populism on V-Party's 0-1 scale: its place on the Council axis, the technocratic end at 0 and the
     * populist end at 1.
     *
     * @param array<string, float> $position A position by axis (PoliticsEngine::coalitionPosition(), or a party's).
     */
    public static function populism(array $position): float
    {
        $council = max(self::POPULIST_END, min(self::TECHNOCRATIC_END, $position[AerieDiet::AXIS_COUNCIL] ?? 0.0));

        return (self::TECHNOCRATIC_END - $council) / (self::TECHNOCRATIC_END - self::POPULIST_END);
    }

    /** The share of quarters a government this populist spends pressing the central bank (Gavin & Manger's probit). */
    public static function shareOfQuarters(float $populism, MathUtility $math): float
    {
        return $math->calculateNormalCDF(self::POPULISM_PROBIT_INTERCEPT + (self::POPULISM_PROBIT_SLOPE * $populism));
    }

    /**
     * The chance a quarter without pressure is followed by one with it, so that, with episodes continuing at CONTINUATION,
     * the long-run share of quarters under pressure is the share given: s = q / (q + 1 - c).
     */
    public static function onsetChance(float $share): float
    {
        return $share * (1.0 - self::CONTINUATION) / (1.0 - $share);
    }

    /** 1 while the Authority is giving ground to an episode of pressure, else 0. */
    public static function concession(PoliticsState|PoliticsStateDTO $state): float
    {
        return $state->pressureSince >= 0.0 && $state->pressureGivingIn > 0.0 ? 1.0 : 0.0;
    }
}
