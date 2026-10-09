<?php

declare(strict_types=1);

use App\Service\Macro\MacroEngine;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Engine response to a monetary-policy shock: for each seed, two runs on the same draws, the second with SHOCK added
 * to the policy rate after the burn-in. Their difference in quarterly AVERAGES of the gap and the policy rate is the
 * engine's own response, the rule's reaction included. Env: SEEDS BURN HORIZON TPY SHOCK OUT
 */
final class PolicyShockProbeTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return \HarnessKernel::class;
    }

    public function testRun(): void
    {
        $burn = (int) (getenv('BURN') ?: 20);
        $horizon = (int) (getenv('HORIZON') ?: 5);
        $tpy = (int) (getenv('TPY') ?: 360);
        $shock = (float) (getenv('SHOCK') ?: 0.01);
        $dt = 1.0 / $tpy;
        $perQuarter = intdiv($tpy, 4);
        $out = [];
        foreach (explode(',', (string) (getenv('SEEDS') ?: '1')) as $seed) {
            foreach ([0.0, $shock] as $impulse) {
                mt_srand((int) $seed);
                $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
                self::bootKernel(['environment' => 'test', 'debug' => false]);
                $engine = static::getContainer()->get(MacroEngine::class);
                for ($tick = 0; $tick < $burn * $tpy; $tick++) {
                    $engine->updateMacroState($dt, null);
                }
                if ($impulse !== 0.0) {
                    $redis = (new \ReflectionProperty(MacroEngine::class, 'redis'))->getValue($engine);
                    $state = json_decode((string) $redis->get(MacroEngine::REDIS_MACRO_STATE), true);
                    $state['policy_rate'] += $impulse;
                    $redis->set(MacroEngine::REDIS_MACRO_STATE, json_encode($state, JSON_PRESERVE_ZERO_FRACTION));
                }
                $rows = [];
                $gapSum = 0.0;
                $rateSum = 0.0;
                for ($tick = 1; $tick <= $horizon * $tpy; $tick++) {
                    $m = $engine->updateMacroState($dt, null);
                    $gapSum += $m->outputGap;
                    $rateSum += $m->policyRate;
                    if ($tick % $perQuarter === 0) {
                        $rows[] = ['gap' => $gapSum / $perQuarter, 'rate' => $rateSum / $perQuarter, 'infl' => $m->inflation];
                        $gapSum = 0.0;
                        $rateSum = 0.0;
                    }
                }
                $out[$seed][$impulse === 0.0 ? 'base' : 'shock'] = $rows;
                self::ensureKernelShutdown();
            }
        }
        file_put_contents((string) getenv('OUT'), json_encode(['shock' => $shock, 'runs' => $out]));
        $this->assertNotEmpty($out);
    }
}
