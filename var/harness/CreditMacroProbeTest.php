<?php

declare(strict_types=1);

use App\Service\Macro\MacroEngine;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Macro-only run for the credit round: quarterly AVERAGES of the gap and the excess bond premium (the data are
 * quarterly averages, so the local projections compare like with like) plus quarter-end credit gauges.
 * Works on trees without the premium (it reads 0). Env: SEEDS YEARS TPY OUT
 */
final class CreditMacroProbeTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return \HarnessKernel::class;
    }

    public function testRun(): void
    {
        $years = (int) (getenv('YEARS') ?: 120);
        $tpy = (int) (getenv('TPY') ?: 360);
        $dt = 1.0 / $tpy;
        $perQuarter = intdiv($tpy, 4);
        $rows = [];
        foreach (explode(',', (string) (getenv('SEEDS') ?: '1')) as $seed) {
            mt_srand((int) $seed);
            $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
            self::bootKernel(['environment' => 'test', 'debug' => false]);
            $macro = static::getContainer()->get(MacroEngine::class);
            $gapSum = 0.0;
            $premiumSum = 0.0;
            $deficitSum = 0.0;
            $spendingSum = 0.0;
            for ($tick = 1; $tick <= $years * $tpy; $tick++) {
                $m = $macro->updateMacroState($dt, null);
                $premium = property_exists($m, 'excessBondPremium') ? $m->excessBondPremium : 0.0;
                $gapSum += $m->outputGap;
                $premiumSum += $premium;
                $deficitSum += $m->primaryDeficitToGdp;
                $spendingSum += log($m->governmentSpendingIndex);
                if ($tick % $perQuarter === 0) {
                    $rows[] = [
                        'seed' => (int) $seed, 't' => $tick / $tpy, 'pot' => $m->potentialGdpIndex,
                        'gap' => $m->outputGap, 'gap_avg' => $gapSum / $perQuarter, 'p' => $premium, 'p_avg' => $premiumSum / $perQuarter,
                        'ig' => $m->macroCreditSpread, 'hy' => $m->highYieldCreditSpread, 'vol' => $m->marketVolatility, 'vol_ema' => $m->marketVolatilityEma,
                        'ted' => $m->interbankLiquiditySpread, 'sloos' => $m->sloosTighteningIndex, 'dflt' => $m->corporateDefaultRate,
                        'crisis' => $m->lastCreditCrisisAt, 'drag' => $m->creditCrisisDrag, 'infl' => $m->inflation, 'u' => $m->unemploymentRate,
                        'policy' => $m->policyRate, 'fci' => $m->financialConditionsIndex, 'supply' => $m->productivitySupplyGap,
                        'credit_gap' => $m->creditToGdpGapEma, 'energy' => $m->energyPriceIndex, 'energy_ema' => $m->energyPriceIndexEma,
                        'resi' => $m->residentialPropertyIndex, 'dti' => $m->householdDebtToIncome, 'hazard' => $m->creditCrisisHazard,
                        'rstar' => $m->naturalRate, 'target' => $m->targetRate, 'tips' => $m->tipsBreakeven,
                        'supercore_ema' => $m->supercoreInflationEma, 'goods_ema' => $m->coreGoodsInflationEma, 'infl_ema' => $m->inflationEma,
                        'pdef' => $m->primaryDeficitToGdp, 'govt' => $m->governmentSpendingIndex, 'ctax' => $m->corporateTaxRate,
                        'pdef_avg' => $deficitSum / $perQuarter, 'lgovt_avg' => $spendingSum / $perQuarter,
                        'dsr' => $m->householdDebtServiceRatio, 'y10_ema' => $m->yield10yEma, 'pol_ema' => $m->policyRateEma,
                        'ngdp' => $m->nominalGdpIndex, 'defl' => $m->gdpDeflator,
                        'nb' => property_exists($m, 'householdNewBorrowing') ? $m->householdNewBorrowing : 0.0,
                    ];
                    $deficitSum = 0.0;
                    $spendingSum = 0.0;
                    $gapSum = 0.0;
                    $premiumSum = 0.0;
                }
            }
            self::ensureKernelShutdown();
        }
        file_put_contents((string) getenv('OUT'), json_encode($rows));
        $this->assertNotEmpty($rows);
    }
}
