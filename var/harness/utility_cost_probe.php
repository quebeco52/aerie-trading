<?php
// Old vs new utility cost ratio, quarter by quarter over a recorded macro path, each model carrying its own momentum.
require '/home/quebeco/Projects/Code/Private/aerie-trading/vendor/autoload.php';
require __DIR__ . '/OldUtilityBusinessModel.php';
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\OldUtilityBusinessModel;
use App\Service\Model\Sector\UtilityBusinessModel;
$path = unserialize(zlib_decode(file_get_contents($argv[1])));
$quiet = new class extends MathUtility { public function generateStandardNormal(): float { return 0.0; } };
$models = ['old' => new OldUtilityBusinessModel(), 'new' => new UtilityBusinessModel()];
$stocks = [];
foreach ($models as $k => $m) { $stocks[$k] = new Stock(); $stocks[$k]->setTicker($argv[2] ?? 'BIRD'); $stocks[$k]->setBeta('0.5'); }
$acc = ['old' => [], 'new' => []];
for ($i = 89; $i < count($path); $i += 90) {
    $macro = $path[$i];
    foreach ($models as $k => $m) {
        $r = $m->computeActualFinancials($stocks[$k], 100.0, 0.45, 20.0, 0.10, $macro, $quiet);
        $acc[$k][] = [$r->clampedMargin, $r->actualRevenue, $r->ebit];
        $stocks[$k]->setEarningsMomentumZ($r->streamZ);
    }
    if ($i % 720 === 89) {
        printf("t %5.2f gas %6.1f power %6.1f | cost ratio old %.4f new %.4f | ebit old %6.2f new %6.2f\n", $macro->totalTime, $macro->naturalGasPriceIndexEma, $macro->wholesalePowerPriceIndexEma, end($acc['old'])[0], end($acc['new'])[0], end($acc['old'])[2], end($acc['new'])[2]);
    }
}
foreach ($acc as $k => $rows) {
    printf("%s: mean cost ratio %.4f | mean ebit %.2f\n", $k, array_sum(array_column($rows, 0)) / count($rows), array_sum(array_column($rows, 2)) / count($rows));
}
