<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\TradeOrder;

/**
 * An order as the desk took it: filled now, or resting on the book until the market reaches its price. The
 * confirmation a player reads depends on which, so a resting limit is never announced as a purchase.
 */
final readonly class PlacedOrderDTO
{
    public function __construct(
        /** STOCK, ETF, BOND or OPTION. */
        public string $assetType,
        public string $action,
        public int $quantity,
        public string $ticker,
        public string $orderType,
        public ?string $limitPrice,
        public ?string $stopPrice,
        public bool $resting,
    ) {}

    public static function fromOrder(TradeOrder $order): self
    {
        return new self(
            $order->getAssetType(),
            $order->getAction(),
            $order->getQuantity(),
            $order->getTicker(),
            $order->getOrderType(),
            $order->getLimitPrice(),
            $order->getStopPrice(),
            $order->getStatus() === TradeOrder::STATUS_OPEN,
        );
    }

    /** The line confirming the order to the player. */
    public function confirmation(): string
    {
        // An option order is counted in contracts of a hundred shares; saying "shares" would understate the position
        // by the multiplier.
        $units = sprintf('%s %s of %s', number_format($this->quantity), match ($this->assetType) {
            'OPTION' => 'contracts',
            'BOND' => 'bonds',
            default => 'shares',
        }, $this->ticker);

        if (!$this->resting) {
            $verb = match ($this->action) {
                'SHORT' => 'Sold short',
                'COVER' => 'Covered',
                'SELL' => 'Sold',
                'WRITE' => 'Wrote',
                default => 'Bought',
            };

            return "{$verb} {$units}.";
        }

        $side = match ($this->action) {
            'SHORT' => 'sell short',
            'COVER' => 'cover',
            'SELL' => 'sell',
            default => 'buy',
        };

        return match ($this->orderType) {
            TradeOrder::TYPE_STOP => sprintf('Placed a stop order to %s %s at %s.', $side, $units, self::dollars($this->stopPrice)),
            TradeOrder::TYPE_STOP_LIMIT => sprintf('Placed a stop-limit order to %s %s at %s, limit %s.', $side, $units, self::dollars($this->stopPrice), self::dollars($this->limitPrice)),
            default => sprintf('Placed a limit order to %s %s at %s.', $side, $units, self::dollars($this->limitPrice)),
        };
    }

    private static function dollars(?string $price): string
    {
        return '$' . number_format((float) $price, 2);
    }
}
