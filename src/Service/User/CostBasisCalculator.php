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
        $averages = [];
        foreach ($this->walk($filledOrders)['positions'] as $ticker => $position) {
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

    /**
     * Gains locked in by closing trades, per ticker: a sale against the running average cost, a cover
     * against the running average proceeds, each net of its share of the closing trade's stamp duty.
     * Option orders are left out: they are priced per share but counted in contracts, and are closed by
     * expiry and exercise as well as by trades.
     *
     * @param  iterable<TradeOrder> $filledOrders Filled orders, oldest first.
     * @return array<string, float> ticker => realised gain in currency, for every ticker that has closed any.
     */
    public function realisedByTicker(iterable $filledOrders): array
    {
        return $this->walk($filledOrders)['realised'];
    }

    /**
     * @param  iterable<TradeOrder> $filledOrders
     * @return array{positions: array<string, array{qty: int, cost: float}>, realised: array<string, float>}
     */
    private function walk(iterable $filledOrders): array
    {
        /** @var array<string, array{qty: int, cost: float}> $positions */
        $positions = [];
        /** @var array<string, float> $realised */
        $realised = [];

        foreach ($filledOrders as $order) {
            $ticker = $order->getTicker();
            if ($ticker === null || $ticker === '') {
                continue;
            }

            $quantity = $order->getFilledQuantity() > 0 ? (int) $order->getFilledQuantity() : (int) $order->getQuantity();
            $price = (float) ($order->getExecutionPrice() ?? $order->getLimitPrice() ?? 0.0);
            // Transfer taxes are part of what a position cost (and come off what a short raised), as a tax basis has them.
            $duty = (float) ($order->getStampDuty() ?? 0.0);
            $positions[$ticker] ??= ['qty' => 0, 'cost' => 0.0];
            $tracksRealised = $order->getAssetType() !== 'OPTION';

            $action = $order->getAction();

            if ($action === 'BUY') {
                $positions[$ticker]['cost'] += ($quantity * $price) + $duty;
                $positions[$ticker]['qty'] += $quantity;
                continue;
            }

            if ($action === 'SHORT') {
                // A short's basis is what it was sold for. Both legs are carried negative so the running
                // average is proceeds per share and the pool arithmetic below is the same in either
                // direction — without that, a short reports its cost as zero and its P&L as its whole value.
                $positions[$ticker]['cost'] -= ($quantity * $price) - $duty;
                $positions[$ticker]['qty'] -= $quantity;
                continue;
            }

            if ($action === 'COVER' && $positions[$ticker]['qty'] < 0) {
                $averageProceeds = $positions[$ticker]['cost'] / $positions[$ticker]['qty'];
                $closed = min($quantity, -$positions[$ticker]['qty']);
                if ($tracksRealised && $quantity > 0) {
                    $realised[$ticker] = ($realised[$ticker] ?? 0.0)
                        + $closed * ($averageProceeds - $price) - $duty * ($closed / $quantity);
                }
                $positions[$ticker]['qty'] = min(0, $positions[$ticker]['qty'] + $quantity);
                $positions[$ticker]['cost'] = $positions[$ticker]['qty'] * $averageProceeds;
                continue;
            }

            if ($action === 'SELL' && $positions[$ticker]['qty'] > 0) {
                $averageCost = $positions[$ticker]['cost'] / $positions[$ticker]['qty'];
                $closed = min($quantity, $positions[$ticker]['qty']);
                if ($tracksRealised && $quantity > 0) {
                    $realised[$ticker] = ($realised[$ticker] ?? 0.0)
                        + $closed * ($price - $averageCost) - $duty * ($closed / $quantity);
                }
                $positions[$ticker]['qty'] = max(0, $positions[$ticker]['qty'] - $quantity);
                $positions[$ticker]['cost'] = $positions[$ticker]['qty'] * $averageCost;
            }
        }

        return ['positions' => $positions, 'realised' => $realised];
    }
}
