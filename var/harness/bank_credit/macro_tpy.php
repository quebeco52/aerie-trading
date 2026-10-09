<?php
// Timestep check of the household default rate, macro loop only (no board, so no equity market cap: the
// equity-wealth loop is off). BASE=<tree> runs another tree's src (vendor symlinked) ahead of this checkout's.
// php macro_tpy.php <tpy> <seedFrom> <seedTo> <years> <out.jsonl>: one line per seed, quarterly samples.
$base = getenv('BASE') ?: dirname(__DIR__, 3);
require $base . '/tests/bootstrap.php';
if (getenv('BASE')) {
    spl_autoload_register(static function (string $class) use ($base): void {
        if (str_starts_with($class, 'App\\')) {
            $file = $base . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }, true, true);
}

use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\{AssetMarketSubsystem, CommodityLogisticsSubsystem, CreditFiscalSubsystem, LaborMarketSubsystem, MacroAggregateSubsystem, MonetaryPolicySubsystem};
use App\Service\Math\MathUtility;

[$script, $tpy, $seedFrom, $seedTo, $years, $out] = $argv;
$tpy = (int) $tpy;
$fh = fopen($out, 'a');
for ($seed = (int) $seedFrom; $seed <= (int) $seedTo; ++$seed) {
    mt_srand($seed);
    $math = new MathUtility();
    $redis = new class extends \Redis {
        private array $store = [];
        public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
        public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
    };
    $engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
        new MacroAggregateSubsystem($math), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
    $q = ['rdr' => [], 'rdr_raw' => [], 'cdr' => [], 'resgap' => [], 'ugap' => []];
    $nonfinite = 0;
    $perQ = intdiv($tpy, 4);
    for ($i = 1; $i <= (int) $years * $tpy; ++$i) {
        $m = $engine->updateMacroState(1.0 / $tpy);
        if ($i % $perQ === 0) {
            $q['rdr'][] = $m->retailDefaultRateEma;
            $q['rdr_raw'][] = $m->retailDefaultRate;
            $q['cdr'][] = $m->corporateDefaultRateEma;
            $q['resgap'][] = $m->residentialWealthTrend > 0 && $m->residentialPropertyIndexEma > 0 ? log($m->residentialPropertyIndexEma / $m->residentialWealthTrend) : null;
            $q['ugap'][] = $m->unemploymentRateEma - $m->nairu;
            $nonfinite += is_finite($m->retailDefaultRateEma) ? 0 : 1;
        }
    }
    fwrite($fh, json_encode(['seed' => $seed, 'tpy' => $tpy, 'years' => (int) $years, 'nonfinite' => $nonfinite] + $q) . "\n");
    fflush($fh);
}
