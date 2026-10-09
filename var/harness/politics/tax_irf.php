<?php
// The engine's output response to a legislated corporate tax cut, against Mertens & Ravn (2013): a 1 point cut in the
// average corporate income tax rate raises GDP 0.4% after a quarter and 0.6% at the peak, a year on. Macro only, no
// fund, no politics. Per seed, two runs on the same draws; after the burn-in the second has the Diet's shift cut by
// SHOCK and the rate stepped with it (the rate's own adjustment would otherwise phase the cut in).
// php tax_irf.php <seedFrom> <seedTo> <burnYears> <horizonYears> <tpy> <shock> <out>
require '/home/quebeco/Projects/Code/Private/aerie-trading/tests/bootstrap.php';
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\{AssetMarketSubsystem, CommodityLogisticsSubsystem, CreditFiscalSubsystem, LaborMarketSubsystem, MacroAggregateSubsystem, MonetaryPolicySubsystem};
use App\Service\Math\MathUtility;
[$script, $seedFrom, $seedTo, $burn, $horizon, $tpy, $shock, $out] = $argv;
$tpy = (int) $tpy; $shock = (float) $shock;
$fh = fopen($out, 'w');
for ($seed = (int) $seedFrom; $seed <= (int) $seedTo; ++$seed) {
    $paths = [];
    foreach (['base' => 0.0, 'cut' => $shock] as $arm => $size) {
        mt_srand($seed);
        $math = new MathUtility();
        $redis = new class extends \Redis {
            public array $store = [];
            public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
        };
        $engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
            new MacroAggregateSubsystem($math), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
        for ($i = 0; $i < (int) $burn * $tpy; ++$i) {
            $engine->updateMacroState(1.0 / $tpy);
        }
        if ($size !== 0.0) {
            $state = json_decode($redis->store[MacroEngine::REDIS_MACRO_STATE], true);
            if (!array_key_exists('corporate_tax_policy_shift', $state)) { fwrite(STDERR, "no shift key\n"); exit(1); }
            $lever = getenv('LEVER') ?: 'tax';
            if ($lever === 'tax') {
                $state['corporate_tax_policy_shift'] -= $size;
                $state['corporate_tax_rate'] -= $size;
            } elseif ($lever === 'tariff') {
                // As a budget round enacts one: the duty, and the productivity loss it brings.
                $loss = -MacroEngine::TARIFF_OUTPUT_LOSS * $size;
                $state['import_tariff_rate'] += $size;
                $state['tfp_shock_level'] += $loss;
                $state['total_factor_productivity_index'] *= exp($loss);
            } else {
                $state['labor_force_growth_rate'] += $size;
            }
            $redis->store[MacroEngine::REDIS_MACRO_STATE] = json_encode($state, JSON_PRESERVE_ZERO_FRACTION);
        }
        $rows = [];
        for ($i = 1; $i <= (int) $horizon * $tpy; ++$i) {
            $m = $engine->updateMacroState(1.0 / $tpy);
            if ($i % intdiv($tpy, 4) === 0) {
                $rows[] = ['gap' => $m->outputGap, 'tax' => $m->corporateTaxRate, 'rate' => $m->policyRate, 'infl' => $m->inflation, 'debt' => $m->sovereignDebtToGdp, 'pot' => $m->potentialGdpIndex, 'defl' => $m->gdpDeflator, 'cpi' => $m->inflation, 'nx' => $m->netExportGap, 'rstar' => $m->naturalRate, 'house' => $m->residentialPropertyIndex, 'imp' => $m->importPriceLevel];
            }
        }
        $paths[$arm] = $rows;
    }
    fwrite($fh, json_encode(['seed' => $seed, 'paths' => $paths]) . "\n");
}
