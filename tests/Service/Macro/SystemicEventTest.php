<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Covers the district-wide systemic event channel.
 *
 * MacroState::$eventType is consumed by MarketTickerCommand to publish an
 * index-level headline against the LBI ETF. These tests pin the two properties that make that channel
 * usable: the right regime produces the right event, and a crisis that persists for thousands of ticks
 * reports once rather than flooding the 50-item news feed.
 */
class SystemicEventTest extends TestCase
{
    private const TICKS_PER_YEAR = 3600;

    private MacroEngine $engine;
    private ReflectionMethod $evaluate;

    protected function setUp(): void
    {
        // The evaluator reads and writes MacroState only, so no collaborators need to be wired up.
        $this->engine = (new ReflectionClass(MacroEngine::class))->newInstanceWithoutConstructor();
        $this->evaluate = new ReflectionMethod(MacroEngine::class, 'evaluateSystemicEvent');
    }

    private function fire(MacroState $state): ?string
    {
        $this->evaluate->invokeArgs($this->engine, [$state, 1.0 / self::TICKS_PER_YEAR]);

        return $state->eventType;
    }

    public function testCalmBaselineProducesNoDistrictEvent(): void
    {
        $this->assertNull($this->fire(new MacroState()), 'A calm economy must not manufacture district-wide news.');
    }

    public function testInterbankFundingFreezeIsReported(): void
    {
        $state = new MacroState();
        $state->interbankLiquiditySpread = MacroEngine::SYSTEMIC_LIQUIDITY_FREEZE_SPREAD + 0.005;

        $this->assertSame(ShockEvent::SYSTEMIC_LIQUIDITY_FREEZE, $this->fire($state));
    }

    public function testHighYieldBlowOutIsReportedAsCreditSeizure(): void
    {
        $state = new MacroState();
        $state->highYieldCreditSpread = MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD + 0.02;

        $this->assertSame(ShockEvent::CREDIT_MARKET_SEIZURE, $this->fire($state));
    }

    /**
     * The yield-curve probit forecasts a recession a year out; the statistics office's call on two falling quarters is
     * what says one is under way, and what the Diet and the fund act on.
     */
    public function testRecessionIsDeclaredByTheStatisticsOfficeNotTheForecast(): void
    {
        $forecastOnly = new MacroState();
        $forecastOnly->recessionProbability = 0.9;
        $forecastOnly->outputGap = -0.02;

        $this->assertNull($this->fire($forecastOnly), 'A recession forecast alone must not declare a recession.');

        $underWay = new MacroState();
        $underWay->recessionProbability = 0.03;
        $underWay->outputGap = -0.03;
        $underWay->recessionDeclared = true;

        $this->assertSame(ShockEvent::RECESSION_DECLARED, $this->fire($underWay), 'The office\'s call is the headline, whatever the curve says.');
    }

    public function testSustainedCurveInversionTripsTheAlarm(): void
    {
        $state = new MacroState();
        $state->inversionDuration = MacroEngine::SYSTEMIC_INVERSION_ALARM_YEARS + 0.25;

        $this->assertSame(ShockEvent::YIELD_CURVE_INVERSION_ALARM, $this->fire($state));
    }

    /** A programme is news the day it launches; the months of purchases that follow are not a headline each. */
    public function testAnAssetPurchaseLaunchIsReportedOnceOnTheDay(): void
    {
        $state = new MacroState();
        $state->totalTime = 8.25;
        $state->lastQeLaunchAt = 8.25;
        $state->qeActive = true;
        $state->qeIntensity = 0.004;
        $state->outputGapEma = -0.03;

        $this->assertSame(ShockEvent::TITAN_INTERVENTION, $this->fire($state));

        // A year into the programme, the cooldown long spent and the stock still growing: no second launch headline.
        $state->totalTime += 1.0;
        $state->eventCooldownTimer = 0.0;
        $state->qeIntensity = 0.009;
        $this->assertNull($this->fire($state), 'A programme in progress is not a new intervention.');
    }

