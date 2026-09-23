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
        /** Debt claims ahead of the equity, the drawn revolver included. */
        public readonly float $claims,
    ) {}

    /** The business covers its cash operating costs, so it is worth more alive than broken up. */
    public function isViable(): bool
    {
        return $this->trailingEbitda > 0.0;
    }

    /** The firm's assets cover every claim ahead of the equity, so the equity is still in the money. */
    public function isSolvent(): bool
    {
        return $this->assetValue >= $this->claims;
    }
}
