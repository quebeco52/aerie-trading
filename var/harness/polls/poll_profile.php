<?php
// The game's own polls against the record: the politics alone on an economy at trend, one tick a month.
// 1. Each published poll's error on the next result, (poll - vote)^2 / p(1 - p) in points^2, by months before the vote
//    (Jennings & Wlezien 2018: ~30 in the final days, ~80 at a month, ~110 at six, ~210 three years out; fit_campaign.out,
//    jw_poll_errors.out).
// 2. The cabinet's polls against its result, by month of the term (Germany 1998-2025: -0.7 at 1, -2.7 at 6, -7.7 at 12, flat
//    after; de_poll_dynamics.out), as a share of month 36.
// php poll_profile.php <seeds> <years>
require dirname(__DIR__, 3) . '/tests/bootstrap.php';

use App\Data\Politics\AerieDiet;
use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\PoliticsState;

[$script, $seeds, $years] = $argv;
$redis = new class extends \Redis {
    private array $store = [];
    public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
    public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
};
$economy = static fn(float $t): MacroStateDTO => new MacroStateDTO(
    totalTime: $t,
    potentialGdpIndex: exp((MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + MacroEngine::TFP_DRIFT) * $t),
    gdpDeflator: exp(MacroEngine::TARGET_INFLATION * $t),
);

$errors = [];      // months out => list of z2
$cabinetGap = [];  // month of term => list of gap (points)
for ($seed = 1; $seed <= (int) $seeds; ++$seed) {
    $engine = new PoliticsEngine(MathUtility::ownStream($seed), $redis);
    $state = new PoliticsState();
    $termPolls = [];   // [t, shares]
    $result = null;
    $resultAt = null;
    $cabinet = null;
    for ($month = 1; $month <= 12 * (int) $years; ++$month) {
        $t = $month / 12.0;
        $engine->advance($state, $economy($t), 1.0 / 12.0);
        if ($state->lastElectionAt === $state->totalTime) {
            // Every poll of the term just ended against this result.
            foreach ($termPolls as [$at, $shares]) {
                $out = (int) round(($state->totalTime - $at) * 12.0);
                foreach (AerieDiet::PARTIES as $party) {
                    $p = $state->dietVoteShares[$party];
                    if ($p >= 0.03) {
                        $errors[$out][] = 1e4 * (($shares[$party] - $p) ** 2) / ($p * (1 - $p));
                    }
                }
            }
            $termPolls = [];
            $result = $state->dietVoteShares;
            $resultAt = $state->totalTime;
            $cabinet = null;
            continue;
        }
        if ($state->polls !== [] && end($state->polls)['t'] === $state->totalTime) {
            $poll = end($state->polls)['shares'];
            $termPolls[] = [$state->totalTime, $poll];
            if ($result !== null) {
                // The cabinet the vote produced, once it has taken office.
                if ($cabinet === null && $state->coalitionTakesOfficeAt < 0.0) {
                    $cabinet = AerieDiet::governingParties($state->governingCoalition);
                }
                if ($cabinet !== null) {
                    $m = (int) round(($state->totalTime - $resultAt) * 12.0);
                    $gap = 0.0;
                    foreach ($cabinet as $party) {
                        $gap += $poll[$party] - $result[$party];
                    }
                    $cabinetGap[$m][] = 100 * $gap;
                }
            }
        }
    }
}

$mean = static fn(array $xs): float => array_sum($xs) / count($xs);
echo "1. Poll error on the result, (poll - vote)^2 / p(1-p) x 1e4, parties of 3% and over\n";
echo "   months out   z2      n\n";
foreach ([1, 2, 3, 6, 9, 12, 18, 24, 30, 36, 42, 47] as $out) {
    if (isset($errors[$out])) {
        printf("   %3d        %6.1f  %6d\n", $out, $mean($errors[$out]), count($errors[$out]));
    }
}
echo "2. The cabinet's polls against its result (points), by month of the term; share of month 36\n";
$base = isset($cabinetGap[36]) ? $mean($cabinetGap[36]) : 1.0;
foreach ([1, 2, 3, 6, 9, 12, 18, 24, 30, 36, 42, 47] as $m) {
    if (isset($cabinetGap[$m])) {
        $g = $mean($cabinetGap[$m]);
        printf("   %3d   %+6.2f  share %5.2f  n %d\n", $m, $g, $g / $base, count($cabinetGap[$m]));
    }
}
