<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\PlacedOrderDTO;
use App\Entity\TradeOrder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** The confirmation a player reads says what happened: a fill, or an order left on the book at its price. */
#[Group('unit')]
class PlacedOrderDTOTest extends TestCase
{
    public function testAFillSaysWhatWasTraded(): void
    {
        $this->assertSame('Sold short 1,200 shares of APEX.', $this->order('SHORT', 1200, 'APEX', TradeOrder::TYPE_MARKET)->confirmation());
        $this->assertSame('Wrote 3 contracts of APEX-C100.', (new PlacedOrderDTO('OPTION', 'WRITE', 3, 'APEX-C100', TradeOrder::TYPE_MARKET, null, null, false))->confirmation());
    }

    public function testARestingOrderSaysItWasPlacedAndAtWhatPrice(): void
    {
        $this->assertSame(
            'Placed a stop order to sell 10 shares of APEX at $75.00.',
            $this->order('SELL', 10, 'APEX', TradeOrder::TYPE_STOP, stop: '75', resting: true)->confirmation(),
        );
        $this->assertSame(
            'Placed a stop-limit order to cover 10 shares of APEX at $105.00, limit $106.50.',
            $this->order('COVER', 10, 'APEX', TradeOrder::TYPE_STOP_LIMIT, limit: '106.5', stop: '105', resting: true)->confirmation(),
        );
    }

    public function testAMarketableLimitThatFilledIsReportedAsAFill(): void
    {
        $order = (new TradeOrder())
            ->setAssetType('BOND')
            ->setAction('BUY')
            ->setQuantity(4)
            ->setTicker('G02-001')
            ->setOrderType(TradeOrder::TYPE_LIMIT)
            ->setLimitPrice('101.00')
            ->setStatus(TradeOrder::STATUS_FILLED);

        $this->assertSame('Bought 4 bonds of G02-001.', PlacedOrderDTO::fromOrder($order)->confirmation());
    }

    private function order(string $action, int $quantity, string $ticker, string $type, ?string $limit = null, ?string $stop = null, bool $resting = false): PlacedOrderDTO
    {
        return new PlacedOrderDTO('STOCK', $action, $quantity, $ticker, $type, $limit, $stop, $resting);
    }
}
