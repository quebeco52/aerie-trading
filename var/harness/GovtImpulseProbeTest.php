<?php

declare(strict_types=1);

use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\OutputGapProbe;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The engine's government purchases multiplier, feedback included, measured the way Ramey & Zubairy (2018) and
 * Blanchard & Perotti (2002) measure it: a shock to purchases themselves, which then decay at the fitted process's
 * own persistence. Macro only (no fund). For each seed, two runs on the same draws, the second with the purchases
 * index scaled by (1 + SHOCK) after the burn-in. The cumulative multiplier at h quarters is the summed gap
 * difference over the summed purchases difference in GDP units (purchases are TARGET_CORPORATE_TAX_RATE of GDP at
 * the baseline index). Env: SEEDS BURN HORIZON TPY SHOCK OUT
 */
final class GovtImpulseProbeTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return \HarnessKernel::class;
    }

    public function testRun(): void
    {
        $burn = (int) (getenv('BURN') ?: 20);
        $horizon = (int) (getenv('HORIZON') ?: 5);
        $tpy = (int) (getenv('TPY') ?: 180);
        $shock = (float) (getenv('SHOCK') ?: 0.05);
        $dt = 1.0 / $tpy;
        $out = [];
        foreach (explode(',', (string) (getenv('SEEDS') ?: '1')) as $seed) {
            foreach ([0.0, $shock] as $size) {
                mt_srand((int) $seed);
                $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
                self::bootKernel(['environment' => 'test', 'debug' => false]);
                $engine = static::getContainer()->get(MacroEngine::class);
                for ($tick = 0; $tick < $burn * $tpy; $tick++) {
                    $engine->updateMacroState($dt, null);
                }
                if ($size !== 0.0) {
                    $redis = (new \ReflectionProperty(MacroEngine::class, 'redis'))->getValue($engine);
                    $state = json_decode((string) $redis->get(MacroEngine::REDIS_MACRO_STATE), true);
                    $this->assertArrayHasKey('government_spending_index', $state);
                    $state['government_spending_index'] *= (1.0 + $size);
                    $redis->set(MacroEngine::REDIS_MACRO_STATE, json_encode($state, JSON_PRESERVE_ZERO_FRACTION));
                }
                $probe = static::getContainer()->get(OutputGapProbe::class);
                $probe->enable();
                $probe->rollWindow();
                $rows = [];
                for ($tick = 1; $tick <= $horizon * $tpy; $tick++) {
                    $m = $engine->updateMacroState($dt, null);
                    if ($tick % intdiv($tpy, 4) === 0) {
                        $probe->rollWindow();
                        $rows[] = ['gap' => $m->outputGap, 'gov' => $m->governmentSpendingIndex, 'policy' => $m->policyRate,
                            'infl' => $m->inflation, 'debt' => $m->sovereignDebtToGdp, 'y10' => $m->yield10y, 'tax' => $m->corporateTaxRate,
                            'c' => $probe->snapshot()['previous']['contributions']];
                    }
                }
                $out[$seed][$size === 0.0 ? 'base' : 'shock'] = $rows;
                self::ensureKernelShutdown();
            }
        }
        file_put_contents((string) getenv('OUT'), json_encode(['shock' => $shock, 'runs' => $out]));
        $this->assertNotEmpty($out);
    }
}
