<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * Quarterly interest cost of a firm's funding, split from the blended rate the wholesale market charges it.
 *
 * A deposit-funded bank pays two very different prices for its liabilities, so the expense and the rate are
 * not derivable from one another: dividing total interest by wholesale debt attributes deposit interest to
 * wholesale and prices the bank as junk.
 */
class InterestExpenseDTO
{
    public function __construct(
        /** Total quarterly interest expense across every funding source (wholesale debt, deposits, float). */
        public readonly float $interestExpense,
        /** Effective rate paid on wholesale debt alone, which is what credit and cost-of-capital tests read. */
        public readonly float $wholesaleRate,
    ) {}
}
