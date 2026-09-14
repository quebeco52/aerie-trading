<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Entity\Stock;
use App\Repository\StockRepository;

/**
 * Builds the holdings breakdown shown on a fund's page.
 *
 * The fund tracks the whole listed market by capitalisation, so its composition is derived from the
 * live board rather than stored: a constituent's weight is its share of total market cap, and a
 * delisted shell carries none of the fund at all.
 */
class EtfCompositionBuilder
{
    public function __construct(private readonly StockRepository $stocks) {}

    /**
     * @return array{
     *     allAssets: list<Stock>,
     *     pieLabels: list<string>,
     *     pieData: list<float>,
     *     sharesMap: array<string, float>,
     *     components: list<array{ticker: string, name: string, sector: string, price: float, marketCap: float, weight: float}>
     * }
     */
    public function build(): array
    {
        $allAssets = $this->stocks->findAll();

        $capByTicker = [];
        $totalMarketCap = 0.0;
        foreach ($allAssets as $stock) {
            if ($stock->isBankrupt()) {
                // A delisted shell is not a holding; counting it would dilute every live weight.
                continue;
            }

            $marketCap = (float) $stock->getPrice() * (float) $stock->getSharesOutstanding();
            $capByTicker[$stock->getTicker()] = $marketCap;
            $totalMarketCap += $marketCap;
        }

        $pieLabels = [];
        $pieData = [];
        $sharesMap = [];
        $components = [];

        foreach ($allAssets as $stock) {
            $ticker = $stock->getTicker();
            if (!isset($capByTicker[$ticker])) {
                continue;
            }

            $marketCap = $capByTicker[$ticker];

            $pieLabels[] = $ticker;
            $pieData[] = $marketCap;
            $sharesMap[$ticker] = (float) $stock->getSharesOutstanding();

            $components[] = [
                'ticker' => $ticker,
                'name' => $stock->getName(),
                'sector' => $stock->getSector(),
                'price' => (float) $stock->getPrice(),
                'marketCap' => $marketCap,
                'weight' => $totalMarketCap > 0.0 ? ($marketCap / $totalMarketCap) * 100.0 : 0.0,
            ];
        }

        usort($components, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);

        return [
            'allAssets' => $allAssets,
            'pieLabels' => $pieLabels,
            'pieData' => $pieData,
            'sharesMap' => $sharesMap,
            'components' => $components,
        ];
    }
}
