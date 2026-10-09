<?php
// Long-run mean and term persistence (16-quarter autocorrelation, pooled over seeds) of each law the market prices,
// measured as the earnings feel it (PolicyCapitalization::measure(); the tax shift as it stands). numpy-free twin of
// lever_means.py that always uses the live measures.
// php lever_pool.php runs_spend/levers_*.jsonl
require dirname(__DIR__, 3) . '/tests/bootstrap.php';

use App\Service\Market\Pricing\PolicyCapitalization;

$rows = [];
foreach (array_slice($argv, 1) as $file) {
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $row = json_decode($line, true);
        $rows[$row['seed']] = $row;
    }
}
ksort($rows);
foreach (array_merge(['corporateTax'], PolicyCapitalization::PER_FIRM_LEVERS) as $lever) {
    $xs = [];
    foreach ($rows as $row) {
        if (!isset($row[$lever])) {
            continue 2;
        }
        $xs[] = array_map(static fn(float $law): float => $lever === 'corporateTax' ? $law : PolicyCapitalization::measure($lever, $law), $row[$lever]);
    }
    $all = array_merge(...$xs);
    $n = count($all);
    $mean = array_sum($all) / $n;
    $den = array_sum(array_map(static fn(float $v): float => ($v - $mean) ** 2, $all));
    $num = 0.0;
    $pairs = 0;
    $means = [];
    $rhos = [];
    foreach ($xs as $x) {
        $m = array_sum($x) / count($x);
        $means[] = $m;
        $d = 0.0;
        $c = 0.0;
        foreach ($x as $i => $v) {
            $d += ($v - $m) ** 2;
            if ($i >= 16) {
                $num += ($v - $mean) * ($x[$i - 16] - $mean);
                $c += ($v - $m) * ($x[$i - 16] - $m);
                ++$pairs;
            }
        }
        $rhos[] = $d > 0.0 ? ($c / (count($x) - 16)) / ($d / count($x)) : NAN;
    }
    $sd = static function (array $v): float {
        $v = array_values(array_filter($v, static fn(float $x): bool => !is_nan($x)));
        $m = array_sum($v) / count($v);
        return sqrt(array_sum(array_map(static fn(float $x): float => ($x - $m) ** 2, $v)) / count($v));
    };
    printf("%s: seeds %d, quarters %d, mean %.6f (se %.6f), sd %.6f, term persistence %.3f (sd across seeds %.2f)\n",
        $lever, count($xs), $n, $mean, $sd($means) / sqrt(count($means)), sqrt($den / $n), ($num / $pairs) / ($den / $n), $sd($rhos));
}
