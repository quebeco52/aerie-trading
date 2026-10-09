<?php
// Every recorded Diet's talks re-run with its blocs replaced by pre-electoral pacts drawn on Golder's (2006) random-effects
// probit (Table 1 Probit 1, replication log), against the fixed Nordic blocs and against no blocs at all.
// php pact_replay.php <draws per Diet> <out.jsonl> <in.jsonl...>
// Arms: fixed = today's blocs and the leaders' rivalry; none = no pacts, no rivalry; g0 / g2 / g4 = Golder pacts at the
// Diet's effective threshold (one 300-seat district: 50/301 + 50/600 = 0.25%) and at 2% / 4% legal thresholds, which
// move only the pact propensity here, not the seats.
require '/home/quebeco/Projects/Code/Private/aerie-trading/tests/bootstrap.php';
// Composer prepends its loader, so the harness copy goes in front of it after the bootstrap.
spl_autoload_register(static function (string $class): void {
    if ($class === 'App\Service\Macro\Subsystem\CoalitionFormation') {
        require __DIR__ . '/ovr/Service/Macro/Subsystem/CoalitionFormation.php';
    }
}, true, true);

use App\Data\Politics\AerieDiet as D;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\CoalitionFormation as F;
use App\Service\Math\MathUtility;

const G = ['incompat' => -0.0065865, 'polar' => -0.0028364, 'thr' => 0.0198085, 'polar_thr' => 0.0004788, 'seat' => 0.0509584,
    'seat2' => -0.0005531, 'asym' => -0.0956167, 'asym_seat' => -0.0283965, 'cons' => -2.41666];
const SIGMA_U = 0.7396898;
const THRESHOLDS = ['g0' => 50.0 / 301.0 + 50.0 / 600.0, 'g2' => 2.0, 'g4' => 4.0];

$draws = (int) $argv[1];
$out = fopen($argv[2], 'w');
$bySeed = [];
foreach (array_slice($argv, 3) as $file) {
    foreach (file($file) as $line) {
        $d = json_decode($line, true);
        if (empty($d['fall'])) {
            $bySeed[$d['s']][] = $d;
        }
    }
}

$k = MacroEngine::FORMATION_MANIFESTO_POINTS_PER_UNIT;
$distance = static function (string $a, string $b, array $positions) use ($k): float {
    $pa = D::position($a, $positions);
    $pb = D::position($b, $positions);
    $sq = 0.0;
    foreach (D::AXES as $axis) {
        $sq += ($pa[$axis] - $pb[$axis]) ** 2;
    }

    return $k * sqrt($sq);
};
$rng = MathUtility::ownStream(20061);

foreach ($bySeed as $seed => $rows) {
    usort($rows, static fn(array $x, array $y): int => $x['t'] <=> $y['t']);
    $lag = D::SEED_SEATS;
    foreach ($rows as $d) {
        $positions = $d['positions'];
        // Polarization: the largest party on each side of the size-of-state question, by the seats going into the vote.
        $left = $right = null;
        foreach (D::PARTIES as $p) {
            $state = D::position($p, $positions)[D::AXIS_STATE];
            if ($state > 0.0 && ($left === null || $lag[$p] > $lag[$left])) {
                $left = $p;
            }
            if ($state < 0.0 && ($right === null || $lag[$p] > $lag[$right])) {
                $right = $p;
            }
        }
        $polar = ($left !== null && $right !== null) ? $distance($left, $right, $positions) : 0.0;
        $total = array_sum($lag);
        $dyads = [];
        foreach (D::PARTIES as $i => $a) {
            foreach (array_slice(D::PARTIES, $i + 1) as $b) {
                $s1 = $lag[$a] / $total;
                $s2 = $lag[$b] / $total;
                $seat = 100.0 * ($s1 + $s2);
                $asym = ($s1 + $s2) > 0.0 ? abs($s1 - $s2) / ($s1 + $s2) : 0.0;
                $dyads[] = [$a, $b, G['cons'] + G['incompat'] * $distance($a, $b, $positions) + G['polar'] * $polar
                    + G['seat'] * $seat + G['seat2'] * $seat * $seat + G['asym'] * $asym + G['asym_seat'] * $asym * $seat];
            }
        }

        for ($r = 0; $r < $draws; ++$r) {
            $u = SIGMA_U * $rng->generateStandardNormal();
            $eps = array_map(static fn(): float => $rng->generateStandardNormal(), $dyads);
            $talkSeed = $rng->generateUniform() * 2147483647;
            $arms = ['fixed' => null, 'none' => array_combine(D::PARTIES, D::PARTIES)];
            $pacts = [];
            foreach (THRESHOLDS as $arm => $thr) {
                $edges = [];
                foreach ($dyads as $j => [$a, $b, $xb]) {
                    if ($xb + G['thr'] * $thr + G['polar_thr'] * $polar * $thr + $u + $eps[$j] > 0.0) {
                        $edges[] = [$a, $b];
                    }
                }
                // A bloc is every party the pacts join, pact by pact (union-find).
                $root = array_combine(D::PARTIES, D::PARTIES);
                $find = static function (string $p) use (&$root, &$find): string {
                    return $root[$p] === $p ? $p : ($root[$p] = $find($root[$p]));
                };
                foreach ($edges as [$a, $b]) {
                    $root[$find($a)] = $find($b);
                }
                $arms[$arm] = array_combine(D::PARTIES, array_map($find, D::PARTIES));
                $pacts[$arm] = $edges;
            }
            foreach ($arms as $arm => $blocs) {
                F::$pactBlocs = $blocs;
                $talks = F::talks($d['seats'], $d['shares'], $positions, $d['outgoing'], MathUtility::ownStream((int) $talkSeed));
                fwrite($out, json_encode([
                    's' => $seed, 't' => $d['t'], 'r' => $r, 'arm' => $arm, 'seats' => $d['seats'], 'polar' => round($polar, 1),
                    'pacts' => $pacts[$arm] ?? null, 'blocs' => $blocs ?? F::blocs($positions),
                    'cabinet' => $talks['cabinet'], 'support' => $talks['support'], 'days' => round($talks['days'], 1), 'attempts' => count($talks['log']),
                ]) . "\n");
            }
            F::$pactBlocs = null;
        }
        $lag = $d['seats'];
    }
}
