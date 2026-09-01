<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Message\ProcessLimitOrdersMessage;
use App\MessageHandler\ProcessLimitOrdersHandler;
use App\Service\Market\TradeExecutionService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ProcessLimitOrdersHandlerTest extends TestCase
{
    public function testInvokeDelegatesToTradeExecutionService(): void
    {
        $serviceMock = $this->createMock(TradeExecutionService::class);
        $serviceMock->expects($this->once())
            ->method('processLimitOrders')
            ->with('APEX', 95.50);

        $handler = new ProcessLimitOrdersHandler($serviceMock);
        $message = new ProcessLimitOrdersMessage('APEX', 95.50);

        $handler($message);
    }
}