    public function testAnAssetPurchaseLaunchIsNotLostToAnActiveCooldown(): void
    {
        $state = new MacroState();
        $state->totalTime = 12.5;
        $state->highYieldCreditSpread = MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD + 0.02;
        $this->assertSame(ShockEvent::CREDIT_MARKET_SEIZURE, $this->fire($state));

        $state->totalTime += 1.0 / self::TICKS_PER_YEAR;
        $state->lastQeLaunchAt = $state->totalTime;
        $this->assertSame(ShockEvent::TITAN_INTERVENTION, $this->fire($state), 'The launch lands on one tick and must be reported through the cooldown.');
    }

    public function testTheReserveFundsBuyingProgrammeIsReportedOnTheTickItStarts(): void
    {
        $state = new MacroState();
        $state->totalTime = 7.25;
        $state->lastSovereignRebalanceAt = $state->totalTime;
        $state->sovereignFundRebalanceBacklog = 1.0e9;
        $this->assertSame(ShockEvent::SOVEREIGN_WEALTH_DEPLOYMENT, $this->fire($state));

        // The next tick of the same programme is trading, not news.
        $state->totalTime += 1.0 / self::TICKS_PER_YEAR;
        $state->eventCooldownTimer = 0.0;
        $this->assertNull($this->fire($state), 'A programme in progress is not a new headline.');
    }

    public function testTheReserveFundsSellingProgrammeIsReportedAsATrim(): void
    {
        $state = new MacroState();
        $state->totalTime = 3.5;
        $state->lastSovereignRebalanceAt = $state->totalTime;
        $state->sovereignFundRebalanceBacklog = -1.0e9;

        $this->assertSame(ShockEvent::SOVEREIGN_WEALTH_TRIM, $this->fire($state));
    }

    public function testAReserveFundProgrammeIsNotLostToAnActiveCooldown(): void
    {
        $state = new MacroState();
        $state->totalTime = 12.5;
        $state->highYieldCreditSpread = MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD + 0.02;
        $this->assertSame(ShockEvent::CREDIT_MARKET_SEIZURE, $this->fire($state));

        $state->totalTime += 1.0 / self::TICKS_PER_YEAR;
        $state->highYieldCreditSpread = 0.0;
        $state->lastSovereignRebalanceAt = $state->totalTime;
        $state->sovereignFundRebalanceBacklog = 5.0e8;
        $this->assertSame(
            ShockEvent::SOVEREIGN_WEALTH_DEPLOYMENT,
            $this->fire($state),
            'The programme starts on one tick and must be reported through the cooldown.'
        );
    }

    public function testDeepValuationsAloneNoLongerManufactureAFundHeadline(): void
    {
        $state = new MacroState();
        $state->equityRiskPremium = 0.09;
        $state->outputGap = -0.01;
        $state->outputGapEma = -0.03;

        $this->assertNull($this->fire($state), 'Only a real rebalance by the fund is reported as its buying.');
    }

    public function testMostSevereConditionWinsWhenSeveralTriggerTogether(): void
    {
        $state = new MacroState();
        $state->interbankLiquiditySpread = MacroEngine::SYSTEMIC_LIQUIDITY_FREEZE_SPREAD + 0.005;
        $state->highYieldCreditSpread = MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD + 0.02;
        $state->recessionDeclared = true;

        $this->assertSame(
            ShockEvent::SYSTEMIC_LIQUIDITY_FREEZE,
            $this->fire($state),
            'A funding freeze must outrank the recession it inevitably drags along with it.'
        );
    }

    public function testEventIsAPulseAndNotALatch(): void
    {
        $state = new MacroState();
        $state->highYieldCreditSpread = MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD + 0.02;

        $this->assertSame(ShockEvent::CREDIT_MARKET_SEIZURE, $this->fire($state));
        $this->assertNull($this->fire($state), 'The event must clear on the next tick rather than latching on.');
    }

    public function testSustainedCrisisReportsOncePerEpisode(): void
    {
        $state = new MacroState();
        $state->highYieldCreditSpread = MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD + 0.02;

        $fired = 0;
        for ($tick = 0; $tick < self::TICKS_PER_YEAR; $tick++) {
            if ($this->fire($state) !== null) {
                $fired++;
            }
        }

        $this->assertSame(1, $fired, 'A year-long crisis is one headline, not one per cooldown window.');

        $state->highYieldCreditSpread = MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD - 0.02;
        $this->assertNull($this->fire($state), 'The seizure eases.');

        $state->highYieldCreditSpread = MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD + 0.02;
        $this->assertSame(ShockEvent::CREDIT_MARKET_SEIZURE, $this->fire($state), 'A fresh seizure is fresh news.');
    }

