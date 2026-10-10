<?php
// php seed_path.php <seed> <years> <tpy>: quarterly gap, crisis drag, debt, rescue fields of the bare macro (BusinessCycleRealismTest's setup).
require dirname(__DIR__, 3) . '/tests/bootstrap.php';
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\{AssetMarketSubsystem, CommodityLogisticsSubsystem, CreditFiscalSubsystem, LaborMarketSubsystem, MacroAggregateSubsystem, MonetaryPolicySubsystem};
use App\Service\Math\MathUtility;
[$script, $seed, $years, $tpy] = $argv;
mt_srand((int) $seed);
$math = new MathUtility();
$redis = new class extends \Redis {
    private array $store = [];
    public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
    public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
};
$engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
    new MacroAggregateSubsystem($math), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
for ($i = 1; $i <= (int) $years * (int) $tpy; ++$i) {
    $m = $engine->updateMacroState(1.0 / (int) $tpy);
    if ($i % intdiv((int) $tpy, 4) === 0) {
        echo json_encode(['t' => round($i / $tpy, 2), 'gap' => round($m->outputGap, 4), 'drag' => round($m->creditCrisisDrag, 4), 'crisis_at' => $m->lastCreditCrisisAt,
            'debt' => round($m->sovereignNetDebtToGdp, 3), 'rescue' => round($m->bankRescueCostToGdp, 3), 'tax' => round($m->corporateTaxRate, 4), 'ebp' => round($m->excessBondPremium, 4)]) . "\n";
    }
}
