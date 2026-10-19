<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics\Department;

use App\Data\Politics\AerieCouncil;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Event\ShockEvent;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\MathUtility;
use App\Service\Politics\Council\CouncilAppointments as Appointments;
use App\Service\Politics\Department\Exchequer;
use App\Service\Politics\Department\FinancialRegulator;
use App\Service\Politics\Department\MonetaryAuthority;
use App\Service\Politics\Department\SovereignReserveFund;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

class ExchequerTest extends TestCase
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

    /**
     * A stance is an average maturity on the Treasury's record: -1 the 48 months of 2008, +1 the 71 of 2017, linear
     * between; the founding book reads balanced.
     */
    public function testAStanceIsAnAverageMaturityOnRecord(): void
    {
        $this->assertSame(4.0, Exchequer::SHORTEST_AVERAGE_MATURITY);
        $this->assertEqualsWithDelta(71.0 / 12.0, Exchequer::LONGEST_AVERAGE_MATURITY, 1e-12);
        $this->assertEqualsWithDelta(-1.0, Exchequer::stance(Exchequer::SHORTEST_AVERAGE_MATURITY), 1e-12);
        $this->assertEqualsWithDelta(1.0, Exchequer::stance(Exchequer::LONGEST_AVERAGE_MATURITY), 1e-12);
        $this->assertEqualsWithDelta(0.0, Exchequer::stance(CreditFiscalSubsystem::FOUNDING_DEBT_AVERAGE_MATURITY), 1e-12, 'The founding book is the record\'s midpoint.');
        $this->assertEqualsWithDelta(5.0, Exchequer::averageMaturity(Exchequer::stance(5.0)), 1e-12);
        $this->assertSame(-1.0, Exchequer::drawStance(0.0));
        $this->assertSame('short', Exchequer::stanceName(4.1));
        $this->assertSame('balanced', Exchequer::stanceName(CreditFiscalSubsystem::FOUNDING_DEBT_AVERAGE_MATURITY));
        $this->assertSame('long', Exchequer::stanceName(5.8));
    }

    /**
     * At Year 1 the head seated before it keeps the book the debt opened with, so the economy is handed no target;
     * every councillor holds a stance on the debt.
     */
    public function testTheHeadAtYearOneKeepsTheOpeningBook(): void
    {
        $state = self::opened();

        $this->assertSame(AerieCouncil::OPENING_EXCHEQUER_HEAD, $state->exchequerHeadName);
        $this->assertEqualsWithDelta(Exchequer::OPENING_HEAD_TERM_END - Exchequer::HEAD_TERM_YEARS, $state->exchequerHeadTermStart, 1e-9);
        $this->assertEqualsWithDelta(CreditFiscalSubsystem::FOUNDING_DEBT_AVERAGE_MATURITY, Exchequer::averageMaturity($state->exchequerHeadStance), 1e-12);
        $this->assertCount(AerieCouncil::SEATS, $state->councilDebtStances);
        $names = Appointments::sittingNames($state);
        $this->assertContains(AerieCouncil::OPENING_EXCHEQUER_HEAD, $names);
        $this->assertSame($names, array_values(array_unique($names)), 'No two people sitting share a name.');

        $this->assertNull(PoliticsStateDTO::fromState($state)->policy()->debtAverageMaturity);
        $this->assertNull((new PoliticsStateDTO())->policy()->debtAverageMaturity);
    }

    /**
     * At the opening head's term end the Council names the candidate nearest its median on the debt, who hands the
     * economy their maturity; nothing changes until the next term ends.
     */
    public function testAtTermEndTheCouncilNamesTheCandidateNearestItsMedian(): void
    {
        foreach ([3, 7, 11, 19] as $seed) {
            $state = self::runTo(self::opened($seed), Exchequer::OPENING_HEAD_TERM_END - self::DT);
            $median = Appointments::median($state->councilDebtStances);
            $sitting = Appointments::sittingNames($state);
            $this->assertSame(AerieCouncil::OPENING_EXCHEQUER_HEAD, $state->exchequerHeadName);

            self::runTo($state, Exchequer::OPENING_HEAD_TERM_END + self::DT);
            [$expected, $passedOver] = Appointments::appoint((int) $state->authoritySalt, 'exchequer', Exchequer::OPENING_HEAD_TERM_END, [Appointments::AXIS_DEBT => $median], $sitting, new MathUtility());
            $this->assertSame($expected['name'], $state->exchequerHeadName, "seed {$seed}");
            $this->assertSame($expected['debt'], $state->exchequerHeadStance);
            $this->assertSame($passedOver, $state->exchequerHeadPassedOver);
            $appointedAt = $state->lastExchequerHeadAppointedAt;
            $this->assertEqualsWithDelta(Exchequer::averageMaturity($state->exchequerHeadStance), PoliticsStateDTO::fromState($state)->policy()->debtAverageMaturity, 1e-12);

            self::runTo($state, Exchequer::OPENING_HEAD_TERM_END + Exchequer::HEAD_TERM_YEARS - self::DT);
            $this->assertSame($expected['name'], $state->exchequerHeadName, 'One term, unchanged until it ends.');
            $this->assertSame($appointedAt, $state->lastExchequerHeadAppointedAt);
        }

        $headline = new PoliticsState();
        $headline->totalTime = 3.0;
        $headline->lastExchequerHeadAppointedAt = 3.0;
        $this->assertSame(ShockEvent::EXCHEQUER_HEAD_APPOINTED, PoliticsEngine::headline($headline));
    }

    /**
     * The debt stance weighs only in naming the Exchequer's head: the Council fills its own seats on the other three
     * questions.
     */
    public function testCouncilSeatsAreNotChosenOnTheDebt(): void
    {
        $state = self::opened();
        $this->assertArrayNotHasKey(Appointments::AXIS_DEBT, Appointments::councilMedians($state));
        $this->assertArrayNotHasKey(Appointments::AXIS_DEBT, Appointments::boardMedians($state));
    }
}
