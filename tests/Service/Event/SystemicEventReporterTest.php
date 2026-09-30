<?php

declare(strict_types=1);

namespace App\Tests\Service\Event;

use App\Data\AerieDiet as Diet;
use App\DTO\MacroStateDTO;
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
        $headline = $this->reporter(bufferedPrice: 100.0)->report($this->macroWith(ShockEvent::BANKING_CRISIS), $this->benchmarkAt('94'));

        $this->assertNotNull($headline);
        $this->assertSame('SHOCK', $headline['type']);
        $this->assertEqualsWithDelta(-6.0, $headline['change_percent'], 1e-9);
        $this->assertSame('-6', $this->persisted[0]->getChangePercent());
    }

    /** A rescue is reported with the move the market made, so a programme launched into a falling market reads as one. */
    public function testALaunchIntoAFallingMarketIsNotLabelledARally(): void
    {
        $headline = $this->reporter(bufferedPrice: 100.0)->report($this->macroWith(ShockEvent::TITAN_INTERVENTION), $this->benchmarkAt('91.5'));

        $this->assertNotNull($headline);
        $this->assertLessThan(0.0, $headline['change_percent']);
    }

    public function testWithoutBufferedHistoryTheHeadlineCarriesNoNumber(): void
    {
        $headline = $this->reporter(bufferedPrice: null)->report($this->macroWith(ShockEvent::ELECTION_HELD), $this->benchmarkAt('100'));

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
        $macro = new MacroStateDTO(
            eventType: ShockEvent::ELECTION_HELD,
            dietSeats: [Diet::CIVIC => 97.0, Diet::VANGUARD => 83.0, Diet::IRON_HARBOR => 46.0, Diet::EXCHANGE => 34.0, Diet::CHARTISTS => 25.0, Diet::COMMON_LOT => 15.0],
            dietVoteSwings: [Diet::CIVIC => 0.048, Diet::VANGUARD => -0.05, Diet::IRON_HARBOR => 0.004, Diet::EXCHANGE => -0.002, Diet::CHARTISTS => 0.0, Diet::COMMON_LOT => 0.0],
            pendingCoalition: Diet::membership([Diet::CIVIC, Diet::IRON_HARBOR]),
            formationLog: [['day' => 20.0, 'formateur' => Diet::CIVIC, 'formed' => true, 'cabinet' => [Diet::CIVIC, Diet::IRON_HARBOR], 'support' => []]],
        );

        // Every phrasing must hold; draw until each has been seen.
        $seen = [];
        for ($i = 0; $i < 60; ++$i) {
            $headline = $this->reporter(bufferedPrice: null)->report($macro, $this->benchmarkAt('100'));
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
        $macro = new MacroStateDTO(
            eventType: ShockEvent::ELECTION_HELD,
            dietSeats: [Diet::CIVIC => 97.0, Diet::VANGUARD => 83.0, Diet::IRON_HARBOR => 46.0, Diet::EXCHANGE => 34.0, Diet::CHARTISTS => 25.0, Diet::COMMON_LOT => 15.0],
            dietVoteSwings: [Diet::CIVIC => 0.048, Diet::VANGUARD => -0.05, Diet::IRON_HARBOR => 0.004, Diet::EXCHANGE => -0.002, Diet::CHARTISTS => 0.0, Diet::COMMON_LOT => 0.0],
            formationLog: [['day' => 20.0, 'formateur' => Diet::VANGUARD, 'formed' => false, 'cabinet' => [Diet::VANGUARD, Diet::EXCHANGE, Diet::CHARTISTS], 'support' => []]],
        );

        $opened = 0;
        for ($i = 0; $i < 60; ++$i) {
            $description = $this->reporter(bufferedPrice: null)->report($macro, $this->benchmarkAt('100'))['description'] ?? '';
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
        $macro = new MacroStateDTO(
            eventType: ShockEvent::ELECTION_HELD,
            dietSeats: [Diet::CIVIC => 78.0, Diet::VANGUARD => 77.0] + Diet::SEED_SEATS,
            dietVoteSwings: [Diet::CIVIC => 0.01, Diet::VANGUARD => -0.01] + array_fill_keys(array_keys(Diet::SEED_SEATS), 0.0),
            formationLog: [['day' => 20.0, 'formateur' => Diet::CIVIC, 'formed' => true, 'cabinet' => [Diet::CIVIC], 'support' => [Diet::BASTION_GUILDS, Diet::IRON_HARBOR, Diet::COMMON_LOT]]],
        );

        $counted = 0;
        for ($i = 0; $i < 60; ++$i) {
            $description = $this->reporter(bufferedPrice: null)->report($macro, $this->benchmarkAt('100'))['description'] ?? '';
            if (str_contains($description, 'The blocs are counted')) {
                ++$counted;
                $this->assertStringContainsString("the Vanguard's side holds 152 of 300 seats", $description);
            }
        }
        $this->assertGreaterThan(0, $counted);
    }

    public function testAPartyWithAMajorityOfItsOwnIsNamedGoverningAlone(): void
    {
        $macro = new MacroStateDTO(
            eventType: ShockEvent::ELECTION_HELD,
            dietSeats: [Diet::CIVIC => 60.0, Diet::VANGUARD => 160.0, Diet::IRON_HARBOR => 30.0, Diet::EXCHANGE => 30.0, Diet::CHARTISTS => 10.0, Diet::COMMON_LOT => 10.0],
            dietVoteSwings: [Diet::CIVIC => -0.1, Diet::VANGUARD => 0.2, Diet::IRON_HARBOR => 0.0, Diet::EXCHANGE => 0.0, Diet::CHARTISTS => 0.0, Diet::COMMON_LOT => 0.0],
        );

        for ($i = 0; $i < 30; ++$i) {
            $headline = $this->reporter(bufferedPrice: null)->report($macro, $this->benchmarkAt('100'));
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
        $macro = new MacroStateDTO(
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
            $headline = $this->reporter(bufferedPrice: null)->report($macro, $this->benchmarkAt('100'));
            $this->assertNotNull($headline);
            $this->assertStringContainsString('the Vanguard', $headline['description']);
            $this->assertStringContainsString('the Exchange Party and the Chartists', $headline['description']);
            $this->assertMatchesRegularExpression('/41 days|after 2 attempts/', $headline['description']);
            $this->assertStringNotContainsString('{', $headline['description']);
            $seen[$headline['description']] = true;
        }
        $this->assertGreaterThan(1, count($seen));
    }

    /** A budget the Council held back says so, and names the debt it answered; one it let through names the levers. */
    public function testTheBudgetHeadlineSaysWhatTheCouncilHeld(): void
    {
        $governing = [Diet::CIVIC => 0.0, Diet::VANGUARD => 1.0, Diet::IRON_HARBOR => 0.0, Diet::EXCHANGE => 1.0];
        $held = new MacroStateDTO(eventType: ShockEvent::BUDGET_ENACTED, totalTime: 4.5, governingCoalition: $governing, sovereignDebtToGdp: 0.93, lastBudgetEnactedAt: 4.5, lastCouncilBrakeAt: 4.5);
        $passed = new MacroStateDTO(eventType: ShockEvent::BUDGET_ENACTED, totalTime: 4.5, governingCoalition: $governing, corporateTaxPolicyShift: -0.026, lastBudgetEnactedAt: 4.5);

        for ($i = 0; $i < 30; ++$i) {
            $headline = $this->reporter(bufferedPrice: null)->report($held, $this->benchmarkAt('100'));
            $this->assertNotNull($headline);
            $this->assertStringContainsString('Vanguard-Exchange Party government', $headline['description']);
            $this->assertStringContainsString('93% of GDP', $headline['description']);
            $this->assertStringNotContainsString('{', $headline['description']);

            $headline = $this->reporter(bufferedPrice: null)->report($passed, $this->benchmarkAt('100'));
            $this->assertNotNull($headline);
            $this->assertStringContainsString('18.4%', $headline['description']);
            $this->assertStringNotContainsString('{', $headline['description']);
        }
    }

    public function testATickWithoutAnEventPublishesNothing(): void
    {
        $this->assertNull($this->reporter(bufferedPrice: 100.0)->report(new MacroStateDTO(), $this->benchmarkAt('100')));
        $this->assertSame([], $this->persisted);
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
