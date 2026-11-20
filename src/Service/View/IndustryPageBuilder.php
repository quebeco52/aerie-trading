<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Company\Sectors;
use App\Data\Company\Segments;
use App\Entity\Stock;
use App\Entity\StockEvent;
use App\Repository\CorporateReportRepository;
use App\Repository\StockEventRepository;
use App\Repository\StockRepository;
use App\Service\Corporate\Deal\MergerReview;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Corporate\Segment\SegmentBooks;
use App\Service\Macro\MacroStateProvider;
use App\Service\News\EventPresenter;
use App\Service\News\NewsCard;

/**
 * The industry pages: the list of industries with a listed company, and one industry's story over the years — what
 * it realised against a balanced industry's price, the plant it left idle and had under construction, who sold its
 * output, what the public expects of its demand, and the deals and failures that changed its shape.
 *
 * Its history is summed from the reports of the companies whose main business it is (IndustryHistory). Who sells in it
 * now is the industry ledger's reading, which also counts the businesses other companies have entered it with.
 */
final class IndustryPageBuilder
{
    // --- History ---
    /** Quarters of reports the industry page reads: twelve years. */
    private const HISTORY_QUARTERS = 48;

    /** Quarters back the share change is read over: three years. */
    private const SHARE_CHANGE_QUARTERS = 12;

    /** Quarters back the plant under construction is compared with: a year. */
    private const BUILDING_CHANGE_QUARTERS = 4;

    /** Companies the share chart draws by name, largest first; the rest are summed as the others. */
    private const SHARE_CHART_COMPANIES = 6;

    // --- Stories ---
    /** Stories the industry page lists. */
    private const STORIES = 15;

    /** Cards an industry's news carries: the stories that change its shape or its prospects. */
    private const STORY_CARDS = [NewsCard::Deal, NewsCard::Bankruptcy, NewsCard::Reorganization, NewsCard::Outlook];

    /**
     * A price gap at or beyond this either way (~2%) reads as short of plant or overbuilt in the page's summary;
     * nearer, as close to balance.
     */
    public const PRICE_GAP_NOTED = 0.02;

    public function __construct(
        private readonly StockRepository $stocks,
        private readonly CorporateReportRepository $reports,
        private readonly StockEventRepository $events,
        private readonly IndustryShareLedger $ledger,
        private readonly IndustryPositionBuilder $position,
        private readonly MacroStateProvider $macroStateProvider,
        private readonly EventPresenter $presenter,
    ) {}

    /**
     * Every industry with a listed company still trading, by name: its sector, companies, market size and
     * concentration, the price and idle plant of its last full quarter, and its demand outlook.
     *
     * @return list<array{name: string, slug: string, sector: string, companies: int, marketSize: ?float, listedShare: ?float, herfindahl: ?float, highlyConcentrated: bool, priceGap: ?float, idle: ?float, outlook: array{level: float, doubt: float, revision: float|null}|null}>
     */
    public function index(): array
    {
        $byIndustry = [];
        foreach ($this->stocks->findBy([], ['industry' => 'ASC']) as $stock) {
            if (!$stock->isBankrupt() && $stock->getIndustry() !== null) {
                $byIndustry[(string) $stock->getIndustry()][] = $stock;
            }
        }

        $macroState = $this->macroStateProvider->liveState();
        $rows = [];
        foreach ($byIndustry as $industry => $stocks) {
            $quarters = IndustryHistory::quarters($this->reports->findForStocksNewestFirst($stocks, 2));
            $latest = $quarters === [] ? null : $quarters[array_key_last($quarters)];
            $structure = $this->position->industryMarketStructure($industry, $stocks, $macroState);
            $rows[] = [
                'name' => $industry,
                'slug' => Sectors::industrySlug($industry),
                'sector' => $stocks[0]->getSector(),
                'companies' => count($stocks),
                'marketSize' => $structure['marketSize'],
                'listedShare' => $structure['listedShare'],
                'herfindahl' => $structure['herfindahl'],
                'highlyConcentrated' => $structure['highlyConcentrated'],
                'priceGap' => $latest['priceGap'] ?? null,
                'idle' => $latest['idle'] ?? null,
                'outlook' => $this->ledger->describeOutlook($industry),
            ];
        }

        return $rows;
    }

