<?php

declare(strict_types=1);

namespace App\Tests\Service\Event;

use App\Data\AerieDiet as Diet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Entity\Etf;
use App\Entity\EtfEvent;
use App\Service\Event\MarketEventPublisher;
use App\Service\Event\NarrativeEngine;
use App\Service\Event\ShockEvent;
use App\Service\Event\SystemicEventReporter;
use App\Service\Market\PriceChangeFeed;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class SystemicEventReporterTest extends TestCase
{
    /** @var list<EtfEvent> */
    private array $persisted = [];

    protected function setUp(): void
    {
        // The publisher echoes SHOCK headlines to the terminal; keep the test output clean.
        ob_start();
    }

    protected function tearDown(): void
    {
        ob_end_clean();
    }

    /** The card carries what the benchmark did over the month its chart buffer holds, not a size assumed for the event. */
    public function testTheHeadlineCarriesTheBenchmarksRealMonthMove(): void
    {
        $headline = $this->reporter(bufferedPrice: 100.0)->report($this->macroWith(ShockEvent::BANKING_CRISIS), new PoliticsStateDTO(), $this->benchmarkAt('94'));

        $this->assertNotNull($headline);
        $this->assertSame('SHOCK', $headline['type']);
        $this->assertEqualsWithDelta(-6.0, $headline['change_percent'], 1e-9);
        $this->assertSame('-6', $this->persisted[0]->getChangePercent());
    }

    /** A rescue is reported with the move the market made, so a programme launched into a falling market reads as one. */
    public function testALaunchIntoAFallingMarketIsNotLabelledARally(): void
    {
        $headline = $this->reporter(bufferedPrice: 100.0)->report($this->macroWith(ShockEvent::TITAN_INTERVENTION), new PoliticsStateDTO(), $this->benchmarkAt('91.5'));

        $this->assertNotNull($headline);
        $this->assertLessThan(0.0, $headline['change_percent']);
    }

    public function testWithoutBufferedHistoryTheHeadlineCarriesNoNumber(): void
    {
        $headline = $this->reporter(bufferedPrice: null)->report($this->macroWith(ShockEvent::RECESSION_DECLARED), new PoliticsStateDTO(), $this->benchmarkAt('100'));

        $this->assertNotNull($headline);
        $this->assertNull($headline['change_percent']);
        $this->assertNull($this->persisted[0]->getChangePercent());
    }

    /**
     * A hung Diet's headline names the largest party, the party opening the talks and the biggest mover, and never the
     * cabinet the talks will produce or try: that is settled on the day but not known until it takes office.
     */
    public function testTheElectionHeadlineOpensTheTalksWithoutGivingAwayTheirOutcome(): void
    {
        $politics = new PoliticsStateDTO(
            eventType: ShockEvent::ELECTION_HELD,
            dietSeats: [Diet::CIVIC => 97.0, Diet::VANGUARD => 83.0, Diet::IRON_HARBOR => 46.0, Diet::EXCHANGE => 34.0, Diet::CHARTISTS => 25.0, Diet::COMMON_LOT => 15.0],
            dietVoteSwings: [Diet::CIVIC => 0.048, Diet::VANGUARD => -0.05, Diet::IRON_HARBOR => 0.004, Diet::EXCHANGE => -0.002, Diet::CHARTISTS => 0.0, Diet::COMMON_LOT => 0.0],
            pendingCoalition: Diet::membership([Diet::CIVIC, Diet::IRON_HARBOR]),
            formationLog: [['day' => 20.0, 'formateur' => Diet::CIVIC, 'formed' => true, 'cabinet' => [Diet::CIVIC, Diet::IRON_HARBOR], 'support' => []]],
        );

        // Every phrasing must hold; draw until each has been seen.
        $seen = [];
        for ($i = 0; $i < 60; ++$i) {
            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(), $politics, $this->benchmarkAt('100'));
            $this->assertNotNull($headline);
            $this->assertStringContainsString('the Civic Front', $headline['description']);
            $this->assertStringContainsString('97', $headline['description']);
            $this->assertStringNotContainsString('Iron Harbor', $headline['description'], 'The talks\' outcome is out before the cabinet takes office.');
            $this->assertStringNotContainsString('{', $headline['description']);
            $seen[$headline['description']] = true;
        }
        $this->assertGreaterThan(1, count($seen));
    }

    /** A party other than the largest can open the talks, when the cabinet it tries leaves the largest out. */
    public function testTheElectionHeadlineNamesAnOpenerOtherThanTheLargestParty(): void
    {
        $politics = new PoliticsStateDTO(
            eventType: ShockEvent::ELECTION_HELD,
            dietSeats: [Diet::CIVIC => 97.0, Diet::VANGUARD => 83.0, Diet::IRON_HARBOR => 46.0, Diet::EXCHANGE => 34.0, Diet::CHARTISTS => 25.0, Diet::COMMON_LOT => 15.0],
            dietVoteSwings: [Diet::CIVIC => 0.048, Diet::VANGUARD => -0.05, Diet::IRON_HARBOR => 0.004, Diet::EXCHANGE => -0.002, Diet::CHARTISTS => 0.0, Diet::COMMON_LOT => 0.0],
            formationLog: [['day' => 20.0, 'formateur' => Diet::VANGUARD, 'formed' => false, 'cabinet' => [Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS], 'support' => []]],
        );

        $opened = 0;
        for ($i = 0; $i < 60; ++$i) {
            $description = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(), $politics, $this->benchmarkAt('100'))['description'] ?? '';
            $this->assertStringNotContainsString('Exchange', $description, 'The cabinet the first attempt tries is out before the talks end.');
            if (str_contains($description, 'opens coalition talks')) {
                ++$opened;
                $this->assertStringContainsString('the Vanguard opens coalition talks, though the Civic Front is the largest with 97', $description);
            }
        }
        $this->assertGreaterThan(0, $opened);
    }

    /** The bloc count names the larger bloc, which need not be the largest party's: Civic leads on seats, the Vanguard's side on blocs. */
    public function testTheElectionHeadlineCountsTheLargerBloc(): void
    {
        $politics = new PoliticsStateDTO(
            eventType: ShockEvent::ELECTION_HELD,
            dietSeats: [Diet::CIVIC => 78.0, Diet::VANGUARD => 77.0, Diet::IRON_HARBOR => 30.0, Diet::EXCHANGE => 30.0, Diet::CHARTISTS => 20.0, Diet::COMMON_LOT => 15.0, Diet::TIDELINE => 15.0, Diet::NEW_HORIZON => 35.0],
            dietVoteSwings: [Diet::CIVIC => 0.01, Diet::VANGUARD => -0.01] + array_fill_keys(array_keys(Diet::SEED_SEATS), 0.0),
            formationLog: [['day' => 20.0, 'formateur' => Diet::CIVIC, 'formed' => true, 'cabinet' => [Diet::CIVIC], 'support' => [Diet::TIDELINE, Diet::IRON_HARBOR, Diet::COMMON_LOT]]],
        );

        $counted = 0;
        for ($i = 0; $i < 60; ++$i) {
            $description = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(), $politics, $this->benchmarkAt('100'))['description'] ?? '';
            if (str_contains($description, 'The blocs are counted')) {
                ++$counted;
                $this->assertStringContainsString("the Vanguard's side holds 162 of 300 seats", $description);
            }
        }
        $this->assertGreaterThan(0, $counted);
    }

    public function testAPartyWithAMajorityOfItsOwnIsNamedGoverningAlone(): void
    {
        $politics = new PoliticsStateDTO(
            eventType: ShockEvent::ELECTION_HELD,
            dietSeats: [Diet::CIVIC => 60.0, Diet::VANGUARD => 160.0, Diet::IRON_HARBOR => 30.0, Diet::EXCHANGE => 30.0, Diet::CHARTISTS => 10.0, Diet::COMMON_LOT => 10.0],
            dietVoteSwings: [Diet::CIVIC => -0.1, Diet::VANGUARD => 0.2, Diet::IRON_HARBOR => 0.0, Diet::EXCHANGE => 0.0, Diet::CHARTISTS => 0.0, Diet::COMMON_LOT => 0.0],
        );

        for ($i = 0; $i < 30; ++$i) {
            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(), $politics, $this->benchmarkAt('100'));
            $this->assertNotNull($headline);
            $this->assertStringContainsString('the Vanguard', $headline['description']);
            $this->assertStringContainsString('160', $headline['description']);
            $this->assertStringNotContainsString('talks', $headline['description']);
        }
    }

    /** The day a cabinet takes office, its headline names it, its supporters, and the talks that made it. */
    public function testTheFormationHeadlineNamesTheCabinetAndItsSupporters(): void
    {
        $log = [
            ['day' => 19.6, 'formateur' => Diet::VANGUARD, 'round' => 1, 'formed' => false, 'cabinet' => [Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS], 'support' => []],
            ['day' => 41.2, 'formateur' => Diet::VANGUARD, 'round' => 2, 'formed' => true, 'cabinet' => [Diet::VANGUARD], 'support' => [Diet::EXCHANGE, Diet::CHARTISTS]],
        ];
        $politics = new PoliticsStateDTO(
            eventType: ShockEvent::GOVERNMENT_FORMED,
            totalTime: 4.12,
            dietSeats: Diet::SEED_SEATS,
            governingCoalition: Diet::membership([Diet::VANGUARD]),
            supportParties: Diet::membership([Diet::EXCHANGE, Diet::CHARTISTS]),
            lastGovernmentFormedAt: 4.12,
            formationLog: $log,
        );

        $seen = [];
        for ($i = 0; $i < 40; ++$i) {
            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(), $politics, $this->benchmarkAt('100'));
            $this->assertNotNull($headline);
            $this->assertStringContainsString('the Vanguard', $headline['description']);
            $this->assertStringContainsString('the Exchange Party and the Chartists', $headline['description']);
            $this->assertMatchesRegularExpression('/41 days|after 2 attempts/', $headline['description']);
            $this->assertStringNotContainsString('{', $headline['description']);
            $seen[$headline['description']] = true;
        }
        $this->assertGreaterThan(1, count($seen));
    }

    /** The day a cabinet falls, its headline names the caretaker, the supporters it lost and the party opening the talks, never the cabinet they will seat. */
    public function testTheFallHeadlineNamesTheCabinetThatFell(): void
    {
        $log = [['day' => 30.0, 'formateur' => Diet::CIVIC, 'formed' => true, 'cabinet' => [Diet::CIVIC, Diet::TIDELINE], 'support' => [Diet::IRON_HARBOR]]];
        $minority = new PoliticsStateDTO(
            eventType: ShockEvent::GOVERNMENT_FELL,
            totalTime: 6.5,
            dietSeats: Diet::SEED_SEATS,
            governingCoalition: Diet::membership([Diet::VANGUARD]),
            supportParties: Diet::membership([Diet::EXCHANGE, Diet::CHARTISTS]),
            coalitionFormedAt: 4.5,
            pendingCoalition: Diet::membership([Diet::CIVIC, Diet::TIDELINE]),
            coalitionTakesOfficeAt: 6.5 + 30.0 / 365.0,
            formationLog: $log,
            lastCabinetFellAt: 6.5,
        );
        $majority = new PoliticsStateDTO(
            eventType: ShockEvent::GOVERNMENT_FELL,
            totalTime: 6.5,
            dietSeats: Diet::SEED_SEATS,
            governingCoalition: Diet::membership([Diet::VANGUARD, Diet::CIVIC]),
            supportParties: Diet::membership([]),
            coalitionFormedAt: 4.5,
            formationLog: $log,
            lastCabinetFellAt: 6.5,
        );

        for ($i = 0; $i < 30; ++$i) {
            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(), $minority, $this->benchmarkAt('100'));
            $this->assertNotNull($headline);
            $this->assertStringContainsString('the Vanguard', $headline['description']);
            $this->assertStringContainsString('the Exchange Party and the Chartists', $headline['description']);
            $this->assertStringNotContainsString('Bastion', $headline['description'], 'The talks\' outcome is not news yet.');
            $this->assertStringNotContainsString('{', $headline['description']);

            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(), $majority, $this->benchmarkAt('100'));
            $this->assertNotNull($headline);
            $this->assertStringContainsString('the Civic Front and the Vanguard', $headline['description']);
            $this->assertStringNotContainsString('{', $headline['description']);
        }
    }

    /** A budget the Council held back says so, and names the debt it answered; one it let through names the levers. */
    public function testTheBudgetHeadlineSaysWhatTheCouncilHeld(): void
    {
        $governing = [Diet::CIVIC => 0.0, Diet::VANGUARD => 1.0, Diet::IRON_HARBOR => 0.0, Diet::EXCHANGE => 1.0];
        $indebted = new MacroStateDTO(totalTime: 4.5, sovereignDebtToGdp: 0.93);
        $held = new PoliticsStateDTO(eventType: ShockEvent::BUDGET_ENACTED, totalTime: 4.5, governingCoalition: $governing, lastBudgetEnactedAt: 4.5, lastCouncilBrakeAt: 4.5);
        $passed = new PoliticsStateDTO(eventType: ShockEvent::BUDGET_ENACTED, totalTime: 4.5, governingCoalition: $governing, corporateTaxPolicyShift: -0.026, lastBudgetEnactedAt: 4.5);

        for ($i = 0; $i < 30; ++$i) {
            $headline = $this->reporter(bufferedPrice: null)->report($indebted, $held, $this->benchmarkAt('100'));
            $this->assertNotNull($headline);
            $this->assertStringContainsString('Vanguard-Exchange Party government', $headline['description']);
            $this->assertStringContainsString('93% of GDP', $headline['description']);
            $this->assertStringNotContainsString('{', $headline['description']);

            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(totalTime: 4.5), $passed, $this->benchmarkAt('100'));
            $this->assertNotNull($headline);
            $this->assertStringContainsString('18.4%', $headline['description']);
            $this->assertStringNotContainsString('{', $headline['description']);
        }
    }

    /**
     * The Authority's headlines name the people and the vote: a new governor by name, age, stance and whom the Council
     * passed over; a committee tipping into a supermajority by its make-up; a rate decision by its move and its split.
     */
    public function testTheAuthoritysHeadlinesNameThePeopleAndTheVote(): void
    {
        $authority = [
            'totalTime' => 3.1, 'authoritySalt' => 5.0, 'councilStances' => array_fill(0, 13, 1.0), 'governorName' => 'Theodora Vance', 'governorBirth' => -50.0,
            'governorStance' => 1.0, 'governorPassedOver' => [['name' => 'Clio Wren', 'birth' => -45.0, 'stance' => -1.0, 'regulation' => 0.0, 'fund' => 0.0], ['name' => 'Ivo Sato', 'birth' => -55.0, 'stance' => 0.0, 'regulation' => 0.0, 'fund' => 0.0]],
            'memberStances' => [1.0, 1.0, 1.0, 0.0, 0.0, -1.0], 'committeeMajority' => 1.0,
            'lastMeetingAt' => 3.1, 'lastMeetingRate' => 0.0425, 'lastMeetingChange' => 0.005, 'lastMeetingVotes' => [0.0, 0.0, 0.0, 0.0, 0.0, 1.0, -1.0],
        ];
        $appointed = new PoliticsStateDTO(...($authority + ['eventType' => ShockEvent::GOVERNOR_APPOINTED, 'lastGovernorAppointedAt' => 3.1]));
        $tipped = new PoliticsStateDTO(...($authority + ['eventType' => ShockEvent::AUTHORITY_MAJORITY_SHIFT, 'lastMajorityShiftAt' => 3.1]));
        $decided = new PoliticsStateDTO(...($authority + ['eventType' => ShockEvent::MONETARY_DECISION]));

        for ($i = 0; $i < 20; ++$i) {
            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(totalTime: 3.1), $appointed, $this->benchmarkAt('100'));
            $this->assertNotNull($headline);
            $this->assertStringContainsString('Theodora Vance', $headline['description']);
            $this->assertStringContainsString('a hawk', $headline['description']);
            $this->assertStringContainsString('Clio Wren, a dove and Ivo Sato, a swing vote', $headline['description']);
            $this->assertStringNotContainsString('{', $headline['description']);

            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(totalTime: 3.1), $tipped, $this->benchmarkAt('100'));
            $this->assertNotNull($headline);
            $this->assertStringContainsString('four hawks, two swing votes and one dove', $headline['description']);
            $this->assertStringContainsString('awk', $headline['description']);

            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(totalTime: 3.1), $decided, $this->benchmarkAt('100'));
            $this->assertNotNull($headline);
            $this->assertStringContainsString('raises the rate by 50 basis points to 4.25%', $headline['description']);
            $this->assertStringContainsString('5-2', $headline['description']);
            $this->assertStringContainsString('one member wanted a higher rate and one a lower', $headline['description']);
        }
    }

    /**
     * A new head of the Financial Regulator is named with their age and stance on the banks, the requirement they set
     * against the one in force (a rise phased in, a cut at once), and whom the Council passed over with the requirement
     * each would have set.
     */
    public function testTheRegulatorsHeadlineNamesTheHeadAndTheRequirement(): void
    {
        $head = [
            'totalTime' => 7.0, 'authoritySalt' => 5.0, 'eventType' => ShockEvent::REGULATOR_APPOINTED, 'lastRegulatorAppointedAt' => 7.0,
            'regulatorName' => 'Odile Marsh', 'regulatorBirth' => -48.0, 'regulatorTermStart' => 7.0,
            'regulatorPassedOver' => [['name' => 'Bram Ellery', 'birth' => -50.0, 'stance' => 0.0, 'regulation' => -1.0, 'fund' => 0.0]],
            'requirementPhaseFrom' => 0.094, 'requirementPhaseStart' => 7.0, 'bankCapitalRequirement' => 0.094,
        ];
        $raising = new PoliticsStateDTO(...($head + ['regulatorStance' => \App\Service\Politics\FinancialRegulator::stance(0.1230)]));
        $cutting = new PoliticsStateDTO(...($head + ['regulatorStance' => \App\Service\Politics\FinancialRegulator::stance(0.0850)]));

        for ($i = 0; $i < 20; ++$i) {
            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(totalTime: 7.0), $raising, $this->benchmarkAt('100'));
            $this->assertNotNull($headline);
            $this->assertStringContainsString('Odile Marsh', $headline['description']);
            $this->assertStringContainsString('a strict regulator', $headline['description']);
            $this->assertStringContainsString('to 12.3% of risk-weighted assets from 9.4%, phased in over the coming year', $headline['description']);
            $this->assertStringNotContainsString('{', $headline['description']);

            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(totalTime: 7.0), $cutting, $this->benchmarkAt('100'));
            $this->assertNotNull($headline);
            $this->assertStringContainsString('a light-touch regulator', $headline['description']);
            $this->assertStringContainsString('cuts the banks\' core capital requirement to 8.5% of risk-weighted assets from 9.4%, with immediate effect', $headline['description']);
        }

        $named = 0;
        for ($i = 0; $i < 40; ++$i) {
            $named += str_contains((string) $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(totalTime: 7.0), $raising, $this->benchmarkAt('100'))['description'], 'Bram Ellery, who would have set 8.4%') ? 1 : 0;
        }
        $this->assertGreaterThan(0, $named, 'One phrasing names whom the Council passed over.');
    }

    /**
     * A new head of the Sovereign Reserve Fund is named with their stance on the reserves and the equity share they set
     * against the fund's, with the part of the board's float the fund will buy or sell to get there.
     */
    public function testTheFundHeadsHeadlineNamesTheHeadAndTheTrade(): void
    {
        $head = [
            'totalTime' => 4.0, 'authoritySalt' => 5.0, 'eventType' => ShockEvent::FUND_HEAD_APPOINTED, 'lastFundHeadAppointedAt' => 4.0,
            'fundHeadName' => 'Ines Varga', 'fundHeadBirth' => -50.0, 'fundHeadTermStart' => 4.0,
            'fundHeadPassedOver' => [['name' => 'Oren Pike', 'birth' => -48.0, 'stance' => 0.0, 'regulation' => 0.0, 'fund' => 1.0]],
        ];
        // A $5T fund 70% in equities, 16% of it in a $15T board float: the board is 0.16 / 0.70 of its equities.
        $macro = new MacroStateDTO(totalTime: 4.0, sovereignFundPolicyEquityShare: 0.70, sovereignFundTargetWeight: 0.16, sovereignFundToGdp: 0.5, sovereignFundDollarsPerGdp: 1.0e11, nominalGdpIndex: 100.0, boardFloatCap: 15.0e12);
        $cautious = new PoliticsStateDTO(...($head + ['fundHeadStance' => \App\Service\Politics\SovereignReserveFund::stance(0.50)]));
        $sold = (0.16 / 0.70) * 0.20 * 5.0e12 / 15.0e12;

        for ($i = 0; $i < 20; ++$i) {
            $headline = $this->reporter(bufferedPrice: null)->report($macro, $cautious, $this->benchmarkAt('100'));
            $this->assertNotNull($headline);
            $this->assertStringContainsString('Ines Varga', $headline['description']);
            $this->assertStringContainsString('a cautious investor', $headline['description']);
            $this->assertStringContainsString('cuts its share in equities to 50% from 70%, selling about ' . number_format($sold * 100.0, 1) . "% of the board's free float over 20 months", $headline['description']);
            $this->assertStringNotContainsString('{', $headline['description']);
        }
    }

    public function testATickWithoutAnEventPublishesNothing(): void
    {
        $this->assertNull($this->reporter(bufferedPrice: 100.0)->report(new MacroStateDTO(), new PoliticsStateDTO(), $this->benchmarkAt('100')));
        $this->assertSame([], $this->persisted);
    }

    /** One headline a tick: the economy's outranks the government's, which is reported when the economy has none. */
    public function testTheEconomysEventOutranksTheGovernments(): void
    {
        $vote = new PoliticsStateDTO(
            eventType: ShockEvent::ELECTION_HELD,
            dietSeats: [Diet::CIVIC => 97.0, Diet::VANGUARD => 83.0, Diet::IRON_HARBOR => 46.0, Diet::EXCHANGE => 34.0, Diet::CHARTISTS => 25.0, Diet::COMMON_LOT => 15.0],
            dietVoteSwings: [Diet::CIVIC => 0.048, Diet::VANGUARD => -0.05, Diet::IRON_HARBOR => 0.004, Diet::EXCHANGE => -0.002, Diet::CHARTISTS => 0.0, Diet::COMMON_LOT => 0.0],
            formationLog: [['day' => 20.0, 'formateur' => Diet::CIVIC, 'formed' => true, 'cabinet' => [Diet::CIVIC], 'support' => []]],
        );

        for ($i = 0; $i < 20; ++$i) {
            $crisis = $this->reporter(bufferedPrice: null)->report($this->macroWith(ShockEvent::BANKING_CRISIS), $vote, $this->benchmarkAt('100'));
            $quiet = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(), $vote, $this->benchmarkAt('100'));

            $this->assertNotNull($crisis);
            $this->assertStringNotContainsString('Civic Front', $crisis['description'], 'A crisis on the day of the vote is the headline.');
            $this->assertNotNull($quiet);
            $this->assertStringContainsString('the Civic Front', $quiet['description']);
        }
    }

    private function reporter(?float $bufferedPrice): SystemicEventReporter
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('lIndex')->willReturn($bufferedPrice === null ? false : json_encode(['price' => $bufferedPrice, 'recorded_at' => '2026-09-01 00:00']));

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->assertInstanceOf(EtfEvent::class, $entity);
            $this->persisted[] = $entity;
        });

        return new SystemicEventReporter(
            new NarrativeEngine(),
            new MarketEventPublisher($entityManager, $this->createStub(LoggerInterface::class), $redis),
            new PriceChangeFeed($redis, $entityManager, 3600),
        );
    }

    private function macroWith(string $eventType): MacroStateDTO
    {
        return new MacroStateDTO(eventType: $eventType);
    }

    private function benchmarkAt(string $price): Etf
    {
        return (new Etf())->setTicker('LBI')->setPrice($price);
    }
}
