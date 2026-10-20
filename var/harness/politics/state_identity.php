<?php
// Politics state identity across a refactor: the macro engine and the Diet as gate.php's `on` arm runs them, one JSON
// line per seed-year of the raw PoliticsState::toArray() the engine keeps, and the page and headline arrays the
// politics feed at the first tick, year 20 and the last year.
//
//   php state_identity.php run <seedFrom> <seedTo> <years> <tpy> <states.jsonl> <pages.jsonl>
//   php state_identity.php compare <head_states.jsonl> <tree_states.jsonl> <head_pages.jsonl> <tree_pages.jsonl>
//
// `compare` maps HEAD's per-head fields (governorName, ..., lastStateGuardCommanderAppointedAt) into the `heads` map,
// then demands every line match exactly, floats included; under the tree's autoload it also checks that
// PoliticsState::fromArray() reads each HEAD line into the tree's line byte for byte. Pages must match byte for byte.
require __DIR__ . '/../../../tests/bootstrap.php';

use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\{AssetMarketSubsystem, CommodityLogisticsSubsystem, CreditFiscalSubsystem, LaborMarketSubsystem, MacroAggregateSubsystem, MonetaryPolicySubsystem, SovereignFundSubsystem};
use App\Service\Math\MathUtility;
use App\Service\News\SystemicEventReporter;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\PoliticsState;
use App\Service\View\{DepartmentPageBuilder, GovernmentPageBuilder, RegulatorPageBuilder, SovereignReservePageBuilder};

const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;
// HEAD's field prefix for each office, and the office's key in `heads`; the last three held no stance.
const OFFICES = [
    'governor' => ['governor', 'Governor', true], 'regulator' => ['regulator', 'Regulator', true],
    'fund' => ['fundHead', 'FundHead', true], 'exchequer' => ['exchequerHead', 'ExchequerHead', true],
    'fair-trading' => ['fairTradingHead', 'FairTradingHead', true], 'securities' => ['securitiesHead', 'SecuritiesHead', true],
    'security' => ['securityDirector', 'SecurityDirector', false], 'coast-guard' => ['coastGuardCommandant', 'CoastGuardCommandant', false],
    'state-guard' => ['stateGuardCommander', 'StateGuardCommander', false],
];

/** A test double from PHPUnit's generator, for the page builders' repositories. */
function stub(string $type): object
{
    static $maker = null;
    $maker ??= new class('stub') extends \PHPUnit\Framework\TestCase {
        public static function make(string $type): object
        {
            return self::createStub($type);
        }
    };

    return $maker::make($type);
}

/** @return array<string, mixed> Every page and headline context the politics feed. */
function pages(MacroStateDTO $macro, PoliticsStateDTO $politics): array
{
    $departments = new DepartmentPageBuilder();
    $government = new GovernmentPageBuilder(stub(\App\Repository\DietElectionRepository::class));
    $news = [];
    foreach (['authorityContext', 'leaderContext', 'fundHeadContext', 'exchequerHeadContext', 'fairTradingHeadContext', 'securitiesHeadContext', 'securityDirectorContext', 'guardCommandersContext', 'regulatorContext'] as $method) {
        if (method_exists(SystemicEventReporter::class, $method)) {
            $reflection = new \ReflectionMethod(SystemicEventReporter::class, $method);
            $news[$method] = $reflection->invoke(null, ...($reflection->getNumberOfParameters() === 2 ? [$macro, $politics] : [$politics]));
        }
    }

    return [
        'government' => [
            'hub' => $government->buildHub($macro, $politics),
            'diet' => $government->buildDiet($macro, $politics),
            'election' => $government->buildElection($macro, $politics),
            'budget' => $government->buildBudget($macro, $politics),
            'council' => $government->buildCouncil($macro, $politics),
            'authority' => $government->buildAuthority($macro, $politics),
        ],
        'department' => [
            'fundHead' => $departments->fundHead($politics, $macro),
            'tradeOffice' => $departments->tradeOffice($politics, $macro),
            'exchequer' => $departments->exchequer($politics, $macro),
            'fairTrading' => $departments->fairTrading($politics),
            'securities' => $departments->securities($politics, $macro),
            'securityBureau' => $departments->securityBureau($politics),
            'coastGuard' => $departments->coastGuard($politics),
            'stateGuard' => $departments->stateGuard($politics),
            'regulator' => $departments->regulator($politics, $macro),
        ],
        'regulator' => (new RegulatorPageBuilder($departments, stub(\Doctrine\ORM\EntityManagerInterface::class)))->build($macro, $politics),
        'reserve' => (new SovereignReservePageBuilder(stub(\App\Repository\StockRepository::class), new SovereignFundSubsystem(new MathUtility())))->build($macro, $politics),
        'news' => $news,
        'policy' => get_object_vars($politics->policy()),
    ];
}

