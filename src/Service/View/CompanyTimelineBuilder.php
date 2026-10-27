<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\District\DistrictCalendar;
use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Repository\StockEventRepository;
use App\Service\News\EventPresenter;
use App\Service\News\NewsCard;

/**
 * A company's history as milestones, oldest first: the deals it struck and the markets it entered or left, its changes
 * of management, failures and reorganisations, splits, moves onto or off Glasswater Row, and the rating changes the
 * news desk ran as headlines (those that crossed investment grade). Results and price moves are left to the news.
 */
final class CompanyTimelineBuilder
{
    // --- Milestones ---
    /** Cards a milestone is drawn from. */
    private const CARDS = [
        NewsCard::Deal, NewsCard::Succession, NewsCard::Bankruptcy, NewsCard::Reorganization, NewsCard::Split,
        NewsCard::Street, NewsCard::Rating,
    ];

    /** Cards that are a milestone only when the news desk ran them as a headline. */
    private const HEADLINE_ONLY = [NewsCard::Rating];

    /** Most milestones listed; a longer history keeps its newest, the earlier ones left to the newswire. */
    public const MAX_MILESTONES = 40;

    /** A simulated time past any game's end, the open end of the history read. */
    private const END_OF_TIME = 1.0e6;

    public function __construct(
        private readonly StockEventRepository $events,
        private readonly EventPresenter $presenter,
    ) {}

    /**
     * Milestones grouped by year, oldest first, and how many earlier ones the cap left out.
     *
     * @return array{years: list<array{year: string, milestones: list<array{card: \App\Service\News\EventCard}>}>, earlier: int}
     */
    public function build(Stock $stock): array
    {
        return self::fromEvents($this->events->findBetween($stock, null, self::END_OF_TIME, NewsCard::typesOf(self::CARDS)), $this->presenter);
    }

    /**
     * @param  list<StockEvent>                                                                                                $events oldest first
     * @return array{years: list<array{year: string, milestones: list<array{card: \App\Service\News\EventCard}>}>, earlier: int}
     */
    public static function fromEvents(array $events, EventPresenter $presenter): array
    {
        $milestones = [];
        foreach ($events as $event) {
            $card = $presenter->present($event);
            if (in_array($card->card, self::HEADLINE_ONLY, true) && $event->getHeadline() !== true) {
                continue;
            }
            $milestones[] = $card;
        }
        $earlier = max(0, count($milestones) - self::MAX_MILESTONES);
        $milestones = array_slice($milestones, $earlier);

        $byYear = [];
        foreach ($milestones as $card) {
            $year = $card->simTime === null ? '' : explode(' Q', DistrictCalendar::quarter($card->simTime))[0];
            $byYear[$year][] = ['card' => $card];
        }
        $years = [];
        foreach ($byYear as $year => $cards) {
            $years[] = ['year' => (string) $year, 'milestones' => $cards];
        }

        return ['years' => $years, 'earlier' => $earlier];
    }
}
