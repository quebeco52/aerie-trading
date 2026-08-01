<?php

declare(strict_types=1);

namespace App\Service\Event;

use App\DTO\EarningsSimulationContext;
use Symfony\Contracts\EventDispatcher\Event;

class EarningsReportedEvent extends Event
{
    public const NAME = 'corporate.earnings.reported';

    public function __construct(
        private readonly EarningsSimulationContext $context
    ) {}

    public function getContext(): EarningsSimulationContext
    {
        return $this->context;
    }
}
