<?php
// Quoted vs expectation breakeven: macro + politics loop (no equity cap, so no equity-wealth loop).
// php breakeven_quote.php <seed> <years> <tpy> <out.jsonl>: one line per quarter of quarterly averages.
require dirname(__DIR__, 3) . '/tests/bootstrap.php';

use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Recorder\OutputGapProbe;
use App\Service\Macro\Subsystem\{AssetMarketSubsystem, CommodityLogisticsSubsystem, CreditFiscalSubsystem, LaborMarketSubsystem, MacroAggregateSubsystem, MonetaryPolicySubsystem};
use App\Service\Math\MathUtility;
use App\Service\Politics\PoliticsEngine;

[$script, $seed, $years, $tpy, $out] = $argv;
$seed = (int) $seed; $tpy = (int) $tpy; $q = intdiv($tpy, 4);
mt_srand($seed);
$math = new MathUtility();
$redis = new class extends \Redis {
    private array $store = [];
    public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
    public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
};
$engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
    new MacroAggregateSubsystem($math, new OutputGapProbe()), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
$politics = new PoliticsEngine(MathUtility::ownStream($seed), $redis);
$policy = $politics->liveState()->policy();
$fields = ['tipsBreakeven', 'tipsBreakevenQuoted', 'excessBondPremium', 'highYieldCreditSpread', 'macroCreditSpread', 'energyPriceIndex', 'outputGap'];
$acc = array_fill_keys($fields, 0.0);
$fh = fopen($out, 'w');
for ($i = 1; $i <= (int) $years * $tpy; ++$i) {
    $m = $engine->updateMacroState(1.0 / $tpy, policy: $policy);
    $policy = $politics->updatePolitics($m, 1.0 / $tpy)->policy();
    foreach ($fields as $f) { $acc[$f] += $m->$f; }
    if ($i % $q === 0) {
        $row = ['seed' => $seed, 't' => $i / $tpy];
        foreach ($fields as $f) { $row[$f] = $acc[$f] / $q; $acc[$f] = 0.0; }
        fwrite($fh, json_encode($row) . "\n");
    }
}
fclose($fh);
