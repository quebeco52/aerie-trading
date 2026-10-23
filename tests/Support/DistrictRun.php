<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Math\MathUtility;
use App\Service\Politics\PoliticsEngine;

/**
 * The District's economy as the ticker runs it, for the long-run realism tests: the macro engine with the reserve fund,
 * and, when asked, the Diet beside it, reading each tick's economy and handing the next tick the laws it passed.
 * Without the Diet the founding levers stand for the whole run.
 */
final class DistrictRun
{
    /**
     * Every tick's economy after the burn-in.
     *
     * @return \Generator<int, MacroStateDTO>
     */
    public static function ticks(int $seed, int $years, int $burnInYears, int $ticksPerYear, bool $withDiet = false): \Generator
    {
        mt_srand($seed);
        $math = new MathUtility();
        $engine = MacroEngine::headless($math, withFund: true);
        $economy = new MacroState();
        $diet = $withDiet ? new PoliticsEngine(MathUtility::ownStream($seed), self::inMemoryRedis()) : null;
        $policy = $diet?->liveState()->policy();

        $dt = 1.0 / $ticksPerYear;
        for ($tick = 1; $tick <= $years * $ticksPerYear; ++$tick) {
            $macro = $engine->step($economy, $dt, policy: $policy);
            if ($diet !== null) {
                $policy = $diet->updatePolitics($macro, $dt)->policy();
            }
            if ($tick > $burnInYears * $ticksPerYear) {
                yield $tick => $macro;
            }
        }
    }

    /** The bootstrap's Redis stand-in forgets everything; the Diet needs its state back each tick. */
    private static function inMemoryRedis(): \Redis
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
