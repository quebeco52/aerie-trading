<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * A bond marked against the live curve at one instant: what it is worth, and how that worth moves.
 */
final readonly class BondValuationDTO
{
    /**
     * @param float $dirtyPrice       Present value of the remaining cash flows, accrued interest included.
     * @param float $cleanPrice       Dirty price less accrued interest: the quoted price.
     * @param float $accruedInterest  Coupon earned by the seller since the last payment.
     * @param float $yieldToMaturity  Continuously compounded rate that reproduces the dirty price.
     * @param float $modifiedDuration First-order sensitivity to a parallel yield shift, in years.
     * @param float $convexity        Second-order sensitivity, in years squared.
     */
    public function __construct(
        public float $dirtyPrice,
        public float $cleanPrice,
        public float $accruedInterest,
        public float $yieldToMaturity,
        public float $modifiedDuration,
        public float $convexity,
    ) {}
}
