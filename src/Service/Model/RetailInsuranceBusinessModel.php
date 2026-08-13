<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Retail Insurance (Life, Property & Casualty).
 * 
 * Financial Physics:
 * - Insulates tail-risk by passing it up to Reinsurance.
 * - Revenue is split between short-tail Property & Casualty premiums and highly sticky, long-duration Life Insurance premiums.
 * - Inherits all standard insurance physics (float, Kenney Rule) from InsuranceBusinessModel.
 */
class RetailInsuranceBusinessModel extends InsuranceBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.70;
    public const BASE_COVERAGE_ERROR = 0.10;
    // --- Stream Weights ---
    public const PROPERTY_CASUALTY_WEIGHT = 0.50;
    public const LIFE_INSURANCE_WEIGHT = 0.50;

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        // Inherit the complex catastrophic claim math, capacity constraints, and hard/soft market cycles
        $result = parent::calculateSectorPhysics($stock, $expectedRevenue, $realizedVariableMargin, $fixedCosts, $baselineVol, $macroState, $mathUtility);

        $revenueZ = $result->streamZ['revenue'] ?? 0.0;
        
        $totalRevenue = $result->actualRevenue;
        
        $pcRevenue = $totalRevenue * self::PROPERTY_CASUALTY_WEIGHT;
        $lifeRevenue = $totalRevenue * self::LIFE_INSURANCE_WEIGHT;

        return new SectorPhysicsResult(
            actualRevenue: $totalRevenue,
            rawVariableMargin: $result->rawVariableMargin,
            primaryShockZ: $result->primaryShockZ,
            observableShockZ: $result->observableShockZ,
            eventType: $result->eventType,
            isPublicEvent: $result->isPublicEvent,
            streamZ: [
                'property_casualty_premiums' => $revenueZ,
                'life_insurance_premiums'    => $revenueZ,
            ],
            streamRevenue: [
                'property_casualty_premiums' => $pcRevenue,
                'life_insurance_premiums'    => $lifeRevenue,
            ],
        );
    }
}
