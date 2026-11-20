<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * How volatile a firm's operating business is on its own: the annual idiosyncratic volatility of its operating assets
 * (or of its equity directly for a financial institution, whose beta is already levered), on a geometric five-step
 * ladder where each step is √2 times the one below. Its industry sets the usual step (IndustryRisks) and lore may move
 * a firm by one step.
 */
enum BusinessRisk: string
{
    // --- Operating Idiosyncratic Volatility (annual, √2 spacing) ---
    /** Very low: regulated utilities, century leases, staples and municipal contracts (8%). */
    public const VERY_LOW_VOL = 0.08;
    /** Low: steady diversified, recurring-revenue or contracted businesses (11%). */
    public const LOW_VOL = 0.11;
    /** Moderate: typical operating firm (~15.5%; ~17% equity idio vol at L ~1.1). */
    public const MODERATE_VOL = 0.155;
    /** High: cyclical, project-driven or high-dispersion businesses (22%). */
    public const HIGH_VOL = 0.22;
    /** Very high: unhedged commodities, spot shipping, frontier hardware and launch (31%). */
    public const VERY_HIGH_VOL = 0.31;

    case VeryLow = 'very_low';
    case Low = 'low';
    case Moderate = 'moderate';
    case High = 'high';
    case VeryHigh = 'very_high';

    /** Annual idiosyncratic volatility of the operating assets (equity idiosyncratic vol for a financial). */
    public function vol(): float
    {
        return match ($this) {
            self::VeryLow => self::VERY_LOW_VOL,
            self::Low => self::LOW_VOL,
            self::Moderate => self::MODERATE_VOL,
            self::High => self::HIGH_VOL,
            self::VeryHigh => self::VERY_HIGH_VOL,
        };
    }

    /** Its place on the ladder, from 0 (very low) to 4 (very high). */
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
            self::VeryLow => 'Very low',
            self::Low => 'Low',
            self::Moderate => 'Moderate',
            self::High => 'High',
            self::VeryHigh => 'Very high',
        };
    }
}
