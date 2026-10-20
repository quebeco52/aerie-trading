<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics\Department;

use App\Data\Politics\AerieCouncil;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Event\ShockEvent;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Politics\Council\CouncilAppointments as Appointments;
use App\Service\Politics\Department\FinancialRegulator as Regulator;
use App\Service\Politics\Department\MonetaryAuthority;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

class FinancialRegulatorTest extends TestCase
{
    private const DT = 1.0 / 52.0;

    /** One tick as the politics engine runs it: the Council, then the Authority, then the Regulator. */
    private static function tick(PoliticsState $state, MathUtility $math): void
    {
        Appointments::advance($state, $math);
        MonetaryAuthority::advance($state, new MacroStateDTO(totalTime: $state->totalTime, policyRate: 0.03), self::DT, $math);
        Regulator::advance($state, $math);
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
     * A stance runs linearly from the lightest requirement (-1) to the strictest (+1), is drawn uniformly, and reads
     * light-touch in the lightest third of the range and strict in the strictest.
     */
    public function testAStanceIsAPlaceOnTheRange(): void
    {
        $this->assertEqualsWithDelta(-1.0, Regulator::stance(Regulator::LIGHTEST_REQUIREMENT), 1e-12);
        $this->assertEqualsWithDelta(1.0, Regulator::stance(Regulator::STRICTEST_REQUIREMENT), 1e-12);
        foreach ([0.08, 0.094, 0.1046, 0.13] as $requirement) {
            $this->assertEqualsWithDelta($requirement, Regulator::requirement(Regulator::stance($requirement)), 1e-12);
        }
        $this->assertEqualsWithDelta(-1.0, Regulator::drawStance(0.0), 1e-12);
        $this->assertEqualsWithDelta(0.0, Regulator::drawStance(0.5), 1e-12);
        $this->assertEqualsWithDelta(1.0, Regulator::drawStance(1.0), 1e-12);

        $this->assertSame('light', Regulator::stanceName(0.0960));
        $this->assertSame('middle', Regulator::stanceName(0.1050));
        $this->assertSame('strict', Regulator::stanceName(0.1200));
        $this->assertSame('light', Regulator::stanceName(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT), 'The District opens light-touch.');
    }

    /** A rise phases in over the year after it is announced; a cut applies at once. */
    public function testARisePhasesInAndACutAppliesAtOnce(): void
    {
        $this->assertEqualsWithDelta(0.094, Regulator::requirementInForce(0.120, 0.094, 0.0), 1e-12);
        $this->assertEqualsWithDelta(0.107, Regulator::requirementInForce(0.120, 0.094, 0.5), 1e-12);
        $this->assertEqualsWithDelta(0.120, Regulator::requirementInForce(0.120, 0.094, 1.0), 1e-12);
        $this->assertEqualsWithDelta(0.120, Regulator::requirementInForce(0.120, 0.094, 4.0), 1e-12);
        $this->assertEqualsWithDelta(0.085, Regulator::requirementInForce(0.085, 0.094, 0.0), 1e-12);
    }

    /**
     * At Year 1 the head seated before it runs the requirement the banks opened under, chosen over no one on record;
     * the economy is handed that requirement, and nothing before the Regulator has a head.
     */
    public function testTheHeadAtYearOneRunsTheOpeningRequirement(): void
    {
        $state = self::opened();

        $this->assertSame(AerieCouncil::OPENING_REGULATOR, $state->regulatorName);
        $this->assertEqualsWithDelta(Regulator::OPENING_HEAD_TERM_END - Regulator::HEAD_TERM_YEARS, $state->regulatorTermStart, 1e-9);
        $this->assertSame([], $state->regulatorPassedOver);
        $this->assertEqualsWithDelta(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT, $state->bankCapitalRequirement, 1e-12);
        $age = $state->regulatorTermStart - $state->regulatorBirth;
        $this->assertGreaterThanOrEqual(Appointments::APPOINTMENT_AGE_MIN, $age);
        $this->assertLessThanOrEqual(Appointments::APPOINTMENT_AGE_MAX, $age);
        $names = Appointments::sittingNames($state);
        $this->assertSame($names, array_values(array_unique($names)), 'No two people sitting share a name.');

        $this->assertEqualsWithDelta(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT, PoliticsStateDTO::fromState($state)->policy()->bankCapitalRequirement, 1e-12);
        $this->assertNull((new PoliticsStateDTO())->policy()->bankCapitalRequirement);
    }

    /**
     * The mortgage cap and the neutral buffer tighten together with the stance: none at or below the stance the
     * District opened under, then linearly to the strictest, the cap starting where no buyer borrows, so it moves
     * continuously.
     */
    public function testTheCapAndBufferTightenFromTheOpeningStance(): void
    {
        $opening = Regulator::stance(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT);
        foreach ([-1.0, $opening - 0.1, $opening] as $stance) {
            $this->assertNull(Regulator::ltvCapForStance($stance));
            $this->assertSame(0.0, Regulator::neutralBufferForStance($stance));
        }
        $this->assertEqualsWithDelta(Regulator::STRICTEST_LTV_CAP, Regulator::ltvCapForStance(1.0), 1e-12);
        $this->assertEqualsWithDelta(Regulator::STRICTEST_NEUTRAL_BUFFER, Regulator::neutralBufferForStance(1.0), 1e-12);

        $justPast = Regulator::ltvCapForStance($opening + 1e-9);
        $this->assertNotNull($justPast);
        $this->assertEqualsWithDelta(0.0, \App\Service\Macro\Subsystem\CreditFiscalSubsystem::mortgageLtvCreditCut($justPast), 1e-6, 'A cap just past the opening binds on no buyer: no jump from none.');
        $midway = ($opening + 1.0) / 2.0;
        $this->assertGreaterThan(Regulator::STRICTEST_LTV_CAP, Regulator::ltvCapForStance($midway));
        $this->assertLessThan(Regulator::ltvCapForStance($opening + 0.01), Regulator::ltvCapForStance($midway));
        $this->assertEqualsWithDelta(Regulator::STRICTEST_NEUTRAL_BUFFER / 2.0, Regulator::neutralBufferForStance($midway), 1e-12);
    }

    /** The head at Year 1 holds no neutral buffer, and a buffer handed over reaches the economy. */
    public function testTheHeadAtYearOneHoldsNoNeutralBuffer(): void
    {
        $state = self::opened();
        $this->assertSame(0.0, $state->regulatorNeutralBuffer, 'The head at Year 1 holds none, as the US does.');
        $this->assertSame(0.0, PoliticsStateDTO::fromState($state)->policy()->neutralCountercyclicalBuffer);
        $handed = new PoliticsStateDTO(regulatorNeutralBuffer: 0.015);
        $this->assertSame(0.015, $handed->policy()->neutralCountercyclicalBuffer);
    }

    /** The head sitting at Year 1 runs no mortgage cap, and the economy is handed none. */
    public function testTheHeadAtYearOneRunsNoMortgageCap(): void
    {
        $state = self::opened();

        $this->assertNull($state->regulatorLtvCap);
        $this->assertNull(PoliticsStateDTO::fromState($state)->policy()->mortgageLtvCap);
    }

    /**
     * At the opening head's term end the Council names the candidate nearest its median on the banks; the new head's
     * requirement takes effect as the head's rule says, the appointment makes the headline, and nothing changes again
     * until the next term ends.
     */
    public function testAtTermEndTheCouncilNamesTheCandidateNearestItsMedian(): void
    {
        foreach ([3, 7, 11, 19, 23] as $seed) {
            $state = self::runTo(self::opened($seed), Regulator::OPENING_HEAD_TERM_END - self::DT);
            $median = Appointments::median($state->councilRegulationStances);
            $sitting = Appointments::sittingNames($state);
            $this->assertSame(AerieCouncil::OPENING_REGULATOR, $state->regulatorName);

            self::runTo($state, Regulator::OPENING_HEAD_TERM_END + self::DT);
            $appointedAt = $state->lastRegulatorAppointedAt;
            [$expected, $passedOver] = Appointments::appoint((int) $state->authoritySalt, 'regulator', Regulator::OPENING_HEAD_TERM_END, [Appointments::AXIS_REGULATION => $median], $sitting, new MathUtility());
            $this->assertSame($expected['name'], $state->regulatorName, "seed {$seed}");
            $this->assertSame($expected['regulation'], $state->regulatorStance);
            $this->assertSame($passedOver, $state->regulatorPassedOver);
            $this->assertGreaterThan(Regulator::OPENING_HEAD_TERM_END - self::DT, $appointedAt);
            $this->assertEqualsWithDelta(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT, $state->requirementPhaseFrom, 1e-12);

            $this->assertSame(Regulator::ltvCapForStance($state->regulatorStance), $state->regulatorLtvCap, 'The head runs their regime\'s cap.');
            $this->assertSame($state->regulatorLtvCap, PoliticsStateDTO::fromState($state)->policy()->mortgageLtvCap, 'In force from the seating, with no phase-in.');
            $this->assertSame(Regulator::neutralBufferForStance($state->regulatorStance), PoliticsStateDTO::fromState($state)->policy()->neutralCountercyclicalBuffer, 'And their regime\'s neutral buffer.');

            $target = Regulator::requirement($state->regulatorStance);
            $this->assertEqualsWithDelta(Regulator::requirementInForce($target, FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT, $state->totalTime - $appointedAt), $state->bankCapitalRequirement, 1e-12);

            self::runTo($state, Regulator::OPENING_HEAD_TERM_END + Regulator::HEAD_TERM_YEARS - self::DT);
            $this->assertSame($expected['name'], $state->regulatorName, 'One term, unchanged until it ends.');
            $this->assertSame($appointedAt, $state->lastRegulatorAppointedAt);
            $this->assertEqualsWithDelta($target, $state->bankCapitalRequirement, 1e-12, 'Phased in by the term\'s end.');
        }
    }

    /** The appointment is the politics engine's headline on its tick, after a governor's. */
    public function testTheAppointmentMakesTheHeadline(): void
    {
        $state = new PoliticsState();
        $state->totalTime = 7.0;
        $state->lastRegulatorAppointedAt = 7.0;
        $this->assertSame(ShockEvent::REGULATOR_APPOINTED, PoliticsEngine::headline($state));

        $state->lastGovernorAppointedAt = 7.0;
        $this->assertSame(ShockEvent::GOVERNOR_APPOINTED, PoliticsEngine::headline($state));
    }

    /**
     * A running game that predates the Regulator gains a head on its next tick, chosen as they would have been for the
     * term in progress, whose requirement is taken as already in force; its Council, governor and committee are
     * untouched.
     */
    public function testARunningGameGainsAHeadForTheTermInProgress(): void
    {
        $state = self::runTo(self::opened(5), 9.0);
        $council = $state->councilNames;
        $governor = $state->governorName;
        $members = $state->memberNames;
        $state->regulatorName = '';
        $state->lastRegulatorAppointedAt = -1.0;
        $state->councilRegulationStances = [];
        $state->bankCapitalRequirement = FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT;

        self::runTo($state, 9.0 + self::DT);

        $this->assertSame($council, $state->councilNames);
        $this->assertSame($governor, $state->governorName);
        $this->assertSame($members, $state->memberNames);
        $this->assertNotSame('', $state->regulatorName);
        $this->assertNotSame(AerieCouncil::OPENING_REGULATOR, $state->regulatorName);
        $this->assertEqualsWithDelta(Regulator::OPENING_HEAD_TERM_END + Regulator::HEAD_TERM_YEARS, $state->regulatorTermStart, 1e-9);
        $this->assertEqualsWithDelta(Regulator::requirement($state->regulatorStance), $state->bankCapitalRequirement, 1e-12);
        $this->assertSame(-1.0, $state->lastRegulatorAppointedAt, 'Catching up is not news.');
    }
}
