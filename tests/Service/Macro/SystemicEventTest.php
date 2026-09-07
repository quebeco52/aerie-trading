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
 * MacroState::$eventType is consumed by MarketTickerCommand and MarketSimulateCommand to publish an
 * index-level headline against the LBI ETF. These tests pin the two properties that make that channel
 * usable: the right regime produces the right event, and a crisis that persists for thousands of ticks
 * reports a handful of times rather than flooding the 50-item news feed.
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

    public function testRecessionIsDeclaredOnlyWhenOutputIsAlsoContracting(): void
    {
        $probabilityOnly = new MacroState();
        $probabilityOnly->recessionProbability = MacroEngine::SYSTEMIC_RECESSION_DECLARE_PROBABILITY + 0.2;
        $probabilityOnly->outputGap = 0.01; // Elevated probability, but output is still expanding.

        $this->assertNull($this->fire($probabilityOnly), 'A recession forecast alone must not declare a recession.');

        $confirmed = new MacroState();
        $confirmed->recessionProbability = MacroEngine::SYSTEMIC_RECESSION_DECLARE_PROBABILITY + 0.2;
        $confirmed->outputGap = MacroEngine::SYSTEMIC_RECESSION_DECLARE_GAP - 0.01;

        $this->assertSame(ShockEvent::RECESSION_DECLARED, $this->fire($confirmed));
    }

    public function testSustainedCurveInversionTripsTheAlarm(): void
    {
        $state = new MacroState();
        $state->inversionDuration = MacroEngine::SYSTEMIC_INVERSION_ALARM_YEARS + 0.25;

        $this->assertSame(ShockEvent::YIELD_CURVE_INVERSION_ALARM, $this->fire($state));
    }

    public function testPolicyBackstopOnlyReadsAsInterventionDuringStress(): void
    {
        $expansionaryQe = new MacroState();
        $expansionaryQe->qeIntensity = MacroEngine::SYSTEMIC_INTERVENTION_QE_INTENSITY + 0.01;
        $expansionaryQe->outputGapEma = 0.02; // Balance sheet growth during a boom is not a rescue.

        $this->assertNull($this->fire($expansionaryQe), 'QE in an expansion must not be reported as a bailout.');

        $rescue = new MacroState();
        $rescue->qeIntensity = MacroEngine::SYSTEMIC_INTERVENTION_QE_INTENSITY + 0.01;
        $rescue->outputGapEma = -0.02;

        $this->assertSame(ShockEvent::TITAN_INTERVENTION, $this->fire($rescue));
    }

    public function testDeepValueDeploymentRequiresTheCycleToBeTurningUp(): void
    {
        $stillFalling = new MacroState();
        $stillFalling->equityRiskPremium = MacroEngine::SYSTEMIC_DEPLOYMENT_ERP_THRESHOLD + 0.01;
        $stillFalling->outputGap = -0.04;
        $stillFalling->outputGapEma = -0.02; // Gap still deteriorating below its own average.

        $this->assertNull($this->fire($stillFalling), 'Capital must not deploy while the cycle is still falling.');

        $turning = new MacroState();
        $turning->equityRiskPremium = MacroEngine::SYSTEMIC_DEPLOYMENT_ERP_THRESHOLD + 0.01;
        $turning->outputGap = -0.01;
        $turning->outputGapEma = -0.03; // Gap recovering from the trough.

        $this->assertSame(ShockEvent::SOVEREIGN_WEALTH_DEPLOYMENT, $this->fire($turning));
    }

    public function testMostSevereConditionWinsWhenSeveralTriggerTogether(): void
    {
        $state = new MacroState();
        $state->interbankLiquiditySpread = MacroEngine::SYSTEMIC_LIQUIDITY_FREEZE_SPREAD + 0.005;
        $state->highYieldCreditSpread = MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD + 0.02;
        $state->recessionProbability = 0.9;
        $state->outputGap = -0.05;

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

    public function testSustainedCrisisDoesNotFloodTheNewsFeed(): void
    {
        $state = new MacroState();
        $state->highYieldCreditSpread = MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD + 0.02;

        $fired = 0;
        for ($tick = 0; $tick < self::TICKS_PER_YEAR; $tick++) {
            if ($this->fire($state) !== null) {
                $fired++;
            }
        }

        $expected = (int) round(1.0 / MacroEngine::SYSTEMIC_EVENT_COOLDOWN_YEARS);

        $this->assertSame(
            $expected,
            $fired,
            sprintf(
                'A year-long crisis must report once per cooldown window (%d), not once per tick (%d).',
                $expected,
                self::TICKS_PER_YEAR
            )
        );
    }
}
