<?php
// Each recorded Diet's cabinets as CoalitionFormation weighs them, split so the fit can price any anti-system strength
// and bar exactly: the log-odds without the anti-system term, the cabinet's distance from the Council median, what kind
// of cabinet it is, its members (a bit per party, in AerieDiet::PARTIES order) and its share of the seats; and each
// party's seat share and rank by size, for the checks on who leads the government.
// php formation_iv.php <in.jsonl...> > out.jsonl
require '/home/quebeco/Projects/Code/Private/aerie-trading/tests/bootstrap.php';
use App\Data\Politics\AerieDiet as D;
use App\Service\Politics\CoalitionFormation as F;
foreach (array_slice($argv, 1) as $file) {
    foreach (file($file) as $line) {
        $d = json_decode($line, true);
        if (!empty($d['fall'])) {
            continue;
        }
        $order = F::bySize($d['seats'], $d['shares']);
        if ($d['seats'][$order[0]] >= D::MAJORITY_SEATS) {
            echo json_encode(['s' => $d['s'], 't' => $d['t'], 'majority' => true]) . "\n";
            continue;
        }
        $median = F::councilMedian($d['seats'], $d['positions']);
        $keys = ['base', 'challenge', 'minority', 'largest', 'grand', 'sq', 'single', 'chartists', 'common_lot', 'members', 'cabinetShare'];
        $total = array_sum($d['seats']);
        $rows = array_fill_keys($keys, []);
        $outgoing = $d['outgoing'];
        sort($outgoing);
        $options = F::options($d['seats'], $d['positions'], $d['blocs'] ?? D::SEED_BLOCS);
        foreach ($options as $o) {
            $challenge = 0.0;
            foreach ($o['cabinet'] as $p) {
                $challenge += abs(D::position($p, $d['positions'])[D::AXIS_COUNCIL] - $median);
            }
            $sorted = $o['cabinet'];
            sort($sorted);
            $rows['base'][] = F::utility($o['cabinet'], $o['support'], $d['seats'], $d['positions'], $d['outgoing'], $order[0], $d['blocs'] ?? D::SEED_BLOCS) - (F::FORMATION_ANTISYSTEM_UTILITY * $challenge);
            $rows['challenge'][] = $challenge;
            $rows['minority'][] = $o['support'] !== [];
            $rows['largest'][] = in_array($order[0], $o['cabinet'], true);
            $rows['grand'][] = in_array($order[0], $o['cabinet'], true) && in_array($order[1], $o['cabinet'], true);
            $rows['sq'][] = $sorted === $outgoing;
            $rows['single'][] = count($o['cabinet']) === 1;
            $rows['chartists'][] = in_array(D::CHARTISTS, $o['cabinet'], true);
            $rows['common_lot'][] = in_array(D::COMMON_LOT, $o['cabinet'], true);
            $rows['members'][] = array_sum(array_map(static fn(string $p): int => 1 << array_search($p, D::PARTIES, true), $o['cabinet']));
            $rows['cabinetShare'][] = F::coalitionSeats($o['cabinet'], $d['seats']) / $total;
        }
        $rows['partyShare'] = array_map(static fn(string $p): float => ($d['seats'][$p] ?? 0) / $total, D::PARTIES);
        $rows['rank'] = array_map(static fn(string $p): int => array_search($p, $order, true) + 1, D::PARTIES);
        echo json_encode(['s' => $d['s'], 't' => $d['t'], 'majority' => false] + $rows) . "\n";
    }
}
