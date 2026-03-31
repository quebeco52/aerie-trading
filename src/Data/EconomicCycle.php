<?php

namespace App\Data;

/**
 * Represents the different states of the macroeconomic cycle, each with corresponding
 * modifiers that affect earnings growth expectations and overall market drift.
 */
enum EconomicCycle: string
{
    case RECESSION = 'Recession';
    case RECOVERY = 'Recovery';
    case EXPANSION = 'Expansion';
    case PEAK = 'Peak';

    public function getGrowthModifier(): float
    {
        return match ($this) {
            self::RECESSION => -0.015, // -1.5% base drag on earnings growth
            self::RECOVERY  => 0.005, // +0.5% base boost
            self::EXPANSION => 0.02, // +2% base boost
            self::PEAK      => 0.00,  // Growth flattens, preparing for a downturn
        };
    }

    /**
     * Returns a modifier to the base market drift (investor sentiment).
     * A positive value is a tailwind, a negative value is a headwind.
     */
    public function getDriftModifier(): float
    {
        return match ($this) {
            self::RECESSION => -0.18,
            self::RECOVERY  => -0.02,
            self::EXPANSION => 0.06,
            self::PEAK      => -0.05,
        };
    }

    public function getTargetDuration(): float
    {
        return match ($this) {
            self::RECESSION => 1.5,  // 1.5 years
            self::RECOVERY  => 1.0,  // 1.0 year
            self::EXPANSION => 3.0,  // 3.0 years
            self::PEAK      => 0.75, // 9 months
        };
    }

    public function getVolatilityModifier(): float
    {
        return match ($this) {
            self::RECESSION => 1.20,
            self::RECOVERY  => 1.00,
            self::EXPANSION => 0.85,
            self::PEAK      => 1.10,
        };
    }
}