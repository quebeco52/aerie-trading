<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Global Advertising Agencies & MarTech Networks.
 * 
 * Financial Physics:
 * - Asset light, human-capital intensive.
 * - Tri-Stream Agency Architecture:
 *      1. Media Buying Commissions: Programmatic take-rates on gross client ad spend; pro-cyclical to corporate earnings.
 *      2. Creative Brand Retainers: High-margin, multi-year Agency of Record (AOR) brand management fees.
 *      3. MarTech & Data Consulting: High-value enterprise marketing automation and customer analytics consulting.
 * - High Operating Leverage: During economic expansions, programmatic ad spend surges rapidly over fixed creative payroll.
 */
class AdvertisingAgencyBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.25;
    public const BASE_COVERAGE_ERROR = 0.06;

    public function getModelThresholds(): array
    {
        return ['min_icr' => 3.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.5,  'dividend_crisis_icr' => 2.00, 'buyback_min_icr' => 3.00, 'reversion_speed' => 0.15, 'moat_spread' => 0.015, 'nwc_intensity' => 0.05, 'capex_completion_rate' => 0.20];
    }

    public function getSecularGrowthRate(Stock $stock): float { return 0.015; }
    
    public function getCapexCyclicality(): float { return 0.10; }
    
    public function getSurpriseBlendWeights(): array { return ['eps_weight' => 0.70, 'revenue_weight' => 0.30]; }

    // --- Stream Weights ---
    /** Baseline fraction of revenue derived from programmatic media buying commissions. */
    public const MEDIA_BUYING_WEIGHT      = 0.50;
    /** Baseline fraction of revenue derived from creative Agency of Record brand retainers. */
    public const BRAND_RETAINER_WEIGHT     = 0.35;
    /** Baseline fraction of revenue derived from marketing technology and data consulting. */
    public const MARTECH_CONSULTING_WEIGHT = 0.15;

    // --- Physics & Variances ---
    public const MEDIA_VARIANCE_SCALAR   = 0.35; // Pro-cyclical to corporate ad spend
    public const BRAND_VARIANCE_SCALAR   = 0.10; // Sticky multi-year retainers
    public const MARTECH_VARIANCE_SCALAR = 0.20; // B2B technology consulting

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::MediaBuyingWeight->value       => self::MEDIA_BUYING_WEIGHT,
            ModelParam::BrandRetainerWeight->value      => self::BRAND_RETAINER_WEIGHT,
            ModelParam::MartechConsultingWeight->value => self::MARTECH_CONSULTING_WEIGHT,
        ]);

        $mediaWeight   = $params[ModelParam::MediaBuyingWeight];
        $brandWeight   = $params[ModelParam::BrandRetainerWeight];
        $martechWeight = $params[ModelParam::MartechConsultingWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);
        $beta     = abs((float) $stock->getBeta());

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'media_buying_commissions' => $params[ModelParam::MediaBuyingWeight],
            'creative_brand_retainers' => $params[ModelParam::BrandRetainerWeight],
            'martech_consulting'       => $params[ModelParam::MartechConsultingWeight],
        ]);

        $mediaWeight   = $activeWeights['media_buying_commissions'];
        $brandWeight   = $activeWeights['creative_brand_retainers'];
        $martechWeight = $activeWeights['martech_consulting'];

        // Ad budgets expand aggressively during GDP booms and contract sharply during recessions and consumer sentiment drops
        $sentimentShift = ($macroState->consumerSentimentIndexEma - MacroEngine::SENTIMENT_BASELINE) / 100.0;
        $macroAdSpendShift = ($macroState->outputGapEma * 1.5 * $beta) + ($sentimentShift * 0.50 * $beta);

        $mediaZ   = $streams->generateZ('media_buying_commissions', 0.25);
        $brandZ   = $streams->generateZ('creative_brand_retainers', 0.50);
        $martechZ = $streams->generateZ('martech_consulting', 0.40);

        $mediaRevenue   = max(0.0, $expectedRevenue * $mediaWeight   * (1.0 + ($mediaZ * ($baselineVol * self::MEDIA_VARIANCE_SCALAR)) + $macroAdSpendShift));
        $brandRevenue   = max(0.0, $expectedRevenue * $brandWeight   * (1.0 + ($brandZ * ($baselineVol * self::BRAND_VARIANCE_SCALAR))));
        $martechRevenue = max(0.0, $expectedRevenue * $martechWeight * (1.0 + ($martechZ * ($baselineVol * self::MARTECH_VARIANCE_SCALAR)) + ($macroAdSpendShift * 0.4)));

        $streamRevenues = [
            'media_buying_commissions' => $mediaRevenue,
            'creative_brand_retainers' => $brandRevenue,
            'martech_consulting'       => $martechRevenue,
        ];

        $actualRevenue = array_sum($streamRevenues);
        $streams->recordStreamShares($streamRevenues);

        $clampedMargin = $this->clampMargin($realizedVariableMargin);

        $primaryShockZ = abs($mediaZ) > abs($brandZ) ? $mediaZ : $brandZ;
        if (abs($martechZ) > abs($primaryShockZ)) {
            $primaryShockZ = $martechZ;
        }

        $observableShockZ = ($mediaZ * $mediaWeight * self::MEDIA_VARIANCE_SCALAR * $baselineVol) +
            ($brandZ * $brandWeight * self::BRAND_VARIANCE_SCALAR * $baselineVol) +
            ($macroAdSpendShift * $mediaWeight);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: null,
            isPublicEvent: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }
}