    /**
     * One industry's page, or null when no listed company has ever sold into it as a main business or segment.
     *
     * @return array<string, mixed>|null
     */
    public function build(string $industry): ?array
    {
        $all = $this->stocks->findBy(['industry' => $industry]);
        if ($all === []) {
            $segmentSellers = $this->findSegmentSellers($industry);
            if ($segmentSellers === []) {
                return null;
            }

            return $this->buildSegmentOnlyIndustry($industry, $segmentSellers);
        }
        $live = array_values(array_filter($all, static fn(Stock $s): bool => !$s->isBankrupt()));
        $quarters = IndustryHistory::quarters($this->reports->findForStocksNewestFirst($all, self::HISTORY_QUARTERS));
        $latest = $quarters === [] ? null : $quarters[array_key_last($quarters)];
        $back = static fn(int $n): ?array => count($quarters) > $n ? $quarters[count($quarters) - 1 - $n] : null;

        // The ledger's reading of the market now, through the largest company still in it: its balance, outlook and
        // whole-market shares across listed sellers, entrants and the unlisted fringe.
        usort($live, static fn(Stock $a, Stock $b): int => self::marketValue($b) <=> self::marketValue($a));
        $macroState = $this->macroStateProvider->liveState();
        $strategy = Sectors::strategyFor($industry);
        $position = $live === [] ? null : $this->position->build($live[0], $macroState)['industry'];
        $structure = $live === [] ? null : $this->position->industryMarketStructure($industry, $live, $macroState);
        $sharedMarket = $structure['sharedMarket'] ?? false;
        $marketShares = $structure['shares'] ?? [];
        $multiListed = count($live) > 1 && $sharedMarket;

        $companies = [];
        foreach ($live as $stock) {
            $ticker = (string) $stock->getTicker();
            $companies[] = [
                'ticker' => $ticker,
                'name' => $stock->getName(),
                'marketValue' => self::marketValue($stock),
                'share' => $sharedMarket ? ($marketShares[$ticker] ?? null) : null,
                'shareChange' => $multiListed && isset($latest['shares'][$ticker]) && ($old = $back(self::SHARE_CHANGE_QUARTERS)) !== null
                    ? $latest['shares'][$ticker] - ($old['shares'][$ticker] ?? 0.0) : null,
            ];
        }
        $listed = array_column($companies, 'ticker');
        $entrants = [];
        if ($sharedMarket) {
            foreach ($marketShares as $ticker => $share) {
                if (!in_array($ticker, $listed, true) && ($stock = $this->stocks->findOneByTicker((string) $ticker)) !== null) {
                    $entrants[] = ['ticker' => (string) $ticker, 'name' => $stock->getName(), 'home' => (string) $stock->getIndustry(), 'homeSlug' => Sectors::industrySlug((string) $stock->getIndustry()), 'share' => $share];
                }
            }
        }

        $yearAgo = $back(self::BUILDING_CHANGE_QUARTERS);
        $mover = null;
        if ($multiListed) {
            foreach ($companies as $company) {
                if ($company['shareChange'] !== null && ($mover === null || abs($company['shareChange']) > abs($mover['shareChange']))) {
                    $mover = $company;
                }
            }
        }

        return [
            'name' => $industry,
            'sector' => ($all[0])->getSector(),
            'pricesCapacity' => $position['pricesCapacity'] ?? false,
            'isFinancial' => $strategy->isFinancial(),
            'sharedMarket' => $sharedMarket,
            'marketSize' => $structure['marketSize'] ?? null,
            'marketGdpShare' => $structure['marketGdpShare'] ?? null,
            'listedShare' => $structure['listedShare'] ?? null,
            'fringeShare' => $structure['fringeShare'] ?? null,
            'herfindahl' => $structure['herfindahl'] ?? null,
            'highlyConcentrated' => $structure['highlyConcentrated'] ?? false,
            'concentrationLine' => $structure['concentrationLine'] ?? MergerReview::reviewScreens($macroState->mergerReviewLeniency)['concentrated'],
            'latest' => $latest,
            'buildingYearAgo' => $yearAgo['building'] ?? null,
            'mover' => $mover,
            'balance' => $position['balance'] ?? 'unknown',
            'balanceTone' => $position['balanceTone'] ?? 'neutral',
            'capacityRatio' => $position['capacityRatio'] ?? null,
            'outlook' => $position['outlook'] ?? $this->ledger->describeOutlook($industry),
            'companies' => $companies,
            'entrants' => $entrants,
            'failed' => count($all) - count($live),
            'charts' => self::charts($quarters, $sharedMarket),
            'stories' => $this->stories($all),
        ];
    }

