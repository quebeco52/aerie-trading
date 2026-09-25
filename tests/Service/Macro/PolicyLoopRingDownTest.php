<?php

declare(strict_types=1);

namespace App\Tests\Service\Macro;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * The policy loop's free response: every draw zero and no jump, two runs settle, one gets a +1.6pp opening gap, and
 * the difference is the loop ringing down on its own. Deterministic, so unlike an emergent statistic it has power.
 *
 * The CBO gap does not swing through zero on its own (autocorrelation +0.07 at 12 quarters, 1949-2019). Before
 * the 2026-09-25 loop fit the engine's did: the gap had no pull of its own, a rate move integrated into it without
 * limit, and a policy loop acting 3-4 quarters late rang at 6-7 years, a +1.6pp kick coming back at -1.1pp.
 */
class PolicyLoopRingDownTest extends TestCase
{
    private const TICKS_PER_YEAR = 72;
    private const BURN_IN_YEARS = 15;
    private const OBSERVE_YEARS = 8;
    private const KICK = 0.016;
    /** A slump's overshoot must be smaller than a boom's by a margin; the symmetric loop's was 5% larger. */
    private const SLUMP_OVERSHOOT_RATIO = 0.95;

    public function testAnOpeningGapFadesWithoutAPendulum(): void
    {
        $response = $this->ringDown(self::KICK);
        $trough = min($response);
        $troughAt = array_search($trough, $response, true);

        $this->assertLessThan(0.5, $response[3], 'Most of a demand kick is gone within a year.');
        $this->assertGreaterThan(-0.25, $trough, 'The swing past zero stays under a quarter of the kick.');
        $this->assertLessThan(0.05, max(array_slice($response, (int) $troughAt)), 'No second swing: after the trough the gap does not come back above trend.');
        foreach (array_slice($response, 20) as $quarter => $value) {
            $this->assertLessThan(0.03, abs($value), sprintf('Settled from year five on (quarter %d).', $quarter + 20));
        }
    }

    /**
     * Barnichon & Matthes (2018), Tenreyro & Thwaites (2016): easing pushes on a string. The rate cuts that follow a
     * slump lift demand less than the hikes that follow a boom cut it, so a slump climbs back without the swing past
     * zero a boom's bust has; the symmetric loop overshot both ways alike (-0.22 against -0.21 per unit of kick),
     * the kinked one -0.19 against -0.21.
     */
    public function testASlumpClimbsBackWithLessOvershootThanABoomFalls(): void
    {
        $boom = $this->ringDown(self::KICK);
        $slump = $this->ringDown(-self::KICK);

        $this->assertLessThan(0.5, $slump[3], 'Most of a slump is gone within a year.');
        $this->assertGreaterThan(self::SLUMP_OVERSHOOT_RATIO * min($boom), min($slump), 'The climb out of a slump overshoots less than the fall after a boom.');
        foreach (array_slice($slump, 20) as $quarter => $value) {
            $this->assertLessThan(0.03, abs($value), sprintf('Settled from year five on (quarter %d).', $quarter + 20));
        }
    }

    /** @return list<float> Kicked minus control gap, per unit of kick, at each quarter end. */
    private function ringDown(float $kickSize): array
    {
        $dt = 1.0 / self::TICKS_PER_YEAR;
        $perQuarter = intdiv(self::TICKS_PER_YEAR, 4);
        $paths = [];
        foreach ([0.0, $kickSize] as $kick) {
            // Common random numbers: a few draws bypass the zeroed methods, so both runs replay the same stream.
            mt_srand(42);
            $math = new class extends MathUtility {
                public function generateStandardNormal(): float
                {
                    return 0.0;
                }

                public function checkProbability(float $probability): bool
                {
                    return false;
                }
            };
            $redis = $this->inMemoryRedis();
            $engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem($math),
                new MacroAggregateSubsystem($math), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
            for ($tick = 0; $tick < self::BURN_IN_YEARS * self::TICKS_PER_YEAR; $tick++) {
                $engine->updateMacroState($dt);
            }
            if ($kick !== 0.0) {
                $state = json_decode((string) $redis->get(MacroEngine::REDIS_MACRO_STATE), true);
                $state['output_gap'] += $kick;
                $redis->set(MacroEngine::REDIS_MACRO_STATE, json_encode($state, JSON_PRESERVE_ZERO_FRACTION));
            }
            $path = [];
            for ($tick = 1; $tick <= self::OBSERVE_YEARS * self::TICKS_PER_YEAR; $tick++) {
                $macro = $engine->updateMacroState($dt);
                if ($tick % $perQuarter === 0) {
                    $path[] = $macro->outputGap;
                }
            }
            $paths[] = $path;
        }

        return array_map(static fn (float $kicked, float $control): float => ($kicked - $control) / $kickSize, $paths[1], $paths[0]);
    }

    /** The bootstrap's Redis stand-in forgets everything; the engine needs its state back each tick. */
    private function inMemoryRedis(): \Redis
    {
        return new class extends \Redis {
            /** @var array<string, string> */
            private array $store = [];

            public function get(mixed $key): mixed
            {
                return $this->store[(string) $key] ?? false;
            }

            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
            {
                $this->store[$key] = (string) $value;

                return true;
            }
        };
    }
}
