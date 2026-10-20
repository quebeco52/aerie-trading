<?php
// Replays recorded Diets (formation/final_*.jsonl rows: seats, shares, positions, blocs, outgoing cabinet) through the
// formation logit: for each Diet where one bloc holds a majority, the probability the talks' logit puts in office a
// cabinet led from the other bloc, with the mandate term (option E) and without it. Also counts how often that
// happened in the recorded runs. Usage: php formation_replay.php [glob=formation/final_*.jsonl]
declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use App\Data\Politics\AerieDiet;
use App\Service\Politics\Diet\CoalitionFormation as F;

$glob = $argv[1] ?? __DIR__ . '/formation/final_*.jsonl';
$rows = 0;
$majorityDiets = 0;
$withE = 0.0;
$withoutE = 0.0;
$realised = 0;
foreach (glob($glob) as $file) {
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $row = json_decode($line, true);
        if (!is_array($row) || !isset($row['seats'], $row['blocs'], $row['positions'])) {
            continue;
        }
        ++$rows;
        $seats = array_map('floatval', $row['seats']);
        $blocs = $row['blocs'];
        $majority = null;
        foreach (array_unique($blocs) as $bloc) {
            if (F::coalitionSeats(array_keys($blocs, $bloc, true), $seats) >= AerieDiet::MAJORITY_SEATS) {
                $majority = $bloc;
            }
        }
        if ($majority === null) {
            continue;
        }
        ++$majorityDiets;
        $order = F::bySize($seats, $row['shares'] ?? []);
        $options = F::options($seats, $row['positions'], $blocs);
        $utilities = F::utilities($options, $seats, $row['positions'], $row['outgoing'] ?? [], $order[0], $blocs);
        $against = array_map(static fn(array $o): bool => F::againstTheMandate($o['cabinet'], $seats, $blocs), $options);
        // Without E: the mandate term taken back out where it alone applied (both leaders never share a government,
        // as neither may support a cabinet holding the other).
        $old = array_map(static fn(float $u, bool $a): float => $a ? $u - F::FORMATION_ANTIPACT_UTILITY : $u, $utilities, $against);
        foreach ([[$utilities, &$withE], [$old, &$withoutE]] as [$u, &$sum]) {
            $max = max($u);
            $weights = array_map(static fn(float $x): float => exp($x - $max), $u);
            $total = array_sum($weights);
            foreach ($weights as $i => $w) {
                $sum += $against[$i] ? $w / $total : 0.0;
            }
        }
        unset($sum);
        $cabinet = $row['cabinet'] ?? [];
        if ($cabinet !== [] && F::againstTheMandate(array_values($cabinet), $seats, $blocs)) {
            ++$realised;
        }
    }
}
printf("rows %d  majority-bloc Diets %d\n", $rows, $majorityDiets);
printf("PM from the losing bloc, expected: without E %.2f%%  with E %.2f%%   realised in the recorded runs %.2f%%\n", 100 * $withoutE / $majorityDiets, 100 * $withE / $majorityDiets, 100 * $realised / $majorityDiets);
