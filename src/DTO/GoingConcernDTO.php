<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * What an operating firm is worth as a going concern, set against what it owes.
 *
 * One assessment answers both questions a distressed firm faces: whether the equity market will still fund
 * it (only while the business is worth more than the debt ahead of the new shares), and, once it has filed,
 * whether it is reorganized or liquidated (only a business that covers its cash operating costs is worth
 * more alive than broken up).
 */
class GoingConcernDTO
{
    public function __construct(
        /** Operating income over the last twelve months, before goodwill write-offs. */
        public readonly float $trailingEbit,
        /** Trailing operating income plus the year's depreciation: cash operating earnings. */
        public readonly float $trailingEbitda,
        /** Market value of the firm's assets, cash included: the Merton value its equity price implies. */
        public readonly float $assetValue,
        /** Cash on hand, part of those assets, which the leverage covenant nets against the debt. */
        public readonly float $cash,
        /**
         * Debt claims ahead of the equity that the asset value is struck against: the drawn revolver included, a
         * lender's deposits not.
         */
        public readonly float $claims,
        /**
         * Through-the-cycle EBITDA: the firm's long-run return on its capital, before tax, plus depreciation. Null
         * before it has a long-run return.
         */
        public readonly ?float $normalEbitda = null,
    ) {}

    /**
     * The business covers its cash operating costs through the cycle, so it is worth more alive than broken up. A
     * trough year that does not (an airline through a fuel spike) leaves a business a court reorganizes; the trailing
     * year decides only for a firm with no long-run return yet.
     */
    public function isViable(): bool
    {
        return ($this->normalEbitda ?? $this->trailingEbitda) > 0.0;
    }

    /** The firm's assets cover every claim ahead of the equity, so the equity is still in the money. */
    public function isSolvent(): bool
    {
        return $this->assetValue >= $this->claims;
    }
}
