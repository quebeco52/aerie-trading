<?php
// floor_sim.php: the engine's idiosyncratic variance recursion alone (strip -> floor -> QE step -> variance jump), with
// and without the 0.2 sigma^2 floor, to see how far the floor lifts the state's mean above theta.
// php floor_sim.php  (prints one line per case)
declare(strict_types=1);
require __DIR__ . '/../../../vendor/autoload.php';

use App\Service\Market\Pricing\MarketEngine;
use App\Service\Math\MathUtility;

$mu = new MathUtility();
$dt = 1.0 / 360;
$years = 400;
// name, sigma, beta, lambda, jumpVol, theta (measured time-mean from the run)
$cases = [['PHIL', 0.15, 0.77, 0.75, 0.09, 0.0044], ['LARK', 0.12, 0.67, 0.75, 0.09, 0.0021], ['CORM', 0.10, 0.70, 0.75, 0.09, 0.0008], ['DIPR', 0.30, 0.48, 0.75, 0.09, 0.0559]];
foreach ($cases as [$tk, $sig, $beta, $lambda, $jv, $theta]) {
    foreach ([true, false] as $useFloor) {
        mt_srand(7);
        $kappa = MarketEngine::varianceReversionSpeed($lambda, MarketEngine::BASE_VARIANCE_REVERSION_SPEED);
        $jp = MarketEngine::calibratedJumpParameters($sig, $beta, $lambda, $jv);
        $floor = 0.2 * $sig * $sig;
        $v = $theta;
        $sum = 0.0;
        $n = (int) ($years / $dt);
        $atFloor = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($useFloor && $v < $floor) {
                $v = $floor;
                $atFloor++;
            }
            $sum += $v;
            $j = $mu->calculateSVJJJumps(lambda: $lambda, pUp: 0.40, etaUp: $jp['eta_up'], etaDown: $jp['eta_down'], muV: max(0.0, $v) * 0.5, dt: $dt);
            $v = $mu->calculateQEVarianceStep(currentVar: $v, theta: $theta, kappa: $kappa, sigma: 0.3, dt: $dt) + $j['var_jump'];
        }
        printf("%-5s floor=%-3s theta %.4f floor %.4f mean state %.4f (x%.2f theta) clamped %.0f%% of ticks\n", $tk, $useFloor ? 'on' : 'off', $theta, $floor, $sum / $n, $sum / $n / $theta, 100 * $atFloor / $n);
    }
}
