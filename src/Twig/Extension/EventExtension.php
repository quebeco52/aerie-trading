<?php

namespace App\Twig\Extension;

use App\Entity\EtfEvent;
use App\Entity\StockEvent;
use App\Service\Event\EventPresenter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class EventExtension extends AbstractExtension
{
    public function __construct(
        private EventPresenter $eventPresenter
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('present_event', [$this, 'presentEvent']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('present_event', [$this, 'presentEvent']),
        ];
    }

    /**
     * @param StockEvent|EtfEvent|array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function presentEvent(StockEvent|EtfEvent|array $event): array
    {
        return $this->eventPresenter->present($event);
    }
}
