#!/usr/bin/env php
<?php

use Workerman\Worker;
use Workerman\Redis\Client;
use Workerman\Connection\TcpConnection;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require dirname(__DIR__) . '/vendor/autoload.php';

// Increase default buffer from 1MB to 10MB
TcpConnection::$defaultMaxSendBufferSize = 10 * 1024 * 1024;

// Retrieve the secret securely from the environment.
// CRITICAL: We trim quotes because Docker does NOT strip them from env vars, but Symfony DOES!
$appSecret = trim($_ENV['APP_SECRET'] ?? getenv('APP_SECRET') ?? '', '"\'');

if (empty($appSecret)) {
    die("FATAL: APP_SECRET is missing.\n");
}

echo "Starting Aerie WebSocket Server on port 8080...\n";
$worker = new Worker('websocket://0.0.0.0:8080');
$worker->count = 1; // Safer to use 1 worker for Redis PubSub to avoid duplicate messages

// Authenticate incoming WebSocket connections
$worker->onWebSocketConnect = function ($connection, $http_buffer) use ($appSecret) {
    try {
        $ticket = '';

        if (is_object($http_buffer) && method_exists($http_buffer, 'get')) {
            $ticket = $http_buffer->get('ticket') ?? '';
        } elseif (is_string($http_buffer) && preg_match('/ticket=([a-zA-Z0-9\.\-_]+)/', $http_buffer, $matches)) {
            $ticket = $matches[1];
        } elseif (isset($_GET['ticket'])) {
            $ticket = $_GET['ticket'];
        }

        if (empty($ticket)) {
            throw new \Exception("No ticket provided.");
        }

        $decoded = JWT::decode($ticket, new Key($appSecret, 'HS256'));
        
        $userId = $decoded->uid ?? null;
        if (!$userId) {
            throw new \Exception("Missing UID in token payload.");
        }

        // Attach the User ID to this specific connection object for future reference
        $connection->uid = $userId;

        $userLabel = $userId === 'guest' ? 'Guest' : "Authenticated User ID {$userId}";
        echo " [+] {$userLabel} connected! (IP: {$connection->getRemoteIp()})\n";
    } catch (\Exception $e) {
        echo " [!] Rejected connection: " . $e->getMessage() . "\n";
        $connection->close();
    }
};

// SUBSCRIBE TO REDIS TO BROADCAST MARKET TICKS
$worker->onWorkerStart = function ($worker) {
    // Ensure correct scheme for Async Redis
    $redisUrl = $_ENV['REDIS_URL'] ?? 'redis://127.0.0.1:6379';
    $redisUrl = str_replace('tcp://', 'redis://', $redisUrl); 
    
    $redis = new Client($redisUrl);
    
    // Subscribe to the channel that MarketTickerCommand publishes to
    $redis->subscribe(['market_updates'], function ($channel, $message) use ($worker) {
        foreach ($worker->connections as $connection) {
            if (isset($connection->uid)) {
                $connection->send($message);
            }
        }
    });
    
    echo " [v] Worker {$worker->id} started and subscribed to Redis.\n";
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

$worker->onError = function ($connection, $code, $msg) {
    echo " [!] Connection Error $code: $msg\n";
};

// Run the worker
Worker::runAll();
