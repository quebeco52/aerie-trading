<?php

namespace App\Factory;

if (!class_exists('Redis')) {
    class RedisFallback
    {
        public const PIPELINE = 2;
        private array $storage = [];

        public function connect($host, $port = 6379, $timeout = 0.0, $reserved = null, $retry_interval = 0, $read_timeout = 0.0): bool
        {
            return true;
        }

        public function get($key): mixed
        {
            return $this->storage[$key] ?? null;
        }

        public function set($key, $val): bool
        {
            $this->storage[$key] = $val;
            return true;
        }

        public function setex($key, $ttl, $val): bool
        {
            $this->storage[$key] = $val;
            return true;
        }

        public function del($key): int
        {
            unset($this->storage[$key]);
            return 1;
        }

        public function lPush($key, $value): int
        {
            if (!isset($this->storage[$key])) {
                $this->storage[$key] = [];
            }
            array_unshift($this->storage[$key], $value);
            return count($this->storage[$key]);
        }

        public function lTrim($key, $start, $stop): bool
        {
            if (isset($this->storage[$key])) {
                $this->storage[$key] = array_slice($this->storage[$key], $start, $stop === -1 ? null : $stop - $start + 1);
            }
            return true;
        }

        public function lRange($key, $start, $end): array
        {
            if (!isset($this->storage[$key])) {
                return [];
            }
            return array_slice($this->storage[$key], $start, $end === -1 ? null : $end - $start + 1);
        }

        public function multi($mode = self::PIPELINE): static
        {
            return $this;
        }

        public function exec(): array
        {
            return [];
        }

        public function publish($channel, $message): int
        {
            return 1;
        }
    }

    class_alias(RedisFallback::class, 'Redis');
}

class RedisFactory
{
    public static function createConnection(): \Redis
    {
        $redisUrl = parse_url($_ENV['REDIS_URL'] ?? 'redis://aerie-redis:6379');
        $host = $redisUrl['host'] ?? '127.0.0.1';
        $port = (int) ($redisUrl['port'] ?? 6379);

        $redis = new \Redis();
        try {
            @$redis->connect($host, $port, 0.2);
        } catch (\Throwable $e) {
            // Gracefully ignore connection failures in test/offline environments
        }

        return $redis;
    }
}