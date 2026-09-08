<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Heavy Engineering, Procurement & Construction (EPC).
 * 
 * Financial Physics:
 * - Tri-Stream Architecture:
 *   1. Civil & Public Infrastructure: Multi-year sovereign/municipal contracts (bridges, transit, dams).
 *      Heavily backlog-damped (75%+), multi-year revenue recognition, highly observable public tender wins.
 *   2. Commercial & Industrial EPC: Private corporate CapEx builds (data centers, foundries, refineries).
 *      Pro-cyclical with GDP output gap and vulnerable to corporate credit freezes/rate hikes.
 *   3. Facilities Maintenance & Concessions (O&M): Recurring long-term operations & maintenance, toll concessions.
 *      Defensive, high-margin, and decoupled from new-build freeze cycles.
 * - Fixed-Price vs. Cost-Plus Contract Squeeze: Heavy reliance on steel, cement, and diesel. Material cost
 *   inflation is dynamically mitigated by the firm's PricingPowerIndex (cost-escalation clauses).
 */
class ConstructionBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    /** Base coverage visibility for EPC contractors. */
    public const BASE_COVERAGE_VISIBILITY = 0.40;

    /** Base coverage forecasting error for EPC contractors. */
    public const BASE_COVERAGE_ERROR = 0.08;

    // --- Backlog & Order Book ---
    /** Fraction of revenue shock absorbed by multi-year order backlog (1.0 = fully absorbed). */
    public const BACKLOG_DAMPING_FACTOR = 0.75;

    // --- Default Tri-Stream Weights ---
    /** Baseline revenue share from public/sovereign civil infrastructure mega-projects. */
    public const CIVIL_INFRASTRUCTURE_WEIGHT = 0.45;

    /** Baseline revenue share from commercial & industrial private development EPC. */
    public const COMMERCIAL_EPC_WEIGHT = 0.35;

    /** Baseline revenue share from recurring operations, maintenance & concession services. */
    public const FACILITIES_MAINTENANCE_WEIGHT = 0.20;

    /** Baseline pricing power and contractual inflation pass-through capability. */
    public const PRICING_POWER_INDEX = 0.40;

    // --- Stream Volatility Scalars ---
    /** Volatility scalar for civil infrastructure projects (damped by long-duration contracts). */
    public const CIVIL_VARIANCE_SCALAR = 0.15;

    /** Volatility scalar for commercial EPC projects (cyclical corporate CapEx swings). */
    public const COMMERCIAL_EPC_VARIANCE_SCALAR = 0.55;

    /** Volatility scalar for recurring facilities maintenance services. */
    public const FACILITIES_MAINTENANCE_VARIANCE_SCALAR = 0.10;

    // --- Material Inflation & Cost Squeeze ---
    /** Base sensitivity of fixed-price contracts to input material inflation (diesel, steel, cement). */
    public const INFLATION_PENALTY_SCALAR = 1.00;

    /** Energy price index drag scalar for heavy diesel and earthmoving equipment. */
    public const ENERGY_COST_SCALAR = 0.10;

    /** Maximum mitigation percentage of material cost drag achieved via perfect pricing power. */
    public const MAX_PRICING_POWER_MITIGATION = 0.60;

    // --- Housing Starts, SLOOS & PPI Transmission ---
    /** Sensitivity of commercial and residential construction activity to housing starts shifts. */
    public const HOUSING_STARTS_SENSITIVITY = 0.40;

    /** Drag on construction financing per unit of SLOOS bank lending standard tightening. */
    public const SLOOS_CREDIT_TIGHTENING_SCALAR = 0.30;

    /** Sensitivity of building materials (cement, lumber, structural steel) to wholesale PPI inflation. */
    public const PPI_CONSTRUCTION_SENSITIVITY = 0.45;

    // --- Tail Risk & Shock Events ---
    /** Z-score threshold for catastrophic project delays and liquidated damages. */
    public const COST_OVERRUN_Z_SCORE = -2.00;

    /** Variable margin penalty from project delays and contract write-downs. */
    public const COST_OVERRUN_PENALTY = 0.06;

    /** Z-score threshold for winning a landmark sovereign infrastructure award. */
    public const MEGA_PROJECT_Z_SCORE = 2.20;

    /** Top-line multiplier boost from a landmark infrastructure contract win. */
    public const MEGA_PROJECT_MULT = 1.15;

        public function getWholesaleLeverageLimit(): float { return 2.5; }
    public function getReversionSpeed(): float { return 0.1; }
    public function getMoatSpread(): float { return 0.015; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.25; }
    public function getCapExCompletionRate(Stock $stock): float { return 0.4; }

    public function getSeasonalityFactors(): array
    {
        return [0.88, 1.06, 1.10, 0.96]; // Q1 winter ground freeze, peak warm weather Q2-Q3; backlog-damped
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.015;
    }

    public function getCapexCyclicality(): float
    {
        return 0.75;
    }

    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.50, 'revenue_weight' => 0.50];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Nullify global generic demand shifts to handle macro cycles discretely per stream.
        $physics['macro_demand_shift'] = 0.0;
        $physics['pricing_power_multiplier'] = 1.0;

        return $physics;
    }

    protected function calculateSectorPhysics(
        Stock $stock,
        float $expectedRevenue,
        float $realizedVariableMargin,
        float $fixedCosts,
        float $baselineVol,
        MacroStateDTO $macroState,
        MathUtility $mathUtility
    ): SectorPhysicsResult {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::CivilInfrastructureWeight->value    => self::CIVIL_INFRASTRUCTURE_WEIGHT,
            ModelParam::CommercialEpcWeight->value          => self::COMMERCIAL_EPC_WEIGHT,
            ModelParam::FacilitiesMaintenanceWeight->value  => self::FACILITIES_MAINTENANCE_WEIGHT,
            ModelParam::PricingPowerIndex->value            => self::PRICING_POWER_INDEX,
        ]);

        $civilWeight       = $params[ModelParam::CivilInfrastructureWeight];
        $commercialWeight  = $params[ModelParam::CommercialEpcWeight];
        $maintenanceWeight = $params[ModelParam::FacilitiesMaintenanceWeight];
        $pricingPower      = $params[ModelParam::PricingPowerIndex];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = new StreamContext($momentum, $mathUtility);
        $beta = abs((float) $stock->getBeta());

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'civil_infrastructure'   => $params[ModelParam::CivilInfrastructureWeight],
            'commercial_epc'         => $params[ModelParam::CommercialEpcWeight],
            'facilities_maintenance' => $params[ModelParam::FacilitiesMaintenanceWeight],
        ]);

        $civilWeight       = $activeWeights['civil_infrastructure'];
        $commercialWeight  = $activeWeights['commercial_epc'];
        $maintenanceWeight = $activeWeights['facilities_maintenance'];

        // Independent stream Z-scores with persistent auto-regressive momentum
        $civilZ       = $streams->generateZ('civil_infrastructure', 0.40);
        $commercialZ  = $streams->generateZ('commercial_epc', 0.20);
        $maintenanceZ = $streams->generateZ('facilities_maintenance', 0.50);
        $eventZ       = $streams->generateZ('event', 0.10);

        // --- Macro Sensitivities (Damped by Order Backlog) ---
        $outputGap = $macroState->outputGapEma;
        $policyRate = $macroState->policyRateEma;
        $residentialShift = ($macroState->residentialPropertyIndexEma - 100.0) / 100.0;
        $commercialPropertyShift = ($macroState->commercialPropertyIndexEma - 100.0) / 100.0;
        $housingStartsShift = MathUtility::calculateHousingStartsShift($macroState->housingStartsIndexEma, sensitivity: self::HOUSING_STARTS_SENSITIVITY);
        $sloosDrag = max(0.0, $macroState->sloosTighteningIndexEma) * self::SLOOS_CREDIT_TIGHTENING_SCALAR;

        $commercialMacroBoost = (($outputGap * 1.5 * $beta) + ($commercialPropertyShift * 0.30) + ($residentialShift * 0.20) + $housingStartsShift) * (1.0 - self::BACKLOG_DAMPING_FACTOR);
        $commercialCreditDrag = (max(0.0, ($policyRate - $macroState->naturalRateEma) * 2.0 * $beta) + $sloosDrag) * (1.0 - self::BACKLOG_DAMPING_FACTOR);
        $maintenanceMacroBoost = ($outputGap * 0.3 * $beta);
        $govSpendShift = ($macroState->governmentSpendingIndexEma - 100.0) / 100.0;

        // --- Tail Risk Events ---
        $dealMultiplier = 1.0;
        $eventType = null;
        $costOverrunDrag = 0.0;

        if ($eventZ > self::MEGA_PROJECT_Z_SCORE) {
            $dealMultiplier = self::MEGA_PROJECT_MULT;
            $eventType = ShockEvent::INFRASTRUCTURE_BILL_WIN ?? 'mega_project_win';
        } elseif ($eventZ < self::COST_OVERRUN_Z_SCORE) {
            $costOverrunDrag = self::COST_OVERRUN_PENALTY;
            $eventType = ShockEvent::PROJECT_DELAY ?? 'cost_overrun';
        }

        // --- Tri-Stream Revenue Calculation ---
        $civilShock = $civilZ * ($baselineVol * self::CIVIL_VARIANCE_SCALAR) * (1.0 - self::BACKLOG_DAMPING_FACTOR);
        $commercialShock = $commercialZ * ($baselineVol * self::COMMERCIAL_EPC_VARIANCE_SCALAR) * (1.0 - self::BACKLOG_DAMPING_FACTOR);
        $maintenanceShock = $maintenanceZ * ($baselineVol * self::FACILITIES_MAINTENANCE_VARIANCE_SCALAR);

        $civilRevenue = max(0.0, $expectedRevenue * $civilWeight * (1.0 + $civilShock + ($govSpendShift * 0.30)) * $dealMultiplier);
        $commercialRevenue = max(0.0, $expectedRevenue * $commercialWeight * (1.0 + $commercialShock + $commercialMacroBoost - $commercialCreditDrag));
        $maintenanceRevenue = max(0.0, $expectedRevenue * $maintenanceWeight * (1.0 + $maintenanceShock + $maintenanceMacroBoost));

        $streamRevenues = [
            'civil_infrastructure'   => $civilRevenue,
            'commercial_epc'         => $commercialRevenue,
            'facilities_maintenance' => $maintenanceRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // --- Fixed-Price Contract Margin Squeeze with Cost-Plus Pass-Through ---
        $inflation = $macroState->inflationEma;
        $energyShift = max(0.0, $macroState->energyCostPushLag / MacroEngine::ENERGY_COST_PUSH_TRANSMISSION);

        $baseInflationPenalty = $inflation > MacroEngine::TARGET_INFLATION
            ? ($inflation - MacroEngine::TARGET_INFLATION) * $beta * self::INFLATION_PENALTY_SCALAR
            : 0.0;

        $metalsShift = ($macroState->industrialMetalsIndexEma - 100.0) / 100.0;
        $ppiCostDrag = MathUtility::calculatePpiCostDrag($macroState->producerPriceInflation, MacroEngine::TARGET_INFLATION, $pricingPower, self::PPI_CONSTRUCTION_SENSITIVITY);
        $rawMaterialCostDrag = $baseInflationPenalty + ($energyShift * self::ENERGY_COST_SCALAR) + ($metalsShift * self::ENERGY_COST_SCALAR * 1.5) + $ppiCostDrag;

        // Pricing power enables contractual cost-plus escalation clauses, mitigating the fixed-price margin squeeze
        $effectiveMaterialCostDrag = $rawMaterialCostDrag * (1.0 - ($pricingPower * self::MAX_PRICING_POWER_MITIGATION));

        $rawMargin = $realizedVariableMargin + $effectiveMaterialCostDrag + $costOverrunDrag;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Determine primary shock driver
        $primaryShockZ = $streams->resolveDominantShockZ([$civilZ, $commercialZ, $maintenanceZ], $eventZ);

        // Civil infrastructure wins are public tenders; maintenance is recurring; commercial is moderately visible
        $observableShockZ = ($civilZ * $civilWeight * self::CIVIL_VARIANCE_SCALAR * 0.80) +
            ($commercialZ * $commercialWeight * self::COMMERCIAL_EPC_VARIANCE_SCALAR * 0.35) +
            ($maintenanceZ * $maintenanceWeight * self::FACILITIES_MAINTENANCE_VARIANCE_SCALAR * 0.90);
        $observableShockZ *= $baselineVol;

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

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return array_unique(array_merge(parent::getOperatingMacroFields(), [
            'commercial_property_index_ema',
            'energy_cost_push_lag',
            'government_spending_index_ema',
            'housing_starts_index_ema',
            'industrial_metals_index_ema',
            'inflation_ema',
            'natural_rate_ema',
            'output_gap_ema',
            'policy_rate_ema',
            'producer_price_inflation',
            'residential_property_index_ema',
            'sloos_tightening_index_ema',
        ]));
    }
}
