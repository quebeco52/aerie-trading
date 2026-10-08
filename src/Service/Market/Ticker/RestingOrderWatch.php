<?php

declare(strict_types=1);

namespace App\Service\Market\Ticker;

use App\Service\Market\Trading\LimitOrderDispatchGate;
use App\Service\Notification\PriceAlertService;

/**
 * Finds the instruments whose resting limit orders or price alerts this tick's quotes may have reached.
 *
 * It only collects: the caller dispatches the checks after the tick commits, because a check sent from inside
 * the tick hands the worker rows the tick still holds, in the opposite lock order (a 1213). The gate keeps it
 * to one check per instrument per retry window while the worker answers.
 */
final class RestingOrderWatch
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly LimitOrderDispatchGate $gate,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $quotes      Stock and fund quotes this tick.
     * @param array<int, array<string, mixed>> $struckBonds Bonds valued this tick: between marks a bond's quote cannot have crossed.
     * @return array{limit_orders: array<string, mixed>, alerts: array<string, float>} Last price per ticker to check.
     */
    public function collect(array $quotes, array $struckBonds, int $tickCount): array
    {
        $limitOrders = [];
        $alerts = [];

        // The whole book's bounds come over in one MGET rather than a round trip per instrument.
        $tradable = array_values(array_filter(
            array_merge($quotes, $struckBonds),
            static fn (array $update): bool => empty($update['is_bankrupt'])
        ));
        $boundsKeys = array_map(static fn (array $update): string => "limit_bounds:{$update['ticker']}", $tradable);
        $boundsRows = $boundsKeys === [] ? [] : $this->redis->mGet($boundsKeys);

        foreach ($tradable as $i => $update) {
            $ticker = $update['ticker'];
            $price = $update['price'];

            $boundsJson = $boundsRows[$i] ?? false;

            if (!is_string($boundsJson) || $boundsJson === '') {
                // No cached book: either no orders, or a Redis flushed since they were placed. Checked once
                // either way; the handler rewrites the bounds, and the gate holds it to one dispatch until then.
                if ($this->gate->allow($ticker, $tickCount)) {
                    $limitOrders[$ticker] = $price;
                }
                continue;
            }

            $bounds = json_decode($boundsJson, true);
            if ($price <= ($bounds['buy'] ?? 0.0) || $price >= ($bounds['sell'] ?? 999999999.0)) {
                if ($this->gate->allow($ticker, $tickCount)) {
                    $limitOrders[$ticker] = $price;
                }
            } else {
                $this->gate->settle($ticker);
            }
        }

        // Price alerts: one read of every instrument's nearest targets; the gate is shared under its own keys.
        $alertBounds = $this->redis->hGetAll(PriceAlertService::REDIS_KEY);
        if (is_array($alertBounds) && $alertBounds !== []) {
            foreach ($quotes as $update) {
                $ticker = $update['ticker'];
                if (!isset($alertBounds[$ticker]) || !empty($update['is_bankrupt'])) {
                    continue;
                }
                $bounds = json_decode((string) $alertBounds[$ticker], true);
                $gateKey = "alert:{$ticker}";
                if (is_array($bounds) && PriceAlertService::crossed($bounds, (float) $update['price'])) {
                    if ($this->gate->allow($gateKey, $tickCount)) {
                        $alerts[$ticker] = (float) $update['price'];
                    }
                } else {
                    $this->gate->settle($gateKey);
                }
            }
        }

        return ['limit_orders' => $limitOrders, 'alerts' => $alerts];
    }
}
