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
 * Earnings strategy for Internet Retail (Weaver Marketplace archetype).
 * 
 * Financial Physics:
 * - Extremely high macro sensitivity (revenue scales directly with absolute consumer volume).
 * - Severe inflation penalty (shipping, logistics, warehouse wages).
 * - Third-Party Marketplace: High margin, sticky, asset-light platform fees.
 * - First-Party Retail: Direct sales, high volume, low margin, highly volatile.
 */
class InternetRetailBusinessModel extends StandardCorporateBusinessModel
{
    // --- Dual-Stream Architecture ---
    /** Baseline fraction of revenue derived from high-margin third-party marketplace fees. */
    public const THIRD_PARTY_WEIGHT = 0.60;
    /** Baseline fraction of revenue derived from low-margin first-party retail sales. */
    public const FIRST_PARTY_WEIGHT = 0.40;

    // --- Revenue & Shock Physics ---
    /** Moderately high baseline variance due to consumer trends. */
    public const REVENUE_VARIANCE_SCALAR = 0.50;
    /** Structural variable cost ratio of the asset-light third-party marketplace. */
    public const THIRD_PARTY_VARIABLE_COST_RATIO = 0.20;

    // --- Tail Risk & Shock Events ---
    /** Negative z-score threshold indicating severe antitrust/marketplace regulation. */
    public const ANTITRUST_FINE_Z_SCORE = -2.20;
    /** Variable cost penalty applied during severe antitrust action and compliance mandates. */
    public const ANTITRUST_FINE_PENALTY = 0.08;
    /** Positive z-score threshold indicating a massive holiday/prime-day super-cycle. */
    public const HOLIDAY_SUPER_CYCLE_Z_SCORE = 2.40;
    /** Top-line revenue multiplier for first-party retail during a holiday super-cycle. */
    public const HOLIDAY_SUPER_CYCLE_MULT = 1.15;

    // --- Continuous Elasticity ---
    /** Variable margin sensitivity to third-party marketplace network expansion. */
    public const MARKETPLACE_NETWORK_ELASTICITY = 0.018;

