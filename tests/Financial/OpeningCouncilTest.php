<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\Department\FairTradingBoard;
use App\Service\Politics\Department\FinancialRegulator;
use App\Service\Politics\Department\MonetaryAuthority;
use App\Service\Politics\Department\Office;
use App\Service\Politics\Department\SecuritiesCommission;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

/**
 * The Council sitting at Year 1 appointed the opening heads, so it stands near the policies they run: its median on
 * each continuous question sits at the opening policy, and a department's first appointment after Year 1 is as likely
 * to loosen as to tighten, rather than tightening in four games of five. The fund's candidates run the seven mixes on
 * record, most below the opening one, so its first pick trims whatever the Council.
 */
class OpeningCouncilTest extends TestCase
{
    private const GAMES = 200;
    private const DT = 1.0 / 12.0;

    public function testTheOpeningCouncilStandsAtTheOpeningPolicy(): void
    {
        $opening = Office::openingPolicy();
        $medians = [CouncilAppointments::AXIS_REGULATION => [], CouncilAppointments::AXIS_COMPETITION => []];
        for ($game = 1; $game <= self::GAMES; ++$game) {
            mt_srand($game);
            $state = new PoliticsState();
            CouncilAppointments::advance($state, new MathUtility());
            $medians[CouncilAppointments::AXIS_REGULATION][] = CouncilAppointments::median($state->councilRegulationStances);
            $medians[CouncilAppointments::AXIS_COMPETITION][] = CouncilAppointments::median($state->councilCompetitionStances);
        }

        foreach ($medians as $axis => $values) {
            $this->assertEqualsWithDelta($opening[$axis], array_sum($values) / self::GAMES, 0.1, "The Council's median on {$axis} at Year 1.");
        }
    }

    public function testAFirstAppointmentIsAsLikelyToLoosenAsToTighten(): void
    {
        $offices = [Office::Regulator, Office::ExchequerHead, Office::FairTradingHead, Office::SecuritiesHead];
        $moves = array_fill_keys(array_map(static fn(Office $office): string => $office->value, $offices), []);
        for ($game = 1; $game <= self::GAMES; ++$game) {
            mt_srand($game);
            $math = new MathUtility();
            $state = new PoliticsState();
            $opening = [];
            for ($t = 0.0; $t <= 5.0 + 1e-9; $t += self::DT) {
                $state->totalTime = $t;
                CouncilAppointments::advance($state, $math);
                MonetaryAuthority::advance($state, new MacroStateDTO(totalTime: $t), self::DT);
                FinancialRegulator::advance($state);
                Office::ExchequerHead->fill($state);
                FairTradingBoard::advance($state);
                SecuritiesCommission::advance($state);
                foreach ($offices as $office) {
                    $head = $state->head($office);
                    $opening[$office->value] ??= $head['stance'];
                    if (!isset($moves[$office->value][$game]) && $head['appointedAt'] >= 0.0) {
                        $moves[$office->value][$game] = $head['stance'] - $opening[$office->value];
                    }
                }
            }
        }

        foreach ($moves as $office => $byGame) {
            $this->assertCount(self::GAMES, $byGame, "Every game appoints a {$office} head within five years.");
            // A Council drawn from the whole pool moved the regulator 0.46 and the Fair Trading Board 0.40 stricter on
            // average.
            $this->assertLessThan(0.2, abs(array_sum($byGame) / self::GAMES), "The {$office} head's first move leans one way.");
        }
    }
}
