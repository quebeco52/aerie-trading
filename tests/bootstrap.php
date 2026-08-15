<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Bypass missing php-redis extension in CLI for tests
if (!class_exists('Redis')) {
    class Redis {
        public function connect($host, $port = 6379, $timeout = 0.0, $reserved = null, $retry_interval = 0, $read_timeout = 0.0) {}
        public function get($key) {}
        public function set($key, $val) {}
        public function setex($key, $ttl, $val) {}
        public function del($key) {}
        public function lPush($key, $value) {}
        public function lTrim($key, $start, $stop) {}
        public function lRange($key, $start, $end) { return []; }
    }
}

// Polyfill missing bcmath extension in CLI for tests
if (!function_exists('bcadd')) {
    function bcadd(string $num1, string $num2, int $scale = 0): string {
        return number_format((float) $num1 + (float) $num2, $scale, '.', '');
    }
}
if (!function_exists('bcsub')) {
    function bcsub(string $num1, string $num2, int $scale = 0): string {
        return number_format((float) $num1 - (float) $num2, $scale, '.', '');
    }
}
if (!function_exists('bcmul')) {
    function bcmul(string $num1, string $num2, int $scale = 0): string {
        return number_format((float) $num1 * (float) $num2, $scale, '.', '');
    }
}
if (!function_exists('bcdiv')) {
    function bcdiv(string $num1, string $num2, int $scale = 0): string {
        $denom = (float) $num2;
        if ($denom === 0.0) {
            throw new \DivisionByZeroError('Division by zero in bcdiv');
        }
        return number_format((float) $num1 / $denom, $scale, '.', '');
    }
}
if (!function_exists('bccomp')) {
    function bccomp(string $num1, string $num2, int $scale = 0): int {
        $f1 = round((float) $num1, $scale);
        $f2 = round((float) $num2, $scale);
        return $f1 <=> $f2;
    }
}

