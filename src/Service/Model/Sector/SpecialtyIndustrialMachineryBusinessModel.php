<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorCoverageProfile;
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
 * - Backlog-Buffered Cyclicality: Equipment sales are driven by the macro output gap, cushioned by multi-quarter order backlogs.
 * - Sticky Demand: Once integrated, customers rarely switch, reducing idiosyncratic volatility.
 * - The Razor/Razorblade: High-margin aftermarket services cushion recessions organically.
 */
class SpecialtyIndustrialMachineryBusinessModel extends HeavyManufacturingBusinessModel
{
    // --- Dual-Stream Revenue Weights ---
    /** Baseline fraction of revenue from heavy equipment and automated machinery sales. */
    public const EQUIPMENT_WEIGHT = 0.70;
    /** Baseline fraction of revenue from high-margin aftermarket parts, consumables, and maintenance. */
    public const SERVICES_WEIGHT  = 0.30;

    // --- Razor/Razorblade Margin Architecture ---
    /** Structural variable cost ratio of the high-margin aftermarket parts and services. */
    public const SERVICES_VARIABLE_COST_RATIO = 0.15;

    // --- Macro & Backlog Physics ---
    /** Macro sensitivity multiplier for equipment demand to the broader output gap. */
    public const MACRO_GDP_SENSITIVITY = 1.50;
    /** Fraction of equipment revenue shock absorbed by multi-quarter order backlog (1.0 = fully absorbed). */
    public const BACKLOG_DAMPING_FACTOR = 0.60;
    /** Volatility multiplier for equipment sales variance. */
    public const REVENUE_VARIANCE_SCALAR = 0.25;
    /** Volatility reduction factor for aftermarket services relative to equipment sales. */
    public const SERVICES_VARIANCE_RATIO = 0.20;
    /** Fraction of energy price spikes passed through based on specialty pricing power. */
    public const INFLATION_PENALTY_SCALAR = 0.60;
    /** Low scalar sensitivity of machinery equipment orders to aggregate industrial capital capacity overhang. */
    public const CAPITAL_OVERHANG_SCALAR = 0.15;

    // --- Tail Risk & Shock Events ---
    /** Negative z-score threshold indicating a severe specialty supply chain or parts disruption. */
    public const SUPPLY_CHAIN_DISRUPTION_Z_SCORE = -2.20;
    /** Variable margin penalty applied during expedited sourcing for disrupted supply chains. */
    public const SUPPLY_CHAIN_DISRUPTION_PENALTY = 0.06;
    /** Revenue multiplier applied during supply chain delays. */
    public const SUPPLY_CHAIN_REVENUE_DROP_MULT   = 0.90;
    /** Positive z-score threshold indicating a massive multi-year infrastructure or factory project win. */
    public const MEGA_INFRASTRUCTURE_DEAL_Z_SCORE = 2.40;
    /** Top-line equipment revenue multiplier for a mega infrastructure deal win. */
    public const MEGA_INFRASTRUCTURE_DEAL_MULT    = 1.20;

