<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Outcome of rolling a quarter's maturing principal.
 *
 * A firm with market access refinances at the prevailing rate and its principal is unchanged. A firm
 * without it must repay from cash, and any part it cannot fund is a payment default.
 *
 * Every field defaults to the "nothing came due" case so a test double that returns an unconfigured
 * instance leaves the balance sheet untouched rather than failing on an uninitialized property.
 */
class MaturityRollDTO
{
    public function __construct(
        /** Principal that came due this quarter. */
        public float $maturingPrincipal = 0.0,
        /** True when the primary market was open to this issuer and the maturity was refinanced. */
        public bool $refinanced = true,
        /** Principal actually repaid out of cash because it could not be refinanced. */
        public float $principalRepaid = 0.0,
        /** Repayment the firm could not fund from cash, before any emergency financing is attempted. */
        public float $unfundedShortfall = 0.0,
    ) {}
}
