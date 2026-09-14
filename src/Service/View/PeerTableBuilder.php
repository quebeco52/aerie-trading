<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Sectors;
use App\Entity\Stock;
use App\Repository\StockRepository;

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
     *     marketCap: float, peRatio: float|null, roic: float, totalEquity: float, isBankrupt: bool
     * }>
     */
    public function build(Stock $stock): array
    {
        $peers = [];

        foreach ($this->stocks->findPeersOf($stock) as $peer) {
            $price = (float) $peer->getPrice();
            $eps = (float) $peer->getEarningsPerShare();
            $isBankrupt = $peer->isBankrupt();

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
            ];
        }

        usort($peers, static fn (array $a, array $b): int => $b['marketCap'] <=> $a['marketCap']);

        return $peers;
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
        $businessModel = Sectors::INDUSTRY_METRICS[$peer->getIndustry() ?? 'General']['business_model'] ?? 'none';

        if (Sectors::isFinancial($businessModel)) {
            return (float) ($peer->getCurrentRoe() ?: $peer->getBaselineRoe());
        }

        return (float) ($peer->getCurrentRoic() ?: $peer->getBaselineRoic());
    }
}
