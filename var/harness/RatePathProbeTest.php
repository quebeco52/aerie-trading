<?php

declare(strict_types=1);

use App\Service\Macro\MacroEngine;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The gap's response to the US post-surprise rate path (Bauer & Swanson 2023, policy_fit.json): for each seed, two runs
 * on the same draws; in the second the policy rate is held at the first run's rate plus SCALE times the US funds-rate
 * response for 16 quarters, then the rule takes over. The quarterly-average gap difference over SCALE is directly
 * comparable to the US gap response per unit surprise, with no normalisation. Env: SEEDS BURN TPY SCALE OUT
 * PATHSRC=bm forces Barnichon-Matthes' recursive funds-rate response instead (asym_fit.json, per 100bp); a negative
 * SCALE is the easing mirror. Each run also records unemployment, and the base run the 7q average gap change before
 * the shock (Tenreyro-Thwaites' growth state, less the smooth potential growth).
 */
final class RatePathProbeTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return \HarnessKernel::class;
    }

    public function testRun(): void
    {
        $burn = (int) (getenv('BURN') ?: 20);
        $tpy = (int) (getenv('TPY') ?: 360);
        $scale = (float) (getenv('SCALE') ?: 0.25);
        $dt = 1.0 / $tpy;
        $perQuarter = intdiv($tpy, 4);
        $horizonQuarters = 20;
        $known = [];
        if (getenv('PATHSRC') === 'bm') {
            foreach (json_decode((string) file_get_contents(__DIR__ . '/asym_fit.json'), true)['bm_rate_path'] as $h => $v) {
                $known[(int) $h] = $v / 100.0;
            }
        } else {
            foreach (json_decode((string) file_get_contents(__DIR__ . '/policy_fit.json'), true)['policy_irf_bs'] as $h => $v) {
                $known[(int) $h] = $v['rate'] / 100.0;
            }
        }
        ksort($known);
        $path = [];
        $hs = array_keys($known);
        for ($q = 0; $q <= 16; $q++) {
            foreach ($hs as $i => $h) {
                if ($h >= $q) {
                    $lo = $hs[max(0, $i - 1)];
                    $path[$q] = $h === $q || $h === $lo ? $known[$h] : $known[$lo] + (($known[$h] - $known[$lo]) * ($q - $lo) / ($h - $lo));
                    break;
                }
            }
        }
        $out = [];
        foreach (explode(',', (string) (getenv('SEEDS') ?: '1')) as $seed) {
            $baseRates = [];
            foreach (['base', 'path'] as $arm) {
                mt_srand((int) $seed);
                $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
                self::bootKernel(['environment' => 'test', 'debug' => false]);
                $engine = static::getContainer()->get(MacroEngine::class);
                $redis = (new \ReflectionProperty(MacroEngine::class, 'redis'))->getValue($engine);
                $preGaps = [];
                $preSum = 0.0;
                for ($tick = 0; $tick < $burn * $tpy; $tick++) {
                    $pre = $engine->updateMacroState($dt, null);
                    $preSum += $pre->outputGap;
                    if (($tick + 1) % $perQuarter === 0) {
                        $preGaps[] = $preSum / $perQuarter;
                        $preSum = 0.0;
                    }
                }
                $growth7q = count($preGaps) > 8 ? ($preGaps[count($preGaps) - 1] - $preGaps[count($preGaps) - 8]) / 7.0 : 0.0;
                $rows = [];
                $gapSum = 0.0;
                $rateSum = 0.0;
                $uSum = 0.0;
                for ($tick = 0; $tick < $horizonQuarters * $perQuarter; $tick++) {
                    $m = $engine->updateMacroState($dt, null);
                    $q = intdiv($tick, $perQuarter);
                    $rate = $m->policyRate;
                    if ($arm === 'base') {
                        $baseRates[$tick] = $rate;
                    } elseif ($q <= 16) {
                        $rate = max(MacroEngine::EFFECTIVE_LOWER_BOUND, $baseRates[$tick] + ($scale * $path[$q]));
                        $state = json_decode((string) $redis->get(MacroEngine::REDIS_MACRO_STATE), true);
                        $state['policy_rate'] = $rate;
                        $redis->set(MacroEngine::REDIS_MACRO_STATE, json_encode($state, JSON_PRESERVE_ZERO_FRACTION));
                    }
                    $gapSum += $m->outputGap;
                    $rateSum += $rate;
                    $uSum += $m->unemploymentRate;
                    if (($tick + 1) % $perQuarter === 0) {
                        $rows[] = ['gap' => $gapSum / $perQuarter, 'rate' => $rateSum / $perQuarter, 'u' => $uSum / $perQuarter];
                        $gapSum = 0.0;
                        $rateSum = 0.0;
                        $uSum = 0.0;
                    }
                }
                $out[$seed][$arm] = $rows;
                if ($arm === 'base') {
                    $out[$seed]['growth7q'] = $growth7q;
                }
                self::ensureKernelShutdown();
            }
        }
        file_put_contents((string) getenv('OUT'), json_encode(['scale' => $scale, 'path' => $path, 'runs' => $out]));
        $this->assertNotEmpty($out);
    }
}
