<?php

declare(strict_types=1);

use App\Service\Macro\MacroEngine;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The fund-financed stabilisation's effect on the cycle, at macro-only speed: a static fund is written into the state
 * after the first tick (OVR must carry the no-op SovereignFundSubsystem from stab_cycle.sh, or the fund's own update
 * would mark the empty sleeves and publish a zero fund), so the budget's rule runs as it does live while nothing
 * else about the fund moves. Quarter rows carry the quarter-AVERAGE gap and potential, so GDP can be HP-filtered as
 * NIPA data are, and the tax rate and purchases for the IMF Table 5 regression. Env: SEEDS YEARS TPY FUND OUT
 */
final class StabCycleProbeTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return \HarnessKernel::class;
    }

    public function testRun(): void
    {
        $years = (int) (getenv('YEARS') ?: 100);
        $tpy = (int) (getenv('TPY') ?: 180);
        $fund = (float) (getenv('FUND') ?: 2.0);
        $dt = 1.0 / $tpy;
        $perQuarter = intdiv($tpy, 4);
        $out = [];
        foreach (explode(',', (string) (getenv('SEEDS') ?: '1')) as $seed) {
            mt_srand((int) $seed);
            $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
            self::bootKernel(['environment' => 'test', 'debug' => false]);
            $engine = static::getContainer()->get(MacroEngine::class);
            $engine->updateMacroState($dt, null);
            if ($fund > 0.0) {
                $redis = (new \ReflectionProperty(MacroEngine::class, 'redis'))->getValue($engine);
                $state = json_decode((string) $redis->get(MacroEngine::REDIS_MACRO_STATE), true);
                foreach (['sovereign_fund_dollars_per_gdp', 'sovereign_fund_to_gdp', 'sovereign_fund_domestic_weight'] as $key) {
                    $this->assertArrayHasKey($key, $state);
                }
                $state['sovereign_fund_dollars_per_gdp'] = 1.0;
                $state['sovereign_fund_to_gdp'] = $fund;
                $state['sovereign_fund_domestic_weight'] = 0.0;
                $redis->set(MacroEngine::REDIS_MACRO_STATE, json_encode($state, JSON_PRESERVE_ZERO_FRACTION));
            }
            $rows = [];
            $gapSum = 0.0;
            for ($tick = 2; $tick <= $years * $tpy; $tick++) {
                $m = $engine->updateMacroState($dt, null);
                $gapSum += $m->outputGap;
                if ($tick % $perQuarter === 0) {
                    $rows[] = ['t' => round($m->totalTime, 3), 'gap' => $gapSum / $perQuarter, 'gap_end' => $m->outputGap, 'pot' => $m->potentialGdpIndex,
                        'stab' => $m->sovereignFundStabilisationToGdp, 'tax' => $m->corporateTaxRate, 'gov' => $m->governmentSpendingIndex,
                        'policy' => $m->policyRate, 'fund' => $m->sovereignFundToGdp];
                    $gapSum = 0.0;
                }
            }
            $out[$seed] = $rows;
            self::ensureKernelShutdown();
        }
        file_put_contents((string) getenv('OUT'), json_encode($out));
        $this->assertNotEmpty($out);
    }
}
