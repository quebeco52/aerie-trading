<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Entity\DistrictNews;
use App\Entity\EtfEvent;
use App\Entity\StockEvent;
use App\Repository\DistrictNewsRepository;
use App\Repository\EtfEventRepository;
use App\Repository\StockEventRepository;
use App\Service\Event\EventCategory;
use App\Service\Event\EventPresenter;
use App\Service\Event\NewsDesk;

/**
 * Assembles the newswire: district stories, company news and fund announcements in one feed, newest first, and
 * the latest headline for the strip under the navigation.
 *
 * Each source keeps its own table; the wire reads the newest rows of each a section draws on and merges them, so
 * no story is copied into a second table.
 */
class NewsFeedBuilder
{
    // --- Feed Size ---

    /** Stories listed on a newswire page. */
    public const FEED_ROWS = 60;

    /** Recent company stories searched for the latest headline; most are not headlines, so it reaches past one. */
    private const HEADLINE_SCAN_ROWS = 40;

    /** Company cards that can make a headline (NewsDesk::isHeadline); the scan reads only these. */
    private const HEADLINE_CAPABLE_CATEGORIES = ['bankruptcy', 'reorganization', 'mna', 'debt', 'earnings', 'shock', 'analyst'];

    public function __construct(
        private readonly DistrictNewsRepository $districtNews,
        private readonly StockEventRepository $stockEvents,
        private readonly EtfEventRepository $etfEvents,
        private readonly EventPresenter $presenter = new EventPresenter(),
    ) {}

    /**
     * @return array{section: string, sections: array<string, string>, items: list<array<string, mixed>>}
     */
    public function build(string $section, int $limit = self::FEED_ROWS): array
    {
        $section = NewsDesk::section($section);

        $rows = match ($section) {
            'district' => $this->districtNews->findLatest($limit),
            'funds' => $this->etfEvents->findLatest($limit),
            'companies' => $this->stockEvents->findLatest($limit),
            'earnings', 'deals', 'credit' => $this->stockEvents->findLatest($limit, EventCategory::typesIn(NewsDesk::COMPANY_SECTION_CATEGORIES[$section])),
            default => [
                ...$this->districtNews->findLatest($limit),
                ...$this->stockEvents->findLatest($limit),
                ...$this->etfEvents->findLatest($limit),
            ],
        };

        $items = array_map($this->item(...), $rows);
        usort($items, static fn(array $a, array $b): int => $b['card']['recordedAt'] <=> $a['card']['recordedAt']);

        return [
            'section' => $section,
            'sections' => NewsDesk::SECTIONS,
            'items' => array_slice($items, 0, $limit),
        ];
    }

    /**
     * The newest headline on the wire, or null before there is one.
     *
     * @return array<string, mixed>|null
     */
    public function latestHeadline(): ?array
    {
        $candidates = [
            ...$this->districtNews->findLatest(1),
            ...$this->stockEvents->findLatest(self::HEADLINE_SCAN_ROWS, EventCategory::typesIn(self::HEADLINE_CAPABLE_CATEGORIES)),
        ];

        $latest = null;
        foreach ($candidates as $row) {
            $item = $this->item($row);
            if ($item['headline'] && ($latest === null || $item['card']['recordedAt'] > $latest['card']['recordedAt'])) {
                $latest = $item;
            }
        }

        return $latest;
    }

    /**
     * One story as the wire lists it: its card, who it is about and where it is filed.
     *
     * @return array<string, mixed>
     */
    private function item(DistrictNews|StockEvent|EtfEvent $row): array
    {
        $card = $this->presenter->present($row);
        $category = (string) $card['category'];

        [$scope, $ticker, $name, $volatility] = match (true) {
            $row instanceof StockEvent => [NewsDesk::SCOPE_COMPANY, $row->getStock()->getTicker(), $row->getStock()->getName(), (float) $row->getStock()->getVolatility()],
            $row instanceof EtfEvent => [NewsDesk::SCOPE_FUND, $row->getEtf()->getTicker(), $row->getEtf()->getName(), null],
            default => [NewsDesk::SCOPE_DISTRICT, null, null, null],
        };

        return [
            'card' => $card,
            'scope' => $scope,
            'section' => NewsDesk::sectionOf($scope, $category),
            // The verdict the desk reached when it ran the story; a row from before verdicts were kept is judged now.
            'headline' => $row->getHeadline() ?? NewsDesk::isHeadline($card, $volatility),
            'ticker' => $ticker,
            'name' => $name,
        ];
    }
}
