<?php
// The rate committee's supermajority over many games, the Council and the Authority run as the politics engine runs
// them (they never read the economy): the share of meetings dovish / neither / hawkish, and the balance's spread.
// Usage: php committee_split.php [seeds=100] [years=60]
declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\Department\MonetaryAuthority as A;
use App\Service\Politics\PoliticsState;

$seeds = (int) ($argv[1] ?? 100);
$years = (float) ($argv[2] ?? 60.0);
$dt = 1.0 / A::MEETINGS_PER_YEAR;
$counts = ['-1' => 0, '0' => 0, '1' => 0];
$balances = [];
$flips = 0;
foreach (range(1, $seeds) as $seed) {
    mt_srand($seed);
    $math = new MathUtility();
    $state = new PoliticsState();
    for ($t = 0.0; $t <= $years + 1e-9; $t += $dt) {
        $state->totalTime = $t;
        $before = $state->committeeMajority;
        CouncilAppointments::advance($state, $math);
        A::advance($state, new MacroStateDTO(totalTime: $t, policyRate: 0.03), $dt, $math);
        ++$counts[(string) (int) $state->committeeMajority];
        $balances[] = round($state->committeeBalance * 7.0);
        $flips += $t > 0.0 && $state->committeeMajority !== $before ? 1 : 0;
    }
}
$n = array_sum($counts);
printf("meetings %d  dovish %.1f%%  neither %.1f%%  hawkish %.1f%%  flips per decade %.2f\n", $n, 100 * $counts['-1'] / $n, 100 * $counts['0'] / $n, 100 * $counts['1'] / $n, 10.0 * $flips / ($seeds * $years));
$dist = array_count_values(array_map(static fn(float $b): string => sprintf('%+d/7', (int) $b), $balances));
ksort($dist);
foreach ($dist as $b => $c) {
    printf("  %6s %5.1f%%\n", $b, 100 * $c / $n);
}
