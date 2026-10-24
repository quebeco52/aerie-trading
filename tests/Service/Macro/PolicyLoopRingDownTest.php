<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\TestCase;

/**
 * The policy loop's free response: every draw zero and no jump, two runs settle, one gets a +1.6pp opening gap, and
 * the difference is the loop ringing down on its own. Deterministic, so unlike an emergent statistic it has power.
 *
 * A stabilising loop damps a shock rather than ringing it into the next cycle: output gaps do not swing through zero
 * on their own. An engine whose gap has no pull of its own, and whose policy acts late, rings instead, a kick coming
 * back at most of its size the other way.
 */
class PolicyLoopRingDownTest extends TestCase
{
    private const TICKS_PER_YEAR = 72;
    private const BURN_IN_YEARS = 15;
    private const OBSERVE_YEARS = 8;
    private const KICK = 0.016;
    /** A slump's overshoot must be smaller than a boom's: easing pushes on a string. */
    private const SLUMP_OVERSHOOT_RATIO = 1.0;
    /**
     * How far from settled the tail may sit, per unit of kick: decisions move in 25bp steps, so kicked and control runs
     * can sit a step apart for a few meetings (0.1pp of gap).
     */
    private const SETTLED_TOLERANCE = 0.001 / self::KICK;
    /** The quarter the tail is settled from: year five. */
    private const SETTLED_FROM_QUARTER = 20;

    public function testAnOpeningGapFadesWithoutAPendulum(): void
    {
        $response = $this->ringDown(self::KICK);
        $trough = min($response);
        $troughAt = array_search($trough, $response, true);
        $this->assertIsInt($troughAt);
        // The swing back is read up to year five; past it the kicked and control runs can sit a decision apart.
        $fromTrough = array_slice($response, $troughAt, max(1, self::SETTLED_FROM_QUARTER - $troughAt));
        $this->assertNotEmpty($fromTrough, 'The trough is a quarter of the window.');

        $this->assertLessThan(0.5, $response[3], 'Most of a demand kick is gone within a year.');
        $this->assertGreaterThan(-0.25, $trough, 'The swing past zero stays under a quarter of the kick.');
        $this->assertLessThan(0.05, max($fromTrough), 'No second swing: after the trough the gap does not come back above trend.');
        // The tail is settled from year five, to within the decisions' step.
        foreach (array_slice($response, self::SETTLED_FROM_QUARTER) as $quarter => $value) {
            $this->assertLessThan(self::SETTLED_TOLERANCE, abs($value), sprintf('Settled from year five on (quarter %d).', $quarter + self::SETTLED_FROM_QUARTER));
        }
    }

    /**
     * Easing pushes on a string: a hike tightens the collateral firms and homebuyers borrow against, while a cut does
     * not make a firm in a slump invest. The District's floating-rate loans pass a cut straight into household cash
     * flow, but that runs through debt service, its own symmetric channel; on the rest the cuts that follow a slump
     * lift demand less than the hikes that follow a boom cut it, so a slump climbs back with less swing past zero than
     * a boom's bust has.
     */
    public function testASlumpClimbsBackWithLessOvershootThanABoomFalls(): void
    {
        $boom = $this->ringDown(self::KICK);
        $slump = $this->ringDown(-self::KICK);

        $this->assertLessThan(0.5, $slump[3], 'Most of a slump is gone within a year.');
        $this->assertGreaterThan(self::SLUMP_OVERSHOOT_RATIO * min($boom), min($slump), 'The climb out of a slump overshoots less than the fall after a boom.');
        // A slump too settles from year five, as a boom does.
        foreach (array_slice($slump, self::SETTLED_FROM_QUARTER) as $quarter => $value) {
            $this->assertLessThan(self::SETTLED_TOLERANCE, abs($value), sprintf('Settled from year five on (quarter %d).', $quarter + self::SETTLED_FROM_QUARTER));
        }
    }

    /** @return non-empty-list<float> Kicked minus control gap, per unit of kick, at each quarter end. */
    private function ringDown(float $kickSize): array
    {
        $dt = 1.0 / self::TICKS_PER_YEAR;
        $perQuarter = intdiv(self::TICKS_PER_YEAR, 4);
        $paths = [];
        foreach ([0.0, $kickSize] as $kick) {
            // Common random numbers: a few draws bypass the zeroed methods, so both runs replay the same stream.
            mt_srand(42);
            $math = new ScriptedDraws();
            $engine = MacroEngine::headless($math);
            $economy = new MacroState();
            for ($tick = 0; $tick < self::BURN_IN_YEARS * self::TICKS_PER_YEAR; $tick++) {
                $engine->step($economy, $dt);
            }
            if ($kick !== 0.0) {
                $economy->outputGap += $kick;
            }
            $path = [];
            for ($tick = 1; $tick <= self::OBSERVE_YEARS * self::TICKS_PER_YEAR; $tick++) {
                $macro = $engine->step($economy, $dt);
                if ($tick % $perQuarter === 0) {
                    $path[] = $macro->outputGap;
                }
            }
            $paths[] = $path;
        }

        $response = array_map(static fn(float $kicked, float $control): float => ($kicked - $control) / $kickSize, $paths[1], $paths[0]);
        self::assertNotEmpty($response, 'The observation window records at least one quarter.');

        return $response;
    }

}
