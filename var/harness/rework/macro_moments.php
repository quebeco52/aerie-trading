<?php
// Macro + politics loop for the 2026-10 rework: one JSON line per seed of quarterly series (analyse.py pools them).
// php macro_moments.php <seed> <years> <out.jsonl> [fund=0|1]. Fields absent from older code read as null, so the
// same script runs on a baseline worktree.
require dirname(__DIR__, 3) . '/tests/bootstrap.php';

use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\{AssetMarketSubsystem, CommodityLogisticsSubsystem, CreditFiscalSubsystem, LaborMarketSubsystem, MacroAggregateSubsystem, MonetaryPolicySubsystem, SovereignFundSubsystem};
use App\Service\Math\MathUtility;
use App\Service\Politics\PoliticsEngine;

[$script, $seed, $years, $out] = $argv;
$fund = ($argv[4] ?? '0') === '1';
$seed = (int) $seed;
$tpy = 48;
mt_srand($seed);
$math = new MathUtility();
$redis = new class extends \Redis {
    private array $store = [];
    public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
    public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
};
$engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
    new MacroAggregateSubsystem($math), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math),
    sovereignFundSubsystem: $fund ? new SovereignFundSubsystem($math) : null);
$politics = new PoliticsEngine(MathUtility::ownStream($seed), $redis);
$policy = $politics->liveState()->policy();
$fields = ['outputGap', 'unemploymentRate', 'inflation', 'supercoreInflation', 'policyRate', 'yield2y', 'yield10y',
    'sovereignDebtToGdp', 'sovereignNetFinancialLiabilitiesToGdp', 'sovereignRiskSpread', 'excessBondPremium',
    'interbankLiquiditySpread', 'consumerSentimentIndex', 'moneySupplyGrowth', 'householdDebtServiceRatio',
    'tradeBalanceToGdp', 'netExportGap', 'netTaxRate', 'sahmRecessionIndicator', 'recessionDeclared', 'lastCreditCrisisAt',
    'potentialGdpIndex', 'debtTarget', 'policySurprise'];
$series = array_fill_keys($fields, []);
$nominal = 1.0;
for ($i = 1; $i <= (int) $years * $tpy; ++$i) {
    $cap = $fund ? SovereignFundSubsystem::BOARD_OPENING_CAP_USD * $nominal : null;
    $macro = $engine->updateMacroState(1.0 / $tpy, equityMarketCap: $cap, boardFloatCap: $cap === null ? null : 0.8 * $cap, policy: $policy);
    $nominal = $macro->nominalGdpIndex;
    $policy = $politics->updatePolitics($macro, 1.0 / $tpy)->policy();
    if ($i % ($tpy / 4) === 0) {
        foreach ($fields as $f) {
            $v = property_exists($macro, $f) ? $macro->$f : null;
            $series[$f][] = is_bool($v) ? (int) $v : $v;
        }
    }
}
file_put_contents($out, json_encode(['seed' => $seed, 'fund' => $fund] + $series) . "\n");