    public function testAnAlarmHeldBackByTheCooldownReportsWhenItEnds(): void
    {
        $state = new MacroState();
        $state->interbankLiquiditySpread = MacroEngine::SYSTEMIC_LIQUIDITY_FREEZE_SPREAD + 0.005;
        $state->recessionDeclared = true;
        $first = $this->fire($state);
        $this->assertSame(ShockEvent::SYSTEMIC_LIQUIDITY_FREEZE, $first);

        $headlines = [];
        for ($tick = 0; $tick < self::TICKS_PER_YEAR; $tick++) {
            $event = $this->fire($state);
            if ($event !== null) {
                $headlines[] = $event;
            }
        }

        $this->assertSame([ShockEvent::RECESSION_DECLARED], $headlines, 'The recession the freeze outranked is reported once the cooldown lets it through, and only once.');
    }


    public function testTheAlarmLatchSurvivesSerializationRoundTrip(): void
    {
        $state = new MacroState();
        $state->recessionDeclared = true;
        $this->assertSame(ShockEvent::RECESSION_DECLARED, $this->fire($state));

        $restored = MacroState::fromArray(\App\DTO\MacroStateDTO::fromMacroState($state)->toArray());
        $restored->eventCooldownTimer = 0.0;

        $this->assertNull($this->fire($restored), 'A reload mid-recession must not declare it a second time.');
    }

    public function testCooldownSurvivesSerializationRoundTrip(): void
    {
        $state = new MacroState();
        $state->highYieldCreditSpread = MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD + 0.02;

        // Tick 1: event fires and sets cooldown
        $this->assertSame(ShockEvent::CREDIT_MARKET_SEIZURE, $this->fire($state));
        $this->assertGreaterThan(0.0, $state->eventCooldownTimer);

        // Simulate Redis / DTO round-trip across tick boundary
        $dto = \App\DTO\MacroStateDTO::fromMacroState($state);
        $restoredState = MacroState::fromArray($dto->toArray());

        $this->assertEqualsWithDelta(
            $state->eventCooldownTimer,
            $restoredState->eventCooldownTimer,
            1e-9,
            'eventCooldownTimer must survive MacroStateDTO serialization without being dropped.'
        );

        // Tick 2: must NOT fire because cooldown is active
        $this->assertNull(
            $this->fire($restoredState),
            'Restored state must maintain cooldown and not fire on consecutive ticks.'
        );
    }



    public function testASovereignDowngradeIsReportedBelowACreditSeizureAndAboveARecession(): void
    {
        $state = new MacroState();
        $state->sovereignRiskSpread = MacroEngine::SYSTEMIC_SOVEREIGN_STRESS_SPREAD + 0.005;
        $state->recessionDeclared = true;
        $this->assertSame(ShockEvent::SOVEREIGN_DOWNGRADE, $this->fire($state));

        $state->eventCooldownTimer = 0.0;
        $state->highYieldCreditSpread = MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD + 0.02;
        $this->assertSame(ShockEvent::CREDIT_MARKET_SEIZURE, $this->fire($state));
    }


    public function testAHeadlineStormIsReportedBelowARecessionAndOnlyOnTheDay(): void
    {
        $state = new MacroState();
        $state->totalTime = 3.0;
        $state->lastCatastropheAt = 3.0;
        $state->lastCatastropheSeverity = 2.0;
        $this->assertSame(ShockEvent::NATURAL_CATASTROPHE, $this->fire($state));

        $state->eventCooldownTimer = 0.0;
        $state->recessionDeclared = true;
        $this->assertSame(ShockEvent::RECESSION_DECLARED, $this->fire($state), 'A declared recession outranks a storm.');

        $state->eventCooldownTimer = 0.0;
        $state->recessionDeclared = false;
        $state->totalTime += 1.0 / self::TICKS_PER_YEAR;
        $this->assertNull($this->fire($state), 'Yesterday\'s storm is not today\'s news.');
    }


