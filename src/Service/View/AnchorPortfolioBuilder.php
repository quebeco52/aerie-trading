<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\AnchorHoldings;
use App\Data\AnchorStake;
use App\Entity\Stock;
use App\Repository\StockRepository;
use App\Service\Market\PriceChangeFeed;

/**
 * The holdings table on a sphere's page: what it owns, how much of it, and what that is worth today.
 *
 * A sphere's NAV moves with the companies it holds, and without this the player sees the effect with none
 * of the cause — a trust down 4% on a day it never traded. Every real sphere publishes this table for the
 * same reason: the portfolio IS the company.
 *
 * Struck at live prices to match the NAV quoted beside it, largest first.
 */
class AnchorPortfolioBuilder
{
    public function __construct(
        private readonly StockRepository $stocks,
        private readonly PriceChangeFeed $priceChangeFeed,
    ) {}

    /**
     * @return array{
     *     holdings: list<array{ticker: string, name: string, industry: string|null, ownership: float, tier: string,
     *                          price: float, changePercent: float|null, value: float, shareOfPortfolio: float,
     *                          shareOfNav: float, isBankrupt: bool}>,
     *     totalValue: float,
     *     shareOfNav: float
     * }|null Null for the overwhelming majority of firms, which hold no stakes.
     */
    public function build(Stock $stock): ?array
    {
        $stakes = AnchorHoldings::forHolder($stock->getTicker());

        if ($stakes === []) {
            return null;
        }

        $tiers = AnchorHoldings::tiersForHolder($stock->getTicker());

        $equity = (float) $stock->getTotalEquity();
        $holdings = [];
        $total = 0.0;

        foreach ($this->stocks->findBy(['ticker' => array_keys($stakes)]) as $held) {
            $ticker = $held->getTicker();
            $ownership = $stakes[$ticker] ?? 0.0;
            $price = (float) $held->getPrice();
            $isBankrupt = $held->isBankrupt();

            // A delisted holding is worth nothing, but stays on the table so the loss stays legible.
            $value = $isBankrupt ? 0.0 : $price * (float) $held->getSharesOutstanding() * $ownership;
            $total += $value;

            $holdings[] = [
                'ticker' => $ticker,
                'name' => $held->getName(),
                'industry' => $held->getIndustry(),
                'ownership' => $ownership,
                'tier' => ($tiers[$ticker] ?? AnchorStake::Minority)->label(),
                'price' => $price,
                'changePercent' => $isBankrupt ? null : $this->priceChangeFeed->changeForTicker($ticker, $price),
                'value' => $value,
                'shareOfPortfolio' => 0.0,
                'shareOfNav' => $equity > 0.0 ? $value / $equity : 0.0,
                'isBankrupt' => $isBankrupt,
            ];
        }

        if ($holdings === []) {
            return null;
        }

        foreach ($holdings as $i => $holding) {
            $holdings[$i]['shareOfPortfolio'] = $total > 0.0 ? $holding['value'] / $total : 0.0;
        }

        usort($holdings, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return [
            'holdings' => $holdings,
            'totalValue' => $total,
            'shareOfNav' => $equity > 0.0 ? $total / $equity : 0.0,
        ];
    }
}
