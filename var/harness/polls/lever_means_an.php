<?php
// Long-run mean and term persistence (16-quarter autocorrelation about the pooled mean) of each priced law, measured as
// the earnings feel it (PolicyCapitalization::measure()), pooled over seeds; replaces lever_means.py, whose measures
// were copied by hand. php lever_means_an.php runs_form/levers_*.jsonl
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use App\Service\Market\Pricing\PolicyCapitalization;

$rows = [];
foreach (array_slice($argv, 1) as $file) {
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $rows[] = json_decode($line, true);
    }
}
$levers = ['corporateTax' => null, 'dividendTax' => null] + array_fill_keys(PolicyCapitalization::PER_FIRM_LEVERS, true);
foreach ($levers as $lever => $measured) {
    $xs = array_map(static fn (array $r): array => array_map(static fn ($v): float => $measured ? PolicyCapitalization::measure($lever, (float) $v) : (float) $v, $r[$lever]), $rows);
    $all = array_merge(...$xs);
    $mean = array_sum($all) / count($all);
    [$num, $den, $pairs] = [0.0, 0.0, 0];
    $means = [];
    foreach ($xs as $x) {
        $means[] = array_sum($x) / count($x);
        for ($i = 16; $i < count($x); ++$i) {
            $num += ($x[$i] - $mean) * ($x[$i - 16] - $mean);
            ++$pairs;
        }
        foreach ($x as $v) {
            $den += ($v - $mean) ** 2;
        }
    }
    $rho = ($num / $pairs) / ($den / count($all));
    $sdMeans = sqrt(array_sum(array_map(static fn (float $m): float => ($m - array_sum($means) / count($means)) ** 2, $means)) / count($means));
    printf("%-22s seeds %d, quarters %d, mean %.6f (se %.6f), sd %.6f, term persistence %.3f\n", $lever, count($xs), count($all), $mean, $sdMeans / sqrt(count($means)), sqrt($den / count($all)), $rho);
}
