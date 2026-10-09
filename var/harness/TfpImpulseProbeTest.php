<?php

declare(strict_types=1);

use App\Service\Macro\MacroEngine;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Engine impulse response to a TFP level shock: for each seed, two runs on the same draws, the second with SHOCK
 * added to the shock level after the burn-in. Their quarterly difference is the engine's own response, feedback
 * included. Env: SEEDS BURN HORIZON TPY SHOCK OUT
 */
final class TfpImpulseProbeTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return \HarnessKernel::class;
    }

    public function testRun(): void
    {
        $burn = (int) (getenv('BURN') ?: 20);
        $horizon = (int) (getenv('HORIZON') ?: 6);
        $tpy = (int) (getenv('TPY') ?: 360);
        $shock = (float) (getenv('SHOCK') ?: 0.01);
        $dt = 1.0 / $tpy;
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
                    $state['tfp_shock_level'] += $impulse;
                    $redis->set(MacroEngine::REDIS_MACRO_STATE, json_encode($state, JSON_PRESERVE_ZERO_FRACTION));
                }
                $rows = [];
                for ($tick = 1; $tick <= $horizon * $tpy; $tick++) {
                    $m = $engine->updateMacroState($dt, null);
                    if ($tick % intdiv($tpy, 4) === 0) {
                        $rows[] = ['gap' => $m->outputGap, 'supply' => $m->productivitySupplyGap, 'u' => $m->unemploymentRate, 'infl' => $m->inflation,
                            'policy' => $m->policyRate, 'potential' => $m->potentialGdpIndex, 'rstar' => $m->naturalRate, 'rwg' => $m->realWageGap, 'x' => $m->tfpShockLevel];
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
