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
}