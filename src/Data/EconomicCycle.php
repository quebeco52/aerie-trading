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
            self::RECESSION => -0.05, // -5% drag on market sentiment/drift
            self::RECOVERY  => 0.02,  // +2% boost
            self::EXPANSION => 0.05,  // +5% boost
            self::PEAK      => -0.01, // -1% drag as market anticipates a downturn
        };
    }

    /**
     * Returns a modifier to the mean of the systemic market shock (marketZ).
     * A positive value biases the market upwards, a negative value biases it downwards.
     */
    public function getMarketZMeanModifier(): float
    {
        return match ($this) {
            self::RECESSION => -0.05, // Negative sentiment, shocks are more likely to be negative
            self::RECOVERY  => 0.02,  // Cautious optimism
            self::EXPANSION => 0.04,  // Positive sentiment, shocks are more likely to be positive
            self::PEAK      => -0.01, // Nervousness, market is skittish
        };
    }

    /**
     * Returns a modifier to the standard deviation of the systemic market shock (marketZ).
     * Values > 1 increase volatility, values < 1 decrease it.
     */
    public function getMarketZStdDevModifier(): float
    {
        return match ($this) {
            self::RECESSION => 1.1,  // 10% more volatile
            self::RECOVERY  => 1.0,  // Normal volatility
            self::EXPANSION => 0.95, // 5% less volatile
            self::PEAK      => 1.05, // 5% more volatile
        };
    }
}