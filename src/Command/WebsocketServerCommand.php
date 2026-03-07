<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Workerman\Worker;
use Workerman\Redis\Client;

#[AsCommand(
    name: 'app:websocket-server',
    description: 'Starts the Workerman WebSocket server to push prices to the frontend.',
)]
class WebsocketServerCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln("<info> Booting up Workerman WebSocket Server on port 8080...</info>");

        // tTrick Workerman into thinking we typed 'start' in the terminal.
        global $argv;
        $argv = ['bin/console', 'start'];

        // Create a WebSocket server listening on port 8080
        $worker = new Worker('websocket://0.0.0.0:8080');

        // Internal processes
        $worker->count = 4;

        // Connect to Redis
        $worker->onWorkerStart = function (Worker $worker) use ($output) {
            $redisUrl = $_ENV['REDIS_URL'] ?? 'redis://127.0.0.1:6379';

            $redis = new Client($redisUrl);

            $redis->subscribe('market_updates', function ($channel, $message) use ($worker) {

                foreach ($worker->connections as $connection) {
                    $connection->send($message);
                }
            });

            $output->writeln("<info>✅ Connected to Redis! Listening for market updates...</info>");
        };

        $worker->onConnect = function ($connection) {
            echo " [+] New browser connected! (IP: {$connection->getRemoteIp()})\n";
        };

        $worker->onClose = function ($connection) {
            echo " [-] Browser disconnected.\n";
        };


        // Start the infinite event loop
        Worker::runAll();

        return Command::SUCCESS;
    }
}