    /**
     * Live companies selling into an industry through a segment (either declared in Segments or entered in play),
     * skipping any divested segment whose plant was sold off.
     *
     * @return list<array{stock: Stock, segment: string}>
     */
    private function findSegmentSellers(string $industry): array
    {
        $sellers = [];
        foreach ($this->stocks->findAll() as $stock) {
            if ($stock->isBankrupt()) {
                continue;
            }
            $momentum = $stock->getEarningsMomentumZ() ?? [];
            $hasPlant = SegmentBooks::plant($momentum) !== null;
            $pending = SegmentBooks::pending($momentum);
            foreach (SegmentBooks::industries($stock) as $segment => $segIndustry) {
                if ($segment === Segments::MAIN || $segIndustry !== $industry) {
                    continue;
                }
                if ($hasPlant && !SegmentBooks::holdsPlant($stock, $segment) && ($pending[$segment] ?? 0.0) <= 0.0) {
                    continue;
                }
                $sellers[] = ['stock' => $stock, 'segment' => $segment];
            }
        }

        return $sellers;
    }

    /**
     * Industry page when no listed company has the industry as its main business, only secondary segments
     * (either declared in Segments or entered in play).
     *
     * @param list<array{stock: Stock, segment: string}> $segmentSellers
     * @return array<string, mixed>
     */
    private function buildSegmentOnlyIndustry(string $industry, array $segmentSellers): array
    {
        $macroState = $this->macroStateProvider->liveState();
        $strategy = Sectors::strategyFor($industry);
        $structure = $this->position->segmentIndustryMarketStructure($industry, $segmentSellers, $macroState);
        $shares = $structure['shares'];

        $byTicker = [];
        foreach ($segmentSellers as ['stock' => $stock, 'segment' => $segment]) {
            $ticker = (string) $stock->getTicker();
            if (isset($byTicker[$ticker])) {
                continue;
            }
            $byTicker[$ticker] = [
                'ticker' => $ticker,
                'name' => $stock->getName(),
                'home' => (string) $stock->getIndustry(),
                'homeSlug' => Sectors::industrySlug((string) $stock->getIndustry()),
                'segmentLabel' => SegmentBooks::label($stock, $segment),
                'share' => $shares[$ticker] ?? null,
                'marketValue' => self::marketValue($stock),
            ];
        }
        $entrants = array_values($byTicker);
        usort($entrants, static fn(array $a, array $b): int => [$b['share'] ?? -1.0, $b['marketValue']] <=> [$a['share'] ?? -1.0, $a['marketValue']]);
        $entrants = array_map(static function (array $e): array {
            unset($e['marketValue']);

            return $e;
        }, $entrants);

        return [
            'name' => $industry,
            'sector' => Sectors::INDUSTRY_SECTORS[$industry] ?? null,
            'pricesCapacity' => !$strategy->isFinancial() && $strategy->getIndustrySubstitutability() > 0.0,
            'isFinancial' => $strategy->isFinancial(),
            'sharedMarket' => true,
            'marketSize' => $structure['marketSize'],
            'marketGdpShare' => $structure['marketGdpShare'],
            'listedShare' => $structure['listedShare'],
            'fringeShare' => $structure['fringeShare'],
            'herfindahl' => $structure['herfindahl'],
            'highlyConcentrated' => $structure['highlyConcentrated'],
            'concentrationLine' => $structure['concentrationLine'],
            'latest' => null,
            'buildingYearAgo' => null,
            'mover' => null,
            'balance' => 'unknown',
            'balanceTone' => 'neutral',
            'capacityRatio' => null,
            'outlook' => $this->ledger->describeOutlook($industry),
            'companies' => [],
            'entrants' => $entrants,
            'failed' => 0,
            'charts' => ['price' => null, 'plant' => null, 'margin' => null, 'shares' => null],
            'stories' => [],
        ];
    }

