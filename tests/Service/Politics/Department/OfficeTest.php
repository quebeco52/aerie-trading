<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics\Department;

use App\Data\Politics\AerieCouncil;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Politics\Council\CouncilAppointments as Appointments;
use App\Service\Politics\Department\FairTradingBoard;
use App\Service\Politics\Department\FinancialRegulator;
use App\Service\Politics\Department\MonetaryAuthority;
use App\Service\Politics\Department\Office;
use App\Service\Politics\Department\SecuritiesCommission;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OfficeTest extends TestCase
{
    private const DT = 1.0 / 52.0;

    /** One tick as the politics engine runs it: the Council, then each department in the Office order. */
    private static function tick(PoliticsState $state, MathUtility $math): void
    {
        Appointments::advance($state, $math);
        MonetaryAuthority::advance($state, new MacroStateDTO(totalTime: $state->totalTime, policyRate: 0.03), self::DT);
        FinancialRegulator::advance($state);
        Office::FundHead->fill($state);
        Office::ExchequerHead->fill($state);
        FairTradingBoard::advance($state);
        SecuritiesCommission::advance($state);
        Office::SecurityDirector->fill($state);
        Office::CoastGuardCommandant->fill($state);
        Office::StateGuardCommander->fill($state);
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

    /** @return array<string, array{Office}> */
    public static function postsWithoutRules(): array
    {
        return [
            'Security Bureau' => [Office::SecurityDirector],
            'Coast Guard' => [Office::CoastGuardCommandant],
            'State Guard' => [Office::StateGuardCommander],
        ];
    }

    /** The appointment draws are hashed from each post's seat, so the seats never change. */
    public function testEachPostKeepsTheSeatItsDrawsAreHashedFrom(): void
    {
        $this->assertSame(
            ['governor', 'regulator', 'fund', 'exchequer', 'fair-trading', 'securities', 'security', 'coast-guard', 'state-guard'],
            array_map(static fn(Office $office): string => $office->value, Office::cases()),
        );
        foreach (Office::cases() as $office) {
            $this->assertLessThan($office->termYears(), $office->openingTermEnd(), "{$office->value}: the Year 1 holder's term is already running.");
            $this->assertEqualsWithDelta($office->openingTermEnd() + $office->termYears(), $office->termEnd($office->openingTermEnd() + 1e-6), 1e-9);
            $this->assertEqualsWithDelta($office->openingTermEnd(), $office->termEnd($office->openingTermEnd() - 1e-6), 1e-9);
        }
    }

    /** At Year 1 every post is held by the person seated before it, dated to their term's start and never news. */
    public function testEveryPostOpensWithItsYearOneHolder(): void
    {
        $state = self::opened();

        foreach (Office::cases() as $office) {
            $head = $state->head($office);
            $this->assertSame($office->openingHolder(), $head['name']);
            $this->assertEqualsWithDelta($office->openingTermEnd() - $office->termYears(), $head['termStart'], 1e-9);
            $this->assertSame(-1.0, $head['appointedAt'], 'Seating the opening holders is not news.');
            $this->assertNull($office->fill($state), 'A post is filled once a term.');
        }
        $this->assertNull(PoliticsEngine::headline($state));
    }

    /** A post's next holder is dated and makes the post's headline; the outgoing one's name is never drawn again. */
    public function testARefilledPostIsNewsAndRetiresItsHolder(): void
    {
        $state = self::runTo(self::opened(3), Office::FairTradingHead->openingTermEnd() - self::DT);
        $state->totalTime = Office::FairTradingHead->openingTermEnd();

        [$seated, $outgoing] = Office::FairTradingHead->fill($state) ?? $this->fail('The term has ended.');

        $this->assertSame(AerieCouncil::OPENING_FAIR_TRADING_HEAD, $outgoing['name']);
        $this->assertSame($seated['name'], $state->head(Office::FairTradingHead)['name']);
        $this->assertSame($seated['competition'], $state->head(Office::FairTradingHead)['stance']);
        $this->assertSame($state->totalTime, $state->head(Office::FairTradingHead)['appointedAt']);
        $this->assertContains(AerieCouncil::OPENING_FAIR_TRADING_HEAD, $state->formerNames);
        $this->assertSame(ShockEvent::FAIR_TRADING_HEAD_APPOINTED, PoliticsEngine::headline($state));
    }

    /** At Year 1 the holder seated before it holds the post, and the department hands the economy nothing. */
    #[DataProvider('postsWithoutRules')]
    public function testThePostAtYearOneIsSeatedAndSetsNoRule(Office $office): void
    {
        $state = self::opened();

        $this->assertSame(0.0, $state->head($office)['stance'], 'A post that sets no rule holds no stance.');
        $this->assertSame([], $state->head($office)['passedOver']);
        $names = Appointments::sittingNames($state);
        $this->assertContains($office->openingHolder(), $names);
        $this->assertSame($names, array_values(array_unique($names)), 'No two people sitting share a name.');

        $without = PoliticsStateDTO::fromState($state);
        $state->heads[$office->value]['name'] = 'Someone Else';
        $this->assertEquals($without->policy(), PoliticsStateDTO::fromState($state)->policy(), 'Who holds the post moves nothing in the economy.');
    }

    /** At the opening holder's term end the Council names the candidate nearest its median on its own questions. */
    #[DataProvider('postsWithoutRules')]
    public function testAtTermEndTheCouncilNamesTheCandidateNearestItsMedians(Office $office): void
    {
        foreach ([3, 11] as $seed) {
            $state = self::runTo(self::opened($seed), $office->openingTermEnd() - self::DT);
            $medians = Appointments::councilMedians($state);
            $sitting = Appointments::sittingNames($state);
            $this->assertSame($office->openingHolder(), $state->head($office)['name']);

            self::runTo($state, $office->openingTermEnd() + self::DT);
            [$expected, $passedOver] = Appointments::appoint((int) $state->authoritySalt, $office->value, $office->openingTermEnd(), $medians, $sitting);
            $this->assertSame($expected['name'], $state->head($office)['name'], "seed {$seed}");
            $this->assertSame($passedOver, $state->head($office)['passedOver']);
            $this->assertEqualsWithDelta($office->openingTermEnd() + $office->termYears(), $office->termEnd($state->totalTime), 1e-9);
        }

        $headline = new PoliticsState();
        $headline->totalTime = 3.0;
        $headline->heads[$office->value] = ['appointedAt' => 3.0] + Office::VACANT;
        $this->assertSame($office->appointmentEvent(), PoliticsEngine::headline($headline));
    }
}
