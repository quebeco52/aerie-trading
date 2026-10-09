<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\AnchorHoldings;
use App\Data\CompanyResearch;
use App\Data\Institutions;
use App\Data\StrategicHoldings;
use App\Entity\Stock;
use App\Repository\StockEventRepository;
use App\Repository\StockRepository;
use App\Service\Market\Chart\PriceChangeFeed;

/**
 * Assembles the long-form company profile: the written article from CompanyResearch beside a column of live
 * facts (price, owners, peers, the company's own headlines), so the history is static and the sidebar keeps up
 * with play.
 */
class CompanyProfilePageBuilder
{
    // --- Page Composition ---

    /** Headline stories listed beside the article. */
    private const HEADLINE_ROWS = 8;

    /** Peers listed beside the article, direct competitors first. */
    private const PEER_ROWS = 5;

    public function __construct(
        private readonly StockRepository $stocks,
        private readonly StockEventRepository $stockEvents,
        private readonly PeerTableBuilder $peerTable,
        private readonly PriceChangeFeed $priceChangeFeed,
    ) {}

    /**
     * @return array<string, mixed>|null The template payload for stock/profile.html.twig; null when no profile is written.
     */
    public function build(Stock $stock): ?array
    {
        $ticker = (string) $stock->getTicker();
        $article = CompanyResearch::for($ticker);
        if ($article === null) {
            return null;
        }

        $price = (float) $stock->getPrice();
        $isDelisted = $stock->isBankrupt();

        return [
            'asset' => $stock,
            'article' => $article,
            'publisher' => Institutions::TICKBIRD_RESEARCH,
            'changePercent' => $isDelisted ? null : $this->priceChangeFeed->changeForTicker($ticker, $price),
            'marketCap' => $isDelisted ? null : $price * (float) $stock->getSharesOutstanding(),
            'management' => StockPageBuilder::managementSummary($stock),
            'freeFloat' => max(0.0, min(1.0, (float) $stock->getPublicFloatPercentage())),
        ] + $this->ownership($ticker) + [
            'peers' => array_slice($this->peerTable->build($stock), 0, self::PEER_ROWS),
            'related' => $this->related($article['related']),
            'headlines' => $this->stockEvents->findHeadlinesFor($stock, self::HEADLINE_ROWS),
        ];
    }

    /**
     * Who holds blocks of the company off the float, and the listed stakes it holds in others.
     *
     * @return array{holders: list<array{name: string, ticker: string|null, share: float}>, holdings: list<array{name: string, ticker: string, share: float}>}
     */
    public function ownership(string $ticker): array
    {
        return ['holders' => $this->holders($ticker), 'holdings' => $this->holdings($ticker)];
    }

    /**
     * The named blocks held off the float: listed anchor holders and the District's own stake.
     *
     * @return list<array{name: string, ticker: string|null, share: float}>
     */
    private function holders(string $ticker): array
    {
        $holders = [];
        foreach (AnchorHoldings::STAKES as $holder => $stakes) {
            if (isset($stakes[$ticker])) {
                $holders[] = ['name' => $this->nameOf($holder), 'ticker' => $holder, 'share' => $stakes[$ticker]->fraction()];
            }
        }

        $strategic = StrategicHoldings::stake($ticker);
        if ($strategic > 0.0) {
            $holders[] = ['name' => Institutions::SOVEREIGN_RESERVE_FUND, 'ticker' => null, 'share' => $strategic];
        }

        usort($holders, static fn (array $a, array $b): int => $b['share'] <=> $a['share']);

        return $holders;
    }

    /**
     * The listed stakes this company holds in others.
     *
     * @return list<array{name: string, ticker: string, share: float}>
     */
    private function holdings(string $ticker): array
    {
        $holdings = [];
        foreach (AnchorHoldings::forHolder($ticker) as $held => $share) {
            $holdings[] = ['name' => $this->nameOf($held), 'ticker' => $held, 'share' => $share];
        }

        return $holdings;
    }

    /**
     * The companies the article names, with whether each still trades and has a profile of its own.
     *
     * @param  list<string> $tickers
     * @return list<array{ticker: string, name: string, hasProfile: bool, isDelisted: bool}>
     */
    private function related(array $tickers): array
    {
        $related = [];
        foreach ($tickers as $ticker) {
            $stock = $this->stocks->findOneByTicker($ticker);
            if ($stock === null) {
                continue;
            }
            $related[] = [
                'ticker' => $ticker,
                'name' => (string) $stock->getName(),
                'hasProfile' => CompanyResearch::for($ticker) !== null,
                'isDelisted' => $stock->isBankrupt(),
            ];
        }

        return $related;
    }

    private function nameOf(string $ticker): string
    {
        return (string) ($this->stocks->findOneByTicker($ticker)?->getName() ?? $ticker);
    }
}