    /** @param Stock $stock */
    private static function marketValue(Stock $stock): float
    {
        return (float) $stock->getPrice() * (float) $stock->getSharesOutstanding();
    }

    /**
     * The page's charts over its quarters: the price against a balanced industry's; idle plant and plant under
     * construction; operating margin; and each company's share of sales, the largest by name.
     *
     * @param  list<array{t: float, label: string, revenue: float, shares: array<string, float>, priceGap: ?float, idle: ?float, building: ?float, margin: ?float}> $quarters
     * @return array<string, mixed>
     */
    public static function charts(array $quarters, bool $sharedMarket = true): array
    {
        /**
         * @param \Closure(array{t: float, label: string, revenue: float, shares: array<string, float>, priceGap: ?float, idle: ?float, building: ?float, margin: ?float}): ?float $value
         */
        $series = static fn(string $name, \Closure $value): array => [
            'name' => $name,
            'points' => array_map(static fn(array $q): array => ['t' => $q['t'], 'label' => $q['label'], 'value' => $value($q)], $quarters),
        ];

        $named = [];
        if ($quarters !== []) {
            $named = array_slice(array_keys($quarters[array_key_last($quarters)]['shares']), 0, self::SHARE_CHART_COMPANIES);
        }
        $shareSeries = [];
        foreach ($named as $ticker) {
            $shareSeries[] = [
                'name' => (string) $ticker,
                'points' => array_map(static fn(array $q): array => ['t' => $q['t'], 'label' => $q['label'], 'value' => $q['shares'][$ticker] ?? null], $quarters),
            ];
        }
        $others = array_map(static fn(array $q): array => [
            't' => $q['t'],
            'label' => $q['label'],
            'value' => $q['shares'] === [] ? null : 1.0 - array_sum(array_intersect_key($q['shares'], array_flip($named))),
        ], $quarters);
        if (array_filter($others, static fn(array $p): bool => $p['value'] !== null && $p['value'] > 1e-6) !== []) {
            $shareSeries[] = ['name' => 'Others', 'points' => $others];
        }

        return [
            'price' => IndustryCharts::lines([$series('Price against balance', static fn(array $q): ?float => $q['priceGap'])], true, true),
            'plant' => IndustryCharts::lines([$series('Idle plant', static fn(array $q): ?float => $q['idle']), $series('Under construction', static fn(array $q): ?float => $q['building'])], true),
            'margin' => IndustryCharts::lines([$series('Operating margin', static fn(array $q): ?float => $q['margin'])], true),
            'shares' => $sharedMarket && count($shareSeries) > 1 ? IndustryCharts::lines($shareSeries, true) : null,
        ];
    }

    /**
     * The industry's deals, failures and outlook revisions, newest first. An outlook revision is told on every company
     * in the industry at once; it is listed once.
     *
     * @param  list<Stock>                                                                                $stocks
     * @return list<array{card: \App\Service\News\EventCard, source: array{ticker: string, name: string}|null}>
     */
    private function stories(array $stocks): array
    {
        $stories = [];
        $outlookTimes = [];
        foreach ($this->events->findLatestAmong($stocks, NewsCard::typesOf(self::STORY_CARDS), 3 * self::STORIES) as $event) {
            $card = $this->presenter->present($event);
            if ($card->card === NewsCard::Outlook) {
                $key = (string) $event->getSimTime() . '|' . $event->getDescription();
                if (isset($outlookTimes[$key])) {
                    continue;
                }
                $outlookTimes[$key] = true;
            }
            $stories[] = ['card' => $card, 'source' => self::source($event, $card->card)];
            if (count($stories) >= self::STORIES) {
                break;
            }
        }

        return $stories;
    }

    /**
     * The company a story is about, or null for an outlook revision, which is the industry's own news.
     *
     * @return array{ticker: string, name: string}|null
     */
    private static function source(StockEvent $event, NewsCard $card): ?array
    {
        if ($card === NewsCard::Outlook) {
            return null;
        }

        return ['ticker' => (string) $event->getStock()->getTicker(), 'name' => $event->getStock()->getName()];
    }
}
