<?php

declare(strict_types=1);

use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Macro-only run: the wage level against prices and trend productivity, plus the cycle it sits in. Env: SEEDS YEARS TPY OUT */
final class LaborMacroProbeTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return \HarnessKernel::class;
    }

    public function testRun(): void
    {
        $years = (int) (getenv('YEARS') ?: 40);
        $tpy = (int) (getenv('TPY') ?: 360);
        $dt = 1.0 / $tpy;
        $rows = [];
        foreach (explode(',', (string) (getenv('SEEDS') ?: '1')) as $seed) {
            mt_srand((int) $seed);
            $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
            self::bootKernel(['environment' => 'test', 'debug' => false]);
            $macro = static::getContainer()->get(MacroEngine::class);
            $perQuarter = intdiv($tpy, 4);
            $gapEma = 0.0; $inflation = 0.02; $rwg = 0.0;
            for ($tick = 1; $tick <= $years * $tpy; $tick++) {
                $m = $macro->updateMacroState($dt, null);
                // Same integral the engine keeps: this tick's wage, last tick's inflation, the trend TFP read off last tick's gap.
                $trend = max(MacroEngine::MIN_TFP_GROWTH_RATE, min(MacroAggregateSubsystem::MAX_TFP_GROWTH_RATE, MacroEngine::TFP_DRIFT + $gapEma * MacroAggregateSubsystem::TFP_OUTPUT_GAP_SENSITIVITY));
                $rwg += ($m->wageGrowth - $inflation - $trend) * $dt;
                $gapEma = $m->outputGapEma; $inflation = $m->inflation;
                if ($tick % $perQuarter === 0) {
                    $rows[] = [
                        'seed' => (int) $seed, 't' => $tick / $tpy,
                        'gap' => $m->outputGap, 'u' => $m->unemploymentRate, 'infl' => $m->inflation, 'wage' => $m->wageGrowth,
                        'policy' => $m->policyRate, 'y10' => $m->yield10y, 'be' => $m->tipsBreakevenEma,
                        'rwg' => property_exists($m, 'realWageGap') ? $m->realWageGap : $rwg, 'rwg_probe' => $rwg,
                        'supply' => property_exists($m, 'productivitySupplyGap') ? $m->productivitySupplyGap : 0.0,
                        'x' => property_exists($m, 'tfpShockLevel') ? $m->tfpShockLevel : 0.0,
                        'potential' => $m->potentialGdpIndex, 'rstar' => $m->naturalRate,
                        'starts' => $m->housingStartsIndexEma, 'resi' => $m->residentialPropertyIndexEma, 'metals' => $m->industrialMetalsIndex,
                    ];
                }
            }
            self::ensureKernelShutdown();
        }
        file_put_contents((string) getenv('OUT'), json_encode($rows));
        $this->assertNotEmpty($rows);
    }
}
