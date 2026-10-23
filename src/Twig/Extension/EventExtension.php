<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Entity\DistrictNews;
use App\Entity\EtfEvent;
use App\Entity\StockEvent;
use App\Service\News\EventCard;
use App\Service\News\EventPresenter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class EventExtension extends AbstractExtension
{
    public function __construct(
        private EventPresenter $eventPresenter,
    ) {}

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

    public function presentEvent(StockEvent|EtfEvent|DistrictNews $event): EventCard
    {
        return $this->eventPresenter->present($event);
    }
}