    public function testHouseholdDeleveragingIsReportedBelowAStormAndOnlyWhileCreditContracts(): void
    {
        $state = new MacroState();
        $state->householdDebtServiceGap = MacroEngine::HOUSEHOLD_DSR_STRESS_MARGIN + 0.01;
        $state->householdDebtToIncome = 1.20;
        $state->householdDebtToIncomeEma = 1.25;
        $this->assertSame(ShockEvent::HOUSEHOLD_DELEVERAGING, $this->fire($state));

        $state->eventCooldownTimer = 0.0;
        $state->totalTime = 3.0;
        $state->lastCatastropheAt = 3.0;
        $state->lastCatastropheSeverity = 2.0;
        $this->assertSame(ShockEvent::NATURAL_CATASTROPHE, $this->fire($state), 'A headline storm outranks the credit cycle turning.');

        $state->eventCooldownTimer = 0.0;
        $state->lastCatastropheAt = -1.0;
        $state->householdDebtToIncome = 1.30;
        $this->assertNull($this->fire($state), 'A burden households are still borrowing into is not yet a deleveraging.');
    }

    public function testCatastropheFiresDuringActiveDistrictCooldown(): void
    {
        $state = new MacroState();
        $state->totalTime = 3.0;
        $state->eventCooldownTimer = 0.15; // Active cooldown from an earlier event
        $state->lastCatastropheAt = 3.0;
        $state->lastCatastropheSeverity = 2.0;

        $this->assertSame(
            ShockEvent::NATURAL_CATASTROPHE,
            $this->fire($state),
            'Edge-triggered catastrophe headlines must not be dropped by an active district cooldown.'
        );
    }

    /**
     * The cooldown lets a catastrophe through, but the level conditions it is suppressing must not then outrank it: a
     * storm in the middle of a recession and a funding freeze is still the storm's headline, not a repeat of theirs.
     */
    public function testACatastropheInsideTheCooldownIsNotOutrankedByTheLevelEventsItSuppresses(): void
    {
        $state = new MacroState();
        $state->totalTime = 3.0;
        $state->eventCooldownTimer = 0.15;
        $state->interbankLiquiditySpread = MacroEngine::SYSTEMIC_LIQUIDITY_FREEZE_SPREAD + 0.005;
        $state->highYieldCreditSpread = MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD + 0.02;
        $state->recessionDeclared = true;
        $state->lastCatastropheAt = 3.0;
        $state->lastCatastropheSeverity = 2.0;

        $this->assertSame(ShockEvent::NATURAL_CATASTROPHE, $this->fire($state));

        $state->totalTime += 1.0 / self::TICKS_PER_YEAR;
        $this->assertNull($this->fire($state), 'and the level events stay suppressed for the rest of the cooldown.');
    }

    public function testABankingCrisisIsReportedOnTheDayAndOutranksTheFreezeItCauses(): void
    {
        $state = new MacroState();
        $state->totalTime = 12.5;
        $state->lastCreditCrisisAt = 12.5;
        $state->interbankLiquiditySpread = MacroEngine::SYSTEMIC_LIQUIDITY_FREEZE_SPREAD + 0.005;

        $this->assertSame(ShockEvent::BANKING_CRISIS, $this->fire($state), 'The crisis is the cause; the funding freeze it forces is the symptom.');

        $state->totalTime += 1.0 / self::TICKS_PER_YEAR;
        $this->assertNotSame(ShockEvent::BANKING_CRISIS, $this->fire($state), 'The crisis headline is a single-tick pulse.');
    }

    public function testABankingCrisisIsNotLostToAnActiveCooldown(): void
    {
        $state = new MacroState();
        $state->totalTime = 12.5;
        $state->highYieldCreditSpread = MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD + 0.02;
        $this->assertSame(ShockEvent::CREDIT_MARKET_SEIZURE, $this->fire($state));
        $this->assertGreaterThan(0.0, $state->eventCooldownTimer);

        $state->totalTime += 1.0 / self::TICKS_PER_YEAR;
        $state->lastCreditCrisisAt = $state->totalTime;
        $this->assertSame(ShockEvent::BANKING_CRISIS, $this->fire($state), 'An edge-triggered crisis lands on one tick and must be reported through the cooldown.');
    }
}
