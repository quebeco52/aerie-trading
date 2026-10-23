<?php

declare(strict_types=1);

namespace App\Service\News;

use App\Entity\DistrictNews;
use App\Entity\EtfEvent;
use App\Entity\StockEvent;

/**
 * A published event as EventPresenter reads it: a stored row, or a story the publisher is about to put on the wire.
 */
final readonly class PresentableEvent
{
    /**
     * @param string       $type          the published type: a company or fund event's type, a district story's desk
     * @param array<string, mixed> $facts         what the story reports as data
     * @param float|null   $changePercent the move the story reports, in percent; null for none
     * @param string|null  $topic         the ShockEvent behind a district story
     * @param float|null   $simTime       when it happened, in simulation years; null on a row from before events
     *                                    carried
     *                                    it
     */
    public function __construct(
        public string $type,
        public string $description,
        public array $facts = [],
        public ?float $changePercent = null,
        public ?string $topic = null,
        public ?float $simTime = null,
        public \DateTimeInterface $recordedAt = new \DateTimeImmutable(),
    ) {}

    public static function of(StockEvent|EtfEvent|DistrictNews $row): self
    {
        $changePercent = $row->getChangePercent() !== null ? (float) $row->getChangePercent() : null;

        return $row instanceof DistrictNews
            ? new self($row->getDesk(), $row->getDescription(), [], $changePercent, $row->getTopic(), $row->getSimTime(), $row->getRecordedAt())
            : new self((string) $row->getEventType(), (string) $row->getDescription(), $row->getFacts() ?? [], $changePercent, null, $row->getSimTime(), $row->getRecordedAt());
    }
}