    // --- Asset Depreciation & Reinvestment ---
    /** Quarterly margin decay rate per unit of underinvestment in physical fulfillment infrastructure. */
    public const FULFILLMENT_DECAY_RATE = 0.022;
    /** Quarterly margin gain scalar per unit of logistics automation modernization. */
    public const AUTOMATION_GAIN_RATE = 0.012;
    /** Structural minimum operating margin floor under severe fulfillment tech debt. */
    public const MIN_OPERATING_MARGIN_FLOOR = 0.08;
    /** Structural maximum operating margin ceiling for automated logistics monopolies. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.30;

    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00, 'reversion_speed' => 0.12, 'moat_spread' => 0.015, 'nwc_intensity' => -0.08, 'capex_completion_rate' => 0.40];
    }
    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.05;
    }
    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.40, 'revenue_weight' => 0.60];
    }

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / 100.0;
        $beta = (float) $stock->getBeta();

        // Consumer sentiment drives digital consumption volume. Beta already amplifies (WEAV beta=1.70).
        $physics['macro_demand_shift'] = $sentimentShift * $beta;

        // Internet retail has no pricing power - highly commoditized
        $physics['pricing_power_multiplier'] = 1.0;

        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            'pricing_power_index' => 0.2, // Commoditized, very low pricing power
            'third_party_weight' => self::THIRD_PARTY_WEIGHT,
            'first_party_weight' => self::FIRST_PARTY_WEIGHT,
        ]);

        $pricingPower = max(0.0, min(1.0, $params['pricing_power_index']));
        $thirdPartyWeight = $params['third_party_weight'];
        $firstPartyWeight = $params['first_party_weight'];

        $momentum = $stock->getEarningsMomentumZ() ?? [];

        // Marketplace fees are sticky. First party retail is highly volatile.
        $thirdPartyZ = $mathUtility->generatePersistentZ($momentum['third_party_marketplace'] ?? 0.0, 0.05);
        $firstPartyZ = $mathUtility->generatePersistentZ($momentum['first_party_retail'] ?? 0.0, 0.25);
        $eventZ = $mathUtility->generatePersistentZ($momentum['event'] ?? 0.0, 0.10);

        // Apply variance scalars. (Macro demand is already applied via capacityUtilization in EarningsEngine)
        $thirdPartyRevenue = $expectedRevenue * $thirdPartyWeight * (1.0 + ($thirdPartyZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.2)));

        $holidayMultiplier = 1.0;
        $eventType = null;
        $antitrustPenalty = 0.0;

        if ($eventZ < self::ANTITRUST_FINE_Z_SCORE) {
            $antitrustPenalty = self::ANTITRUST_FINE_PENALTY;
            $eventType = ShockEvent::REGULATORY_FINE;
        } elseif ($eventZ > self::HOLIDAY_SUPER_CYCLE_Z_SCORE) {
            $holidayMultiplier = self::HOLIDAY_SUPER_CYCLE_MULT;
            $eventType = ShockEvent::VIRAL_GROWTH;
        }

        $firstPartyRevenue = $expectedRevenue * $firstPartyWeight * (1.0 + ($firstPartyZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 1.8))) * $holidayMultiplier;

        $actualRevenue = max(0.0, $thirdPartyRevenue + $firstPartyRevenue);

        // --- Structural Margin Blending ---
        // Third-party marketplace is asset-light and operates at a very low variable cost (high margin).
        // First-party retail operates at a much higher variable cost (low margin).
        $expectedThirdPartyRevenue = $expectedRevenue * $thirdPartyWeight;
        $expectedFirstPartyRevenue = $expectedRevenue * $firstPartyWeight;

        $thirdPartyBaselineCosts = $expectedThirdPartyRevenue * self::THIRD_PARTY_VARIABLE_COST_RATIO;

        // Derive required first-party cost ratio to hit the engine's target margin at baseline
        $targetTotalCosts = $expectedRevenue * $realizedVariableMargin;
        $firstPartyBaselineCosts = $targetTotalCosts - $thirdPartyBaselineCosts;
        $firstPartyVariableMargin = $expectedFirstPartyRevenue > 0 ? $firstPartyBaselineCosts / $expectedFirstPartyRevenue : $realizedVariableMargin;

        // Apply derived distinct margins to actual shocked revenues
        $actualVariableCosts = ($thirdPartyRevenue * self::THIRD_PARTY_VARIABLE_COST_RATIO) + ($firstPartyRevenue * $firstPartyVariableMargin);

        // Supply Chain Inflation Penalty
        $inflation = $macroState->inflationEma;
        $inflationMultiplier = 2.0 - ($pricingPower * 2.0); 
        $baseInflationPenalty = $inflation > MacroEngine::TARGET_INFLATION ? ($inflation - MacroEngine::TARGET_INFLATION) * abs((float) $stock->getBeta()) * self::INFLATION_PENALTY_SCALAR : 0.0;
        $inflationPenalty = $baseInflationPenalty * $inflationMultiplier;

        // Continuous Marketplace Network Elasticity
        $networkElasticityShift = -self::MARKETPLACE_NETWORK_ELASTICITY * $thirdPartyZ * $thirdPartyWeight;

        $rawMargin = ($actualVariableCosts / max(1.0, $actualRevenue)) + $inflationPenalty + $antitrustPenalty + $networkElasticityShift;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Blended primary shock for standard model integration
        $primaryShockZ = ($thirdPartyZ * $thirdPartyWeight) + ($firstPartyZ * $firstPartyWeight);
        if (abs($eventZ) > abs($primaryShockZ)) {
            $primaryShockZ = $eventZ;
        }

        $thirdPartyShock = $thirdPartyZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.2);
        $firstPartyShock = $firstPartyZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 1.8);
        $observableShockZ = $primaryShockZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: [
                'third_party_marketplace' => $thirdPartyZ,
                'first_party_retail' => $firstPartyZ,
                'event' => $eventZ,
            ],
            streamRevenue: [
                'Third-Party Marketplace' => $thirdPartyRevenue,
                'First-Party Retail' => $firstPartyRevenue,
            ]
        );
    }

    public function getCoverageProfile(): \App\DTO\SectorCoverageProfile
    {
        // Third-party marketplace GMV and physical shipping volume is partially trackable via web scraping and logistics (~35%).
        // Holiday super-cycles are fully public knowledge.
        return new \App\DTO\SectorCoverageProfile(
            baseVisibility: 0.35,
            errorStdDev: 0.05,
            minVisibility: 0.10,
            eventBaseVisibility: 0.80,
            eventMinVisibility: 0.50
        );
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            // Fulfillment center tech debt causes margin decay
            $decayRate = self::FULFILLMENT_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            // Logistics automation modernization improves margin
            $modGain = self::AUTOMATION_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}
