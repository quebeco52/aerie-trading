<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * How wide a firm's moat is: the excess return over its cost of capital its franchise earns for good, on a five-step
 * ladder (Morningstar rates three: none, narrow, wide). The step is what a firm's return reverts to above its cost of
 * capital and, for a firm whose books its model builds, what it opens on. Its industry sets the usual step
 * (IndustryMoats) and lore moves a firm within a step of it.
 */
enum Moat: string
{
    // --- Durable Excess Return ---
    /** No moat: competition takes every return above the cost of capital. */
    public const NONE_SPREAD = 0.0;
    /** Narrow: 3pp over the cost of capital, about what the median listed firm earns. */
    public const NARROW_SPREAD = 0.03;
    /** Moderate: 6pp, an advantage that holds through a cycle. */
    public const MODERATE_SPREAD = 0.06;
    /** Wide: 10pp, a franchise few rivals can contest. */
    public const WIDE_SPREAD = 0.10;
    /** Very wide: 15pp, a network, a standard or a licence nobody else holds. */
    public const VERY_WIDE_SPREAD = 0.15;

    case None = 'none';
    case Narrow = 'narrow';
    case Moderate = 'moderate';
    case Wide = 'wide';
    case VeryWide = 'very_wide';

    /** The excess return over the cost of capital this step earns, after tax. */
    public function spread(): float
    {
        return match ($this) {
            self::None => self::NONE_SPREAD,
            self::Narrow => self::NARROW_SPREAD,
            self::Moderate => self::MODERATE_SPREAD,
            self::Wide => self::WIDE_SPREAD,
            self::VeryWide => self::VERY_WIDE_SPREAD,
        };
    }

    /** Its place on the ladder, from 0 (none) to 4 (very wide). */
    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    /** The step a number of steps up (positive) or down the ladder, held to its ends. */
    public function shifted(int $steps): self
    {
        $cases = self::cases();

        return $cases[max(0, min(count($cases) - 1, $this->rank() + $steps))];
    }

    /** The step as the District's pages name it. */
    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Narrow => 'Narrow',
            self::Moderate => 'Moderate',
            self::Wide => 'Wide',
            self::VeryWide => 'Very wide',
        };
    }
}
