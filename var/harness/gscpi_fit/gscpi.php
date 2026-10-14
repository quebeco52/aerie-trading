<?php
// Macro loop alone (no board, no fund, no equity-wealth loop), politics beside it: one row a simulated month with the
// GSCPI inputs, the candidate demand gaps, the index and the inflation guards.
// php gscpi.php <seed> <years> <tpy> <out.jsonl>. BASE = another tree (HEAD), OVR = dir of src-relative class copies.
require (getenv('BASE') ?: dirname(__DIR__, 3)) . '/tests/bootstrap.php';
if (getenv('BASE')) {
    spl_autoload_register(static function (string $class): void {
        $file = getenv('BASE') . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (str_starts_with($class, 'App\\') && is_file($file)) { require $file; }
    }, true, true);
}
if (getenv('OVR')) {
    spl_autoload_register(static function (string $class): void {
        $file = getenv('OVR') . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (str_starts_with($class, 'App\\') && is_file($file)) { require $file; }
    }, true, true);
}

use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\{AssetMarketSubsystem, CommodityLogisticsSubsystem, CreditFiscalSubsystem, LaborMarketSubsystem, MacroAggregateSubsystem, MonetaryPolicySubsystem};
use App\Service\Math\MathUtility;
use App\Service\Politics\PoliticsEngine;

[$script, $seed, $years, $tpy, $out] = $argv;
$tpy = (int) $tpy;
mt_srand((int) $seed);
$math = new MathUtility();
$redis = new class extends \Redis {
    private array $store = [];
    public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
    public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
};
$engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
    new MacroAggregateSubsystem($math), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
$politics = new PoliticsEngine(MathUtility::ownStream((int) $seed), $redis);
$policy = $politics->liveState()->policy();
$hasDelivery = method_exists(CommodityLogisticsSubsystem::class, 'shippingTightness');
$fh = fopen($out, 'w');
$month = intdiv($tpy, 12);
for ($i = 1; $i <= (int) round((float) $years * $tpy); ++$i) {
    $m = $engine->updateMacroState(1.0 / $tpy, policy: $policy);
    $policy = $politics->updatePolitics($m, 1.0 / $tpy)->policy();
    if ($i % $month === 0) {
        $row = ['t' => $i / $tpy, 'fr' => $m->freightRateIndexEma, 'frr' => $m->freightRateIndex, 'inv' => $m->inventoryStockGapEma, 'invr' => $m->inventoryStockGap,
            'cu' => $m->capacityUtilizationRate, 'og' => $m->outputGapEma, 'gg' => $m->globalDemandGapEma, 'dg' => $m->domesticDemandGapEma,
            'sc' => $m->supplyChainPressureIndex, 'sce' => $m->supplyChainPressureIndexEma, 'infl' => $m->inflation,
            'cg' => $m->coreGoodsInflation, 'ppi' => $m->producerPriceInflation, 'gap' => $m->outputGap];
        if ($hasDelivery) {
            $row['del'] = $m->supplierDeliveryTimesEma;
            $row['delr'] = $m->supplierDeliveryTimes;
            $row['tight'] = CommodityLogisticsSubsystem::shippingTightness($engine->getLiveState());
        }
        fwrite($fh, json_encode($row) . "\n");
    }
}
