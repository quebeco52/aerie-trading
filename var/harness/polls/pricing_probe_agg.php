<?php
// Pools the per-seed runs of pricing_probe_seeds.php: |log move| p95 and max pooled over seeds (pricing_probe.php's
// quantile, sorted |x| at index floor(0.95 n)), the per-seed p95 and max across seeds, and a seed bootstrap of the
// pooled p95 at 3 seeds (the recorded run's size) and at all seeds.
// php pricing_probe_agg.php <dir with seed*.json> [first seeds to check against pricing_probe.out, e.g. 3]
[$script, $dir] = $argv;
$check = (int) ($argv[2] ?? 0);
$runs = [];
foreach (glob($dir . '/seed*.json') as $file) {
    $run = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $runs[$run['seed']] = $run;
}
ksort($runs);
$seeds = array_keys($runs);
$n = count($seeds);
$abs = static fn(array $xs): array => array_map('abs', $xs);
$p95 = static function (array $xs): float {
    $a = array_map('abs', $xs);
    sort($a);

    return $a[(int) (0.95 * count($a))];
};
$max = static fn(array $xs): float => max(array_map('abs', $xs));
$mean = static fn(array $xs): float => array_sum($xs) / count($xs);
$sd = static function (array $xs) use ($mean): float {
    $m = $mean($xs);

    return sqrt(array_sum(array_map(static fn(float $x): float => ($x - $m) ** 2, $xs)) / max(1, count($xs) - 1));
};
$series = static fn(array $run, string $kind, string $name): array => array_column($run['moves'][$kind], $name);
$pool = static function (array $which, string $kind, string $name) use ($runs, $series): array {
    $xs = [];
    foreach ($which as $s) {
        array_push($xs, ...$series($runs[$s], $kind, $name));
    }

    return $xs;
};

printf("seeds %s (n %d), %d years each, one tick a month, moves after year 4\n", implode(',', $seeds), $n, $runs[$seeds[0]]['years']);
printf("seconds per seed: mean %.0f, range %.0f-%.0f\n\n", $mean(array_column($runs, 'seconds')), min(array_column($runs, 'seconds')), max(array_column($runs, 'seconds')));

if ($check > 0) {
    echo "Reproduction of pricing_probe.out (seeds 1-$check pooled):\n";
    foreach (['poll', 'vote', 'office'] as $kind) {
        foreach (['miner law', 'broker law', 'bank law'] as $name) {
            $xs = $pool(range(1, $check), $kind, $name);
            printf("  %-6s %-10s n %4d  p95|.| %6.3f%%  max|.| %6.3f%%\n", $kind, $name, count($xs), 100 * $p95($xs), 100 * $max($xs));
        }
    }
    echo "\n";
}

mt_srand(20261005);
$boot = static function (array $which, int $k, string $kind, string $name, int $draws) use ($pool, $p95, $sd): float {
    $vals = [];
    for ($b = 0; $b < $draws; ++$b) {
        $pick = [];
        for ($i = 0; $i < $k; ++$i) {
            $pick[] = $which[mt_rand(0, count($which) - 1)];
        }
        $vals[] = $p95($pool($pick, $kind, $name));
    }

    return $sd($vals);
};

echo "|log move|, the firm's own law alone (tax part removed); percent\n";
printf("%-6s %-10s %6s %8s %8s | %-28s | %-24s | %-14s\n", 'news', 'firm', 'n', 'pool p95', 'pool max', 'per-seed p95 mean (sd) range', 'per-seed max med range', 'boot sd p95 3/n');
foreach (['poll', 'vote', 'office'] as $kind) {
    foreach (['miner law', 'broker law', 'bank law'] as $name) {
        $all = $pool($seeds, $kind, $name);
        $perP95 = [];
        $perMax = [];
        foreach ($seeds as $s) {
            $perP95[] = $p95($series($runs[$s], $kind, $name));
            $perMax[] = $max($series($runs[$s], $kind, $name));
        }
        $sortedMax = $perMax;
        sort($sortedMax);
        printf(
            "%-6s %-10s %6d %8.3f %8.3f | %5.3f (%5.3f) %5.3f-%5.3f | %5.2f %5.2f-%6.2f | %5.3f / %5.3f\n",
            $kind, $name, count($all), 100 * $p95($all), 100 * $max($all),
            100 * $mean($perP95), 100 * $sd($perP95), 100 * min($perP95), 100 * max($perP95),
            100 * $sortedMax[intdiv($n, 2)], 100 * min($perMax), 100 * max($perMax),
            100 * $boot($seeds, 3, $kind, $name, 400), 100 * $boot($seeds, $n, $kind, $name, 400),
        );
    }
}

echo "\nPer-seed broker law (percent): seed | poll p95 max | vote p95 max\n";
foreach ($seeds as $s) {
    printf("  %2d | %5.3f %6.3f | %6.3f %6.3f\n", $s,
        100 * $p95($series($runs[$s], 'poll', 'broker law')), 100 * $max($series($runs[$s], 'poll', 'broker law')),
        100 * $p95($series($runs[$s], 'vote', 'broker law')), 100 * $max($series($runs[$s], 'vote', 'broker law')));
}
