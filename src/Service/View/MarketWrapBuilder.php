<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Entity\StockEvent;
use App\Repository\StockEventRepository;
use App\Service\News\EventPresenter;

/**
 * The Markets page's wrap of the month on the exchange: how many listings rose and fell, the largest moves either way,
 * each sector's move weighted by market value, and the latest stories the news desk ran as headlines.
 *
 * The move is the board's own one-month change (PriceChangeFeed), the figure the listings table prints beside it.
 */
final class MarketWrapBuilder
{
    // --- Wrap ---
    /** Listings named as leaders and as laggards. */
    private const MOVERS = 5;

    /** Headlines listed. */
    private const HEADLINES = 5;

    /** A move under half a basis point reads as unchanged, as the listings table prints it. */
    private const UNCHANGED = 0.00005;

    public function __construct(
        private readonly StockEventRepository $events,
        private readonly EventPresenter $presenter,
    ) {}

    /**
     * @param  list<array{ticker: string, name: string, sector: string, marketCap: float, isBankrupt: bool, changePercent: float|null}> $listings
     * @return array<string, mixed>
     */
    public function build(array $listings): array
    {
        $headlines = [];
        foreach ($this->events->findBy(['headline' => true], ['recordedAt' => 'DESC', 'id' => 'DESC'], self::HEADLINES) as $event) {
            $headlines[] = ['card' => $this->presenter->present($event), 'source' => self::source($event)];
        }

        return self::moves($listings) + ['headlines' => $headlines];
    }

    /**
     * Breadth, leaders, laggards and sector moves from the listings still trading that have a month of prices.
     *
     * @param  list<array{ticker: string, name: string, sector: string, marketCap: float, isBankrupt: bool, changePercent: float|null}> $listings
     * @return array{advancing: int, declining: int, unchanged: int, leaders: list<array{ticker: string, name: string, change: float}>, laggards: list<array{ticker: string, name: string, change: float}>, sectors: list<array{name: string, change: float, companies: int}>}
     */
    public static function moves(array $listings): array
    {
        $priced = array_values(array_filter($listings, static fn(array $l): bool => !$l['isBankrupt'] && $l['changePercent'] !== null));
        $row = static fn(array $l): array => ['ticker' => $l['ticker'], 'name' => $l['name'], 'change' => (float) $l['changePercent']];

        $up = array_values(array_filter($priced, static fn(array $l): bool => $l['changePercent'] >= self::UNCHANGED));
        $down = array_values(array_filter($priced, static fn(array $l): bool => $l['changePercent'] <= -self::UNCHANGED));
        usort($up, static fn(array $a, array $b): int => $b['changePercent'] <=> $a['changePercent']);
        usort($down, static fn(array $a, array $b): int => $a['changePercent'] <=> $b['changePercent']);

        $sums = [];
        foreach ($priced as $l) {
            $sum = $sums[$l['sector']] ?? ['weighted' => 0.0, 'cap' => 0.0, 'companies' => 0];
            $sum['weighted'] += $l['marketCap'] * (float) $l['changePercent'];
            $sum['cap'] += $l['marketCap'];
            ++$sum['companies'];
            $sums[$l['sector']] = $sum;
        }
        $sectors = [];
        foreach ($sums as $name => $sum) {
            if ($sum['cap'] > 0.0) {
                $sectors[] = ['name' => (string) $name, 'change' => $sum['weighted'] / $sum['cap'], 'companies' => $sum['companies']];
            }
        }
        usort($sectors, static fn(array $a, array $b): int => $b['change'] <=> $a['change']);

        return [
            'advancing' => count($up),
            'declining' => count($down),
            'unchanged' => count($priced) - count($up) - count($down),
            'leaders' => array_map($row, array_slice($up, 0, self::MOVERS)),
            'laggards' => array_map($row, array_slice($down, 0, self::MOVERS)),
            'sectors' => $sectors,
        ];
    }

    /** @return array{ticker: string, name: string} */
    private static function source(StockEvent $event): array
    {
        return ['ticker' => (string) $event->getStock()->getTicker(), 'name' => $event->getStock()->getName()];
    }
}
