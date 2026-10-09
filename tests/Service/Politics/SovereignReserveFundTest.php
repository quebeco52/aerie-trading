<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics;

use App\Data\Politics\AerieCouncil;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Event\ShockEvent;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Math\MathUtility;
use App\Service\Politics\CouncilAppointments as Appointments;
use App\Service\Politics\FinancialRegulator;
use App\Service\Politics\MonetaryAuthority;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\PoliticsState;
use App\Service\Politics\SovereignReserveFund as Fund;
use PHPUnit\Framework\TestCase;

class SovereignReserveFundTest extends TestCase
{
    private const DT = 1.0 / 52.0;

    /** One tick as the politics engine runs it: the Council, then each department. */
    private static function tick(PoliticsState $state, MathUtility $math): void
    {
        Appointments::advance($state, $math);
        MonetaryAuthority::advance($state, new MacroStateDTO(totalTime: $state->totalTime, policyRate: 0.03), self::DT, $math);
        FinancialRegulator::advance($state, $math);
        Fund::advance($state, $math);
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
     * A stance is a policy mix on record: -1 the most cautious, +1 the boldest, linear between; the mixes are in
     * ascending order; every candidate's draw lands on one of them; and the fund's own opening mix reads as a stance on
     * the same scale.
     */
    public function testAStanceIsAPolicyMixOnRecord(): void
    {
        $sorted = Fund::OBSERVED_EQUITY_SHARES;
        sort($sorted);
        $this->assertSame(Fund::OBSERVED_EQUITY_SHARES, $sorted);
        $this->assertCount(7, Fund::OBSERVED_EQUITY_SHARES);
        $this->assertEqualsWithDelta(0.65, Fund::OBSERVED_EQUITY_SHARES[3], 1e-12, 'Median: GIC\'s reference portfolio.');
        $this->assertSame('bold', Fund::stanceName(SovereignFundSubsystem::OPENING_POLICY_EQUITY_SHARE), 'The fund opens a shade bolder than the GPFG.');
        $this->assertSame('balanced', Fund::stanceName(0.65));
        $this->assertSame('cautious', Fund::stanceName(0.50));

        $this->assertEqualsWithDelta(-1.0, Fund::stance(Fund::mostCautious()), 1e-12);
        $this->assertEqualsWithDelta(1.0, Fund::stance(Fund::boldest()), 1e-12);
        foreach (Fund::OBSERVED_EQUITY_SHARES as $share) {
            $this->assertEqualsWithDelta($share, Fund::equityShare(Fund::stance($share)), 1e-12);
        }
        $this->assertEqualsWithDelta(Fund::mostCautious(), Fund::equityShare(Fund::drawStance(0.0)), 1e-12);
        $this->assertEqualsWithDelta(Fund::boldest(), Fund::equityShare(Fund::drawStance(1.0 - 1e-12)), 1e-12);

        $opening = Fund::stance(SovereignFundSubsystem::OPENING_POLICY_EQUITY_SHARE);
        $this->assertGreaterThanOrEqual(-1.0, $opening);
        $this->assertLessThanOrEqual(1.0, $opening);
        $this->assertLessThan(Fund::BOLD_ABOVE, Fund::CAUTIOUS_BELOW + 1e-9);
    }

    /**
     * At Year 1 the head seated before it keeps the mix the fund opened with, so the economy is handed no share; nothing
     * before the fund has a head.
     */
    public function testTheHeadAtYearOneKeepsTheOpeningMix(): void
    {
        $state = self::opened();

        $this->assertSame(AerieCouncil::OPENING_FUND_HEAD, $state->fundHeadName);
        $this->assertEqualsWithDelta(Fund::OPENING_HEAD_TERM_END - Fund::HEAD_TERM_YEARS, $state->fundHeadTermStart, 1e-9);
        $this->assertEqualsWithDelta(SovereignFundSubsystem::OPENING_POLICY_EQUITY_SHARE, Fund::equityShare($state->fundHeadStance), 1e-12);
        $this->assertSame([], $state->fundHeadPassedOver);
        $this->assertCount(AerieCouncil::SEATS, $state->councilFundStances);
        $names = Appointments::sittingNames($state);
        $this->assertSame($names, array_values(array_unique($names)), 'No two people sitting share a name.');

        $this->assertNull(PoliticsStateDTO::fromState($state)->policy()->reserveFundEquityShare);
        $this->assertNull((new PoliticsStateDTO())->policy()->reserveFundEquityShare);
    }

    /**
     * At the opening head's term end the Council names the candidate nearest its median on the reserves, who hands the
     * economy their share; the appointment makes the headline; and nothing changes until the next term ends.
     */
    public function testAtTermEndTheCouncilNamesTheCandidateNearestItsMedian(): void
    {
        foreach ([3, 7, 11, 19] as $seed) {
            $state = self::runTo(self::opened($seed), Fund::OPENING_HEAD_TERM_END - self::DT);
            $median = Appointments::median($state->councilFundStances);
            $sitting = Appointments::sittingNames($state);
            $this->assertSame(AerieCouncil::OPENING_FUND_HEAD, $state->fundHeadName);

            self::runTo($state, Fund::OPENING_HEAD_TERM_END + self::DT);
            [$expected, $passedOver] = Appointments::appoint((int) $state->authoritySalt, 'fund', Fund::OPENING_HEAD_TERM_END, [Appointments::AXIS_FUND => $median], $sitting, new MathUtility());
            $this->assertSame($expected['name'], $state->fundHeadName, "seed {$seed}");
            $this->assertSame($expected['fund'], $state->fundHeadStance);
            $this->assertSame($passedOver, $state->fundHeadPassedOver);
            $appointedAt = $state->lastFundHeadAppointedAt;
            $this->assertGreaterThan(Fund::OPENING_HEAD_TERM_END - self::DT, $appointedAt);
            $this->assertEqualsWithDelta(Fund::equityShare($state->fundHeadStance), PoliticsStateDTO::fromState($state)->policy()->reserveFundEquityShare, 1e-12);

            self::runTo($state, Fund::OPENING_HEAD_TERM_END + Fund::HEAD_TERM_YEARS - self::DT);
            $this->assertSame($expected['name'], $state->fundHeadName, 'One term, unchanged until it ends.');
            $this->assertSame($appointedAt, $state->lastFundHeadAppointedAt);
        }

        $headline = new PoliticsState();
        $headline->totalTime = 4.0;
        $headline->lastFundHeadAppointedAt = 4.0;
        $this->assertSame(ShockEvent::FUND_HEAD_APPOINTED, PoliticsEngine::headline($headline));
        $headline->lastRegulatorAppointedAt = 4.0;
        $this->assertSame(ShockEvent::REGULATOR_APPOINTED, PoliticsEngine::headline($headline));
    }

    /**
     * A running game that predates the fund's head gains one on its next tick for the term in progress, and every
     * councillor gains a stance on the reserves; catching up is not news, and the rest of the Council is untouched.
     */
    public function testARunningGameGainsAHeadForTheTermInProgress(): void
    {
        $state = self::runTo(self::opened(5), 11.0);
        $council = $state->councilNames;
        $regulator = $state->regulatorName;
        $state->fundHeadName = '';
        $state->councilFundStances = [];
        $state->lastFundHeadAppointedAt = -1.0;

        self::runTo($state, 11.0 + self::DT);

        $this->assertSame($council, $state->councilNames);
        $this->assertSame($regulator, $state->regulatorName);
        $this->assertCount(AerieCouncil::SEATS, $state->councilFundStances);
        $this->assertNotSame('', $state->fundHeadName);
        $this->assertNotSame(AerieCouncil::OPENING_FUND_HEAD, $state->fundHeadName);
        $this->assertEqualsWithDelta(Fund::OPENING_HEAD_TERM_END + Fund::HEAD_TERM_YEARS, $state->fundHeadTermStart, 1e-9);
        $this->assertSame(-1.0, $state->lastFundHeadAppointedAt);
    }
}
