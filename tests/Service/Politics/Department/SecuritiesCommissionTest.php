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
use App\Service\Politics\Department\FairTradingBoard;
use App\Service\Politics\Department\FinancialRegulator;
use App\Service\Politics\Department\MonetaryAuthority;
use App\Service\Politics\Department\Office;
use App\Service\Politics\Department\SecuritiesCommission as Commission;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

class SecuritiesCommissionTest extends TestCase
{
    private const DT = 1.0 / 52.0;

    /** One tick as the politics engine runs it: the Council, then each department. */
    private static function tick(PoliticsState $state, MathUtility $math): void
    {
        Appointments::advance($state, $math);
        MonetaryAuthority::advance($state, new MacroStateDTO(totalTime: $state->totalTime, policyRate: 0.03), self::DT);
        FinancialRegulator::advance($state);
        Office::FundHead->fill($state);
        Office::ExchequerHead->fill($state);
        FairTradingBoard::advance($state);
        Commission::advance($state);
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
     * Each rule stands at its founding value at the middle stance and runs out to its record's ends; the light margin
     * still leaves a fresh short clear of its maintenance.
     */
    public function testEachRuleRunsThroughItsFoundingValueToTheRecordsEnds(): void
    {
        $this->assertSame(FinancialConstants::INITIAL_MARGIN_REQUIREMENT, Commission::initialMargin(0.0));
        $this->assertSame(FinancialConstants::INITIAL_MARGIN_REQUIREMENT, Commission::initialMargin(null));
        $this->assertEqualsWithDelta(0.40, Commission::initialMargin(-1.0), 1e-12);
        $this->assertEqualsWithDelta(0.80, Commission::initialMargin(1.0), 1e-12);
        $this->assertEqualsWithDelta(0.45, Commission::initialMargin(-0.5), 1e-12);
        $this->assertEqualsWithDelta(0.65, Commission::initialMargin(0.5), 1e-12);

        $this->assertSame(1.0, Commission::earningsManagementScale(0.0));
        $this->assertEqualsWithDelta(1.5, Commission::earningsManagementScale(-1.0), 1e-12);
        $this->assertEqualsWithDelta(0.5, Commission::earningsManagementScale(1.0), 1e-12);

        foreach ([-1.0, 0.0, 1.0] as $stance) {
            $margins = Commission::margins(Commission::initialMargin($stance));
            $this->assertSame(FinancialConstants::MAINTENANCE_MARGIN_LONG, $margins['long'], 'Maintenance does not move with the head.');
            $this->assertSame(FinancialConstants::MAINTENANCE_MARGIN_SHORT, $margins['short']);
            $this->assertGreaterThanOrEqual($margins['short'] + 0.10 - 1e-12, $margins['initial'], 'A fresh short opens well clear of a call.');
        }
        $this->assertSame('light', Commission::stanceName(-0.9));
        $this->assertSame('strict', Commission::stanceName(0.9));
    }

    /**
     * At Year 1 the head seated before it keeps the founding rules, so the economy is handed none; every councillor
     * holds a stance on the markets.
     */
    public function testTheHeadAtYearOneKeepsTheFoundingRules(): void
    {
        $state = self::opened();

        $this->assertSame(AerieCouncil::OPENING_SECURITIES_HEAD, $state->head(Office::SecuritiesHead)['name']);
        $this->assertEqualsWithDelta(Office::SecuritiesHead->openingTermEnd() - Office::SecuritiesHead->termYears(), $state->head(Office::SecuritiesHead)['termStart'], 1e-9);
        $this->assertCount(AerieCouncil::SEATS, $state->councilMarketsStances);
        $names = Appointments::sittingNames($state);
        $this->assertContains(AerieCouncil::OPENING_SECURITIES_HEAD, $names);
        $this->assertSame($names, array_values(array_unique($names)), 'No two people sitting share a name.');

        foreach ([PoliticsStateDTO::fromState($state)->policy(), (new PoliticsStateDTO())->policy()] as $policy) {
            $this->assertNull($policy->initialMarginRequirement);
            $this->assertNull($policy->earningsManagementScale);
        }
    }

    /**
     * At the opening head's term end the Council names the candidate nearest its median on the markets; the founding
     * rules hold through their notice, theirs after it, and nothing changes until the next term ends.
     */
    public function testAtTermEndTheCouncilNamesTheCandidateNearestItsMedianWhoseRulesFollowTheirNotice(): void
    {
        foreach ([3, 7, 11, 19] as $seed) {
            $state = self::runTo(self::opened($seed), Office::SecuritiesHead->openingTermEnd() - self::DT);
            $median = Appointments::median($state->councilMarketsStances);
            $sitting = Appointments::sittingNames($state);

            self::runTo($state, Office::SecuritiesHead->openingTermEnd() + self::DT);
            [$expected, $passedOver] = Appointments::appoint((int) $state->authoritySalt, 'securities', Office::SecuritiesHead->openingTermEnd(), [Appointments::AXIS_MARKETS => $median], $sitting);
            $this->assertSame($expected['name'], $state->head(Office::SecuritiesHead)['name'], "seed {$seed}");
            $this->assertSame($expected['markets'], $state->head(Office::SecuritiesHead)['stance']);
            $this->assertSame($passedOver, $state->head(Office::SecuritiesHead)['passedOver']);
            $this->assertNull($state->securitiesPriorStance, 'The head before ran the founding rules.');
            $this->assertNull(PoliticsStateDTO::fromState($state)->policy()->initialMarginRequirement, 'Inside the notice the founding rules hold.');

            self::runTo($state, Office::SecuritiesHead->openingTermEnd() + Commission::RULE_NOTICE_YEARS + self::DT);
            $policy = PoliticsStateDTO::fromState($state)->policy();
            $this->assertEqualsWithDelta(Commission::initialMargin($state->head(Office::SecuritiesHead)['stance']), $policy->initialMarginRequirement, 1e-12);
            $this->assertEqualsWithDelta(Commission::earningsManagementScale($state->head(Office::SecuritiesHead)['stance']), $policy->earningsManagementScale, 1e-12);
            $appointedAt = $state->head(Office::SecuritiesHead)['appointedAt'];
            $first = $state->head(Office::SecuritiesHead)['stance'];

            self::runTo($state, Office::SecuritiesHead->openingTermEnd() + Office::SecuritiesHead->termYears() - self::DT);
            $this->assertSame($expected['name'], $state->head(Office::SecuritiesHead)['name'], 'One term, unchanged until it ends.');
            $this->assertSame($appointedAt, $state->head(Office::SecuritiesHead)['appointedAt']);

            self::runTo($state, Office::SecuritiesHead->openingTermEnd() + Office::SecuritiesHead->termYears() + self::DT);
            $this->assertSame($first, $state->securitiesPriorStance, 'The next head\'s notice runs on their predecessor\'s rules.');
            $this->assertEqualsWithDelta(Commission::initialMargin($first), PoliticsStateDTO::fromState($state)->policy()->initialMarginRequirement, 1e-12);
        }

        $headline = new PoliticsState();
        $headline->totalTime = 3.0;
        $headline->heads[Office::SecuritiesHead->value] = ['appointedAt' => 3.0] + Office::VACANT;
        $this->assertSame(ShockEvent::SECURITIES_HEAD_APPOINTED, PoliticsEngine::headline($headline));
    }

    /**
     * The markets weigh only in naming the Commission's head: the Council fills its own seats on the other questions.
     */
    public function testCouncilSeatsAreNotChosenOnTheMarkets(): void
    {
        $state = self::opened();
        $this->assertArrayNotHasKey(Appointments::AXIS_MARKETS, Appointments::councilMedians($state));
        $this->assertArrayNotHasKey(Appointments::AXIS_MARKETS, Appointments::boardMedians($state));
    }
}
