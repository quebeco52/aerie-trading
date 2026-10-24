<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics\Department;

use App\Data\Politics\AerieCouncil;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Corporate\Deal\MergerReview;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Politics\Council\CouncilAppointments as Appointments;
use App\Service\Politics\Department\FairTradingBoard;
use App\Service\Politics\Department\FinancialRegulator;
use App\Service\Politics\Department\MonetaryAuthority;
use App\Service\Politics\Department\Office;
use App\Service\Politics\Policy\Lever;
use App\Service\Politics\Policy\Platforms;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

class FairTradingBoardTest extends TestCase
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
     * A stance is a set of screens on the agencies' record: -1 the 2010 guidelines, +1 the 2023 ones, linear between.
     */
    public function testAStanceRunsFromTheLightestGuidelinesToTheStrictest(): void
    {
        $lightest = MergerReview::reviewScreens(FairTradingBoard::leniency(-1.0));
        $strictest = MergerReview::reviewScreens(FairTradingBoard::leniency(1.0));
        $this->assertEqualsWithDelta(0.25, $lightest['concentrated'], 1e-12);
        $this->assertEqualsWithDelta(0.02, $lightest['delta'], 1e-12);
        $this->assertSame(1.0, $lightest['shareCeiling']);
        $this->assertEqualsWithDelta(0.18, $strictest['concentrated'], 1e-12);
        $this->assertEqualsWithDelta(0.01, $strictest['delta'], 1e-12);
        $this->assertEqualsWithDelta(0.30, $strictest['shareCeiling'], 1e-12);

        $this->assertEqualsWithDelta(0.4, FairTradingBoard::leniency(FairTradingBoard::stance(0.4)), 1e-12);
        $this->assertSame(-1.0, FairTradingBoard::drawStance(0.0));
        $this->assertSame('light', FairTradingBoard::stanceName(-0.9));
        $this->assertSame('balanced', FairTradingBoard::stanceName(0.0));
        $this->assertSame('strict', FairTradingBoard::stanceName(0.9));
    }

    /**
     * At Year 1 the head seated before it holds the founding screens, which the economy is handed; every councillor
     * holds a stance on competition.
     */
    public function testTheHeadAtYearOneHoldsTheFoundingScreens(): void
    {
        $state = self::opened();

        $this->assertSame(AerieCouncil::OPENING_FAIR_TRADING_HEAD, $state->head(Office::FairTradingHead)['name']);
        $this->assertEqualsWithDelta(Office::FairTradingHead->openingTermEnd() - Office::FairTradingHead->termYears(), $state->head(Office::FairTradingHead)['termStart'], 1e-9);
        $this->assertCount(AerieCouncil::SEATS, $state->councilCompetitionStances);
        $names = Appointments::sittingNames($state);
        $this->assertContains(AerieCouncil::OPENING_FAIR_TRADING_HEAD, $names);
        $this->assertSame($names, array_values(array_unique($names)), 'No two people sitting share a name.');

        $this->assertSame(FairTradingBoard::FOUNDING_LENIENCY, PoliticsStateDTO::fromState($state)->policy()->mergerReviewLeniency);
        $this->assertSame(FairTradingBoard::FOUNDING_LENIENCY, (new PoliticsStateDTO())->policy()->mergerReviewLeniency, 'Before the Board has a head.');
    }

    /**
     * At the opening head's term end the Council names the candidate nearest its median on competition, whose screens
     * the economy reads; nothing changes until the next term ends.
     */
    public function testAtTermEndTheCouncilNamesTheCandidateNearestItsMedian(): void
    {
        foreach ([3, 7, 11, 19] as $seed) {
            $state = self::runTo(self::opened($seed), Office::FairTradingHead->openingTermEnd() - self::DT);
            $median = Appointments::median($state->councilCompetitionStances);
            $sitting = Appointments::sittingNames($state);
            $opening = $state->head(Office::FairTradingHead)['stance'];
            $this->assertSame(AerieCouncil::OPENING_FAIR_TRADING_HEAD, $state->head(Office::FairTradingHead)['name']);

            self::runTo($state, Office::FairTradingHead->openingTermEnd() + self::DT);
            [$expected, $passedOver] = Appointments::appoint((int) $state->authoritySalt, 'fair-trading', Office::FairTradingHead->openingTermEnd(), [Appointments::AXIS_COMPETITION => $median], $sitting);
            $this->assertSame($expected['name'], $state->head(Office::FairTradingHead)['name'], "seed {$seed}");
            $this->assertSame($expected['competition'], $state->head(Office::FairTradingHead)['stance']);
            $this->assertSame($passedOver, $state->head(Office::FairTradingHead)['passedOver']);
            $this->assertSame($opening, $state->fairTradingPriorStance);
            $appointedAt = $state->head(Office::FairTradingHead)['appointedAt'];
            $this->assertEqualsWithDelta(FairTradingBoard::leniency($state->head(Office::FairTradingHead)['stance']), PoliticsStateDTO::fromState($state)->policy()->mergerReviewLeniency, 1e-12);

            self::runTo($state, Office::FairTradingHead->openingTermEnd() + Office::FairTradingHead->termYears() - self::DT);
            $this->assertSame($expected['name'], $state->head(Office::FairTradingHead)['name'], 'One term, unchanged until it ends.');
            $this->assertSame($appointedAt, $state->head(Office::FairTradingHead)['appointedAt']);
        }

        $headline = new PoliticsState();
        $headline->totalTime = 3.0;
        $headline->heads[Office::FairTradingHead->value] = ['appointedAt' => 3.0] + Office::VACANT;
        $this->assertSame(ShockEvent::FAIR_TRADING_HEAD_APPOINTED, PoliticsEngine::headline($headline));
    }

    /** Competition weighs only in naming the Board's head, and merger review is no longer the Diet's to legislate. */
    public function testMergerReviewIsTheBoardsAlone(): void
    {
        $state = self::opened();
        $this->assertArrayNotHasKey(Appointments::AXIS_COMPETITION, Appointments::councilMedians($state));
        $this->assertArrayNotHasKey(Appointments::AXIS_COMPETITION, Appointments::boardMedians($state));
        $this->assertNull(Lever::tryFrom('mergerReviewLeniency'));
        $this->assertArrayNotHasKey('mergerReviewLeniency', Platforms::platform([]));
    }
}
