<?php

declare(strict_types=1);

namespace App\Service\Market\Chart;

use App\Entity\Stock;
use App\Repository\StockEventRepository;
use App\Service\News\EventPresenter;
use App\Service\News\NewsCard;

/**
 * The company stories a price chart pins to its line, so a jump or a fall sits beside the news that came with it.
 *
 * Each marker is dated by its age behind the newest history row, the clock the chart's bars are dated by
 * (PriceBarAggregator), and carries the card the news list shows for the same story: its badge, tone and headline.
 */
final class ChartEventMarkers
{
    // --- Markers ---
    /**
     * Cards a chart pins: the stories that move a price or explain a step in it. Analyst notes, index and street
     * changes and district news are left to the news list.
     */
    private const CARDS = [
        NewsCard::Earnings, NewsCard::Shock, NewsCard::Split, NewsCard::Rating, NewsCard::Deal,
        NewsCard::Bankruptcy, NewsCard::Reorganization, NewsCard::Succession, NewsCard::Outlook,
    ];

    /**
     * Most markers one chart carries (~40, about one per 10 px of a phone-width line). Past it only the headlines the
     * news desk ran and splits, which explain a step in an unadjusted line, are kept; then the newest.
     */
    public const MAX_MARKERS = 40;

    public function __construct(
        private readonly StockEventRepository $events,
        private readonly EventPresenter $presenter,
    ) {}

    /**
     * Markers for the stories stamped between $from (null: the first) and $newest, oldest first. Results in line with
     * forecasts are routine and left out.
     *
     * @return list<array{age: float, card: string, badge: string, tone: string, headline: string, dateline: string, major: bool}>
     */
    public function between(Stock $stock, ?float $from, float $newest): array
    {
        $markers = [];
        foreach ($this->events->findBetween($stock, $from, $newest, NewsCard::typesOf(self::CARDS)) as $event) {
            $card = $this->presenter->present($event);
            if ($card->isEarnings() && !in_array($card->surpriseType, ['beat', 'miss'], true)) {
                continue;
            }
            $markers[] = [
                'age' => max(0.0, $newest - (float) $event->getSimTime()),
                'card' => $card->card->value,
                'badge' => $card->badge,
                'tone' => $card->tone->value,
                'headline' => $card->headline,
                'dateline' => $card->dateline(),
                'major' => $event->getHeadline() === true || $card->card === NewsCard::Split,
            ];
        }

        if (count($markers) > self::MAX_MARKERS) {
            $markers = array_values(array_filter($markers, static fn(array $m): bool => $m['major']));
        }

        return array_slice($markers, -self::MAX_MARKERS);
    }
}
