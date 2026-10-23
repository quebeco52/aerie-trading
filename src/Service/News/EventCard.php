<?php

declare(strict_types=1);

namespace App\Service\News;

use App\Data\District\DistrictCalendar;

/**
 * A story as the stock page, the newswire, the live feed and the district map render it: what EventPresenter made of a
 * published event, on the card its type routes to. Twig reads it as it stands; toWire() is the copy the live feed and
 * the headline strip receive.
 *
 * @phpstan-type WireCard array{type: string, category: string, badge: string, tone: string, badgeClass: string, icon: string, iconClass: string, isEarnings: bool, headline: string, pills: list<array{kind: string, text: string}>, changePercent: float|null, recordedAt: string, simTime: float|null, dateline: string, eps: string|null, surpriseType: string|null, surpriseAmount: string|null, surpriseText: string|null, eva: string|null, evaPositive: bool}
 */
final readonly class EventCard
{
    /**
     * @param string                                  $type          the published type, upper-cased
     * @param float|null                              $changePercent the move the story reports, in percent; null for
     *                                                               none
     * @param float|null                              $simTime       when it happened, in simulation years; null on a
     *                                                               row
     *                                                               from before events carried it
     * @param array<string, mixed> $facts         what the story reports as data; the news desk reads a
     *                                                               rating change from it
     * @param list<array{kind: string, text: string}> $pills         the actions that came with a report, one line each
     * @param string|null                             $eps           an earnings card's figures: adjusted earnings a
     *                                                               share,
     *                                                               the surprise against consensus and the economic profit
     */
    public function __construct(
        public string $type,
        public NewsCard $card,
        public string $badge,
        public Tone $tone,
        public string $icon,
        public string $headline,
        public ?float $changePercent,
        public \DateTimeInterface $recordedAt,
        public ?float $simTime,
        public array $facts = [],
        public array $pills = [],
        public ?string $eps = null,
        public ?string $surpriseType = null,
        public ?string $surpriseAmount = null,
        public ?string $surpriseText = null,
        public ?string $eva = null,
        public bool $evaPositive = true,
    ) {}

    public function badgeClass(): string
    {
        return $this->tone->badgeClass();
    }

    public function iconClass(): string
    {
        return $this->tone->iconClass();
    }

    public function isEarnings(): bool
    {
        return $this->card === NewsCard::Earnings;
    }

    /**
     * Dated in the District's calendar; a row from before events carried their simulation time keeps its clock time.
     */
    public function dateline(): string
    {
        return $this->simTime !== null ? DistrictCalendar::dateline($this->simTime) : $this->recordedAt->format('Y-m-d H:i');
    }

    /**
     * The card as JSON carries it, with its classes and dateline printed, since the browser cannot call a method.
     *
     * @return WireCard
     */
    public function toWire(): array
    {
        return [
            'type' => $this->type,
            'category' => $this->card->value,
            'badge' => $this->badge,
            'tone' => $this->tone->value,
            'badgeClass' => $this->badgeClass(),
            'icon' => $this->icon,
            'iconClass' => $this->iconClass(),
            'isEarnings' => $this->isEarnings(),
            'headline' => $this->headline,
            'pills' => $this->pills,
            'changePercent' => $this->changePercent,
            'recordedAt' => $this->recordedAt->format('Y-m-d H:i'),
            'simTime' => $this->simTime,
            'dateline' => $this->dateline(),
            'eps' => $this->eps,
            'surpriseType' => $this->surpriseType,
            'surpriseAmount' => $this->surpriseAmount,
            'surpriseText' => $this->surpriseText,
            'eva' => $this->eva,
            'evaPositive' => $this->evaPositive,
        ];
    }
}
