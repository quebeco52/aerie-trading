<?php
require '/home/quebeco/Projects/Code/Private/aerie-trading/tests/bootstrap.php';
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\{AssetMarketSubsystem, CommodityLogisticsSubsystem, CreditFiscalSubsystem, DistrictPoliticsSubsystem, LaborMarketSubsystem, MacroAggregateSubsystem, MonetaryPolicySubsystem};
use App\Service\Math\MathUtility;
mt_srand(1);
$math = new MathUtility();
$redis = new class extends \Redis { private array $store = []; public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; } public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; } };
$engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(), new MacroAggregateSubsystem($math), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math), politicsSubsystem: new DistrictPoliticsSubsystem(MathUtility::ownStream(1)));
for ($i = 1; $i <= 730; ++$i) {
    $m = $engine->updateMacroState(1.0 / 180);
    if ($i >= 715 && $i <= 725) printf("%d t=%.12f elect=%.12f formed=%.12f budget=%.12f shift=%+.4f debt=%.4f\n", $i, $m->totalTime, $m->lastElectionAt, $m->coalitionFormedAt, $m->lastBudgetEnactedAt, $m->corporateTaxPolicyShift, $m->sovereignDebtToGdp);
}
