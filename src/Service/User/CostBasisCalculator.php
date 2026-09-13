<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\TradeOrder;

/**
 * Weighted-average cost basis per ticker, derived from a user's filled orders.
 *
 * One implementation for every surface that prints an average cost or an unrealized P&L. The stock page
 * used to average every BUY the user had ever placed and ignore SELLs entirely while the dashboard ran the
 * moving average below, so the same position showed two different cost bases, and two different P&L
 * figures, depending on which page it was read from.
 *
 * Weighted average is the convention the schema can actually support: a purchase adds its consideration to
 * the pool, a sale removes shares at the running average and therefore leaves the average per share
 * unchanged. There are no tax lots to identify against.
 */
final class CostBasisCalculator
{
    /**
     * @param  iterable<TradeOrder> $filledOrders Filled orders, oldest first.
     * @return array<string, float> ticker => average price per share, for tickers with a surviving position.
     *                               For a short this is the average PROCEEDS per share, which is the basis
     *                               its profit is measured down from rather than up to.
     */
    public function calculate(iterable $filledOrders): array
    {
        /** @var array<string, array{qty: int, cost: float}> $positions */
        $positions = [];

        foreach ($filledOrders as $order) {
            $ticker = $order->getTicker();
            if ($ticker === null || $ticker === '') {
                continue;
            }

            $quantity = $order->getFilledQuantity() > 0 ? (int) $order->getFilledQuantity() : (int) $order->getQuantity();
            $price = (float) ($order->getExecutionPrice() ?? $order->getLimitPrice() ?? 0.0);
            $positions[$ticker] ??= ['qty' => 0, 'cost' => 0.0];

            $action = $order->getAction();

            if ($action === 'BUY') {
                $positions[$ticker]['cost'] += $quantity * $price;
                $positions[$ticker]['qty'] += $quantity;
                continue;
            }

            if ($action === 'SHORT') {
                // A short's basis is what it was sold for. Both legs are carried negative so the running
                // average is proceeds per share and the pool arithmetic below is the same in either
                // direction — without that, a short reports its cost as zero and its P&L as its whole value.
                $positions[$ticker]['cost'] -= $quantity * $price;
                $positions[$ticker]['qty'] -= $quantity;
                continue;
            }

            if ($action === 'COVER' && $positions[$ticker]['qty'] < 0) {
                $averageProceeds = $positions[$ticker]['cost'] / $positions[$ticker]['qty'];
                $positions[$ticker]['qty'] = min(0, $positions[$ticker]['qty'] + $quantity);
                $positions[$ticker]['cost'] = $positions[$ticker]['qty'] * $averageProceeds;
                continue;
            }

            if ($action === 'SELL' && $positions[$ticker]['qty'] > 0) {
                $averageCost = $positions[$ticker]['cost'] / $positions[$ticker]['qty'];
                $positions[$ticker]['qty'] = max(0, $positions[$ticker]['qty'] - $quantity);
                $positions[$ticker]['cost'] = $positions[$ticker]['qty'] * $averageCost;
            }
        }

        $averages = [];
        foreach ($positions as $ticker => $position) {
            // Per share either way. Cost and quantity carry the same sign, so the quotient is positive for
            // a long and for a short alike: it is a price, not a signed exposure.
            if ($position['qty'] !== 0) {
                $averages[$ticker] = round($position['cost'] / $position['qty'], 2);
            }
        }

        return $averages;
    }

    /**
     * Average cost for a single ticker, or null when nothing is held.
     *
     * @param iterable<TradeOrder> $filledOrders Filled orders, oldest first.
     */
    public function calculateForTicker(iterable $filledOrders, string $ticker): ?float
    {
        return $this->calculate($filledOrders)[$ticker] ?? null;
    }
}
