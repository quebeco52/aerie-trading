<?php

namespace App\Tests\Service;

use App\Entity\TradeOrder;
use PHPUnit\Framework\TestCase;

class PortfolioCalculationTest extends TestCase
{
    /**
     * Tests moving average cost basis calculation logic.
     */
    public function testMovingAverageCostBasisCalculation(): void
    {
        // Scenario: User buys 10 shares @ $100 ($1000 cost), then buys 10 shares @ $120 ($1200 cost) -> Avg cost = $110
        $order1 = new TradeOrder();
        $order1->setTicker('SHRK');
        $order1->setAction('BUY');
        $order1->setQuantity(10);
        $order1->setFilledQuantity(10);
        $order1->setExecutionPrice('100.00');
        $order1->setStatus('FILLED');

        $order2 = new TradeOrder();
        $order2->setTicker('SHRK');
        $order2->setAction('BUY');
        $order2->setQuantity(10);
        $order2->setFilledQuantity(10);
        $order2->setExecutionPrice('120.00');
        $order2->setStatus('FILLED');

        $orders = [$order1, $order2];
        $costMap = $this->calculateCostBasisMap($orders);

        $this->assertArrayHasKey('SHRK', $costMap);
        $this->assertEquals(110.00, $costMap['SHRK']);

        // Scenario 2: User sells 5 shares -> Avg cost should remain $110
        $order3 = new TradeOrder();
        $order3->setTicker('SHRK');
        $order3->setAction('SELL');
        $order3->setQuantity(5);
        $order3->setFilledQuantity(5);
        $order3->setExecutionPrice('130.00');
        $order3->setStatus('FILLED');

        $orders[] = $order3;
        $costMap2 = $this->calculateCostBasisMap($orders);

        $this->assertEquals(110.00, $costMap2['SHRK']);
    }

    /**
     * Replicates the calculation method in DashboardController.
     *
     * @param TradeOrder[] $orders
     * @return array<string, float>
     */
    private function calculateCostBasisMap(array $orders): array
    {
        $basis = [];

        foreach ($orders as $order) {
            $ticker = $order->getTicker();
            if (!$ticker) continue;

            $qty = $order->getFilledQuantity() > 0 ? $order->getFilledQuantity() : $order->getQuantity();
            $price = (float) ($order->getExecutionPrice() ?? $order->getLimitPrice() ?? 0.0);

            if (!isset($basis[$ticker])) {
                $basis[$ticker] = ['qty' => 0, 'cost' => 0.0];
            }

            if ($order->getAction() === 'BUY') {
                $basis[$ticker]['cost'] += ($qty * $price);
                $basis[$ticker]['qty'] += $qty;
            } elseif ($order->getAction() === 'SELL') {
                if ($basis[$ticker]['qty'] > 0) {
                    $currentAvg = $basis[$ticker]['cost'] / $basis[$ticker]['qty'];
                    $basis[$ticker]['qty'] = max(0, $basis[$ticker]['qty'] - $qty);
                    $basis[$ticker]['cost'] = $basis[$ticker]['qty'] * $currentAvg;
                }
            }
        }

        $result = [];
        foreach ($basis as $ticker => $data) {
            if ($data['qty'] > 0) {
                $result[$ticker] = round($data['cost'] / $data['qty'], 2);
            }
        }

        return $result;
    }
}
