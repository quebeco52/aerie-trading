<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * How often a firm's own idiosyncratic price jumps arrive, in arrivals per year, on a four-step ladder. Every step
 * puts the variance budget's full jump share (VarianceBudget::MAX_IDIOSYNCRATIC_JUMP_VARIANCE_SHARE) into jumps, so a
 * rarer step means fewer, larger own jumps and a more frequent step means smaller, steadier ones.
 */
enum EventRisk: string
{
    // --- Idiosyncratic Jump Arrival Rate (per year) ---
    /** Rare: ~0.3 own jumps a year (utilities, contracted trusts, reinsurance tail events). */
    public const RARE_INTENSITY = 0.30;
    /** Occasional: ~0.5 own jumps a year (typical industrial, consumer or financial firm). */
    public const OCCASIONAL_INTENSITY = 0.50;
    /** Frequent: ~0.8 own jumps a year (cyclical, regulatory, programme or deal-driven businesses). */
    public const FREQUENT_INTENSITY = 0.80;
    /** Constant: ~1.25 own jumps a year (trading desks, event-driven funds, fast-cycle tech and autos). */
    public const CONSTANT_INTENSITY = 1.25;

    case Rare = 'rare';
    case Occasional = 'occasional';
    case Frequent = 'frequent';
    case Constant = 'constant';

    /** Expected number of firm-specific jump arrivals per year. */
    public function intensity(): float
    {
        return match ($this) {
            self::Rare => self::RARE_INTENSITY,
            self::Occasional => self::OCCASIONAL_INTENSITY,
            self::Frequent => self::FREQUENT_INTENSITY,
            self::Constant => self::CONSTANT_INTENSITY,
        };
    }

    /** Its place on the ladder, from 0 (rare) to 3 (constant). */
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
            self::Rare => 'Rare',
            self::Occasional => 'Occasional',
            self::Frequent => 'Frequent',
            self::Constant => 'Constant',
        };
    }
}
