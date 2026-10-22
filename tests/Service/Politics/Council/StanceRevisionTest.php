<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics\Council;

use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Politics\Council\CouncilAppointments as Appointments;
use App\Service\Politics\Council\StanceRevision;
use App\Service\Politics\Department\MonetaryAuthority as Authority;
use App\Service\Politics\Department\Office;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

class StanceRevisionTest extends TestCase
{
    /** A Council and Authority opened at Year 1 on a seed. */
    private static function opened(int $seed): PoliticsState
    {
        mt_srand($seed);
        $state = new PoliticsState();
        $state->totalTime = 0.0;
        $math = new MathUtility();
        Appointments::advance($state, $math);
        Authority::advance($state, new MacroStateDTO(totalTime: 0.0, policyRate: 0.03), 1.0 / 52.0);

        return $state;
    }

    /** Everyone's camp on money with their type, so a revision with nobody reseated can be compared. */
    private static function camps(PoliticsState $state): array
    {
        return [$state->councilStances, $state->councilSwingers, $state->head(Office::Governor)['stance'], $state->governorSwinger, $state->memberStances, $state->memberSwingers];
    }

    /**
     * Only swing votes on the committee change camp, and each crosses over in a year with the chance the switch rate
     * gives, 1 - exp(-1/10.8) = 8.8%: with nobody reseated, a hawk or a dove keeps their camp for good.
     */
    public function testOnlySwingVotesChangeCampAtTheirRate(): void
    {
        $swingYears = 0;
        $switches = 0;
        for ($seed = 1; $seed <= 60; ++$seed) {
            $state = self::opened($seed);
            $first = self::camps($state);
            for ($year = 1; $year <= 40; ++$year) {
                $before = self::camps($state);
                $state->totalTime = (float) $year;
                $committee = StanceRevision::revise($state, 1.0 / 52.0);
                $after = self::camps($state);

                $this->assertSame($before[0], $after[0], 'Councillors are not revised: their lean matters only when they appoint.');
                foreach ([[4, 5]] as [$stances, $swingers]) {
                    foreach ($before[$stances] as $seat => $stance) {
                        $swing = $before[$swingers][$seat] > 0.0;
                        $swingYears += $swing ? 1 : 0;
                        if ($after[$stances][$seat] !== $stance) {
                            $this->assertTrue($swing, 'Only a swing vote changes camp.');
                            $this->assertSame(-$stance, $after[$stances][$seat], 'A change of camp crosses to the other.');
                            ++$switches;
                        }
                    }
                }
                $this->assertSame($after[2] !== $before[2] || $after[4] !== $before[4], $committee, 'The committee is reported changed exactly when someone on it changed camp.');
            }
            foreach ($first[0] as $seat => $stance) {
                if ($first[1][$seat] === 0.0) {
                    $this->assertSame($stance, $state->councilStances[$seat]);
                }
            }
        }
        $chance = 1.0 - exp(-StanceRevision::SWITCH_RATE);
        $this->assertGreaterThan(1000, $swingYears);
        $this->assertEqualsWithDelta($chance, $switches / $swingYears, 4.0 * sqrt($chance * (1.0 - $chance) / $swingYears));
    }

    /** The review sits once a year whatever the tick: the same changes of camp at weekly and monthly ticks. */
    public function testChangesOfCampDoNotDependOnTheTickRate(): void
    {
        $histories = [];
        foreach ([1.0 / 52.0, 1.0 / 12.0] as $dt) {
            $state = self::opened(9);
            $history = [];
            $ticks = (int) round(30.0 / $dt);
            for ($tick = 1; $tick <= $ticks; ++$tick) {
                $state->totalTime = $tick * $dt;
                StanceRevision::revise($state, $dt);
                if (abs($state->totalTime - round($state->totalTime)) < $dt / 2.0) {
                    $history[(int) round($state->totalTime)] = self::camps($state);
                }
            }
            $histories[] = $history;
        }
        $this->assertSame($histories[0], $histories[1]);
    }

}
