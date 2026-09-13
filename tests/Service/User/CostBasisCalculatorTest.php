<?php

declare(strict_types=1);

namespace App\Tests\Service\User;

use App\Entity\TradeOrder;
use App\Service\User\CostBasisCalculator;
use PHPUnit\Framework\TestCase;

/**
 * One cost basis for every surface that prints one.
 *
 * The stock page used to average every BUY the user had placed and ignore SELLs entirely, while the
 * dashboard ran the weighted average below — so the same position reported two different average costs
 * and two different unrealized P&L figures depending on which page it was read from.
 */
final class CostBasisCalculatorTest extends TestCase
{
    private CostBasisCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new CostBasisCalculator();
    }

    private function order(string $action, int $quantity, ?string $price, string $ticker = 'APEX'): TradeOrder
    {
        $order = new TradeOrder();
        $order->setTicker($ticker);
        $order->setAction($action);
        $order->setOrderType('MARKET');
        $order->setStatus('FILLED');
        $order->setQuantity($quantity);
        $order->setFilledQuantity($quantity);
        $order->setExecutionPrice($price);

        return $order;
    }

    /** Two purchases average by consideration, not by price. */
    public function testWeightsPurchasesByConsideration(): void
    {
        $basis = $this->calculator->calculate([
            $this->order('BUY', 10, '10.00'),
            $this->order('BUY', 30, '20.00'),
        ]);

        // (10 x 10 + 30 x 20) / 40 = 17.50
        $this->assertEqualsWithDelta(17.50, $basis['APEX'], 0.001);
    }

    /** A sale removes shares at the running average, so the average per share does not move. */
    public function testSaleLeavesTheAverageUnchanged(): void
    {
        $basis = $this->calculator->calculate([
            $this->order('BUY', 10, '10.00'),
            $this->order('BUY', 30, '20.00'),
            $this->order('SELL', 20, '50.00'),
        ]);

        $this->assertEqualsWithDelta(17.50, $basis['APEX'], 0.001);
    }

    /**
     * The bug this replaces: ignoring sales and averaging only purchases gets the average right but the
     * POSITION wrong, because the remaining quantity no longer matches the purchases it was drawn from.
     */
    public function testSellingOutAndRebuyingResetsTheBasis(): void
    {
        $basis = $this->calculator->calculate([
            $this->order('BUY', 100, '10.00'),
            $this->order('SELL', 100, '12.00'),
            $this->order('BUY', 50, '40.00'),
        ]);

        $this->assertEqualsWithDelta(40.00, $basis['APEX'], 0.001, 'Averaging every BUY ever placed would report $20.');
    }

    /** A fully closed position has no basis to report. */
    public function testClosedPositionIsAbsent(): void
    {
        $basis = $this->calculator->calculate([
            $this->order('BUY', 10, '10.00'),
            $this->order('SELL', 10, '15.00'),
        ]);

        $this->assertArrayNotHasKey('APEX', $basis);
        $this->assertNull($this->calculator->calculateForTicker([], 'APEX'));
    }

    /** Overselling cannot drive the position, or the cost, negative. */
    public function testOversellIsFlooredAtZero(): void
    {
        $basis = $this->calculator->calculate([
            $this->order('BUY', 10, '10.00'),
            $this->order('SELL', 25, '15.00'),
        ]);

        $this->assertArrayNotHasKey('APEX', $basis);
    }

    /** Tickers are kept apart. */
    public function testPositionsAreTrackedPerTicker(): void
    {
        $basis = $this->calculator->calculate([
            $this->order('BUY', 10, '10.00', 'APEX'),
            $this->order('BUY', 10, '30.00', 'BREW'),
        ]);

        $this->assertEqualsWithDelta(10.00, $basis['APEX'], 0.001);
        $this->assertEqualsWithDelta(30.00, $basis['BREW'], 0.001);
    }

    /** A limit order that filled without an execution price recorded falls back to the limit it filled at. */
    public function testFallsBackToLimitPriceWhenNoExecutionPriceIsRecorded(): void
    {
        $order = $this->order('BUY', 10, null);
        $order->setLimitPrice('25.00');

        $this->assertEqualsWithDelta(25.00, $this->calculator->calculate([$order])['APEX'], 0.001);
    }
}
