<?php

namespace App\Factory;

class RedisFactory
{
    public static function createConnection(): \Redis
    {
        $redisUrl = parse_url($_ENV['REDIS_URL'] ?? 'redis://aerie-redis:6379');
        
        $redis = new \Redis();
        $redis->connect($redisUrl['host'], $redisUrl['port'] ?? 6379);
        
        return $redis;
    }
}