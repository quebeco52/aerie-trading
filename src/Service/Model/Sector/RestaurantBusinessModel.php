<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Restaurant Franchisors & Fast Food Giants.
 * 
 * Financial Physics:
 * - Tri-Stream Franchisor Architecture:
 *      1. Company-Operated Stores: High variable food/labor/energy costs, low margin, traffic volatility.
 *      2. Franchise Royalties & Ad Pool Fees: Pure royalty (% of franchisee gross sales) with near 100% margin.
 *      3. Franchise Real Estate Leases: Captive real estate rental income with CPI escalation clauses.
 * - Inflation Sensitivity: Food commodities and kitchen labor compress company-operated margins,
 *   while franchise real estate leases provide a reliable inflation hedge.
 */
class RestaurantBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Dining out is cut in downturns; restaurants substitute freely. */
    public const OPERATING_CYCLICALITY = 1.00;
    /** Own-price elasticity of demand: volume lost per unit of real price increase. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.80;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers rather than won from a larger market. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.70;

    // --- Input Cost Basket ---
    /** Shares of the variable cost base bought in tracked input markets (energy, metals, agri, freight, wholesale goods, variable payroll). */
    public const INPUT_COST_EXPOSURES = ['agri' => 0.30, 'labor' => 0.35, 'energy' => 0.05, 'ppi' => 0.10];
    /** Menu prices move freely but the diner's next-best meal is one storefront away, so a chain recovers only part of a food or wage move before traffic answers. */
    public const PRICING_POWER_INDEX = 0.45;

    // --- Balance Sheet Realism ---
    /** Capitalized operating lease liabilities as a fraction of annual revenue (IFRS 16 / ASC 842). Franchisor real estate and store leases are the largest obligation on a restaurant balance sheet. */
    public const LEASE_LIABILITY_INTENSITY = 0.60;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Store-level crew wages sit in variable cost; only management and support payroll is fixed overhead. */
    public const FIXED_COST_LABOR_SHARE = 0.50;

    // --- Tri-Stream Architecture ---
    /** Baseline fraction of revenue derived from company-owned store operations. */
    public const CORPORATE_WEIGHT       = 0.50;
    /** Baseline fraction of revenue derived from high-margin franchise royalties. */
    public const FRANCHISE_WEIGHT       = 0.30;
    /** Baseline fraction of revenue derived from franchise real estate rental leases. */
    public const FRANCHISE_LEASE_WEIGHT = 0.20;

    // --- Pricing Power & Macro Physics ---
    public const MIN_BETA_PRICING_POWER_FLOOR = 0.40;
    /** Menu boards reprice within a couple of quarters; franchisors pass food and wage inflation to guests quickly. */
    public const INPUT_PASS_THROUGH_LAG_YEARS = 0.50;

    // --- Revenue & Shock Physics ---
    public const REVENUE_VARIANCE_SCALAR = 0.40;
    public const FRANCHISE_COST_INTENSITY = 0.05;
    public const LEASE_COST_INTENSITY     = 0.02;
    public const LEASE_INFLATION_CAPTURE  = 0.80; // CPI rent escalation clause

    // --- Tail Risk & Shock Events ---
    public const FOOD_SAFETY_SCANDAL_Z_SCORE = -2.20;
    /** Onset-quarter variable cost hit from inventory write-offs, closures and crisis response. */
    public const FOOD_SAFETY_SCANDAL_PENALTY = 0.08;
    /** Regime key for the multi-quarter guest-traffic recovery that follows a food-safety scandal. */
    public const REGIME_FOOD_SAFETY_RECOVERY = 'food_safety_recovery';
    /** Quarterly probability the scandal drops out of guest memory (~7 quarter expected recovery). */
    public const FOOD_SAFETY_RECOVERY_EXIT_HAZARD = 0.15;
    /** Share of company-store traffic lost in the scandal quarter. */
    public const FOOD_SAFETY_TRAFFIC_LOSS = 0.15;
    /** Quarterly geometric rate at which lost guests return (half-life ~2 quarters). */
    public const FOOD_SAFETY_TRAFFIC_RECOVERY_RATE = 0.35;
    /** Ongoing quarterly cost of food-safety audits and win-back marketing during the recovery. */
    public const FOOD_SAFETY_REMEDIATION_PENALTY = 0.02;
    public const VIRAL_MENU_ITEM_Z_SCORE     = 2.40;
    public const VIRAL_MENU_ITEM_MULT        = 1.15;

    // --- Continuous Elasticity ---
    public const FRANCHISE_SCALE_ELASTICITY = 0.015;

    // --- Asset Depreciation & Reinvestment ---
    public const STORE_AGING_DECAY_RATE      = 0.020;
    public const DIGITAL_KIOSK_GAIN_RATE     = 0.012;
    public const MIN_OPERATING_MARGIN_FLOOR  = 0.08;
    public const MAX_OPERATING_MARGIN_CEILING = 0.32;

        public function getMoatSpread(): float { return 0.015; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return -0.05; }

    public function getSeasonalityFactors(): array
    {
        return [0.85, 1.10, 1.20, 0.85]; // Spring/summer patio dining and vacation demand
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.035;
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / 100.0;
        $beta = $this->getOperatingCyclicality($stock);

        $physics['macro_demand_shift'] = $sentimentShift * $beta;

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::PricingPowerIndex->value      => self::MIN_BETA_PRICING_POWER_FLOOR,
            ModelParam::CompanyStoresWeight->value     => self::CORPORATE_WEIGHT,
            ModelParam::FranchiseRoyaltiesWeight->value => self::FRANCHISE_WEIGHT,
            ModelParam::FranchiseLeaseWeight->value    => self::FRANCHISE_LEASE_WEIGHT,
        ]);

        $corporateWeight = $params[ModelParam::CompanyStoresWeight];
        $franchiseWeight = $params[ModelParam::FranchiseRoyaltiesWeight];
        $leaseWeight     = $params[ModelParam::FranchiseLeaseWeight];
        $pricingPower    = max(0.0, min(1.0, $params[ModelParam::PricingPowerIndex]));

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'company_operated_stores'      => $params[ModelParam::CompanyStoresWeight],
            'franchise_royalties'          => $params[ModelParam::FranchiseRoyaltiesWeight],
            'franchise_real_estate_leases' => $params[ModelParam::FranchiseLeaseWeight],
        ]);

        $corporateWeight = $activeWeights['company_operated_stores'];
        $franchiseWeight = $activeWeights['franchise_royalties'];
        $leaseWeight     = $activeWeights['franchise_real_estate_leases'];

        // Independent stream Z-scores
        $corporateZ = $streams->generateZ('company_operated_stores', 0.10);
        $franchiseZ = $streams->generateZ('franchise_royalties', 0.20);
        $leaseZ     = $streams->generateZ('franchise_real_estate_leases', 0.50);
        $eventZ     = $streams->generateExogenousZ('event', 0.10);

        // Tail Risk Events
        // A food-safety scandal is a one-quarter write-off followed by a multi-quarter traffic recovery:
        // lost guests return geometrically while audits and win-back marketing keep costs elevated.
        $recoveryElapsed = $streams->evolveRegime(self::REGIME_FOOD_SAFETY_RECOVERY, 0.0, self::FOOD_SAFETY_RECOVERY_EXIT_HAZARD);
        $viralMultiplier = 1.0;
        $eventType = null;
        $foodSafetyPenalty = 0.0;

        if ($eventZ < self::FOOD_SAFETY_SCANDAL_Z_SCORE && $recoveryElapsed === 0) {
            $recoveryElapsed = $streams->startRegime(self::REGIME_FOOD_SAFETY_RECOVERY);
            $foodSafetyPenalty = self::FOOD_SAFETY_SCANDAL_PENALTY;
            $eventType = ShockEvent::PRODUCT_RECALL;
        } elseif ($eventZ > self::VIRAL_MENU_ITEM_Z_SCORE) {
            $viralMultiplier = self::VIRAL_MENU_ITEM_MULT;
            $eventType = ShockEvent::VIRAL_GROWTH;
        }

        if ($recoveryElapsed > 0) {
            $trafficLoss = self::FOOD_SAFETY_TRAFFIC_LOSS * exp(-self::FOOD_SAFETY_TRAFFIC_RECOVERY_RATE * ($recoveryElapsed - 1));
            $viralMultiplier *= (1.0 - $trafficLoss);
            if ($recoveryElapsed > 1) {
                $foodSafetyPenalty += self::FOOD_SAFETY_REMEDIATION_PENALTY;
            }
        }

        // CPI Escalator on franchise real estate rents
        $excessInflation = max(0.0, $macroState->inflationEma - MacroEngine::TARGET_INFLATION);
        $rentEscalator = $excessInflation * self::LEASE_INFLATION_CAPTURE;

        $corporateRevenue = max(0.0, $expectedRevenue * $corporateWeight * (1.0 + ($corporateZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR))) * $viralMultiplier);
        $franchiseRevenue = max(0.0, $expectedRevenue * $franchiseWeight * (1.0 + ($franchiseZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.15))));
        $leaseRevenue     = max(0.0, $expectedRevenue * $leaseWeight     * (1.0 + ($leaseZ     * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.05)) + $rentEscalator));
        // CPI escalators reprice existing franchise leases: pure price revenue with no incremental cost.
        $priceRevenue     = max(0.0, $expectedRevenue * $leaseWeight * $rentEscalator);

        $streamRevenues = [
            'company_operated_stores'      => $corporateRevenue,
            'franchise_royalties'          => $franchiseRevenue,
            'franchise_real_estate_leases' => $leaseRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        // Structural Margin Blending:
        // Corporate stores pay the bulk of variable costs.
        // Franchise royalties & real estate lease rents carry minimal variable cost.
        $blendedDivisor = $corporateWeight + (self::FRANCHISE_COST_INTENSITY * $franchiseWeight) + (self::LEASE_COST_INTENSITY * $leaseWeight);
        $corporateVariableMargin = $blendedDivisor > 0 ? ($realizedVariableMargin / $blendedDivisor) : $realizedVariableMargin;
        $franchiseVariableMargin = $corporateVariableMargin * self::FRANCHISE_COST_INTENSITY;
        $leaseVariableMargin     = $corporateVariableMargin * self::LEASE_COST_INTENSITY;

        $actualVariableCosts = ($corporateRevenue * $corporateVariableMargin) + ($franchiseRevenue * $franchiseVariableMargin) + ($leaseRevenue * $leaseVariableMargin);

        // Input cost basket: food commodities, kitchen crew wages, utilities and packaging reach company-operated
        // kitchens at spot and are recovered on the menu board with the repricing lag. Franchise royalties and
        // rents carry almost no variable cost, which the blended cost ratio above already reflects.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $pricingPower, $realizedVariableMargin);

        // Continuous Elasticity
        $elasticityShift = -self::FRANCHISE_SCALE_ELASTICITY * $franchiseZ * $franchiseWeight;

        $rawMargin = ($actualVariableCosts / max(1.0, $actualRevenue)) + $foodSafetyPenalty + $inputCostDrag + $elasticityShift;
        $clampedMargin = $this->clampMargin($rawMargin);

        $primaryShockZ = $streams->resolveDominantShockZ([
            ($corporateZ * $corporateWeight) + ($franchiseZ * $franchiseWeight),
        ], $eventZ);

        $observableShockZ = ($corporateZ * $corporateWeight * self::REVENUE_VARIANCE_SCALAR * $baselineVol) + ($rentEscalator * $leaseWeight);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            priceRevenue: $priceRevenue,
        );
    }

    public function getCoverageProfile(\App\Entity\Stock $stock): \App\DTO\SectorCoverageProfile
    {
        return new \App\DTO\SectorCoverageProfile(
            baseVisibility: 0.30,
            errorStdDev: 0.05,
            minVisibility: 0.10,
            eventBaseVisibility: 0.90,
            eventMinVisibility: 0.50
        );
    }

    /** Under-investment below replacement CapEx erodes operating margin toward the sector floor. */
    public function getDepreciationDecayRate(): float
    {
        return self::STORE_AGING_DECAY_RATE;
    }

    /** Over-investment above replacement CapEx compounds margin toward the sector ceiling. */
    public function getModernizationGainRate(): float
    {
        return self::DIGITAL_KIOSK_GAIN_RATE;
    }

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [
            'agricultural_commodity_index_ema',
            'consumer_sentiment_index_ema',
            'energy_cost_push_lag',
            'exchange_rate_index_ema',
            'inflation_ema',
            'output_gap_ema',
            'producer_price_inflation_ema',
            'tips_breakeven_ema',
            'wage_growth_ema',
        ];
    }
}
