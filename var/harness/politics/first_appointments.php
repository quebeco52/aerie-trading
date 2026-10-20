<?php
// Each department's first appointment after Year 1 against the stance its opening head ran: the share of games where
// the Council's first pick stands more than 0.05 above or below the opening policy (stances run -1 to +1; the fund's
// are the seven mixes on record, so a pick next to the opening one is no move), and the mean move, the Council and the
// departments run as the politics engine runs them (they never read the economy).
// Usage: php first_appointments.php [games=200]
declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\Department\FairTradingBoard;
use App\Service\Politics\Department\FinancialRegulator;
use App\Service\Politics\Department\MonetaryAuthority;
use App\Service\Politics\Department\Office;
use App\Service\Politics\Department\SecuritiesCommission;
use App\Service\Politics\PoliticsState;

$games = (int) ($argv[1] ?? 200);
$offices = [Office::Regulator, Office::FundHead, Office::ExchequerHead, Office::FairTradingHead, Office::SecuritiesHead];
$above = array_fill_keys(array_map(static fn(Office $o): string => $o->value, $offices), 0);
$below = $above;
$shift = array_fill_keys(array_keys($above), 0.0);
$dt = 1.0 / 12.0;
foreach (range(1, $games) as $seed) {
    mt_srand($seed);
    $math = new MathUtility();
    $state = new PoliticsState();
    $opening = [];
    $done = [];
    for ($t = 0.0; $t <= 6.0 + 1e-9; $t += $dt) {
        $state->totalTime = $t;
        CouncilAppointments::advance($state, $math);
        MonetaryAuthority::advance($state, new MacroStateDTO(totalTime: $t, policyRate: 0.03), $dt);
        FinancialRegulator::advance($state);
        Office::FundHead->fill($state);
        Office::ExchequerHead->fill($state);
        FairTradingBoard::advance($state);
        SecuritiesCommission::advance($state);
        foreach ($offices as $office) {
            $head = $state->head($office);
            $opening[$office->value] ??= $head['stance'];
            if (!isset($done[$office->value]) && $head['appointedAt'] >= 0.0) {
                $done[$office->value] = true;
                $move = $head['stance'] - $opening[$office->value];
                $above[$office->value] += $move > 0.05 ? 1 : 0;
                $below[$office->value] += $move < -0.05 ? 1 : 0;
                $shift[$office->value] += $move;
            }
        }
    }
}
foreach ($above as $office => $count) {
    printf("%-14s first pick over 0.05 above the opening stance %5.1f%%  below %5.1f%%  mean move %+.3f\n", $office, 100 * $count / $games, 100 * $below[$office] / $games, $shift[$office] / $games);
}
