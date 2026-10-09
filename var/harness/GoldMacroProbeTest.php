<?php

declare(strict_types=1);

use App\Service\Macro\MacroEngine;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Macro-only run: samples the gold index and its drivers each quarter. Env: SEEDS YEARS TPY OUT */
final class GoldMacroProbeTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return \HarnessKernel::class;
    }

    public function testRun(): void
    {
        $years = (int) (getenv('YEARS') ?: 40);
        $tpy = (int) (getenv('TPY') ?: 360);
        $rows = [];
        foreach (explode(',', (string) (getenv('SEEDS') ?: '1')) as $seed) {
            mt_srand((int) $seed);
            $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
            self::bootKernel(['environment' => 'test', 'debug' => false]);
            $macro = static::getContainer()->get(MacroEngine::class);
            $perQuarter = intdiv($tpy, 4);
            for ($tick = 1; $tick <= $years * $tpy; $tick++) {
                $m = $macro->updateMacroState(1.0 / $tpy, null);
                if ($tick % $perQuarter === 0) {
                    $rows[] = [
                        'seed' => (int) $seed,
                        't' => $tick / $tpy,
                        'gold' => $m->goldPriceIndexEma,
                        'gold_spot' => $m->goldPriceIndex,
                        'gap' => $m->outputGapEma,
                        'sent' => $m->consumerSentimentIndexEma,
                        'real' => $m->yield10yEma - $m->tipsBreakevenEma,
                        'be' => $m->tipsBreakevenEma,
                        'rec' => $m->recessionProbability,
                        'metals' => $m->industrialMetalsIndexEma,
                        'equity' => $m->equityMarketCap,
                    ];
                }
            }
            self::ensureKernelShutdown();
        }
        file_put_contents((string) getenv('OUT'), json_encode($rows));
        $this->assertNotEmpty($rows);
    }
}
