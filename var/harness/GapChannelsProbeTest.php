<?php
declare(strict_types=1);
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\OutputGapProbe;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
/** Quarterly output-gap drift decomposition from the engine's own probe. Env: SEEDS YEARS TPY OUT */
final class GapChannelsProbeTest extends KernelTestCase
{
    protected static function getKernelClass(): string { return \HarnessKernel::class; }
    public function testRun(): void
    {
        $years = (int) getenv('YEARS'); $tpy = (int) getenv('TPY'); $dt = 1.0 / $tpy; $rows = [];
        foreach (explode(',', (string) getenv('SEEDS')) as $seed) {
            mt_srand((int) $seed);
            $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
            self::bootKernel(['environment' => 'test', 'debug' => false]);
            $macro = static::getContainer()->get(MacroEngine::class);
            $probe = static::getContainer()->get(OutputGapProbe::class);
            $probe->enable();
            for ($tick = 1; $tick <= $years * $tpy; $tick++) {
                $m = $macro->updateMacroState($dt, null);
                if ($tick % intdiv($tpy, 4) === 0) {
                    $probe->rollWindow();
                    $w = $probe->snapshot()['previous'];
                    if ($tick > 10 * $tpy) {
                        $rows[] = ['seed' => (int) $seed, 't' => $tick / $tpy, 'crisis' => $m->lastCreditCrisisAt, 'gap' => $m->outputGap] + ['c' => $w['contributions'], 'diffusion' => $w['diffusion'], 'clamp' => $w['clamp'], 'unexplained' => $w['unexplained']];
                    }
                }
            }
            self::ensureKernelShutdown();
        }
        file_put_contents((string) getenv('OUT'), json_encode($rows));
        $this->assertNotEmpty($rows);
    }
}
