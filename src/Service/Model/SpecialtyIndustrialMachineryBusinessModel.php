<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Specialty Industrial Machinery.
 * 
 * Financial Physics:
 * - High Moat: Specialty products mean higher margins and pricing power.
 * - Still Cyclical: Sensitive to the output gap, but less so than raw commodity/heavy manufacturing.
 * - Sticky demand: Once integrated, customers rarely switch, reducing idiosyncratic volatility.
 * - Backlog-Buffered: Multi-quarter order backlogs dampen revenue shock transmission.
 */
class SpecialtyIndustrialMachineryBusinessModel extends HeavyManufacturingBusinessModel
{
    public const EQUIPMENT_WEIGHT = 0.70;
    public const SERVICES_WEIGHT  = 0.30;

    // --- Razor/Razorblade Margin Architecture ---
    /** Structural variable cost ratio of the extremely high margin aftermarket parts/services. */
    public const SERVICES_VARIABLE_COST_RATIO = 0.15;

    // --- Tail Risk & Shock Events ---
    /** Negative z-score threshold indicating a severe specialty supply chain disruption. */
    public const SUPPLY_CHAIN_DISRUPTION_Z_SCORE = -2.20;
    /** Variable margin penalty applied during expedited sourcing for disrupted supply chains. */
    public const SUPPLY_CHAIN_DISRUPTION_PENALTY = 0.06;
    /** Positive z-score threshold indicating a massive multi-year infrastructure or government project win. */
    public const MEGA_INFRASTRUCTURE_DEAL_Z_SCORE = 2.40;
    /** Top-line equipment revenue multiplier for a mega infrastructure deal. */
    public const MEGA_INFRASTRUCTURE_DEAL_MULT = 1.20;

    // --- Backlog & Order Book ---
    /** Fraction of equipment revenue shock absorbed by multi-quarter order backlog (1.0 = fully absorbed). */
    public const BACKLOG_DAMPING_FACTOR = 0.60;

    // --- Continuous Elasticity ---
    /** Variable margin sensitivity to a growing installed equipment base driving aftermarket parts. */
    public const AFTERMARKET_INSTALLED_BASE_ELASTICITY = 0.015;

