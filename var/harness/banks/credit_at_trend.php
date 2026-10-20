<?php

declare(strict_types=1);

/*
 * What each loan segment loses over the District's own credit cycle, against its through-the-cycle rate.
 *
 * Runs the macro engine alone (tests/Support/DistrictRun) and reads, once a quarter, the household and corporate
 * default rates against their measured trends. For each segment PD it averages the loss multiple two mappings give:
 * - raw: the Vasicek conditional PD at the factor solved against the trend, over the long-run PD (the pre-D1 reading);
 * - anchored: CreditRisk::calculateVasicekPdMultiple, one at trend.
 * The mapping that averages one is the one whose book loses its through-the-cycle rate over the cycle. Also reports
 * the mean and skew of the observed rate over its trend, which decides it.
 *
 * Usage: bin/php-slot php var/harness/banks/credit_at_trend.php <seeds> <years> [tpy] [burn-in years]
 */

require __DIR__ . '/../../../tests/bootstrap.php';

use App\Service\Math\CreditRisk;
use App\Service\Model\Sector\CommercialBankBusinessModel as Bank;
use App\Service\Model\Sector\CreditServicesBusinessModel as Card;
use App\Tests\Support\DistrictRun;

$seeds = (int) ($argv[1] ?? 4);
$years = (int) ($argv[2] ?? 60);
$tpy = (int) ($argv[3] ?? 120);
$burnIn = (int) ($argv[4] ?? 10);
$lgd = Bank::LGD_BASELINE;
$segments = [
    'card' => [Card::CONSUMER_CHARGE_OFF_RATE / $lgd, 'retail'],
    'consumer' => [Bank::CONSUMER_CHARGE_OFF_RATE / $lgd, 'retail'],
    'residential' => [Bank::RESIDENTIAL_CHARGE_OFF_RATE / $lgd, 'retail'],
    'cre' => [Bank::COMMERCIAL_REAL_ESTATE_CHARGE_OFF_RATE / $lgd, 'corporate'],
    'business' => [Bank::BUSINESS_CHARGE_OFF_RATE / $lgd, 'corporate'],
];
$rho = ['retail' => CreditRisk::RETAIL_ASRF_RHO, 'corporate' => CreditRisk::CORPORATE_DEFAULT_RHO];

$sums = [];
$ratios = ['retail' => [], 'corporate' => []];
for ($seed = 1; $seed <= $seeds; ++$seed) {
    foreach (DistrictRun::ticks(20261020 + $seed, $years, $burnIn, $tpy) as $tick => $macro) {
        if ($tick % intdiv($tpy, 4) !== 0) {
            continue;
        }
        $rates = [
            'retail' => [$macro->retailDefaultRateEma, $macro->retailDefaultRateTrend],
            'corporate' => [$macro->corporateDefaultRateEma, $macro->corporateDefaultRateTrend],
        ];
        foreach ($rates as $population => [$observed, $trend]) {
            $ratios[$population][] = $observed / $trend;
        }
        foreach ($segments as $name => [$pd, $population]) {
            [$observed, $trend] = $rates[$population];
            $raw = CreditRisk::calculateVasicekExpectedLoss(CreditRisk::calculateVasicekSystematicFactor($observed, $trend, $rho[$population]), $pd, $rho[$population], 1.0) / $pd;
            $anchored = CreditRisk::calculateVasicekPdMultiple($observed, $trend, $pd, $rho[$population]);
            $sums[$name]['raw'][$seed][] = $raw;
            $sums[$name]['anchored'][$seed][] = $anchored;
        }
    }
}

$mean = static fn(array $xs): float => array_sum($xs) / count($xs);
foreach ($ratios as $population => $xs) {
    $m = $mean($xs);
    $sd = sqrt($mean(array_map(static fn(float $x): float => ($x - $m) ** 2, $xs)));
    $skew = $mean(array_map(static fn(float $x): float => (($x - $m) / $sd) ** 3, $xs));
    printf("%-9s observed/trend: mean %.4f sd %.4f skew %+.2f (n %d)\n", $population, $m, $sd, $skew, count($xs));
}
printf("\n%-12s %18s %18s\n", 'segment', 'raw mean (se)', 'anchored mean (se)');
foreach ($sums as $name => $arms) {
    $cells = [];
    foreach (['raw', 'anchored'] as $arm) {
        $bySeed = array_map($mean, $arms[$arm]);
        $m = $mean($bySeed);
        $se = count($bySeed) > 1 ? sqrt(array_sum(array_map(static fn(float $x): float => ($x - $m) ** 2, $bySeed)) / (count($bySeed) - 1) / count($bySeed)) : 0.0;
        $cells[] = sprintf('%.4f (%.4f)', $m, $se);
    }
    printf("%-12s %18s %18s\n", $name, ...$cells);
}
