<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Entity\Etf;
use App\Entity\Stock;
use App\Entity\User;
use App\Repository\HoldingRepository;
use App\Repository\TradeOrderRepository;
use App\Service\User\CostBasisCalculator;
use App\Service\User\DividendIncomeCalculator;

/**
 * Builds the "your position" panel: size, cost, unrealised P&L, income and working orders.
 */
class ViewerPositionBuilder
{
    // --- Order History ---

    /** Settled orders listed beneath a position; older fills belong to the portfolio history page. */
    private const TRADE_HISTORY_ROWS = 20;

    public function __construct(
        private readonly HoldingRepository $holdings,
        private readonly TradeOrderRepository $orders,
        private readonly CostBasisCalculator $costBasis,
        private readonly DividendIncomeCalculator $dividendIncome,
    ) {}

    /**
     * @return array<string, mixed> The position keys the stock page renders.
     */
    public function build(Stock|Etf $asset, string $ticker, ?User $viewer): array
    {
        $position = [
            'userQuantity' => 0,
            'userAvgCost' => (float) $asset->getPrice(),
            'userUnrealizedPnL' => 0.0,
            'userUnrealizedPnLPercent' => 0.0,
            'userDividendIncome' => 0.0,
            'openOrders' => [],
            'userTrades' => [],
        ];

        if (!$viewer instanceof User) {
            return $position;
        }

        $holding = $asset instanceof Etf
            ? $this->holdings->findEtfHolding($viewer, $asset)
            : $this->holdings->findStockHolding($viewer, $asset);

        $quantity = $holding !== null ? (int) $holding->getQuantity() : 0;

        // Lifetime dividend cash this ticker has paid the viewer, read whether or not they still
        // hold it: income already received survives selling out, and zeroing it for a closed
        // position would hide cash the viewer actually has.
        $position['userDividendIncome'] = $this->dividendIncome->totalsByTicker($viewer)[$ticker] ?? 0.0;
        $position['userQuantity'] = $quantity;
        $position['openOrders'] = $this->orders->findOpenForUserAndTicker($viewer, $ticker);
        $position['userTrades'] = $this->orders->findSettledForUserAndTicker($viewer, $ticker, self::TRADE_HISTORY_ROWS);

        if ($quantity <= 0) {
            return $position;
        }

        // The weighted-average basis the dashboard reports, from the same calculator over the same
        // ordered fills. Averaging buys alone and ignoring sells gave this page a different cost,
        // and a different P&L, for the very same position.
        $filledOrders = $this->orders->findFilledForUserAndTicker($viewer, $ticker);
        $averageCost = $this->costBasis->calculateForTicker($filledOrders, $ticker) ?? $position['userAvgCost'];

        $positionCost = $averageCost * $quantity;
        $marketValue = (float) $asset->getPrice() * $quantity;
        $unrealised = $marketValue - $positionCost;

        $position['userAvgCost'] = $averageCost;
        $position['userUnrealizedPnL'] = $unrealised;
        $position['userUnrealizedPnLPercent'] = $positionCost > 0.0 ? ($unrealised / $positionCost) * 100.0 : 0.0;

        return $position;
    }
}