    // --- Asset Depreciation & Reinvestment ---
    /** Quarterly margin decay rate per unit of underinvestment in precision manufacturing IP. */
    public const PRECISION_TOOLING_DECAY_RATE = 0.020;
    /** Quarterly margin gain scalar per unit of next-gen CNC and R&D automation overinvestment. */
    public const AUTOMATION_RND_GAIN_RATE = 0.010;
    /** Structural minimum operating margin floor under severe manufacturing tech debt. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.10;
    /** Structural maximum operating margin ceiling for automated precision monopolies. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.28;

    public function getModelThresholds(): array
    {
        $thresholds = parent::getModelThresholds();
        $thresholds['moat_spread'] = 0.025; // Wider moat than standard heavy manufacturing
        $thresholds['reversion_speed'] = 0.10; // Stickier margins than standard manufacturing
        $thresholds['nwc_intensity'] = 0.18; // Heavy work-in-progress inventory for long cycle builds
        return $thresholds;
    }

    public function getSurpriseBlendWeights(): array { return ['eps_weight' => 0.70, 'revenue_weight' => 0.30]; }
    public function getCapexCyclicality(): float { return 2.5; } // Smoother than generic heavy manufacturing (4.0)
    public function getSecularGrowthRate(Stock $stock): float { return 0.03; } // Higher than heavy mfg (1.5%) due to installed-base compounding

    // --- Pricing Power & Macro Physics ---
    public const MIN_BETA_PRICING_POWER_FLOOR = 1.20; // Higher pricing power due to specialty nature

    // --- Revenue & Shock Physics ---
    public const REVENUE_VARIANCE_SCALAR = 0.25; // Less volatile sales than raw heavy manufacturing
    public const INFLATION_PENALTY_SCALAR = 0.60; // Pass down costs better than generic heavy manufacturing

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        
        // Less sensitive to output gap compared to base Heavy Manufacturing (which is 1.75x)
        $outputGap = $macroState->outputGapEma;
        $beta = (float) $stock->getBeta();
        
        $physics['macro_demand_shift'] = $outputGap * $beta * 1.25; 
        
        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            'pricing_power_index' => 0.7, // Specialty pricing power — can pass costs to customers
            'equipment_weight' => self::EQUIPMENT_WEIGHT,
            'services_weight'  => self::SERVICES_WEIGHT,
        ]);

        $pricingPower = max(0.0, min(1.0, $params['pricing_power_index']));

        $equipmentWeight = $params['equipment_weight'];
        $servicesWeight  = $params['services_weight'];

        $momentum = $stock->getEarningsMomentumZ() ?? [];

        // Independent stream Z-scores with AR(1) persistence
        // Equipment is highly volatile, Services is very stable
        $equipmentZ = $mathUtility->generatePersistentZ($momentum['equipment'] ?? 0.0, 0.20); 
        $servicesZ  = $mathUtility->generatePersistentZ($momentum['services'] ?? 0.0, 0.05); 
        $eventZ     = $mathUtility->generatePersistentZ($momentum['event'] ?? 0.0, 0.10);

        // Equipment revenue gets the full variance scalar, services gets a fraction
        $dealMultiplier = 1.0;
        $eventType = null;
        $supplyChainPenalty = 0.0;
        
        if ($eventZ < self::SUPPLY_CHAIN_DISRUPTION_Z_SCORE) {
            $supplyChainPenalty = self::SUPPLY_CHAIN_DISRUPTION_PENALTY;
            $eventType = ShockEvent::AUTO_SUPPLY_CHAIN_DISRUPTION;
            $dealMultiplier = 0.90; // Add revenue drop
        } elseif ($eventZ > self::MEGA_INFRASTRUCTURE_DEAL_Z_SCORE) {
            $dealMultiplier = self::MEGA_INFRASTRUCTURE_DEAL_MULT;
            $eventType = ShockEvent::DEFENSE_CONTRACT_WIN; // Treat as mega contract
        }

        // Backlog Damping: Multi-quarter order books absorb a fraction of equipment revenue shocks.
        // Only the undamped portion of the Z-shock flows through as current-quarter revenue.
        $dampedEquipmentShock = $equipmentZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR) * (1.0 - self::BACKLOG_DAMPING_FACTOR);

        $equipmentRevenue = $expectedRevenue * $equipmentWeight * (1.0 + $dampedEquipmentShock) * $dealMultiplier;
        $servicesRevenue  = $expectedRevenue * $servicesWeight * (1.0 + ($servicesZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.2)));

        $actualRevenue = max(0.0, $equipmentRevenue + $servicesRevenue);

        // --- Structural Margin Blending ---
        $expectedServicesRevenue = $expectedRevenue * $servicesWeight;
        $expectedEquipmentRevenue = $expectedRevenue * $equipmentWeight;

        $servicesBaselineCosts = $expectedServicesRevenue * self::SERVICES_VARIABLE_COST_RATIO;
        $targetTotalCosts = $expectedRevenue * $realizedVariableMargin;
        $equipmentBaselineCosts = $targetTotalCosts - $servicesBaselineCosts;
        
        $equipmentVariableMargin = $expectedEquipmentRevenue > 0 ? $equipmentBaselineCosts / $expectedEquipmentRevenue : $realizedVariableMargin;

        $actualVariableCosts = ($servicesRevenue * self::SERVICES_VARIABLE_COST_RATIO) + ($equipmentRevenue * $equipmentVariableMargin);

        // Energy Price Inflation Penalty (specialty machinery uses steel, aluminum, energy-intensive inputs)
        $energyShift = ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / 100.0;
        $inflationMultiplier = 2.0 - ($pricingPower * 2.0);
        $baseInflationPenalty = $energyShift > 0 ? $energyShift * abs((float) $stock->getBeta()) * self::INFLATION_PENALTY_SCALAR : 0.0;
        $inflationPenalty = $baseInflationPenalty * $inflationMultiplier;

        // Continuous Elasticity
        $elasticityShift = -self::AFTERMARKET_INSTALLED_BASE_ELASTICITY * $equipmentZ * $equipmentWeight;

        $rawMargin = ($actualVariableCosts / max(1.0, $actualRevenue)) + $supplyChainPenalty + $inflationPenalty + $elasticityShift;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Blended primary shock for standard model integration
        $primaryShockZ = ($equipmentZ * $equipmentWeight) + ($servicesZ * $servicesWeight);
        if (abs($eventZ) > abs($primaryShockZ)) {
            $primaryShockZ = $eventZ;
        }
        
        $observableShockZ = $primaryShockZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: [
                'equipment' => $equipmentZ,
                'services' => $servicesZ,
                'event' => $eventZ,
            ],
            streamRevenue: [
                'Equipment Sales' => $equipmentRevenue,
                'Aftermarket Services' => $servicesRevenue,
            ]
        );
    }

    public function getCoverageProfile(): \App\DTO\SectorCoverageProfile
    {
        // B2B long-cycle orders are opaque (low base visibility).
        // Mega infrastructure deal wins are widely publicized (high event visibility).
        return new \App\DTO\SectorCoverageProfile(
            baseVisibility: 0.15,
            errorStdDev: 0.05,
            minVisibility: 0.0,
            eventBaseVisibility: 0.60,
            eventMinVisibility: 0.30
        );
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            // Precision tooling tech debt and loss of manufacturing edge
            $decayRate = self::PRECISION_TOOLING_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            // Investment in next-gen CNC and R&D automation expands margin
            $modGain = self::AUTOMATION_RND_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}
