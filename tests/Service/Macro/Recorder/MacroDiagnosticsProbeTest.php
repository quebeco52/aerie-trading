<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro\Recorder;

use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
use App\Service\Macro\Recorder\OutputGapProbe;
use PHPUnit\Framework\TestCase;

class MacroDiagnosticsProbeTest extends TestCase
{
    public function testADisabledProbeRecordsNothing(): void
    {
        $probe = new MacroDiagnosticsProbe();
        $probe->recordInflation(['target' => 0.02], 0.02, 0.25);
        $probe->recordEvent('energy', 0.1);
        $probe->rollWindow();

        $this->assertSame(['enabled' => false, 'current' => null, 'previous' => null], $probe->snapshot());
    }

    /** Terms are time averages: a term held for three quarters of the window weighs three times the rest. */
    public function testTermsAreTimeWeightedAndSumToTheAverageTheyBuild(): void
    {
        $probe = new MacroDiagnosticsProbe();
        $probe->enable();
        $probe->recordInflation(['target' => 0.02, 'demandPull' => 0.004], 0.024, 0.75);
        $probe->recordInflation(['target' => 0.02, 'demandPull' => -0.008], 0.012, 0.25);

        $inflation = $probe->snapshot()['current']['inflation'];

        $this->assertEqualsWithDelta(0.021, $inflation['average'], 1e-15);
        $this->assertEqualsWithDelta(0.001, $inflation['terms']['demandPull'], 1e-15);
        $this->assertEqualsWithDelta($inflation['average'], array_sum($inflation['terms']), 1e-15);
    }

    public function testConstraintsAreSharesOfTheWindow(): void
    {
        $probe = new MacroDiagnosticsProbe();
        $probe->enable();
        $probe->recordPolicyTarget(['naturalRate' => 0.015, 'inflationTarget' => 0.02], 0.035, 0.1);
        $probe->recordPolicyRate(0.01, ['lowerBound' => true, 'evansHold' => false], 0.1);
        $probe->recordPolicyRate(0.02, ['lowerBound' => false, 'evansHold' => false], 0.3);

        $policy = $probe->snapshot()['current']['policy'];

        $this->assertEqualsWithDelta(0.25, $policy['constraints']['lowerBound'], 1e-15);
        $this->assertSame(0.0, $policy['constraints']['evansHold'], 'A constraint that never bound is reported, at zero.');
        $this->assertEqualsWithDelta(0.0175, $policy['averageRate'], 1e-15);
        $this->assertEqualsWithDelta(0.035, $policy['averageTarget'], 1e-15);
    }

    public function testAveragesReadEveryDeclaredFieldAndCountTicks(): void
    {
        $probe = new MacroDiagnosticsProbe();
        $probe->enable();
        $state = new MacroState();
        $state->outputGap = -0.01;
        $probe->recordAverages($state, 0.5);
        $state->outputGap = 0.03;
        $probe->recordAverages($state, 0.5);

        $window = $probe->snapshot()['current'];

        $this->assertSame(2, $window['ticks']);
        $this->assertEqualsWithDelta(1.0, $window['years'], 1e-15);
        $this->assertEqualsWithDelta(0.01, $window['averages']['outputGap'], 1e-15);
        $this->assertSame(MacroDiagnosticsProbe::AVERAGED_FIELDS, array_keys($window['averages']));
    }

    public function testEventsCountSumAndKeepTheLargestAndShocksSum(): void
    {
        $probe = new MacroDiagnosticsProbe();
        $probe->enable();
        $probe->recordEvent('energy', 0.2);
        $probe->recordEvent('energy', -0.5);
        $probe->recordShock('tfpInnovation', 0.001);
        $probe->recordShock('tfpInnovation', -0.003);

        $window = $probe->snapshot()['current'];

        $this->assertSame(['count' => 2, 'sum' => -0.3, 'maxAbs' => 0.5], $window['events']['energy']);
        $this->assertEqualsWithDelta(-0.002, $window['shocks']['tfpInnovation'], 1e-15);
    }

    public function testRollingKeepsTheClosedWindowAndStartsClean(): void
    {
        $probe = new MacroDiagnosticsProbe();
        $probe->enable();
        $probe->recordEvent('catastrophe', 1.5);
        $probe->rollWindow();

        $snapshot = $probe->snapshot();

        $this->assertNull($snapshot['current'], 'Nothing recorded since the roll, so no window is open.');
        $this->assertSame(1, $snapshot['previous']['events']['catastrophe']['count']);

        $probe->recordEvent('energy', 0.1);
        $this->assertArrayNotHasKey('catastrophe', $probe->snapshot()['current']['events']);
    }

    // --- The gap probe's external bucket ---

    /** A write to the gap between ticks is the one leak no per-tick check can see, so it is booked on its own. */
    public function testAGapMoveBetweenTicksIsBookedAsExternal(): void
    {
        $probe = new OutputGapProbe();
        $probe->enable();
        $probe->record(['momentum' => 0.04], 0.0, 0.010, 0.011, 0.011, 0.025);
        // The next tick opens 0.5pp above where the last closed: something moved the gap in between.
        $probe->record(['momentum' => 0.04], 0.0, 0.016, 0.017, 0.017, 0.025);

        $window = $probe->snapshot()['current'];

        $this->assertEqualsWithDelta(0.005, $window['external'], 1e-15);
        $this->assertEqualsWithDelta(
            $window['change'],
            $window['drift'] + $window['diffusion'] + $window['clamp'] + $window['external'] + $window['unexplained'],
            1e-15
        );
    }

    /** Consecutive windows chain: the next one opens where the last closed, so a move between them is booked. */
    public function testWindowsChainAcrossTheRoll(): void
    {
        $probe = new OutputGapProbe();
        $probe->enable();
        $probe->record([], 0.0, 0.010, 0.010, 0.010, 0.25);
        $probe->rollWindow();
        $probe->record([], 0.0, 0.012, 0.012, 0.012, 0.25);

        $window = $probe->snapshot()['current'];

        $this->assertSame(0.010, $window['opening_gap']);
        $this->assertEqualsWithDelta(0.002, $window['external'], 1e-15);
        $this->assertEqualsWithDelta($window['change'], $window['external'], 1e-15);
    }
}
