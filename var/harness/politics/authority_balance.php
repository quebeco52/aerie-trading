<?php
// The Monetary Authority's appointment chain alone (it never reads the economy): the committee's balance at each
// meeting over many games, its quartiles, the shares of meetings at or past them, how often the Council's median
// stance changes, and the appointees' ages and types.
require __DIR__ . '/../../../vendor/autoload.php';

use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Politics\MonetaryAuthority as A;
use App\Service\Politics\PoliticsState;

$seeds = (int) ($argv[1] ?? 64);
$years = (float) ($argv[2] ?? 200.0);
$burn = (float) ($argv[3] ?? 0.0);
$dt = 1.0 / A::MEETINGS_PER_YEAR;
$balances = [];
$flips = [];
$ages = [];
$types = ['hawk' => 0, 'swing' => 0, 'dove' => 0];
$govMatch = [0, 0];
foreach (range(1, $seeds) as $seed) {
    mt_srand($seed);
    $math = new MathUtility();
    $state = new PoliticsState();
    $state->totalTime = 0.0;
    A::advance($state, new MacroStateDTO(totalTime: 0.0, policyRate: 0.03), $dt, $math);
    $median = A::median($state->councilStances);
    $f = 0;
    $seen = [];
    for ($t = $dt; $t <= $years + 1e-9; $t += $dt) {
        $state->totalTime = $t;
        $before = $state->councilNames;
        $memBefore = $state->memberNames;
        $govBefore = $state->governorName;
        A::advance($state, new MacroStateDTO(totalTime: $t, policyRate: 0.03), $dt, $math);
        foreach ($state->councilNames as $s => $n) {
            if ($n !== ($before[$s] ?? null)) { $ages[] = $state->councilSince[$s] - $state->councilBirths[$s]; ++$types[A::stanceName($state->councilStances[$s])]; }
        }
        foreach ($state->memberNames as $s => $n) {
            if ($n !== ($memBefore[$s] ?? null)) {
                $ages[] = $state->memberSince[$s] - $state->memberBirths[$s];
                ++$types[A::stanceName($state->memberStances[$s])];
                if ($state->governorStance === -1.0) { ++$govMatch[1]; $govMatch[0] += $state->memberStances[$s] === -1.0 ? 1 : 0; }
            }
        }
        if ($state->governorName !== $govBefore) { $ages[] = $state->governorTermStart - $state->governorBirth; ++$types[A::stanceName($state->governorStance)]; }
        if ($t >= $burn) { $balances[] = $state->committeeBalance; }
        $m = A::median($state->councilStances);
        if ($m !== $median) { ++$f; $median = $m; }
    }
    $flips[] = $f * 100.0 / $years;
}
sort($balances);
$n = count($balances);
$q = static fn(float $p): float => $balances[(int) floor($p * ($n - 1))];
$dist = array_count_values(array_map(static fn(float $b): string => number_format($b, 3), $balances));
ksort($dist, SORT_NUMERIC);
printf("meetings %d  balance mean %.3f  P25 %.2f  P50 %.2f  P75 %.2f\n", $n, array_sum($balances) / $n, $q(0.25), $q(0.5), $q(0.75));
foreach ($dist as $b => $c) { printf("  %5s %6.2f%%\n", $b, 100 * $c / $n); }
$atOrBelow = static fn(float $x): float => count(array_filter($balances, static fn(float $b): bool => $b <= $x + 1e-9)) / $n;
$atOrAbove = static fn(float $x): float => count(array_filter($balances, static fn(float $b): bool => $b >= $x - 1e-9)) / $n;
printf("share <= P25 %.4f  share >= P75 %.4f\n", $atOrBelow($q(0.25)), $atOrAbove($q(0.75)));
printf("share <= %.2f (const) %.4f  share >= %.2f (const) %.4f\n", A::DOVISH_MAJORITY_STANCE, $atOrBelow(A::DOVISH_MAJORITY_STANCE), A::HAWKISH_MAJORITY_STANCE, $atOrAbove(A::HAWKISH_MAJORITY_STANCE));
printf("council median changes per century %.2f\n", array_sum($flips) / count($flips));
sort($ages);
$na = count($ages);
$mean = array_sum($ages) / $na;
printf("appointees %d  age mean %.2f sd %.2f min %.1f P10 %.1f P50 %.1f P90 %.1f max %.1f\n", $na, $mean, sqrt(array_sum(array_map(static fn($a) => ($a - $mean) ** 2, $ages)) / $na), $ages[0], $ages[(int) (0.1 * $na)], $ages[(int) (0.5 * $na)], $ages[(int) (0.9 * $na)], $ages[$na - 1]);
$tt = array_sum($types);
printf("appointee types hawk %.3f swing %.3f dove %.3f\n", $types['hawk'] / $tt, $types['swing'] / $tt, $types['dove'] / $tt);
printf("dovish governor's seats going to doves %.3f (n %d)\n", $govMatch[1] ? $govMatch[0] / $govMatch[1] : 0, $govMatch[1]);
