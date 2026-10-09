<?php
// The market's forecast against what happened: the politics alone on an economy at trend, one tick a month.
// For every monthly forecast, each party's chance of leading the next government against whether it did, binned
// (calibration), the Brier score by months to the vote, and the forecast's cost in milliseconds.
// php forecast_calibration.php <fromSeed> <toSeed> <years> <out.jsonl>   (forecast_calibration.py reads the rows)
require dirname(__DIR__, 3) . '/tests/bootstrap.php';

use App\Data\Politics\AerieDiet;
use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Politics\CoalitionFormation;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\PoliticsState;

[$script, $fromSeed, $toSeed, $years, $out] = $argv;
$fh = fopen($out, 'w');
$redis = new class extends \Redis {
    private array $store = [];
    public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
    public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
};
$economy = static fn(float $t): MacroStateDTO => new MacroStateDTO(
    totalTime: $t,
    potentialGdpIndex: exp((MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + MacroEngine::TFP_DRIFT) * $t),
    gdpDeflator: exp(MacroEngine::TARGET_INFLATION * $t),
    sovereignDebtToGdp: 0.6,
);

$pending = [];   // forecasts awaiting their vote: [monthsOut, leaders]
$bins = array_fill(0, 10, [0.0, 0.0, 0]);
$brier = [];
$cost = [];
for ($seed = (int) $fromSeed; $seed <= (int) $toSeed; ++$seed) {
    $engine = new PoliticsEngine(MathUtility::ownStream($seed), $redis);
    $state = new PoliticsState();
    $pending = [];
    for ($month = 1; $month <= 12 * (int) $years; ++$month) {
        $t = $month / 12.0;
        $start = hrtime(true);
        $engine->advance($state, $economy($t), 1.0 / 12.0);
        $elapsed = (hrtime(true) - $start) / 1e6;
        if ($state->forecastAt === $state->totalTime && $state->forecastFor > $state->totalTime) {
            $cost[] = $elapsed;
            $pending[] = [(int) round(($state->forecastFor - $state->totalTime) * 12.0), $state->forecastLeaders];
        }
        // Score the term's forecasts once the government the vote produced has taken office.
        if ($state->lastGovernmentFormedAt === $state->totalTime && $state->lastElectionAt >= 0.0 && $state->totalTime - $state->lastElectionAt < 0.5 && $pending !== []) {
            $cabinet = AerieDiet::governingParties($state->governingCoalition);
            $leader = CoalitionFormation::leader($cabinet, CoalitionFormation::bySize($state->dietSeats, $state->dietVoteShares));
            foreach ($pending as [$monthsOut, $leaders]) {
                fwrite($fh, json_encode(['out' => $monthsOut, 'leaders' => $leaders, 'leader' => $leader]) . "\n");
                $score = 0.0;
                foreach (AerieDiet::PARTIES as $party) {
                    $p = $leaders[$party] ?? 0.0;
                    $hit = $party === $leader ? 1.0 : 0.0;
                    $bin = min(9, (int) floor($p * 10));
                    $bins[$bin][0] += $p;
                    $bins[$bin][1] += $hit;
                    $bins[$bin][2] += 1;
                    $score += ($p - $hit) ** 2;
                }
                $brier[$monthsOut][] = $score;
            }
            $pending = [];
        }
    }
}

echo "Calibration: chance given to a party of leading the next government, against how often it did\n";
foreach ($bins as $index => [$p, $hits, $n]) {
    if ($n > 0) {
        printf("  %.1f-%.1f  mean chance %.3f  led %.3f  n %d\n", $index / 10, ($index + 1) / 10, $p / $n, $hits / $n, $n);
    }
}
echo "Brier score (sum over parties) by months to the vote\n";
ksort($brier);
foreach ([1, 3, 6, 12, 24, 36, 47] as $out) {
    if (isset($brier[$out])) {
        printf("  %2d  %.3f  n %d\n", $out, array_sum($brier[$out]) / count($brier[$out]), count($brier[$out]));
    }
}
fwrite($fh, json_encode(['cost' => $cost]) . "\n");
sort($cost);
printf("Forecast tick cost: median %.1f ms, p90 %.1f ms, max %.1f ms (n %d)\n", $cost[intdiv(count($cost), 2)], $cost[(int) (0.9 * count($cost))], end($cost), count($cost));
