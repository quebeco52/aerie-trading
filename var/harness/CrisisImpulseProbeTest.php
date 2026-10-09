<?php

declare(strict_types=1);

use App\Service\Macro\MacroEngine;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The engine's output-gap response to one credit crisis, feedback included. Needs the OVR crisis_irf.sh builds: no
 * crisis ever lands unless FORCE_CRISIS_AT is crossed, and the dice are drawn every tick either way. For each seed, two
 * runs on the same draws, the second with the crisis at BURN years and the boom behind it at CRISIS_GAP. Quarter rows
 * carry the quarter-average gap, the policy rate and the premium. Env: SEEDS BURN HORIZON TPY CRISIS_GAP OUT
 */
final class CrisisImpulseProbeTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return \HarnessKernel::class;
    }

    public function testRun(): void
    {
        $burn = (float) (getenv('BURN') ?: 20);
        $horizon = (int) (getenv('HORIZON') ?: 10);
        $tpy = (int) (getenv('TPY') ?: 180);
        $dt = 1.0 / $tpy;
        $perQuarter = intdiv($tpy, 4);
        $out = [];
        foreach (explode(',', (string) (getenv('SEEDS') ?: '1')) as $seed) {
            foreach (['base' => '', 'crisis' => (string) $burn] as $arm => $at) {
                putenv('FORCE_CRISIS_AT=' . $at);
                if (getenv('CRISIS_GAP') === false) {
                    putenv('CRISIS_GAP=0.043');
                }
                mt_srand((int) $seed);
                $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
                self::bootKernel(['environment' => 'test', 'debug' => false]);
                $engine = static::getContainer()->get(MacroEngine::class);
                $rows = [];
                $gapSum = 0.0;
                $ticks = (int) round(($burn + $horizon) * $tpy);
                for ($tick = 1; $tick <= $ticks; $tick++) {
                    $m = $engine->updateMacroState($dt, null);
                    $gapSum += $m->outputGap;
                    if ($tick % $perQuarter === 0) {
                        if ($m->totalTime > $burn - 1.0 + 1e-9) {
                            $rows[] = ['t' => round($m->totalTime, 3), 'gap' => $gapSum / $perQuarter, 'policy' => $m->policyRate,
                                'ebp' => $m->excessBondPremium, 'drag' => $m->creditCrisisDrag, 'u' => $m->unemploymentRate, 'infl' => $m->inflation];
                        }
                        $gapSum = 0.0;
                    }
                }
                $out[$seed][$arm] = $rows;
                self::ensureKernelShutdown();
            }
        }
        putenv('FORCE_CRISIS_AT');
        file_put_contents((string) getenv('OUT'), json_encode($out));
        $this->assertNotEmpty($out);
    }
}
