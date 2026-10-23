<?php
// The engine's output response to a legislated corporate tax cut, against Mertens & Ravn (2013): a 1 point cut in the
// average corporate income tax rate raises GDP 0.4% after a quarter and 0.6% at the peak, a year on. Macro only, no
// fund, no politics. Per seed, two runs on the same draws; after the burn-in the second has the Diet's shift cut by
// SHOCK and the rate stepped with it (the rate's own adjustment would otherwise phase the cut in).
// php tax_irf.php <seedFrom> <seedTo> <burnYears> <horizonYears> <tpy> <shock> <out>
require '/home/quebeco/Projects/Code/Private/aerie-trading/tests/bootstrap.php';
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
[$script, $seedFrom, $seedTo, $burn, $horizon, $tpy, $shock, $out] = $argv;
$tpy = (int) $tpy; $shock = (float) $shock;
$fh = fopen($out, 'w');
for ($seed = (int) $seedFrom; $seed <= (int) $seedTo; ++$seed) {
    $paths = [];
    foreach (['base' => 0.0, 'cut' => $shock] as $arm => $size) {
        mt_srand($seed);
        $math = new MathUtility();
        $engine = MacroEngine::headless($math);
        $economy = new MacroState();
        for ($i = 0; $i < (int) $burn * $tpy; ++$i) {
            $engine->step($economy, 1.0 / $tpy);
        }
        if ($size !== 0.0) {
            $lever = getenv('LEVER') ?: 'tax';
            if ($lever === 'tax') {
                $economy->corporateTaxPolicyShift -= $size;
                $economy->corporateTaxRate -= $size;
            } elseif ($lever === 'tariff') {
                // As a budget round enacts one: the duty, and the productivity loss it brings.
                $loss = -MacroConstants::TARIFF_OUTPUT_LOSS * $size;
                $economy->importTariffRate += $size;
                $economy->tfpShockLevel += $loss;
                $economy->totalFactorProductivityIndex *= exp($loss);
            } else {
                $economy->laborForceGrowthRate += $size;
            }
        }
        $rows = [];
        for ($i = 1; $i <= (int) $horizon * $tpy; ++$i) {
            $m = $engine->step($economy, 1.0 / $tpy);
            if ($i % intdiv($tpy, 4) === 0) {
                $rows[] = ['gap' => $m->outputGap, 'tax' => $m->corporateTaxRate, 'rate' => $m->policyRate, 'infl' => $m->inflation, 'debt' => $m->sovereignDebtToGdp, 'pot' => $m->potentialGdpIndex, 'defl' => $m->gdpDeflator, 'cpi' => $m->inflation, 'nx' => $m->netExportGap, 'rstar' => $m->naturalRate, 'house' => $m->residentialPropertyIndex, 'imp' => $m->importPriceLevel];
            }
        }
        $paths[$arm] = $rows;
    }
    fwrite($fh, json_encode(['seed' => $seed, 'paths' => $paths]) . "\n");
}
