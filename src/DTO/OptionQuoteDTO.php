<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * One listed contract priced as of one tick: the desk's mark, the two sides it will trade, and the
 * sensitivities both the holder and the desk's own hedge are computed from.
 *
 * Every premium is per SHARE. Multiplying by the contract multiplier happens where cash moves, not here,
 * so a quote can be compared with a strike and an underlying price without a units conversion in between.
 */
final readonly class OptionQuoteDTO
{
    /**
     * @param float $mark              Mid premium per share.
     * @param float $bid               What the desk pays per share.
     * @param float $ask               What the desk sells at per share.
     * @param float $impliedVolatility The volatility the mark was struck at, after the smile.
     * @param float $delta             Change in premium per unit change in the underlying.
     * @param float $gamma             Change in delta per unit change in the underlying.
     * @param float $vega              Change in premium per 1.00 change in volatility.
     * @param float $theta             Change in premium per YEAR of calendar time.
     * @param float $rho               Change in premium per 1.00 change in the risk-free rate.
     * @param float $timeToExpiry      Years of life left at the quote.
     * @param float $riskFreeRate      Rate the contract was discounted at.
     * @param float $dividendYield     Continuous yield the spot leg was discounted at.
     */
    public function __construct(
        public float $mark,
        public float $bid,
        public float $ask,
        public float $impliedVolatility,
        public float $delta,
        public float $gamma,
        public float $vega,
        public float $theta,
        public float $rho,
        public float $timeToExpiry,
        public float $riskFreeRate,
        public float $dividendYield,
    ) {}

    /** The side of the quote a buyer lifts, or a seller hits. */
    public function executionPrice(bool $isBuy): float
    {
        return $isBuy ? $this->ask : $this->bid;
    }
}
