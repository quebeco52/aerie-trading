<?php
// One give-in episode on the macro engine alone, written so the same script runs on every commit since the pressure
// mechanics went in (22d4bb9): the policy DTO is built by reflection from whatever levers the state carries, so a
// lever added later neither breaks an old tree nor changes it. Quarterly rows as JSON lines, the fields episode_an.py reads.
// php episode_gate.php <tree root> <seed> <years> <tpy> <out>; CONCEDE_QUARTERS (unset: length drawn on
// PoliticalPressure::CONTINUATION per seed, as gate.php's concede arm; 0: no episode), STEP_AT (10).
[$script, $root, $seed, $years, $tpy, $out] = $argv;
require $root . '/tests/bootstrap.php';
use App\DTO\GovernmentPolicyDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\{AssetMarketSubsystem, CommodityLogisticsSubsystem, CreditFiscalSubsystem, LaborMarketSubsystem, MacroAggregateSubsystem, MonetaryPolicySubsystem};
use App\Service\Math\MathUtility;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\PoliticalPressure;

$seed = (int) $seed;
$tpy = (int) $tpy;
$at = (float) (getenv('STEP_AT') ?: 10.0);
$fixed = getenv('CONCEDE_QUARTERS');
mt_srand($seed);
$math = new MathUtility();
$redis = new class extends \Redis {
    private array $store = [];
    public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
    public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
};
$engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
    new MacroAggregateSubsystem($math), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
$required = array_map(fn(\ReflectionParameter $p) => $p->getName(),
    array_filter((new \ReflectionClass(GovernmentPolicyDTO::class))->getConstructor()->getParameters(), fn($p) => !$p->isOptional()));

// App\Service\Math\TimeSeries::crossedSimulatedBoundary at a quarter, inlined: the class is newer than the first trees.
$crossedQuarter = static function (float $t, float $dt): bool {
    $epsilon = $dt / 0.25 / 2.0;
    $index = (int) floor($t / 0.25 + $epsilon);

    return $index >= 1 && $index > (int) floor(max(0.0, $t - $dt) / 0.25 + $epsilon);
};
$fh = fopen($out, 'w');
$policy = null;
$conceding = null;
$perQuarter = intdiv($tpy, 4);
for ($i = 1; $i <= (int) $years * $tpy; ++$i) {
    $m = $engine->updateMacroState(1.0 / $tpy, policy: $policy);
    $quarter = (int) round($m->totalTime * 4.0);
    if ($fixed !== false) {
        $conceding = $m->totalTime >= $at && $m->totalTime < $at + ((int) $fixed) / 4.0;
    } elseif ($m->totalTime >= $at && $conceding === null) {
        $conceding = true;
    } elseif ($conceding === true && $crossedQuarter($m->totalTime, 1.0 / $tpy)
        && MathUtility::hashedUniform($seed, "concede:{$quarter}") >= PoliticalPressure::CONTINUATION) {
        $conceding = false;
    }
    $args = [];
    foreach ($required as $name) {
        $args[$name] = $m->{$name};
    }
    $policy = new GovernmentPolicyDTO(...($args + ['authorityConcession' => $conceding === true ? 1.0 : 0.0]));
    if ($i % $perQuarter === 0) {
        fwrite($fh, json_encode([
            's' => $seed, 't' => round($m->totalTime, 3), 'rate' => $m->policyRate, 'infl' => $m->inflation, 'be' => $m->tipsBreakeven,
            'anchor' => $m->inflationAnchorDrift, 'defl' => $m->gdpDeflator, 'gap' => $m->outputGap, 'unemp' => $m->unemploymentRate,
            'conc' => $conceding === true ? 1.0 : 0.0,
        ]) . "\n");
    }
}
fclose($fh);
