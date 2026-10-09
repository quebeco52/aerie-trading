<?php
// appointments.php <seeds> <years>: the Council and its three departments alone (no economy), weekly ticks; where the
// fund's mix sits over the century, how far the first chosen head moves it, and the money and banks leans for comparison.
require '/home/quebeco/Projects/Code/Private/aerie-trading/tests/bootstrap.php';
use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Politics\{CouncilAppointments as A, MonetaryAuthority as M, FinancialRegulator as R, SovereignReserveFund as F, PoliticsState};
[$script, $seeds, $years] = $argv;
$dt = 1.0 / 52.0;
$shares = []; $firstMoves = []; $labels = ['cautious' => 0, 'balanced' => 0, 'bold' => 0]; $heads = 0; $maj = [1 => 0, 0 => 0, -1 => 0]; $cmedChanges = 0; $n = 0;
for ($seed = 1; $seed <= (int) $seeds; ++$seed) {
    mt_srand($seed);
    $math = new MathUtility();
    $s = new PoliticsState();
    $first = null; $lastCmed = null;
    for ($t = 0.0; $t < (float) $years; $t += $dt) {
        $s->totalTime = $t;
        A::advance($s, $math);
        M::advance($s, new MacroStateDTO(totalTime: $t, policyRate: 0.03), $dt, $math);
        R::advance($s, $math);
        F::advance($s, $math);
        if (fmod($t, 0.25) < $dt) {
            $share = $s->fundHeadTermStart < 0.0 ? \App\Service\Macro\Subsystem\SovereignFundSubsystem::OPENING_POLICY_EQUITY_SHARE : F::equityShare($s->fundHeadStance);
            $shares[] = $share; ++$labels[F::stanceName($share)]; ++$maj[(int) $s->committeeMajority]; ++$n;
            $cmed = A::median($s->councilStances);
            if ($lastCmed !== null && $cmed !== $lastCmed) { ++$cmedChanges; }
            $lastCmed = $cmed;
            if ($first === null && $s->fundHeadTermStart >= 0.0) { $first = $share; $firstMoves[] = $share - \App\Service\Macro\Subsystem\SovereignFundSubsystem::OPENING_POLICY_EQUITY_SHARE; }
        }
    }
    $heads += 1 + (int) floor(((float) $years - F::OPENING_HEAD_TERM_END) / F::HEAD_TERM_YEARS) + 1;
}
sort($shares); sort($firstMoves);
$q = static fn(array $x, float $p): float => $x[(int) floor($p * (count($x) - 1))];
printf("fund equity share over %d seeds x %sy: mean %.3f, p5 %.3f, p50 %.3f, p95 %.3f\n", $seeds, $years, array_sum($shares) / count($shares), $q($shares, 0.05), $q($shares, 0.5), $q($shares, 0.95));
printf("labels: cautious %.3f balanced %.3f bold %.3f\n", $labels['cautious'] / $n, $labels['balanced'] / $n, $labels['bold'] / $n);
printf("first chosen head's move from the opening 70.7%%: mean %.3f, p10 %.3f, p50 %.3f, p90 %.3f; share that cut equities %.2f\n", array_sum($firstMoves) / count($firstMoves), $q($firstMoves, 0.1), $q($firstMoves, 0.5), $q($firstMoves, 0.9), count(array_filter($firstMoves, static fn($m) => $m < -0.005)) / count($firstMoves));
printf("money: committee hawkish %.3f dovish %.3f; Council money-median changes per century %.2f\n", $maj[1] / $n, $maj[-1] / $n, $cmedChanges / (int) $seeds * 100.0 / (float) $years);
