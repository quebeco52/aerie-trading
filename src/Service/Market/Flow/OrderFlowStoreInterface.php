<?php

declare(strict_types=1);

namespace App\Service\Market\Flow;

/**
 * Signed order flow accumulated between ticks: one running total of shares bought minus shares sold, per
 * ticker.
 *
 * Trades arrive on the web process and impact is applied on the ticker process, so the two need somewhere
 * to meet that survives neither of them. The total is drained on each tick rather than read, because a
 * quantity that has already moved the price must not move it again on the next one.
 */
interface OrderFlowStoreInterface
{
    /**
     * Adds a fill to the running total.
     *
     * @param string $ticker         The instrument traded.
     * @param float  $signedQuantity Positive for a buy, negative for a sell.
     */
    public function record(string $ticker, float $signedQuantity): void;

    /**
     * Takes every accumulated total and resets them, atomically.
     *
     * @return array<string, float> ticker => signed shares since the last drain.
     */
    public function drain(): array;
}
