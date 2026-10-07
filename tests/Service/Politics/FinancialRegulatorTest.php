<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics;

use App\Data\AerieCouncil;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Event\ShockEvent;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Politics\CouncilAppointments as Appointments;
use App\Service\Politics\FinancialRegulator as Regulator;
use App\Service\Politics\MonetaryAuthority;
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
     * A stance is a regime: -1 the lightest on record (Luxembourg's 8.43%), +1 the strictest (Norway's 13.15%), linear
     * between; the regimes are the 17 largest banks' end-2024 requirements in ascending order, with the median 10.0%; and
     * they read light-touch below the Asian centres' gap, strict above Canada's.
     */
    public function testAStanceIsARegimeOnRecord(): void
    {
        $this->assertCount(17, Regulator::OBSERVED_REQUIREMENTS);
        $sorted = Regulator::OBSERVED_REQUIREMENTS;
        sort($sorted);
        $this->assertSame(Regulator::OBSERVED_REQUIREMENTS, $sorted);
        $this->assertEqualsWithDelta(0.100, Regulator::OBSERVED_REQUIREMENTS[8], 1e-12, 'Median: UBS going concern.');

        $this->assertEqualsWithDelta(-1.0, Regulator::stance(0.0843), 1e-12);
        $this->assertEqualsWithDelta(1.0, Regulator::stance(0.1315), 1e-12);
        foreach ([0.0843, 0.094, 0.1046, 0.1315] as $requirement) {
            $this->assertEqualsWithDelta($requirement, Regulator::requirement(Regulator::stance($requirement)), 1e-12);
        }
        $this->assertEqualsWithDelta(Regulator::OBSERVED_REQUIREMENTS[0], Regulator::requirement(Regulator::drawStance(0.0)), 1e-12);
        $this->assertEqualsWithDelta(Regulator::OBSERVED_REQUIREMENTS[16], Regulator::requirement(Regulator::drawStance(1.0 - 1e-12)), 1e-12);

        $this->assertSame('light', Regulator::stanceName(0.0950));
        $this->assertSame('middle', Regulator::stanceName(0.0966));
        $this->assertSame('middle', Regulator::stanceName(0.1150));
        $this->assertSame('strict', Regulator::stanceName(0.1230));
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
     * At Year 1 the head seated before it runs the requirement the banks opened under, chosen over no one on record; the
     * economy is handed that requirement, and nothing before the Regulator has a head.
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
     * Each regime carries its jurisdiction's mortgage cap, null where there is none: from Hong Kong's 70% to the
     * Netherlands' and Luxembourg's 100%, the US among those with none; a stance on record reads its own regime's cap.
     */
    public function testEachRegimeCarriesItsMortgageCap(): void
    {
        $this->assertCount(count(Regulator::OBSERVED_REQUIREMENTS), Regulator::OBSERVED_LTV_CAPS);
        $this->assertSame(0.70, min(array_filter(Regulator::OBSERVED_LTV_CAPS, static fn (?float $cap): bool => $cap !== null)));
        $this->assertSame(1.00, max(Regulator::OBSERVED_LTV_CAPS));
        $this->assertNull(Regulator::OBSERVED_LTV_CAPS[array_search(0.1230, Regulator::OBSERVED_REQUIREMENTS, true)], 'The US runs none.');
        $this->assertCount(6, array_filter(Regulator::OBSERVED_LTV_CAPS, static fn (?float $cap): bool => $cap === null));
        foreach (Regulator::OBSERVED_REQUIREMENTS as $index => $requirement) {
            $this->assertSame(Regulator::OBSERVED_LTV_CAPS[$index], Regulator::ltvCapForStance(Regulator::stance($requirement)), "regime {$index}");
        }
    }

    /** The head sitting at Year 1 runs no mortgage cap, and the economy is handed none. */
    public function testTheHeadAtYearOneRunsNoMortgageCap(): void
    {
        $state = self::opened();

        $this->assertNull(Regulator::ltvCap($state));
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

            $this->assertSame(Regulator::ltvCapForStance($state->regulatorStance), Regulator::ltvCap($state), 'The head runs their regime\'s cap.');
            $this->assertSame(Regulator::ltvCap($state), PoliticsStateDTO::fromState($state)->policy()->mortgageLtvCap, 'In force from the seating, with no phase-in.');

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
     * term in progress, whose requirement is taken as already in force; its Council, governor and committee are untouched.
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
