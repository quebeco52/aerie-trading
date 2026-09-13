<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Message\ProcessLimitOrdersMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Where a limit-order check actually runs.
 *
 * Messenger handles an unrouted message synchronously in the dispatching process. For this message that
 * process is the market ticker, so every resting-order SELECT, every FOR UPDATE on a user row and every
 * mid-tick flush the fill path performs landed inside the tick transaction. The handler has always claimed
 * to run on the worker; this pins the routing entry that makes the claim true.
 */
class ProcessLimitOrdersRoutingTest extends TestCase
{
    public function testLimitOrderMessagesAreRoutedToTheAsyncTransport(): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/packages/messenger.yaml');

        $routing = $config['framework']['messenger']['routing'] ?? [];

        $this->assertArrayHasKey(ProcessLimitOrdersMessage::class, $routing);
        $this->assertSame('async', $routing[ProcessLimitOrdersMessage::class]);
    }
}
