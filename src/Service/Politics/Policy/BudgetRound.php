<?php

declare(strict_types=1);

namespace App\Service\Politics\Policy;

use App\Data\Politics\AerieDiet;
use App\DTO\PoliticsStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MacroConstants;
use App\Service\Politics\PoliticsState;

/**
 * A budget round: the cabinet's programme, as far as its support parties and the reserve fund let it, becomes law.
 */
final class BudgetRound
{
    /**
     * The levers in force, keyed and ordered as platform() writes them, so an unchanged budget compares equal.
     *
     * @return array<string, float>
     */
    public static function standingLevers(PoliticsState|PoliticsStateDTO $state): array
    {
        $standing = [];
        foreach (Lever::cases() as $lever) {
            $standing[$lever->value] = $state->{$lever->field()};
        }

        return $standing;
    }

    /**
     * What a budget round would enact: the budget closest to the cabinet's programme that all its supporters accept
     * (BudgetBargain). A draw above half the fund's expected return needs the fund's consent, given only while
     * MacroEngine::recessionDeclared(); out of a recession the share above the ceiling lapses.
     *
     * @param list<string>                        $cabinet   The cabinet's parties.
     * @param list<string>                        $support   Its support parties.
     * @param array<string, int|float>            $seats     Seats by party.
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @param array<string, float>                $standing  The levers in force (standingLevers()).
     * @param bool                                $recession Whether a recession is declared (the fund consents).
     * @return array{levers: array<string, float>, platform: array<string, float>, supportHeld: array<string, bool>, fundHeld: bool}
     *         The levers enacted, the cabinet's programme, the levers its supporters hold short of it, and whether the
     *         fund held the draw at its ceiling.
     */
    public static function budget(array $cabinet, array $support, array $seats, array $positions, array $standing, bool $recession = false): array
    {
        $platform = Platforms::platform(Platforms::coalitionPosition(AerieDiet::membership($cabinet), $seats, $positions)) + Platforms::debtPlanks($cabinet, $seats);
        $supporters = array_map(static fn(string $party): array => [
            'programme' => Platforms::partyProgramme($party, $positions),
            'redLines' => BudgetBargain::redLines($party),
        ], $support);

        $proposal = [];
        foreach (Lever::cases() as $lever) {
            $proposal[$lever->value] = $platform[$lever->value];
        }
        // Out of a recession the fund will not consent above the ceiling, so the bargain is struck within it.
        $caps = $recession ? [] : ['reserveDrawShare' => MacroConstants::RESERVE_DRAW_CEILING];
        $levers = BudgetBargain::winSetPoint($proposal, $standing, $supporters, $caps);
        $fundHeld = !$recession && $platform['reserveDrawShare'] > MacroConstants::RESERVE_DRAW_CEILING
            && $levers['reserveDrawShare'] >= MacroConstants::RESERVE_DRAW_CEILING * (1.0 - 1e-12);
        if ($fundHeld) {
            $levers['reserveDrawShare'] = MacroConstants::RESERVE_DRAW_CEILING;
        }
        $supportHeld = [];
        foreach ($levers as $lever => $value) {
            $supportHeld[$lever] = $value !== $platform[$lever] && !($lever === 'reserveDrawShare' && $fundHeld);
        }

        return ['levers' => $levers, 'platform' => $platform, 'supportHeld' => $supportHeld, 'fundHeld' => $fundHeld];
    }

    /**
     * A budget round: budget() becomes law, and the economy reads the levers from the next tick.
     *
     * @param PoliticsState $state     The politics, advanced in place.
     * @param bool          $recession Whether a recession is declared (the fund consents to a draw above its ceiling).
     */
    public static function enactBudget(PoliticsState $state, bool $recession = false): void
    {
        $standing = self::standingLevers($state);
        $levers = self::budget(
            AerieDiet::governingParties($state->governingCoalition),
            AerieDiet::governingParties($state->supportParties),
            $state->dietSeats,
            $state->partyPositions,
            $standing,
            $recession,
        )['levers'];

        if ($levers !== $standing) {
            $state->lastBudgetEnactedAt = $state->totalTime;
        }
        foreach (Lever::cases() as $lever) {
            $state->{$lever->field()} = $levers[$lever->value];
        }
    }
}
