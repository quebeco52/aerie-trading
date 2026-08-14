<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Consumer Staples (Food, Tobacco, Household Goods).
 * 
 * Financial Physics:
 * - Inelastic demand: Consumers must buy these products regardless of the economic cycle.
 * - High pricing power: They can pass supply chain inflation directly to consumers without losing sales volume.
 * - Extremely low top-line volatility compared to discretionary retail.
 */
class ConsumerStaplesBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.30;
    public const BASE_COVERAGE_ERROR = 0.05;
    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00, 'reversion_speed' => 0.15, 'moat_spread' => 0.015, 'nwc_intensity' => 0.05, 'capex_completion_rate' => 0.33];
    }
    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.03;
    }
    public function getCapexCyclicality(): float
    {
        return 0.8;
    }
    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.75, 'revenue_weight' => 0.25];
    }

    // --- Dual-Stream Consumer Staples Architecture ---
    /** Baseline fraction of revenue derived from premium packaged branded staples and inelastic consumer goods. */
    public const BRANDED_STAPLES_WEIGHT  = 0.65;
    /** Baseline fraction of revenue derived from bulk commodity food processing and agricultural volume. */
    public const VOLUME_COMMODITY_WEIGHT = 0.35;

    // --- Inelastic Demand & Shock Physics ---
    /** Volatility multiplier for top-line revenue shocks in stable consumer staples models. */
    public const REVENUE_VARIANCE_SCALAR   = 0.05;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Product Recall & Regulatory Lore Thresholds ---
    /** Negative z-score threshold indicating severe supply chain contamination and massive product recall. */
    public const RECALL_SEVERE_Z_SCORE     = -2.50;
    /** Variable cost penalty applied during massive product recalls and inventory write-offs. */
    public const RECALL_SEVERE_PENALTY     = 0.08;
    /** Negative z-score threshold indicating sudden health regulatory scrutiny and fines. */
    public const RECALL_MODERATE_Z_SCORE   = -2.00;
    /** Variable cost penalty applied during moderate regulatory fines and legal fees. */
    public const RECALL_MODERATE_PENALTY   = 0.03;

    // --- Analyst Visibility & Error ---
    // Moved to getCoverageProfile() — see MarketConsensusEngine.

    // --- Reversion & Working Capital ---
    /** Operating margin mean reversion speed: fast speed reflects intense retail price competition. */
    public const STAPLES_REVERSION_SPEED   = 5.0;

    // --- Agricultural & Packaging Commodity Input Elasticity ---
    /** Variable margin sensitivity to agricultural and packaging commodity input cost shocks. */
    public const COMMODITY_INPUT_ELASTICITY   = 0.015;

    // --- Brand Equity Amortization & Marketing Reinvestment Physics ---
    /** Quarterly margin decay rate per unit of underinvestment below brand maintenance CapEx. */
    public const BRAND_EQUITY_DECAY_RATE      = 0.020;
    /** Quarterly margin gain scalar per unit of logarithmic brand marketing super-cycle investment. */
    public const BRAND_MARKETING_GAIN_RATE    = 0.010;
    /** Structural minimum operating margin floor under private-label generic retail competition. */
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.10;
    /** Structural maximum operating margin ceiling for dominant global consumer staple brands. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.35;

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::BrandedStaplesWeight->value   => self::BRANDED_STAPLES_WEIGHT,
            ModelParam::VolumeCommodityWeight->value  => self::VOLUME_COMMODITY_WEIGHT,
            ModelParam::CommodityTradingWeight->value => 0.00,
            ModelParam::LandSpeculationWeight->value  => 0.00,
        ]);

        $brandedWeight     = $params[ModelParam::BrandedStaplesWeight];
        $volumeWeight      = $params[ModelParam::VolumeCommodityWeight];
        $commodityWeight   = $params[ModelParam::CommodityTradingWeight];
        $landWeight        = $params[ModelParam::LandSpeculationWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];

        // Independent stream Z-scores with AR(1) persistence
        $brandedZ = $mathUtility->generatePersistentZ($momentum['branded'] ?? 0.0, 0.15); // Core branded consumer products
        $volumeZ  = $mathUtility->generatePersistentZ($momentum['volume'] ?? 0.0, 0.15); // Unbranded bulk volume / wholesale processing

        $brandedRevenue = $expectedRevenue * $brandedWeight * (1.0 + ($brandedZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));
        $volumeRevenue  = $expectedRevenue * $volumeWeight * (1.0 + ($volumeZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));

        // Tail Risk: Product Recalls and Health Regulations
        // Scaled proportionally to packaged branded consumer staples ($brandedWeight).
        $eventZ = $mathUtility->generatePersistentZ($momentum['event'] ?? 0.0, 0.05);
        $eventType = null;
        $recallPenalty = 0.0;

        if ($eventZ < self::RECALL_SEVERE_Z_SCORE) {
            $recallPenalty = self::RECALL_SEVERE_PENALTY * $brandedWeight;
            $eventType = ShockEvent::PRODUCT_RECALL;
        } elseif ($eventZ < self::RECALL_MODERATE_Z_SCORE) {
            $recallPenalty = self::RECALL_MODERATE_PENALTY * $brandedWeight;
            $eventType = ShockEvent::REGULATORY_FINE;
        }

        $commodityRevenue = 0.0;
        $commodityZ = 0.0;
        if ($commodityWeight > 0.0) {
            $commodityZ = $mathUtility->generatePersistentZ($momentum['commodity_trading'] ?? 0.0, 0.20);
            $commodityRevenue = $expectedRevenue * $commodityWeight * (1.0 + ($commodityZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * 2.5));
        }

        $landRevenue = 0.0;
        $landZ = 0.0;
        if ($landWeight > 0.0) {
            $landZ = $mathUtility->generatePersistentZ($momentum['land_speculation'] ?? 0.0, 0.50);
            $landRevenue = $expectedRevenue * $landWeight * (1.0 + ($landZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.5));
        }

        $actualRevenue = max(0.0, $brandedRevenue + $volumeRevenue + $commodityRevenue + $landRevenue);

        // Agricultural & Packaging Commodity Input Cost Elasticity:
        // Fluctuations in bulk agricultural processing ($volumeZ) smoothly shift variable input costs.
        $commodityInputShift = self::COMMODITY_INPUT_ELASTICITY * $volumeZ * $volumeWeight;

        // Supply Chain & Packaging Penalty (Energy Price Index)
        $energyShift = max(0.0, ($macroState->energyPriceIndexEma - MacroEngine::ENERGY_BASELINE) / 100.0);
        $logisticsPenalty = $energyShift * abs((float) $stock->getBeta()) * 0.50; // Plastic packaging & freight cost spike

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $recallPenalty + $commodityInputShift + $logisticsPenalty);

        $primaryShockZ = abs($eventZ) > abs($brandedZ) ? $eventZ : $brandedZ;
        $observableShockZ = ($brandedZ * $brandedWeight + $volumeZ * $volumeWeight) * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);

        $streamZ = [
            'branded' => $brandedZ,
            'volume'  => $volumeZ,
            'event'   => $eventZ,
        ];

        $streamRevenue = [
            'branded' => $brandedRevenue,
            'volume'  => $volumeRevenue,
        ];

        if ($commodityWeight > 0.0) {
            $streamZ['commodity_trading'] = $commodityZ;
            $streamRevenue['commodity_trading'] = $commodityRevenue;
        }

        if ($landWeight > 0.0) {
            $streamZ['land_speculation'] = $landZ;
            $streamRevenue['land_speculation'] = $landRevenue;
        }

        $result = new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streamZ,
            streamRevenue: $streamRevenue,
        );

        return $result;
    }

    public function getMarginReversionSpeed(): float
    {
        return self::STAPLES_REVERSION_SPEED; // High retail competition and consumer price sensitivity cause rapid margin mean reversion
    }

    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        return -0.05; // Negative working capital cycle (supermarket customer upfront payment vs 60-day vendor payables float)
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            // Brand equity erosion toward private-label floor
            $decayRate = self::BRAND_EQUITY_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            // Brand marketing super-cycle expands pricing power
            $modGain = self::BRAND_MARKETING_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}
