<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * How flexible or rigid a firm's cash operating cost base is relative to its industry: five steps spaced additively by
 * STEP_OFFSET (0.10 of cash operating cost per step). More rigid means a higher fixed cost share and greater operating
 * leverage.
 */
enum CostStructure: string
{
    // --- Fixed-Share Step ---
    /**
     * Additive offset to the industry's opening fixed share of cash operating cost per step on the ladder: five steps
     * span ±0.20 around the industry norm.
     */
    public const STEP_OFFSET = 0.10;

    case VeryFlexible = 'very_flexible';
    case Flexible = 'flexible';
    case Typical = 'typical';
    case Rigid = 'rigid';
    case VeryRigid = 'very_rigid';

    /** Signed steps from the industry norm (positive = heavier fixed costs / more rigid). */
    public function steps(): int
    {
        return match ($this) {
            self::VeryFlexible => -2,
            self::Flexible => -1,
            self::Typical => 0,
            self::Rigid => 1,
            self::VeryRigid => 2,
        };
    }

    /** Additive offset applied to the industry's opening fixed share of cash operating cost. */
    public function fixedShareOffset(): float
    {
        return self::STEP_OFFSET * $this->steps();
    }

    /** The step as the District's pages name it. */
    public function label(): string
    {
        return match ($this) {
            self::VeryFlexible => 'Very flexible',
            self::Flexible => 'Flexible',
            self::Typical => 'Typical',
            self::Rigid => 'Rigid',
            self::VeryRigid => 'Very rigid',
        };
    }
}