    // --- Asset Depreciation & Reinvestment ---
    /** Quarterly margin decay rate per unit of underinvestment in precision manufacturing IP. */
    public const PRECISION_TOOLING_DECAY_RATE = 0.020;
    /** Quarterly margin gain scalar per unit of next-gen CNC and R&D automation overinvestment. */
    public const AUTOMATION_RND_GAIN_RATE     = 0.010;
    /** Structural minimum operating margin floor under severe manufacturing tech debt. */
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.10;
    /** Structural maximum operating margin ceiling for automated precision monopolies. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.28;

        public function getReversionSpeed(): float { return 0.1; }
    public function getMoatSpread(): float { return 0.025; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.18; }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.70, 'revenue_weight' => 0.30];
    }

    public function getCapexCyclicality(): float
    {
        return 2.5;
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.03;
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify global demand shift so macro cyclicality is isolated 
        // exclusively to equipment sales, keeping aftermarket services defensive.
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::PricingPowerIndex->value => 0.70,
            ModelParam::EquipmentWeight->value    => self::EQUIPMENT_WEIGHT,
            ModelParam::ServicesWeight->value     => self::SERVICES_WEIGHT,
        ]);

        $pricingPower    = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));
        $equipmentWeight = $params[ModelParam::EquipmentWeight];
        $servicesWeight  = $params[ModelParam::ServicesWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);
        $beta = abs((float) $stock->getBeta());

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'equipment_sales'      => $params[ModelParam::EquipmentWeight],
            'aftermarket_services' => $params[ModelParam::ServicesWeight],
        ]);

        $equipmentWeight = $activeWeights['equipment_sales'];
        $servicesWeight  = $activeWeights['aftermarket_services'];

        // Independent stream Z-scores
        $equipmentZ = $streams->generateZ('equipment_sales', 0.20);
        $servicesZ  = $streams->generateZ('aftermarket_services', 0.05);
        $eventZ     = $streams->generateZ('event', 0.10);

        // --- Macro Sensitivities ---
        // Equipment is highly exposed to GDP, but cushioned by the backlog
        $overhangDrag = ($macroState->capitalStockOverhangEma * self::CAPITAL_OVERHANG_SCALAR) * (1.0 - self::BACKLOG_DAMPING_FACTOR);
        $macroEquipmentBoost = (($macroState->outputGapEma * self::MACRO_GDP_SENSITIVITY * $beta) * (1.0 - self::BACKLOG_DAMPING_FACTOR)) - $overhangDrag;

        // --- Tail Risk Events ---
        $dealMultiplier = 1.0;
        $eventType = null;
        $supplyChainPenalty = 0.0;

        if ($eventZ < self::SUPPLY_CHAIN_DISRUPTION_Z_SCORE) {
            $supplyChainPenalty = self::SUPPLY_CHAIN_DISRUPTION_PENALTY;
            $eventType = ShockEvent::AUTO_SUPPLY_CHAIN_DISRUPTION;
            $dealMultiplier = self::SUPPLY_CHAIN_REVENUE_DROP_MULT;
        } elseif ($eventZ > self::MEGA_INFRASTRUCTURE_DEAL_Z_SCORE) {
            $dealMultiplier = self::MEGA_INFRASTRUCTURE_DEAL_MULT;
            $eventType = ShockEvent::INFRASTRUCTURE_BILL_WIN;
        }

        // --- Clamped Stream Revenue Calculation ---
        $fxShift = ($macroState->exchangeRateIndexEma - 100.0) / 100.0;
        $dampedEquipmentShock = $equipmentZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR) * (1.0 - self::BACKLOG_DAMPING_FACTOR);

        $equipmentRevenue = max(0.0, $expectedRevenue * $equipmentWeight * (1.0 + $dampedEquipmentShock + $macroEquipmentBoost - ($fxShift * 0.10)) * $dealMultiplier);
        $servicesRevenue  = max(0.0, $expectedRevenue * $servicesWeight * (1.0 + ($servicesZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * self::SERVICES_VARIANCE_RATIO))));

        $streamRevenues = [
            'equipment_sales'      => $equipmentRevenue,
            'aftermarket_services' => $servicesRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Structural Razor/Razorblade Margin Blending ---
        $expectedServicesRevenue  = $expectedRevenue * $servicesWeight;
        $expectedEquipmentRevenue = $expectedRevenue * $equipmentWeight;

        $servicesBaselineCosts  = $expectedServicesRevenue * self::SERVICES_VARIABLE_COST_RATIO;
        $targetTotalCosts       = $expectedRevenue * $realizedVariableMargin;
        $equipmentBaselineCosts = max(0.0, $targetTotalCosts - $servicesBaselineCosts);

        $equipmentVariableMargin = $expectedEquipmentRevenue > 0 ? ($equipmentBaselineCosts / $expectedEquipmentRevenue) : $realizedVariableMargin;
        $actualVariableCosts = ($servicesRevenue * self::SERVICES_VARIABLE_COST_RATIO) + ($equipmentRevenue * $equipmentVariableMargin);

        // --- Energy & Metals Price Inflation Penalty ---
        $energyShift = $macroState->energyCostPushLag / MacroEngine::ENERGY_COST_PUSH_TRANSMISSION;
        $metalsShift = ($macroState->industrialMetalsIndexEma - 100.0) / 100.0;
        $metalsCostDrag = max(0.0, $metalsShift) * 0.05;

        $inflationMultiplier = 2.0 - ($pricingPower * 2.0);
        $baseInflationPenalty = ($energyShift > 0 ? ($energyShift * $beta * self::INFLATION_PENALTY_SCALAR) : 0.0) + $metalsCostDrag;
        $inflationPenalty = $baseInflationPenalty * $inflationMultiplier;

        // Effective blended variable cost ratio
        $effectiveMargin = $actualRevenue > 0 ? ($actualVariableCosts / $actualRevenue) : $realizedVariableMargin;
        $rawMargin = $effectiveMargin + $supplyChainPenalty + $inflationPenalty;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Primary shock is whichever stream deviated the most, overridden by tail events
        $primaryShockZ = $streams->resolveDominantShockZ([$equipmentZ, $servicesZ], $eventZ);

        $observableShockZ = $primaryShockZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    public function getCoverageProfile(Stock $stock): SectorCoverageProfile
    {
        return new SectorCoverageProfile(
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
            $decayRate = self::PRECISION_TOOLING_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            $modGain = self::AUTOMATION_RND_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}
