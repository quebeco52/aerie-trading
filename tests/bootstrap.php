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
