<?php
// Debug probe: election, formation and budget timestamps around ticks 715-725 of seed 1 at 180 ticks/year.
// php var/harness/politics/dbg.php
require dirname(__DIR__, 3) . '/tests/bootstrap.php';

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Math\MathUtility;
use App\Service\Politics\PoliticsEngine;

mt_srand(1);
$math = new MathUtility();
$redis = new class extends \Redis { private array $store = []; public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; } public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; } };
$engine = MacroEngine::headless($math);
$economy = new MacroState();
$politics = new PoliticsEngine(MathUtility::ownStream(1), $redis);
$policy = $politics->liveState()->policy();
for ($i = 1; $i <= 730; ++$i) {
    $m = $engine->step($economy, 1.0 / 180, policy: $policy);
    $p = $politics->updatePolitics($m, 1.0 / 180);
    $policy = $p->policy();
    if ($i >= 715 && $i <= 725) printf("%d t=%.12f elect=%.12f formed=%.12f budget=%.12f shift=%+.4f debt=%.4f\n", $i, $m->totalTime, $p->lastElectionAt, $p->coalitionFormedAt, $p->lastBudgetEnactedAt, $m->corporateTaxPolicyShift, $m->sovereignDebtToGdp);
}