function run(int $seedFrom, int $seedTo, int $years, int $tpy, string $statesOut, string $pagesOut): void
{
    $states = fopen($statesOut, 'w');
    $pageLines = fopen($pagesOut, 'w');
    for ($seed = $seedFrom; $seed <= $seedTo; ++$seed) {
        mt_srand($seed);
        $math = new MathUtility();
        $redis = new class extends \Redis {
            /** @var array<string, string> */
            public array $store = [];
            public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
        };
        $politics = new PoliticsEngine(MathUtility::ownStream($seed), $redis);
        $engine = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
            new MacroAggregateSubsystem($math), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
        $policy = $politics->liveState()->policy();
        for ($i = 1; $i <= $years * $tpy; ++$i) {
            $macro = $engine->updateMacroState(1.0 / $tpy, policy: $policy);
            $p = $politics->updatePolitics($macro, 1.0 / $tpy);
            $policy = $p->policy();
            if ($i % $tpy === 0) {
                fwrite($states, '{"s":' . $seed . ',"y":' . intdiv($i, $tpy) . ',"state":' . $redis->store[PoliticsEngine::REDIS_POLITICS_STATE] . "}\n");
            }
            if ($i === 1 || $i === intdiv($years, 2) * $tpy || $i === $years * $tpy) {
                fwrite($pageLines, json_encode(['s' => $seed, 'tick' => $i, 'pages' => pages($macro, $p)], JSON_FLAGS) . "\n");
            }
        }
        fflush($states);
        fflush($pageLines);
    }
}

/** HEAD's per-head fields folded into `heads`, keyed by office in the tree's order. */
function mapHead(array $state): array
{
    $heads = [];
    foreach (OFFICES as $office => [$prefix, $capital, $hasStance]) {
        $heads[$office] = [
            'name' => $state["{$prefix}Name"],
            'birth' => $state["{$prefix}Birth"],
            'termStart' => $state["{$prefix}TermStart"],
            'stance' => $hasStance ? $state["{$prefix}Stance"] : 0.0,
            'passedOver' => $state["{$prefix}PassedOver"],
            'appointedAt' => $state["last{$capital}AppointedAt"],
        ];
        unset($state["{$prefix}Name"], $state["{$prefix}Birth"], $state["{$prefix}TermStart"], $state["{$prefix}Stance"], $state["{$prefix}PassedOver"], $state["last{$capital}AppointedAt"]);
    }

    return $state + ['heads' => $heads];
}

/** Keys sorted at every level, so two payloads compare by content alone. */
function canonical(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (!array_is_list($value)) {
        ksort($value, SORT_STRING);
    }

    return array_map('canonical', $value);
}

function compare(string $headStates, string $treeStates, string $headPages, string $treePages): int
{
    $head = file($headStates, FILE_IGNORE_NEW_LINES);
    $tree = file($treeStates, FILE_IGNORE_NEW_LINES);
    $mismatches = $shim = 0;
    $shimChecked = method_exists(PoliticsState::class, 'head');
    printf("state lines: head %d, tree %d\n", count($head), count($tree));
    foreach ($head as $n => $line) {
        $h = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $t = json_decode($tree[$n] ?? 'null', true, 512, JSON_THROW_ON_ERROR);
        $expected = json_encode(canonical(['s' => $h['s'], 'y' => $h['y'], 'state' => mapHead($h['state'])]), JSON_FLAGS);
        $actual = json_encode(canonical($t), JSON_FLAGS);
        if ($expected !== $actual) {
            ++$mismatches;
            if ($mismatches <= 3) {
                $e = canonical(mapHead($h['state']));
                $a = canonical($t['state'] ?? []);
                $keys = array_unique([...array_keys($e), ...array_keys($a)]);
                $diff = array_values(array_filter($keys, static fn($k) => json_encode($e[$k] ?? null, JSON_FLAGS) !== json_encode($a[$k] ?? null, JSON_FLAGS)));
                printf("  mismatch seed %d year %d: %s\n", $h['s'], $h['y'], implode(', ', array_slice($diff, 0, 10)));
            }
        }
        if ($shimChecked) {
            // The tree's own reading of the HEAD payload, written back out, against the tree's payload byte for byte.
            $raw = substr($tree[$n], strpos($tree[$n], '"state":') + 8, -1);
            if (json_encode(PoliticsState::fromArray($h['state'])->toArray(), JSON_FLAGS) !== $raw) {
                ++$shim;
            }
        }
    }
    printf("state mismatches: %d of %d%s\n", $mismatches + abs(count($head) - count($tree)), max(count($head), count($tree)), $shimChecked ? sprintf('; HEAD lines read through fromArray() unlike the tree: %d', $shim) : '');

    $hp = file($headPages, FILE_IGNORE_NEW_LINES);
    $tp = file($treePages, FILE_IGNORE_NEW_LINES);
    $pageMismatches = abs(count($hp) - count($tp));
    foreach ($hp as $n => $line) {
        if ($line !== ($tp[$n] ?? null)) {
            ++$pageMismatches;
            $h = json_decode($line, true);
            $t = json_decode($tp[$n] ?? 'null', true);
            foreach ($h['pages'] as $group => $pages) {
                foreach (is_array($pages) ? $pages : [] as $page => $content) {
                    if (json_encode($content, JSON_FLAGS) !== json_encode($t['pages'][$group][$page] ?? null, JSON_FLAGS)) {
                        printf("  page mismatch seed %d tick %d: %s.%s\n", $h['s'], $h['tick'], $group, $page);
                    }
                }
            }
        }
    }
    printf("page lines: %d (%s bytes); mismatches: %d\n", count($hp), number_format(array_sum(array_map('strlen', $hp))), $pageMismatches);

    return $mismatches + $shim + $pageMismatches > 0 ? 1 : 0;
}

$mode = $argv[1] ?? '';
if ($mode === 'run') {
    run((int) $argv[2], (int) $argv[3], (int) $argv[4], (int) $argv[5], $argv[6], $argv[7]);
} elseif ($mode === 'compare') {
    exit(compare($argv[2], $argv[3], $argv[4], $argv[5]));
} else {
    fwrite(STDERR, "usage: state_identity.php run|compare ...\n");
    exit(2);
}
