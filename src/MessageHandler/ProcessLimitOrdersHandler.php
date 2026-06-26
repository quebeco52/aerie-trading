<?php

namespace App\MessageHandler;

use App\Message\ProcessLimitOrdersMessage;
use App\Service\Market\TradeExecutionService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ProcessLimitOrdersHandler
{
    public function __construct(
        private TradeExecutionService $tradeExecutionService
    ) {}

    public function __invoke(ProcessLimitOrdersMessage $message): void
    {
        $this->tradeExecutionService->processLimitOrders(
            $message->getTicker(),
            $message->getCurrentPrice()
        );
    }
}
