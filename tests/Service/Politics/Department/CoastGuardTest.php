<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics\Department;

use App\Data\Politics\AerieCouncil;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Politics\Council\CouncilAppointments as Appointments;
use App\Service\Politics\Department\CoastGuard;
use App\Service\Politics\Department\Exchequer;
use App\Service\Politics\Department\FairTradingBoard;
use App\Service\Politics\Department\FinancialRegulator;
use App\Service\Politics\Department\MonetaryAuthority;
use App\Service\Politics\Department\SecuritiesCommission;
use App\Service\Politics\Department\SecurityBureau;
use App\Service\Politics\Department\SovereignReserveFund;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

class CoastGuardTest extends TestCase
{
    private const DT = 1.0 / 52.0;

    /** One tick as the politics engine runs it: the Council, then each department. */
    private static function tick(PoliticsState $state, MathUtility $math): void
    {
        Appointments::advance($state, $math);
        MonetaryAuthority::advance($state, new MacroStateDTO(totalTime: $state->totalTime, policyRate: 0.03), self::DT, $math);
        FinancialRegulator::advance($state, $math);
        SovereignReserveFund::advance($state, $math);
        Exchequer::advance($state, $math);
        FairTradingBoard::advance($state, $math);
        SecuritiesCommission::advance($state, $math);
        SecurityBureau::advance($state, $math);
        CoastGuard::advance($state, $math);
    }

    private static function opened(int $seed = 7): PoliticsState
    {
        mt_srand($seed);
        $state = new PoliticsState();
        self::tick($state, new MathUtility());

        return $state;
    }

    private static function runTo(PoliticsState $state, float $until): PoliticsState
    {
        $math = new MathUtility();
        while ($state->totalTime + (self::DT / 2.0) < $until) {
            $state->totalTime += self::DT;
            self::tick($state, $math);
        }

        return $state;
    }

    /** At Year 1 the commandant seated before it holds the post, and the Coast Guard hands the economy nothing. */
    public function testTheCommandantAtYearOneIsSeatedAndSetsNoRule(): void
    {
        $state = self::opened();

        $this->assertSame(AerieCouncil::OPENING_COAST_GUARD_COMMANDANT, $state->coastGuardCommandantName);
        $this->assertEqualsWithDelta(CoastGuard::OPENING_COMMANDANT_TERM_END - CoastGuard::COMMANDANT_TERM_YEARS, $state->coastGuardCommandantTermStart, 1e-9);
        $names = Appointments::sittingNames($state);
        $this->assertContains(AerieCouncil::OPENING_COAST_GUARD_COMMANDANT, $names);
        $this->assertSame($names, array_values(array_unique($names)), 'No two people sitting share a name.');

        $without = PoliticsStateDTO::fromState($state);
        $state->coastGuardCommandantName = 'Someone Else';
        $this->assertEquals($without->policy(), PoliticsStateDTO::fromState($state)->policy(), 'Who commands the Coast Guard moves nothing in the economy.');
    }

    /**
     * At the opening commandant's term end the Council names the candidate nearest its median on its own questions, for
     * one five-year term.
     */
    public function testAtTermEndTheCouncilNamesTheCandidateNearestItsMedians(): void
    {
        foreach ([3, 11] as $seed) {
            $state = self::runTo(self::opened($seed), CoastGuard::OPENING_COMMANDANT_TERM_END - self::DT);
            $medians = Appointments::councilMedians($state);
            $sitting = Appointments::sittingNames($state);
            $this->assertSame(AerieCouncil::OPENING_COAST_GUARD_COMMANDANT, $state->coastGuardCommandantName);

            self::runTo($state, CoastGuard::OPENING_COMMANDANT_TERM_END + self::DT);
            [$expected, $passedOver] = Appointments::appoint((int) $state->authoritySalt, 'coast-guard', CoastGuard::OPENING_COMMANDANT_TERM_END, $medians, $sitting, new MathUtility());
            $this->assertSame($expected['name'], $state->coastGuardCommandantName, "seed {$seed}");
            $this->assertSame($passedOver, $state->coastGuardCommandantPassedOver);
            $this->assertEqualsWithDelta(CoastGuard::OPENING_COMMANDANT_TERM_END + CoastGuard::COMMANDANT_TERM_YEARS, CoastGuard::commandantTermEnd($state->totalTime), 1e-9);
        }

        $headline = new PoliticsState();
        $headline->totalTime = 3.0;
        $headline->lastCoastGuardCommandantAppointedAt = 3.0;
        $this->assertSame(ShockEvent::COAST_GUARD_COMMANDANT_APPOINTED, PoliticsEngine::headline($headline));
    }
}
