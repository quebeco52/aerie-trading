<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProcessPriceAlertsMessage;
use App\Service\Notification\PriceAlertService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessPriceAlertsHandler
{
    public function __construct(private readonly PriceAlertService $alerts) {}

    public function __invoke(ProcessPriceAlertsMessage $message): void
    {
        $this->alerts->trigger($message->getTicker(), $message->getPrice());
    }
}
