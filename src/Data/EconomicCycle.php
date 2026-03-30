<?php

namespace App\Data;

/**
 * Represents the different states of the macroeconomic cycle.
 * Each state has a corresponding modifier that affects earnings growth expectations.
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
}