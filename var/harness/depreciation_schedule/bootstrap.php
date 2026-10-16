<?php

// In-memory Redis declared BEFORE the project bootstrap, so its do-nothing stub is never defined. Every
// Redis-backed service (industry ledger, macro state, agent books, order flow) then keeps real state.
class Redis
{
    public const PIPELINE = 2;
    public const MULTI = 1;

    /** @var array<string, mixed> */
    private array $s = [];
    private bool $queueing = false;
    /** @var list<array{0: string, 1: array<mixed>}> */
    private array $queue = [];
    /** @var array<string, int> */
    public static array $unknown = [];

    public function connect($host, $port = 6379, $timeout = 0.0, $reserved = null, $retry_interval = 0, $read_timeout = 0.0) { return true; }

    public function multi($mode = self::MULTI) { $this->queueing = true; $this->queue = []; return $this; }

    public function exec()
    {
        $this->queueing = false;
        $out = [];
        foreach ($this->queue as [$m, $a]) {
            $out[] = $this->run($m, $a);
        }
        $this->queue = [];
        return $out;
    }

    public function __call($m, $a)
    {
        if ($this->queueing) {
            $this->queue[] = [$m, $a];
            return $this;
        }
        return $this->run($m, $a);
    }

    public function get($k) { return $this->__call('get', [$k]); }
    public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { return $this->__call('set', [$key, $value]); }

    private function run(string $m, array $a): mixed
    {
        switch (strtolower($m)) {
            case 'get': return $this->s[$a[0]] ?? false;
            case 'set': $this->s[$a[0]] = $a[1]; return true;
            case 'setex': $this->s[$a[0]] = $a[2]; return true;
            case 'mget': return array_map(fn ($k) => $this->s[$k] ?? false, $a[0]);
            case 'del': foreach ((array) $a[0] as $k) { unset($this->s[$k]); } return 1;
            case 'hget': return $this->s[$a[0]][$a[1]] ?? false;
            case 'hset': $this->s[$a[0]][$a[1]] = $a[2]; return 1;
            case 'hmset': foreach ($a[1] as $f => $v) { $this->s[$a[0]][$f] = $v; } return true;
            case 'hgetall': return $this->s[$a[0]] ?? [];
            case 'hdel': unset($this->s[$a[0]][$a[1]]); return 1;
            case 'hincrbyfloat': $this->s[$a[0]][$a[1]] = (float) ($this->s[$a[0]][$a[1]] ?? 0) + (float) $a[2]; return $this->s[$a[0]][$a[1]];
            case 'lpush': $this->s[$a[0]] = $this->s[$a[0]] ?? []; array_unshift($this->s[$a[0]], $a[1]); return count($this->s[$a[0]]);
            case 'ltrim': $l = $this->s[$a[0]] ?? []; $end = $a[2] < 0 ? count($l) + $a[2] : $a[2]; $this->s[$a[0]] = array_slice($l, $a[1], $end - $a[1] + 1); return true;
            case 'lrange': $l = $this->s[$a[0]] ?? []; $end = $a[2] < 0 ? count($l) + $a[2] : $a[2]; return array_slice($l, $a[1], $end - $a[1] + 1);
            case 'lindex': $l = $this->s[$a[0]] ?? []; $i = $a[1] < 0 ? count($l) + $a[1] : $a[1]; return $l[$i] ?? false;
            case 'publish': return 0;
            case 'flushall': $this->s = []; return true;
            case 'expire': return true;
            case 'exists': return isset($this->s[$a[0]]) ? 1 : 0;
            case 'incr': $this->s[$a[0]] = (int) ($this->s[$a[0]] ?? 0) + 1; return $this->s[$a[0]];
        }
        self::$unknown[$m] = (self::$unknown[$m] ?? 0) + 1;
        return false;
    }
}

// The Diet draws from its own Mt19937 (config/services.yaml app.politics_draws), seeded from entropy unless POLITICS_SEED
// is set, which made two runs of one seed part at tick 1. Derive it from the run's SEED; hashed, since a stream seeded
// with the same integer as mt_srand() repeats the economy's draws.
if (getenv('POLITICS_SEED') === false) {
    $politicsSeed = (string) crc32('politics:' . (getenv('SEED') ?: '1'));
    putenv("POLITICS_SEED={$politicsSeed}");
    $_ENV['POLITICS_SEED'] = $_SERVER['POLITICS_SEED'] = $politicsSeed;
}

// ROOT=<tree> is the arm: a full checkout with its own real vendor/, so its composer autoloader resolves App\ to ITS
// src. Nothing from any other checkout is loaded.
if (!getenv('ROOT') || !is_file(getenv('ROOT') . '/vendor/autoload.php')) {
    fwrite(STDERR, "ROOT=<tree with vendor> required\n");
    exit(2);
}
define('HARNESS_PROJECT', rtrim((string) getenv('ROOT'), '/'));
require HARNESS_PROJECT . '/tests/bootstrap.php';

/** Filled by the OVR copy of EarningsEngine in calculateDepreciation(): ticker -> [utilization, seasonal factor, schedule D, charged D]. */
final class DepProbe
{
    /** @var array<string, list<float>> */
    public static array $q = [];
}

// OVR=<dir> holds probe copies of src files at their src-relative paths (made by make_ovr.sh from the arm's own file).
if (getenv('OVR')) {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'App\\')) {
            $file = getenv('OVR') . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }, true, true);
}

final class HarnessKernel extends \App\Kernel
{
    public function getProjectDir(): string { return HARNESS_PROJECT; }
    public function getCacheDir(): string { return __DIR__ . '/cache-' . (getenv('ARM') ?: 'x') . '/' . $this->environment; }
    public function getBuildDir(): string { return __DIR__ . '/cache-' . (getenv('ARM') ?: 'x') . '/' . $this->environment; }
    public function getLogDir(): string { return __DIR__ . '/log'; }
}
