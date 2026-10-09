<?php

declare(strict_types=1);

use App\Service\Macro\MacroConfigFingerprint;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
use App\Service\Macro\Recorder\OutputGapProbe;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A headless run in the shape of `make macro-dump`: one NDJSON row per quarter with the state, gap_channels and
 * quarter_diagnostics, so var/harness/dump_an.py reads a harness run and a live dump alike. Env: SEEDS YEARS TPY OUT
 */
final class DiagnosticsRunTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return \HarnessKernel::class;
    }

    public function testRun(): void
    {
        $years = (int) (getenv('YEARS') ?: 60);
        $tpy = (int) (getenv('TPY') ?: 360);
        $perQuarter = intdiv($tpy, 4);
        $out = fopen((string) getenv('OUT'), 'wb');
        foreach (explode(',', (string) (getenv('SEEDS') ?: '1')) as $seed) {
            mt_srand((int) $seed);
            self::ensureKernelShutdown();
            $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
            self::bootKernel(['environment' => 'test', 'debug' => false]);
            $container = static::getContainer();
            $macro = $container->get(MacroEngine::class);
            $gapProbe = $container->get(OutputGapProbe::class);
            $diagnostics = $container->get(MacroDiagnosticsProbe::class);
            $gapProbe->enable();
            $diagnostics->enable();
            $fingerprint = (new MacroConfigFingerprint())->fingerprint();
            for ($tick = 1; $tick <= $years * $tpy; $tick++) {
                $state = $macro->updateMacroState(1.0 / $tpy, null);
                if ($tick % $perQuarter === 0) {
                    $gapProbe->rollWindow();
                    $diagnostics->rollWindow();
                    $row = $state->toArray();
                    unset($row['sector_z'], $row['sector_demand_z']);
                    $row['seed'] = (int) $seed;
                    $row['gap_channels'] = $gapProbe->snapshot()['previous'];
                    $row['quarter_diagnostics'] = $diagnostics->snapshot()['previous'];
                    $row['config_fingerprint'] = $fingerprint;
                    $row['ticks_per_year'] = $tpy;
                    fwrite($out, json_encode($row) . "\n");
                }
            }
        }
        fclose($out);
        $this->assertTrue(true);
    }
}
