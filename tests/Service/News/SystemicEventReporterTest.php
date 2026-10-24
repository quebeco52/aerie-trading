<?php

declare(strict_types=1);

namespace App\Tests\Service\News;

use App\Data\Politics\AerieDiet as Diet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Entity\DistrictNews;
use App\Entity\Etf;
use App\Service\Event\ShockEvent;
use App\Service\Market\Chart\PriceChangeFeed;
use App\Service\News\MarketEventPublisher;
use App\Service\News\NarrativeEngine;
use App\Service\News\SystemicEventReporter;
use App\Service\Politics\Department\Office;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SystemicEventReporterTest extends TestCase
{
    /** @var list<DistrictNews> */
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

    /**
     * The card carries what the benchmark did over the month its chart buffer holds, not a size assumed for the event.
     */
    public function testTheHeadlineCarriesTheBenchmarksRealMonthMove(): void
    {
        $headline = $this->reporter(bufferedPrice: 100.0)->report($this->macroWith(ShockEvent::BANKING_CRISIS), new PoliticsStateDTO(), $this->benchmarkAt('94'))[0] ?? null;

        $this->assertNotNull($headline);
        $this->assertSame(DistrictNews::DESK_ECONOMY, $headline['type']);
        $this->assertEqualsWithDelta(-6.0, $headline['change_percent'], 1e-9);
        $this->assertSame('-6', $this->persisted[0]->getChangePercent());
    }

    /** The story goes on the desk of the engine that made it, carries its topic, and is a headline on the wire. */
    public function testAStoryIsFiledOnItsDeskWithItsTopicAsAHeadline(): void
    {
        $crisis = $this->reporter(bufferedPrice: 100.0)->report($this->macroWith(ShockEvent::BANKING_CRISIS), new PoliticsStateDTO(), $this->benchmarkAt('94'))[0] ?? null;
        $budget = $this->reporter(bufferedPrice: 100.0)->report(new MacroStateDTO(), new PoliticsStateDTO(eventType: ShockEvent::BUDGET_ENACTED), $this->benchmarkAt('94'))[0] ?? null;

        $this->assertNotNull($crisis);
        $this->assertNotNull($budget);
        $this->assertSame([DistrictNews::DESK_ECONOMY, DistrictNews::DESK_GOVERNMENT], [$this->persisted[0]->getDesk(), $this->persisted[1]->getDesk()]);
        $this->assertSame([ShockEvent::BANKING_CRISIS, ShockEvent::BUDGET_ENACTED], [$this->persisted[0]->getTopic(), $this->persisted[1]->getTopic()]);
        $this->assertNull($crisis['ticker']);
        $this->assertSame(['district', 'district'], [$crisis['section'], $budget['section']]);
        $this->assertTrue($crisis['headline'] && $budget['headline']);
        $this->assertSame(['BANKING CRISIS', 'BUDGET'], [$crisis['presented']['badge'], $budget['presented']['badge']]);
    }

    /**
     * A rescue is reported with the move the market made, so a programme launched into a falling market reads as one.
     */
    public function testALaunchIntoAFallingMarketIsNotLabelledARally(): void
    {
        $headline = $this->reporter(bufferedPrice: 100.0)->report($this->macroWith(ShockEvent::TITAN_INTERVENTION), new PoliticsStateDTO(), $this->benchmarkAt('91.5'))[0] ?? null;

        $this->assertNotNull($headline);
        $this->assertLessThan(0.0, $headline['change_percent']);
    }

    public function testWithoutBufferedHistoryTheHeadlineCarriesNoNumber(): void
    {
        $headline = $this->reporter(bufferedPrice: null)->report($this->macroWith(ShockEvent::RECESSION_DECLARED), new PoliticsStateDTO(), $this->benchmarkAt('100'))[0] ?? null;

        $this->assertNotNull($headline);
        $this->assertNull($headline['change_percent']);
        $this->assertNull($this->persisted[0]->getChangePercent());
    }

    /** The story quotes the fall of the quarter that made the call. */
    public function testARecessionCalledThisQuarterQuotesTheQuartersFall(): void
    {
        $macro = new MacroStateDTO(eventType: ShockEvent::RECESSION_DECLARED, recessionDeclared: true, gdpQuarterStreak: -2.0, realGdpQuarterlyGrowth: -0.006);

        $this->reporter(bufferedPrice: 100.0)->report($macro, new PoliticsStateDTO(), $this->benchmarkAt('97'));

        $this->assertStringContainsString('0.6%', (string) $this->persisted[0]->getDescription());
    }

    /**
     * A call reported late, behind a bigger story on the declaring tick, lands in a later quarter that may have grown.
     * It must not say output shrank by that quarter's figure.
     */
    public function testARecessionReportedLateQuotesNoQuarterlyFall(): void
    {
        $macro = new MacroStateDTO(eventType: ShockEvent::RECESSION_DECLARED, recessionDeclared: true, gdpQuarterStreak: 1.0, realGdpQuarterlyGrowth: 0.004);

        $this->reporter(bufferedPrice: 100.0)->report($macro, new PoliticsStateDTO(), $this->benchmarkAt('97'));

        $description = (string) $this->persisted[0]->getDescription();
        $this->assertStringNotContainsString('-0.4%', $description);
        $this->assertStringNotContainsString('shrank', $description);
        $this->assertMatchesRegularExpression('/recession/i', $description);
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
            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(), $politics, $this->benchmarkAt('100'))[0] ?? null;
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
            $description = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(), $politics, $this->benchmarkAt('100'))[0]['description'] ?? '';
            $this->assertStringNotContainsString('Exchange', $description, 'The cabinet the first attempt tries is out before the talks end.');
            if (str_contains($description, 'opens coalition talks')) {
                ++$opened;
                $this->assertStringContainsString('the Vanguard opens coalition talks, though the Civic Front is the largest with 97', $description);
            }
        }
        $this->assertGreaterThan(0, $opened);
    }

    /**
     * The bloc count names the larger bloc, which need not be the largest party's: Civic leads on seats, the Vanguard's
     * side on blocs.
     */
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
            $description = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(), $politics, $this->benchmarkAt('100'))[0]['description'] ?? '';
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
            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(), $politics, $this->benchmarkAt('100'))[0] ?? null;
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
            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(), $politics, $this->benchmarkAt('100'))[0] ?? null;
            $this->assertNotNull($headline);
            $this->assertStringContainsString('the Vanguard', $headline['description']);
            $this->assertStringContainsString('the Exchange Party and the Chartists', $headline['description']);
            $this->assertMatchesRegularExpression('/41 days|after 2 attempts/', $headline['description']);
            $this->assertStringNotContainsString('{', $headline['description']);
            $seen[$headline['description']] = true;
        }
        $this->assertGreaterThan(1, count($seen));
    }

    /**
     * The day a cabinet falls, its headline names the caretaker, the supporters it lost and the party opening the
     * talks, never the cabinet they will seat.
     */
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
            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(), $minority, $this->benchmarkAt('100'))[0] ?? null;
            $this->assertNotNull($headline);
            $this->assertStringContainsString('the Vanguard', $headline['description']);
            $this->assertStringContainsString('the Exchange Party and the Chartists', $headline['description']);
            $this->assertStringNotContainsString('Bastion', $headline['description'], 'The talks\' outcome is not news yet.');
            $this->assertStringNotContainsString('{', $headline['description']);

            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(), $majority, $this->benchmarkAt('100'))[0] ?? null;
            $this->assertNotNull($headline);
            $this->assertStringContainsString('the Civic Front and the Vanguard', $headline['description']);
            $this->assertStringNotContainsString('{', $headline['description']);
        }
    }

    /**
     * The Authority's headlines name the people and the vote: a new governor by name, age, stance and whom the Council
     * passed over; a committee tipping into a supermajority by its make-up; a rate decision by its move and its split.
     */
    public function testTheAuthoritysHeadlinesNameThePeopleAndTheVote(): void
    {
        $governor = ['name' => 'Theodora Vance', 'birth' => -50.0, 'stance' => 1.0, 'passedOver' => [['name' => 'Clio Wren', 'birth' => -45.0, 'stance' => -1.0, 'regulation' => 0.0, 'fund' => 0.0, 'debt' => 0.0, 'competition' => 0.0, 'markets' => 0.0], ['name' => 'Ivo Sato', 'birth' => -55.0, 'stance' => 0.0, 'regulation' => 0.0, 'fund' => 0.0, 'debt' => 0.0, 'competition' => 0.0, 'markets' => 0.0]]] + Office::VACANT;
        $authority = [
            'totalTime' => 3.1, 'authoritySalt' => 5.0, 'councilStances' => array_fill(0, 13, 1.0), 'heads' => [Office::Governor->value => $governor],
            'memberStances' => [1.0, 1.0, 1.0, 0.0, 0.0, -1.0], 'committeeMajority' => 1.0,
            'lastMeetingAt' => 3.1, 'lastMeetingRate' => 0.0425, 'lastMeetingChange' => 0.005, 'lastMeetingVotes' => [0.0, 0.0, 0.0, 0.0, 0.0, 1.0, -1.0],
        ];
        $appointed = new PoliticsStateDTO(...(['eventType' => ShockEvent::GOVERNOR_APPOINTED, 'heads' => [Office::Governor->value => ['appointedAt' => 3.1] + $governor]] + $authority));
        $tipped = new PoliticsStateDTO(...($authority + ['eventType' => ShockEvent::AUTHORITY_MAJORITY_SHIFT, 'lastMajorityShiftAt' => 3.1]));
        $decided = new PoliticsStateDTO(...($authority + ['eventType' => ShockEvent::MONETARY_DECISION]));

        for ($i = 0; $i < 20; ++$i) {
            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(totalTime: 3.1), $appointed, $this->benchmarkAt('100'))[0] ?? null;
            $this->assertNotNull($headline);
            $this->assertStringContainsString('Theodora Vance', $headline['description']);
            $this->assertStringContainsString('a hawk', $headline['description']);
            $this->assertStringContainsString('Clio Wren, a dove and Ivo Sato, a swing vote', $headline['description']);
            $this->assertStringNotContainsString('{', $headline['description']);

            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(totalTime: 3.1), $tipped, $this->benchmarkAt('100'))[0] ?? null;
            $this->assertNotNull($headline);
            $this->assertStringContainsString('four hawks, two swing votes and one dove', $headline['description']);
            $this->assertStringContainsString('awk', $headline['description']);

            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(totalTime: 3.1), $decided, $this->benchmarkAt('100'))[0] ?? null;
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
            'totalTime' => 7.0, 'authoritySalt' => 5.0, 'eventType' => ShockEvent::REGULATOR_APPOINTED,
            'requirementPhaseFrom' => 0.094, 'requirementPhaseStart' => 7.0, 'bankCapitalRequirement' => 0.094,
        ];
        $regulator = static fn(float $requirement): array => [Office::Regulator->value => [
            'name' => 'Odile Marsh', 'birth' => -48.0, 'termStart' => 7.0, 'stance' => \App\Service\Politics\Department\FinancialRegulator::stance($requirement),
            'passedOver' => [['name' => 'Bram Ellery', 'birth' => -50.0, 'stance' => 0.0, 'regulation' => -1.0, 'fund' => 0.0, 'debt' => 0.0, 'competition' => 0.0, 'markets' => 0.0]], 'appointedAt' => 7.0,
        ]];
        $raising = new PoliticsStateDTO(...($head + ['heads' => $regulator(0.1230)]));
        $cutting = new PoliticsStateDTO(...($head + ['heads' => $regulator(0.0850)]));

        for ($i = 0; $i < 20; ++$i) {
            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(totalTime: 7.0), $raising, $this->benchmarkAt('100'))[0] ?? null;
            $this->assertNotNull($headline);
            $this->assertStringContainsString('Odile Marsh', $headline['description']);
            $this->assertStringContainsString('a strict regulator', $headline['description']);
            $this->assertStringContainsString('to 12.3% of risk-weighted assets from 9.4%, phased in over the coming year', $headline['description']);
            $this->assertStringNotContainsString('{', $headline['description']);

            $headline = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(totalTime: 7.0), $cutting, $this->benchmarkAt('100'))[0] ?? null;
            $this->assertNotNull($headline);
            $this->assertStringContainsString('a light-touch regulator', $headline['description']);
            $this->assertStringContainsString('cuts the banks\' core capital requirement to 8.5% of risk-weighted assets from 9.4%, with immediate effect', $headline['description']);
        }

        $named = 0;
        for ($i = 0; $i < 40; ++$i) {
            $named += str_contains((string) $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(totalTime: 7.0), $raising, $this->benchmarkAt('100'))[0]['description'], 'Bram Ellery, who would have set 8.0%') ? 1 : 0;
        }
        $this->assertGreaterThan(0, $named, 'One phrasing names whom the Council passed over.');
    }

    /**
     * A new head of the Sovereign Reserve Fund is named with their stance on the reserves and the equity share they set
     * against the fund's, with the part of the board's float the fund will buy or sell to get there.
     */
    public function testTheFundHeadsHeadlineNamesTheHeadAndTheTrade(): void
    {
        $head = ['totalTime' => 4.0, 'authoritySalt' => 5.0, 'eventType' => ShockEvent::FUND_HEAD_APPOINTED];
        $fundHead = static fn(float $equityShare): array => [Office::FundHead->value => [
            'name' => 'Ines Varga', 'birth' => -50.0, 'termStart' => 4.0, 'stance' => \App\Service\Politics\Department\SovereignReserveFund::stance($equityShare),
            'passedOver' => [['name' => 'Oren Pike', 'birth' => -48.0, 'stance' => 0.0, 'regulation' => 0.0, 'fund' => 1.0, 'debt' => 0.0, 'competition' => 0.0, 'markets' => 0.0]], 'appointedAt' => 4.0,
        ]];
        // A $5T fund 70% in equities, 16% of it in a $15T board float: the board is 0.16 / 0.70 of its equities.
        $macro = new MacroStateDTO(totalTime: 4.0, sovereignFundPolicyEquityShare: 0.70, sovereignFundTargetWeight: 0.16, sovereignFundToGdp: 0.5, sovereignFundDollarsPerGdp: 1.0e11, nominalGdpIndex: 100.0, boardFloatCap: 15.0e12);
        $cautious = new PoliticsStateDTO(...($head + ['heads' => $fundHead(0.50)]));
        $sold = (0.16 / 0.70) * 0.20 * 5.0e12 / 15.0e12;

        for ($i = 0; $i < 20; ++$i) {
            $headline = $this->reporter(bufferedPrice: null)->report($macro, $cautious, $this->benchmarkAt('100'))[0] ?? null;
            $this->assertNotNull($headline);
            $this->assertStringContainsString('Ines Varga', $headline['description']);
            $this->assertStringContainsString('a cautious investor', $headline['description']);
            $this->assertStringContainsString('cuts its share in equities to 50% from 70%, selling about ' . number_format($sold * 100.0, 1) . "% of the board's free float over 20 months", $headline['description']);
            $this->assertStringNotContainsString('{', $headline['description']);
        }
    }

    /**
     * A new head of the Exchequer is named with their stance on the debt and the average maturity they steer it toward
     * against today's, with what that does to the ten-year once the book has rolled over.
     */
    public function testTheExchequerHeadsHeadlineNamesTheHeadAndTheMaturity(): void
    {
        $short = new PoliticsStateDTO(
            totalTime: 3.0,
            authoritySalt: 5.0,
            eventType: ShockEvent::EXCHEQUER_HEAD_APPOINTED,
            heads: [Office::ExchequerHead->value => [
                'name' => 'Rosalind Tate',
                'birth' => -51.0,
                'termStart' => 3.0,
                'stance' => \App\Service\Politics\Department\Exchequer::stance(4.0),
                'passedOver' => [['name' => 'Hal Brennan', 'birth' => -47.0, 'stance' => 0.0, 'regulation' => 0.0, 'fund' => 0.0, 'debt' => 1.0, 'competition' => 0.0, 'markets' => 0.0]],
                'appointedAt' => 3.0,
            ]],
        );
        $macro = new MacroStateDTO(totalTime: 3.0, sovereignDebtToGdpEma: 0.9, sovereignDebtAverageMaturity: 5.0);
        $basisPoints = (int) round(10000.0 * 0.0029 * 0.9 * 1.0);

        for ($i = 0; $i < 20; ++$i) {
            $headline = $this->reporter(bufferedPrice: null)->report($macro, $short, $this->benchmarkAt('100'))[0] ?? null;
            $this->assertNotNull($headline);
            $this->assertStringContainsString('Rosalind Tate', $headline['description']);
            $this->assertStringContainsString('who favours borrowing short', $headline['description']);
            $this->assertStringContainsString("shortens the debt toward an average of 4.0 years from 5.0 years, which as the debt rolls over takes about {$basisPoints} basis points off the ten-year", $headline['description']);
            $this->assertStringNotContainsString('{', $headline['description']);
        }
    }

    /**
     * A party's new leader is named with their age, the leader they succeed and how long that one led; when the party
     * leads the cabinet, the news is of a new prime minister.
     */
    public function testALeadersHeadlineNamesTheSuccessionAndAPremiersNamesTheNewPrimeMinister(): void
    {
        $change = [
            'totalTime' => 6.0, 'authoritySalt' => 5.0, 'lastLeaderChangeAt' => 6.0,
            'leaderNames' => [Diet::VANGUARD => 'Maren Holt', Diet::CIVIC => 'Tomas Reyne'],
            'leaderBirths' => [Diet::VANGUARD => -40.0, Diet::CIVIC => -45.0],
            'leaderSince' => [Diet::VANGUARD => 6.0, Diet::CIVIC => 6.0],
        ];
        $premier = new PoliticsStateDTO(...($change + ['eventType' => ShockEvent::PRIME_MINISTER_CHANGED, 'lastLeaderChangeParty' => Diet::VANGUARD,
            'leaderHistory' => [Diet::VANGUARD => [['name' => 'Ada Quill', 'birth' => -60.0, 'since' => -1.0, 'until' => 6.0]]]]));
        $party = new PoliticsStateDTO(...($change + ['eventType' => ShockEvent::PARTY_LEADER_CHANGED, 'lastLeaderChangeParty' => Diet::CIVIC,
            'leaderHistory' => [Diet::CIVIC => [['name' => 'Ola Brandt', 'birth' => -60.0, 'since' => 5.5, 'until' => 6.0]]]]));

        for ($i = 0; $i < 20; ++$i) {
            $news = (string) $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(totalTime: 6.0), $premier, $this->benchmarkAt('100'))[0]['description'];
            $this->assertStringContainsString('Maren Holt', $news);
            $this->assertStringContainsString('46', $news);
            $this->assertStringContainsString('Ada Quill', $news);
            $this->assertStringContainsString('7 years', $news);
            $this->assertStringContainsString('prime minister', strtolower($news));
            $this->assertStringNotContainsString('{', $news);

            $news = (string) $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(totalTime: 6.0), $party, $this->benchmarkAt('100'))[0]['description'];
            $this->assertStringContainsString('Tomas Reyne', $news);
            $this->assertStringContainsString('Ola Brandt', $news);
            $this->assertStringContainsString('less than a year', $news);
            $this->assertStringContainsString('the Civic Front', $news);
            $this->assertStringNotContainsString('prime minister', strtolower($news));
        }
    }

    public function testATickWithoutAnEventPublishesNothing(): void
    {
        $this->assertSame([], $this->reporter(bufferedPrice: 100.0)->report(new MacroStateDTO(), new PoliticsStateDTO(), $this->benchmarkAt('100')));
        $this->assertSame([], $this->persisted);
    }

    /**
     * Each desk files its own story: a crisis on the day of the vote leads, and the vote is still reported beside it.
     */
    public function testTheEconomysStoryLeadsAndTheGovernmentsIsStillFiled(): void
    {
        $vote = new PoliticsStateDTO(
            eventType: ShockEvent::ELECTION_HELD,
            dietSeats: [Diet::CIVIC => 97.0, Diet::VANGUARD => 83.0, Diet::IRON_HARBOR => 46.0, Diet::EXCHANGE => 34.0, Diet::CHARTISTS => 25.0, Diet::COMMON_LOT => 15.0],
            dietVoteSwings: [Diet::CIVIC => 0.048, Diet::VANGUARD => -0.05, Diet::IRON_HARBOR => 0.004, Diet::EXCHANGE => -0.002, Diet::CHARTISTS => 0.0, Diet::COMMON_LOT => 0.0],
            formationLog: [['day' => 20.0, 'formateur' => Diet::CIVIC, 'formed' => true, 'cabinet' => [Diet::CIVIC], 'support' => []]],
        );

        $stories = $this->reporter(bufferedPrice: null)->report($this->macroWith(ShockEvent::BANKING_CRISIS), $vote, $this->benchmarkAt('100'));

        $this->assertSame([DistrictNews::DESK_ECONOMY, DistrictNews::DESK_GOVERNMENT], array_column($stories, 'type'));
        $this->assertStringNotContainsString('Civic Front', $stories[0]['description'], 'The crisis leads.');
        $this->assertStringContainsString('the Civic Front', $stories[1]['description']);
    }

    /**
     * A budget round falls on a meeting day every time; a decision that made news is filed beside it rather than lost,
     * and a decision that is itself the government's story is filed once.
     */
    public function testARateDecisionIsFiledBesideTheBudgetRoundItFallsOn(): void
    {
        $meeting = [
            'totalTime' => 3.5, 'authoritySalt' => 5.0, 'councilStances' => array_fill(0, 13, 1.0), 'heads' => [Office::Governor->value => ['name' => 'Theodora Vance', 'birth' => -50.0] + Office::VACANT],
            'memberStances' => [1.0, 1.0, 1.0, 0.0, 0.0, -1.0], 'committeeMajority' => 1.0,
            'lastMeetingAt' => 3.5, 'lastMeetingRate' => 0.0425, 'lastMeetingChange' => 0.0025, 'lastMeetingVotes' => array_fill(0, 7, 0.0),
        ];
        $politics = static fn(array $fields): PoliticsStateDTO => new PoliticsStateDTO(...$fields);

        $budgetDay = $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(totalTime: 3.5), $politics($meeting + ['eventType' => ShockEvent::BUDGET_ENACTED]), $this->benchmarkAt('100'));
        $this->assertSame([ShockEvent::BUDGET_ENACTED, ShockEvent::MONETARY_DECISION], array_map(static fn(DistrictNews $news): string => $news->getTopic(), $this->persisted));
        $this->assertStringContainsString('raises the rate by 25 basis points to 4.25%', $budgetDay[1]['description']);

        $this->persisted = [];
        $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(totalTime: 3.5), $politics($meeting + ['eventType' => ShockEvent::MONETARY_DECISION]), $this->benchmarkAt('100'));
        $this->assertCount(1, $this->persisted);

        $this->persisted = [];
        $held = ['lastMeetingChange' => 0.0] + $meeting;
        $this->reporter(bufferedPrice: null)->report(new MacroStateDTO(totalTime: 3.5), $politics($held + ['eventType' => ShockEvent::BUDGET_ENACTED]), $this->benchmarkAt('100'));
        $this->assertCount(1, $this->persisted, 'A unanimous hold is no news.');
    }

    private function reporter(?float $bufferedPrice): SystemicEventReporter
    {
        $redis = $this->createStub(\Redis::class);
        $redis->method('lIndex')->willReturn($bufferedPrice === null ? false : json_encode(['price' => $bufferedPrice, 'recorded_at' => '2026-09-01 00:00']));

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->assertInstanceOf(DistrictNews::class, $entity, 'A district story is not filed against the benchmark fund.');
            $this->persisted[] = $entity;
        });

        return new SystemicEventReporter(
            new NarrativeEngine(),
            new MarketEventPublisher($entityManager, $this->createStub(LoggerInterface::class)),
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
