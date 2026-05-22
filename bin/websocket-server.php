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

$kernel = new Kernel($env, $debug);
$kernel->boot();

echo "Starting Aerie WebSocket Server on port 8080...\n";

// Initialize Workerman
$worker = new Worker('websocket://0.0.0.0:8080');
$worker->count = 4;

// THE BOUNCER

$worker->onConnect = function ($connection) {
    // Workerman v5+ moves the WebSocket handshake intercept to the connection object
    $connection->onWebSocketConnect = function ($connection, $http_buffer) {
        // Extract the ticket safely (fallback to raw HTTP buffer if $_GET is empty)
        $ticket = $_GET['ticket'] ?? '';
        if (empty($ticket) && preg_match('/ticket=([a-zA-Z0-9]+)/', $http_buffer, $matches)) {
            $ticket = $matches[1];
        }

        if (empty($ticket)) {
            echo " [!] Rejected connection: No ticket provided.\n";
            $connection->close();
            return;
        }

        // Lazily initialize a synchronous Redis client per-worker
        static $syncRedis = null;
        if ($syncRedis === null) {
            $syncRedis = new \Redis();
            $redisUrl = parse_url($_ENV['REDIS_URL'] ?? 'redis://127.0.0.1:6379');
            $syncRedis->connect($redisUrl['host'], $redisUrl['port'] ?? 6379);
        }

        // Check if the ticket exists in Redis
        $userId = $syncRedis->get("ws_ticket:{$ticket}");

        if (!$userId) {
            echo " [!] Rejected connection: Invalid or expired ticket.\n";
            $connection->close();
            return;
        }

        // Validated!
        // give it a 15-second grace period. This allows multiple client scripts to connect simultaneously.
        $syncRedis->expire("ws_ticket:{$ticket}", 15);

        // Attach the User ID to this specific connection object for future reference
        $connection->uid = $userId;
        
        echo " [+] Authenticated User ID {$userId} connected! (IP: {$connection->getRemoteIp()})\n";
    };
};


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

// Graceful Shutdown to prevent Docker Exit Code 137
$worker->onWorkerStop = function (Worker $worker) {
    echo " [x] Worker {$worker->id} shutting down gracefully.\n";
};

// Handle incoming messages from the browser without blocking
$worker->onMessage = function ($connection, $data) {
    if ($data === 'ping') {
        $connection->send('pong');
    }
};

$worker->onClose = function ($connection) {
    $userId = $connection->uid ?? 'Unknown';
    echo " [-] Browser disconnected (User ID: {$userId}).\n";
};

// Run the worker
Worker::runAll();