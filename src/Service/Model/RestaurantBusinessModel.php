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
 * Earnings strategy for Restaurants (Fast Food, Casual Dining, Fine Dining).
 * 
 * Financial Physics:
 * - High Operating Leverage: Corporate stores have high fixed costs and low variable margins.
 * - Franchise Model: Highly profitable royalty stream with near 100% margin and low volatility.
 * - Inflation Sensitivity: Highly sensitive to food commodity inflation and labor costs.
 */
class RestaurantBusinessModel extends StandardCorporateBusinessModel
{
    // --- Dual-Stream Architecture ---
    /** Baseline fraction of revenue derived from highly volatile corporate-owned stores. */
    public const CORPORATE_WEIGHT = 0.70;
    /** Baseline fraction of revenue derived from highly profitable, stable franchise royalties. */
    public const FRANCHISE_WEIGHT = 0.30;

    // --- Pricing Power & Macro Physics ---
    /** Hard to pass on all costs to consumers without losing traffic. */
    public const MIN_BETA_PRICING_POWER_FLOOR = 0.40; 

    // --- Revenue & Shock Physics ---
    /** High sensitivity to consumer discretionary spending. */
    public const REVENUE_VARIANCE_SCALAR = 0.40;
    /** Structural variable cost ratio of franchise royalties (near 100% margin). */
    public const FRANCHISE_VARIABLE_COST_RATIO = 0.02;
    /** Sensitivity scalar for supply chain inflation cost penalties during high CPI/PPI regimes. */
    public const INFLATION_PENALTY_SCALAR = 1.50; // Massively exposed to food & labor inflation

    // --- Tail Risk & Shock Events ---
    /** Negative z-score threshold indicating a severe food safety scandal (e.g., E. coli). */
    public const FOOD_SAFETY_SCANDAL_Z_SCORE = -2.20;
    /** Variable margin penalty applied due to PR crisis and supply chain cleaning. */
    public const FOOD_SAFETY_SCANDAL_PENALTY = 0.08;
    /** Positive z-score threshold indicating a massive viral menu item. */
    public const VIRAL_MENU_ITEM_Z_SCORE = 2.40;
    /** Top-line corporate revenue multiplier for a viral menu item. */
    public const VIRAL_MENU_ITEM_MULT = 1.15;

    // --- Continuous Elasticity ---
    /** Variable margin sensitivity to a growing franchise base improving corporate supply chain leverage. */
    public const FRANCHISE_SCALE_ELASTICITY = 0.015;

    // --- Asset Depreciation & Reinvestment ---
    /** Quarterly margin decay rate per unit of underinvestment in store remodeling. */
    public const STORE_AGING_DECAY_RATE = 0.020;
    /** Quarterly margin gain scalar per unit of digital kiosk and drive-thru modernization. */
    public const DIGITAL_KIOSK_GAIN_RATE = 0.012;
    /** Structural minimum operating margin floor under severe physical store tech debt. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.08;
    /** Structural maximum operating margin ceiling for modernized digital restaurants. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.32;

    public function getModelThresholds(): array
    {
        $thresholds = parent::getModelThresholds();
        $thresholds['moat_spread'] = 0.015; // Brand equity provides some moat
        $thresholds['nwc_intensity'] = -0.05; // Customers pay instantly, suppliers paid on terms
        return $thresholds;
    }

    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.035;
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Highly sensitive to consumer sentiment
        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / 100.0;
        $beta = (float) $stock->getBeta();

        $physics['macro_demand_shift'] = $sentimentShift * $beta;

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            'pricing_power_index' => self::MIN_BETA_PRICING_POWER_FLOOR,
            'corporate_weight' => self::CORPORATE_WEIGHT,
            'franchise_weight' => self::FRANCHISE_WEIGHT,
        ]);

        $corporateWeight = $params['corporate_weight'];
        $franchiseWeight = $params['franchise_weight'];
        $pricingPower = max(0.0, min(1.0, $params['pricing_power_index']));

        $momentum = $stock->getEarningsMomentumZ() ?? [];

        // Corporate stores are highly volatile, franchise revenue is very stable
        $corporateZ = $mathUtility->generatePersistentZ($momentum['corporate'] ?? 0.0, 0.10);
        $franchiseZ = $mathUtility->generatePersistentZ($momentum['franchise'] ?? 0.0, 0.20);
        $eventZ     = $mathUtility->generatePersistentZ($momentum['event'] ?? 0.0, 0.10);

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

        // Corporate revenue gets the full variance scalar, franchise gets a fraction
        // (Macro demand shift is already handled by EarningsEngine capacityUtilization)
        $corporateRevenue = $expectedRevenue * $corporateWeight * (1.0 + ($corporateZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR))) * $viralMultiplier;
        $franchiseRevenue = $expectedRevenue * $franchiseWeight * (1.0 + ($franchiseZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.15)));

        $actualRevenue = max(0.0, $corporateRevenue + $franchiseRevenue);

        // --- Structural Margin Blending ---
        // Corporate stores pay the bulk of the variable costs (food, labor, utilities).
        // Franchise revenue is a royalty stream with near 100% margin (minimal variable cost).
        $expectedCorporateRevenue = $expectedRevenue * $corporateWeight;
        $expectedFranchiseRevenue = $expectedRevenue * $franchiseWeight;

        $franchiseBaselineCosts = $expectedFranchiseRevenue * self::FRANCHISE_VARIABLE_COST_RATIO;
        $targetTotalCosts = $expectedRevenue * $realizedVariableMargin;
        $corporateBaselineCosts = $targetTotalCosts - $franchiseBaselineCosts;

        $corporateVariableMargin = $expectedCorporateRevenue > 0 ? $corporateBaselineCosts / $expectedCorporateRevenue : $realizedVariableMargin;

        $actualVariableCosts = ($corporateRevenue * $corporateVariableMargin) + ($franchiseRevenue * self::FRANCHISE_VARIABLE_COST_RATIO);

        // Re-implementing Inflation Penalty (dropped from Standard Corporate model)
        $inflation = $macroState->inflationEma;
        $inflationMultiplier = 2.0 - ($pricingPower * 2.0);
        $baseInflationPenalty = $inflation > MacroEngine::TARGET_INFLATION ? ($inflation - MacroEngine::TARGET_INFLATION) * abs((float) $stock->getBeta()) * self::INFLATION_PENALTY_SCALAR : 0.0;
        $inflationPenalty = $baseInflationPenalty * $inflationMultiplier;

        // Continuous Elasticity
        $elasticityShift = -self::FRANCHISE_SCALE_ELASTICITY * $franchiseZ * $franchiseWeight;

        $rawMargin = ($actualVariableCosts / max(1.0, $actualRevenue)) + $foodSafetyPenalty + $inflationPenalty + $elasticityShift;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Blended primary shock for standard model integration
        $primaryShockZ = ($corporateZ * $corporateWeight) + ($franchiseZ * $franchiseWeight);
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
                'corporate' => $corporateZ,
                'franchise' => $franchiseZ,
                'event'     => $eventZ,
            ],
            streamRevenue: [
                'Corporate Store Sales' => $corporateRevenue,
                'Franchise Royalties' => $franchiseRevenue,
            ]
        );
    }

    public function getCoverageProfile(): \App\DTO\SectorCoverageProfile
    {
        // Foot traffic and credit card data make restaurant sales moderately visible.
        // Food safety recalls are highly public.
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
            // Store aging and brand fatigue
            $decayRate = self::STORE_AGING_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            // Digital kiosk and store remodel modernization
            $modGain = self::DIGITAL_KIOSK_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}
