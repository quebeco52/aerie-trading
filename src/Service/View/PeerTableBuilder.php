<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Company\FinancialBooks;
use App\Data\Company\Sectors;
use App\Entity\Stock;
use App\Repository\StockRepository;
use App\Service\Corporate\Segment\SegmentBooks;

/**
 * Builds the sector comparison table shown beside a company.
 *
 * Returns the peers largest first, because the question the table answers is where this company sits
 * among the names it competes with, and that reads off a size ranking.
 */
class PeerTableBuilder
{
    public function __construct(private readonly StockRepository $stocks) {}

    /**
     * @return list<array{
     *     ticker: string, name: string, industry: string|null, price: float,
     *     marketCap: float, peRatio: float|null, roic: float, totalEquity: float, isBankrupt: bool, sameIndustry: bool, sharedMarket: bool
     * }>
     */
    public function build(Stock $stock): array
    {
        $ownMarkets = self::marketsOf($stock);
        $peers = [];

        foreach ($this->stocks->findPeersOf($stock) as $peer) {
            $price = (float) $peer->getPrice();
            $eps = (float) $peer->getEarningsPerShare();
            $isBankrupt = $peer->isBankrupt();
            $sameIndustry = $peer->getIndustry() === $stock->getIndustry();
            $sharedMarket = !$sameIndustry && array_intersect($ownMarkets, self::marketsOf($peer)) !== [];

            $peers[] = [
                'ticker' => $peer->getTicker(),
                'name' => $peer->getName(),
                'industry' => $peer->getIndustry(),
                'price' => $price,
                // A delisted shell has no claim left to capitalise, so it ranks at zero rather than
                // at whatever its last traded price happened to be.
                'marketCap' => $isBankrupt ? 0.0 : $price * (float) $peer->getSharesOutstanding(),
                'peRatio' => (!$isBankrupt && $eps > 0.0) ? $price / $eps : null,
                'roic' => $this->returnOnCapital($peer),
                'totalEquity' => (float) $peer->getTotalEquity(),
                'isBankrupt' => $isBankrupt,
                'sameIndustry' => $sameIndustry,
                'sharedMarket' => $sharedMarket,
            ];
        }

        // Direct competitors lead, followed by cross-market rivals, then the rest of the sector, each by size.
        usort($peers, static fn(array $a, array $b): int => [
            $b['sameIndustry'] || $b['sharedMarket'],
            $b['sameIndustry'],
            $b['marketCap'],
        ] <=> [
            $a['sameIndustry'] || $a['sharedMarket'],
            $a['sameIndustry'],
            $a['marketCap'],
        ]);

        return $peers;
    }

    /**
     * Distinct active product or book markets the stock sells into.
     *
     * @return list<string>
     */
    private static function marketsOf(Stock $stock): array
    {
        $primary = $stock->getIndustry();
        if ($primary === null || $primary === '') {
            return [];
        }

        $markets = [$primary];
        if (!(new \ReflectionProperty(Stock::class, 'ticker'))->isInitialized($stock)) {
            return $markets;
        }

        if (FinancialBooks::hasBooks($stock)) {
            foreach (FinancialBooks::industries($stock) as $industry) {
                if ($industry !== '') {
                    $markets[] = $industry;
                }
            }
        } elseif (SegmentBooks::keepsBooks($stock)) {
            $hasPlant = SegmentBooks::plant($stock->getEarningsMomentumZ() ?? []) !== null;
            foreach (SegmentBooks::industries($stock) as $segment => $industry) {
                if ($industry !== '' && (!$hasPlant || SegmentBooks::holdsPlant($stock, $segment))) {
                    $markets[] = $industry;
                }
            }
        }

        return array_values(array_unique($markets));
    }

    /**
     * The return a peer is judged on: equity for a balance-sheet business, invested capital otherwise.
     *
     * A lender funds its book with deposits and wholesale borrowing, so capital employed is not a
     * meaningful denominator for it and ROIC would rank it against industrials on a number that
     * means something different.
     */
    private function returnOnCapital(Stock $peer): float
    {
        $businessModel = Sectors::businessModelFor($peer->getIndustry());

        if (Sectors::isFinancial($businessModel)) {
            return (float) $peer->getCurrentRoe() ?: (float) $peer->getBaselineRoe();
        }

        return (float) $peer->getCurrentRoic() ?: (float) $peer->getBaselineRoic();
    }
}
