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
    // --- Tri-Stream Architecture ---
    /** Baseline fraction of revenue derived from company-owned store operations. */
    public const CORPORATE_WEIGHT       = 0.50;
    /** Baseline fraction of revenue derived from high-margin franchise royalties. */
    public const FRANCHISE_WEIGHT       = 0.30;
    /** Baseline fraction of revenue derived from franchise real estate rental leases. */
    public const FRANCHISE_LEASE_WEIGHT = 0.20;

    // --- Pricing Power & Macro Physics ---
    public const MIN_BETA_PRICING_POWER_FLOOR = 0.40;
    /** Variable margin cost drag from labor tightness and kitchen wage pressure when unemployment is below natural rate. */
    public const LABOR_TIGHTNESS_WAGE_SCALAR = 0.50;
    /** Sensitivity of company-operated kitchen food & paper wholesale costs to Producer Price Inflation (PPI). */
    public const PPI_COST_SENSITIVITY = 0.35;

    // --- Revenue & Shock Physics ---
    public const REVENUE_VARIANCE_SCALAR = 0.40;
    public const FRANCHISE_COST_INTENSITY = 0.05;
    public const LEASE_COST_INTENSITY     = 0.02;
    public const INFLATION_PENALTY_SCALAR = 1.50; // Massively exposed to food & labor inflation
    public const LEASE_INFLATION_CAPTURE  = 0.80; // CPI rent escalation clause

    // --- Energy & Utility Physics ---
    /** Variable margin penalty scalar applied to company-operated restaurant kitchens during energy/utility price spikes. */
    public const UTILITY_ENERGY_DRAG_SCALAR = 0.20;

    // --- Tail Risk & Shock Events ---
    public const FOOD_SAFETY_SCANDAL_Z_SCORE = -2.20;
    public const FOOD_SAFETY_SCANDAL_PENALTY = 0.08;
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

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.035;
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / 100.0;
        $beta = (float) $stock->getBeta();

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
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);

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
        $eventZ     = $streams->generateZ('event', 0.10);

        // Tail Risk Events
        $viralMultiplier = 1.0;
        $eventType = null;
        $foodSafetyPenalty = 0.0;

        if ($eventZ < self::FOOD_SAFETY_SCANDAL_Z_SCORE) {
            $foodSafetyPenalty = self::FOOD_SAFETY_SCANDAL_PENALTY;
            $eventType = ShockEvent::PRODUCT_RECALL;
            $viralMultiplier = 0.85; // Severe traffic drop
        } elseif ($eventZ > self::VIRAL_MENU_ITEM_Z_SCORE) {
            $viralMultiplier = self::VIRAL_MENU_ITEM_MULT;
            $eventType = ShockEvent::VIRAL_GROWTH;
        }

        // CPI Escalator on franchise real estate rents
        $excessInflation = max(0.0, $macroState->inflationEma - MacroEngine::TARGET_INFLATION);
        $rentEscalator = $excessInflation * self::LEASE_INFLATION_CAPTURE;

        $corporateRevenue = max(0.0, $expectedRevenue * $corporateWeight * (1.0 + ($corporateZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR))) * $viralMultiplier);
        $franchiseRevenue = max(0.0, $expectedRevenue * $franchiseWeight * (1.0 + ($franchiseZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.15))));
        $leaseRevenue     = max(0.0, $expectedRevenue * $leaseWeight     * (1.0 + ($leaseZ     * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.05)) + $rentEscalator));

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

        // Inflation Penalty (food commodities + restaurant wages)
        $inflation = $macroState->inflationEma;
        $inflationMultiplier = 2.0 - ($pricingPower * 2.0);
        $baseInflationPenalty = $inflation > MacroEngine::TARGET_INFLATION ? ($inflation - MacroEngine::TARGET_INFLATION) * abs((float) $stock->getBeta()) * self::INFLATION_PENALTY_SCALAR : 0.0;
        $inflationPenalty = $baseInflationPenalty * $inflationMultiplier;

        // Energy & Utility Drag: Company-operated stores pay kitchen gas, power, and refrigeration utilities
        $energyShift = max(0.0, $macroState->energyCostPushLag / MacroEngine::ENERGY_COST_PUSH_TRANSMISSION);
        $energyDrag = $energyShift * self::UTILITY_ENERGY_DRAG_SCALAR * $corporateWeight;

        // Food Commodity Drag (Agri Index): Company-operated stores pay direct food ingredient costs
        $agriShift = max(0.0, ($macroState->agriculturalCommodityIndexEma - 100.0) / 100.0);
        $foodCommodityDrag = $agriShift * 0.20 * (1.0 - ($pricingPower * 0.50)) * $corporateWeight;

        // Labor Market Tightness: Kitchen wage inflation when unemployment drops below natural rate
        $laborTightness = max(0.0, MacroEngine::NATURAL_UNEMPLOYMENT - $macroState->unemploymentRateEma);
        $laborTightnessDrag = $laborTightness * self::LABOR_TIGHTNESS_WAGE_SCALAR * $corporateWeight;

        // Producer Price Inflation: Wholesale food and packaging input costs
        $ppiCostDrag = MathUtility::calculatePpiCostDrag(
            $macroState->producerPriceInflationEma,
            MacroEngine::TARGET_INFLATION,
            $pricingPower,
            self::PPI_COST_SENSITIVITY
        ) * $corporateWeight;

        // Continuous Elasticity
        $elasticityShift = -self::FRANCHISE_SCALE_ELASTICITY * $franchiseZ * $franchiseWeight;

        $rawMargin = ($actualVariableCosts / max(1.0, $actualRevenue)) + $foodSafetyPenalty + $inflationPenalty + $ppiCostDrag + $energyDrag + $foodCommodityDrag + $laborTightnessDrag + $elasticityShift;
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

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            $decayRate = self::STORE_AGING_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            $modGain = self::DIGITAL_KIOSK_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}
