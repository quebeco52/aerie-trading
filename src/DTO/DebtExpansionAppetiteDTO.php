<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * A firm's appetite for raising new debt this quarter, as a Bernoulli draw and a size.
 */
class DebtExpansionAppetiteDTO
{
    public function __construct(
        /** Probability the firm attempts a debt raise at all this quarter. */
        public readonly float $probability,
        /** Fraction of remaining debt capacity drawn when it does raise. */
        public readonly float $aggressiveness,
    ) {}
}
