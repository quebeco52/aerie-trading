<?php
// Long-run averages of the laws the market prices beyond the next term: every lever in force, sampled each quarter
// after the first term, on the full macro loop (no fund) with the politics beside it.
// php lever_means.php <seedFrom> <seedTo> <years> <out.jsonl>: one line per seed, each lever's quarterly series (lever_means.py pools them).
require dirname(__DIR__, 3) . '/tests/bootstrap.php';

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Math\MathUtility;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\Policy\Lever;

[$script, $seedFrom, $seedTo, $years, $out] = $argv;
$tpy = 48;
$fh = fopen($out, 'a');
for ($seed = (int) $seedFrom; $seed <= (int) $seedTo; ++$seed) {
    $series = array_fill_keys(array_column(Lever::cases(), 'value'), []);
    mt_srand($seed);
    $math = new MathUtility();
    $redis = new class extends \Redis {
        private array $store = [];
        public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
        public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
    };
    $engine = MacroEngine::headless($math);
    $economy = new MacroState();
    $politics = new PoliticsEngine(MathUtility::ownStream($seed), $redis);
    $policy = $politics->liveState()->policy();
    for ($i = 1; $i <= (int) $years * $tpy; ++$i) {
        $macro = $engine->step($economy, 1.0 / $tpy, policy: $policy);
        $m = $politics->updatePolitics($macro, 1.0 / $tpy);
        $policy = $m->policy();
        if ($i > 4 * $tpy && $i % 12 === 0) {
            foreach (Lever::fields() as $lever => $field) {
                $series[$lever][] = $m->$field;
            }
        }
    }
    fwrite($fh, json_encode(['seed' => $seed] + $series) . "\n");
    fflush($fh);
}
