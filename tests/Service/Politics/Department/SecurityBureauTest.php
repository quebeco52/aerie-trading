<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics\Department;

use App\Data\Politics\AerieCouncil;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Politics\Council\CouncilAppointments as Appointments;
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

class SecurityBureauTest extends TestCase
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

    /** At Year 1 the director seated before it holds the post, and the Bureau hands the economy nothing. */
    public function testTheDirectorAtYearOneIsSeatedAndSetsNoRule(): void
    {
        $state = self::opened();

        $this->assertSame(AerieCouncil::OPENING_SECURITY_DIRECTOR, $state->securityDirectorName);
        $this->assertEqualsWithDelta(SecurityBureau::OPENING_DIRECTOR_TERM_END - SecurityBureau::DIRECTOR_TERM_YEARS, $state->securityDirectorTermStart, 1e-9);
        $names = Appointments::sittingNames($state);
        $this->assertContains(AerieCouncil::OPENING_SECURITY_DIRECTOR, $names);
        $this->assertSame($names, array_values(array_unique($names)), 'No two people sitting share a name.');

        $without = PoliticsStateDTO::fromState($state);
        $state->securityDirectorName = 'Someone Else';
        $this->assertEquals($without->policy(), PoliticsStateDTO::fromState($state)->policy(), 'Who directs the Bureau moves nothing in the economy.');
    }

    /**
     * At the opening director's term end the Council names the candidate nearest its median on its own questions, for
     * one ten-year term.
     */
    public function testAtTermEndTheCouncilNamesTheCandidateNearestItsMedians(): void
    {
        foreach ([3, 11] as $seed) {
            $state = self::runTo(self::opened($seed), SecurityBureau::OPENING_DIRECTOR_TERM_END - self::DT);
            $medians = Appointments::councilMedians($state);
            $sitting = Appointments::sittingNames($state);
            $this->assertSame(AerieCouncil::OPENING_SECURITY_DIRECTOR, $state->securityDirectorName);

            self::runTo($state, SecurityBureau::OPENING_DIRECTOR_TERM_END + self::DT);
            [$expected, $passedOver] = Appointments::appoint((int) $state->authoritySalt, 'security', SecurityBureau::OPENING_DIRECTOR_TERM_END, $medians, $sitting, new MathUtility());
            $this->assertSame($expected['name'], $state->securityDirectorName, "seed {$seed}");
            $this->assertSame($passedOver, $state->securityDirectorPassedOver);
            $this->assertEqualsWithDelta(SecurityBureau::OPENING_DIRECTOR_TERM_END + SecurityBureau::DIRECTOR_TERM_YEARS, SecurityBureau::directorTermEnd($state->totalTime), 1e-9);
        }

        $headline = new PoliticsState();
        $headline->totalTime = 3.0;
        $headline->lastSecurityDirectorAppointedAt = 3.0;
        $this->assertSame(ShockEvent::SECURITY_DIRECTOR_APPOINTED, PoliticsEngine::headline($headline));
    }
}
