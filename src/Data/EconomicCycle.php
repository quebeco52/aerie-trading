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
            self::RECESSION => -0.045, // -4.5% base drag on earnings growth
            self::RECOVERY  => 0.01, // +1% base boost
            self::EXPANSION => 0.045, // +4.5% base boost
            self::PEAK      => 0.00,  // Growth flattens, preparing for a downturn
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
            self::EXPANSION => 0.90,
            self::PEAK      => 1.10,
        };
    }
}