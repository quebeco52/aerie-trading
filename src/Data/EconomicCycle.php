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
            self::RECESSION => -0.12,
            self::RECOVERY  =>  0.12,
            self::EXPANSION =>  0.04,
            self::PEAK      =>  0.015,
        };
    }

    public function getTargetDuration(): float
    {
        return match ($this) {
            self::RECESSION => 1.0,  // 1.0 years
            self::RECOVERY  => 1.0,  // 1.0 year
            self::EXPANSION => 3.0,  // 3.0 years
            self::PEAK      => 0.5, // 6 months
        };
    }

    public function getVolatilityModifier(): float
    {
        return match ($this) {
            self::RECESSION => 1.20,
            self::RECOVERY  => 1.00,
            self::EXPANSION => 0.90,
            self::PEAK      => 1.10,
        };
    }

    /**
     * Returns a modifier to the base market drift (investor sentiment).
     */
    public function getDriftModifier(): float
    {
        return match ($this) {
            self::RECESSION => -0.12,
            self::RECOVERY  => 0.02,
            self::EXPANSION => 0.00,
            self::PEAK      => -0.06,
        };
    }
}