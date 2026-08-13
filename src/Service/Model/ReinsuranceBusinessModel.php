<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Reinsurance.
 * 
 * Financial Physics:
 * - Extremely lumpy and catastrophic tail-risk.
 * - Revenue is split between core reinsurance premiums and high-yield catastrophe bonds.
 * - Inherits all the standard insurance physics (float, Kenney Rule capacity, hard market cycles) from InsuranceBusinessModel.
 */
class ReinsuranceBusinessModel extends InsuranceBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.80;
    public const BASE_COVERAGE_ERROR = 0.10;
    // --- Stream Weights ---
    public const REINSURANCE_PREMIUM_WEIGHT = 0.60;
    public const CAT_BOND_WEIGHT = 0.40;

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        // Inherit the complex catastrophic claim math, capacity constraints, and hard/soft market cycles
        $result = parent::calculateSectorPhysics($stock, $expectedRevenue, $realizedVariableMargin, $fixedCosts, $baselineVol, $macroState, $mathUtility);

        $revenueZ = $result->streamZ['revenue'] ?? 0.0;
        $claimZ = $result->streamZ['claim'] ?? 0.0; // Negative claimZ = more catastrophes

        // The parent calculates 'actualRevenue' based on the Kenney Rule. Split this into our lore-specific streams.
        $totalRevenue = $result->actualRevenue;
        
        $reinsuranceRevenue = $totalRevenue * self::REINSURANCE_PREMIUM_WEIGHT;
        $catBondRevenue = $totalRevenue * self::CAT_BOND_WEIGHT;

        return new SectorPhysicsResult(
            actualRevenue: $totalRevenue,
            rawVariableMargin: $result->rawVariableMargin,
            primaryShockZ: $result->primaryShockZ,
            observableShockZ: $result->observableShockZ,
            eventType: $result->eventType,
            isPublicEvent: $result->isPublicEvent,
            streamZ: [
                'reinsurance_premiums' => $revenueZ,
                'catastrophe_bonds'    => $claimZ, // Cat bond yields are heavily correlated to the catastrophe claims Z-score
            ],
            streamRevenue: [
                'reinsurance_premiums' => $reinsuranceRevenue,
                'catastrophe_bonds'    => $catBondRevenue,
            ],
        );
    }
}
