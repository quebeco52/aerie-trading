<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Entity\Stock;
use App\Repository\StockRepository;
use App\Service\Market\IndexCommittee;

/**
 * Builds the holdings breakdown shown on a fund's page.
 *
 * The fund tracks an INDEX, not the whole board, so its composition is the index's standing membership —
 * and a constituent's weight is its share of the members' FLOAT-adjusted capitalisation, because what a
 * passive fund can actually hold is the part of a company that trades. Listing every company instead was
 * describing a market capitalisation rather than a fund: it showed holdings in names the fund does not own
 * and weights struck on stock it could not buy.
 *
 * Derived rather than stored, like the index level itself. Before the first reconstitution there is no
 * membership, and the fund is then the whole live board — which is exactly what it was.
 */
class EtfCompositionBuilder
{
    public function __construct(
        private readonly StockRepository $stocks,
        private readonly IndexCommittee $indexCommittee,
    ) {}

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
        $members = $this->indexCommittee->currentMembers();

        $capByTicker = [];
        $totalMarketCap = 0.0;
        foreach ($allAssets as $stock) {
            if ($stock->isBankrupt()) {
                // A delisted shell is not a holding; counting it would dilute every live weight.
                continue;
            }

            // Only what the index carries. An empty membership is a market that has not reconstituted yet,
            // and there the fund is still the whole board.
            if ($members !== [] && !isset($members[$stock->getTicker()])) {
                continue;
            }

            $marketCap = IndexCommittee::floatAdjustedCap($stock);
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
