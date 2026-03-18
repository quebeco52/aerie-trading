#!/usr/bin/env php
<?php

use App\Kernel;
use Workerman\Worker;
use Workerman\Redis\Client; // <--- We MUST use the Async client here!
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// Load environment variables
if (file_exists(dirname(__DIR__) . '/.env')) {
    (new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
}

$env = $_SERVER['APP_ENV'] ?? 'prod';
$debug = (bool) ($_SERVER['APP_DEBUG'] ?? ('prod' !== $env));

// Boot the Symfony Kernel 
$kernel = new Kernel($env, $debug);
$kernel->boot();

echo "Starting Aerie WebSocket Server on port 8080...\n";

// Initialize Workerman
$worker = new Worker('websocket://0.0.0.0:8080');
$worker->count = 4;

// Subscribe to Redis ONCE when the worker boots up
$worker->onWorkerStart = function (Worker $worker) {
    $redisUrl = $_ENV['REDIS_URL'] ?? 'redis://127.0.0.1:6379';
    $asyncRedis = new Client($redisUrl);

    $asyncRedis->subscribe('market_updates', function ($channel, $message) use ($worker) {
        foreach ($worker->connections as $connection) {
            $connection->send($message);
        }
    });

    echo " [√] Worker {$worker->id} connected to Async Redis! Listening for market updates...\n";
};

// Handle incoming messages from the browser without blocking
$worker->onMessage = function ($connection, $data) {
    if ($data === 'ping') {
        $connection->send('pong');
    }
};

$worker->onConnect = function ($connection) {
    echo " [+] New browser connected! (IP: {$connection->getRemoteIp()})\n";
};

$worker->onClose = function ($connection) {
    echo " [-] Browser disconnected.\n";
};

// Run the worker
Worker::runAll();