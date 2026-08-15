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
 * Earnings strategy for Technology & Software companies.
 * 
 * Financial Physics:
 * - Asset-light, highly scalable revenues. Marginal cost of a new user is near zero.
 * - Mostly immune to physical supply chain inflation, but exposed to wage inflation.
 * - Higher inherent top-line volatility (viral growth or sudden user churn).
 * - Vulnerable to "fat left-tail" risks like regulatory anti-trust fines or data breaches.
 */
class TechBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.20;
    public const BASE_COVERAGE_ERROR = 0.05;
    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00, 'reversion_speed' => 0.10, 'moat_spread' => 0.025, 'nwc_intensity' => -0.05, 'capex_completion_rate' => 0.50];
    }
    public function getSecularGrowthRate(Stock $stock): float
    {
        return 0.06;
    }
    public function getCapexCyclicality(): float
    {
        return 1.0;
    }
    public function getSurpriseBlendWeights(): array
    {
        return ['eps_weight' => 0.25, 'revenue_weight' => 0.75];
    }

    // --- Dual-Stream Tech & Software Architecture ---
    /** Baseline fraction of revenue derived from recurring SaaS subscription & cloud infrastructure. */
    public const SUBSCRIPTION_REVENUE_WEIGHT    = 0.60;
    /** Baseline fraction of revenue derived from digital advertising networks & platform usage fees. */
    public const ADVERTISING_REVENUE_WEIGHT     = 0.40;
    /** Sensitivity of digital advertising revenue to macroeconomic output gap cycles. */
    public const ADVERTISING_CYCLICALITY_SCALAR = 0.15;

    // --- Revenue Volatility & Wage Inflation Rails ---
    /** Volatility multiplier for top-line revenue shocks reflecting rapid software user scaling and churn. */
    public const REVENUE_VARIANCE_SCALAR   = 0.20;
    /** Multiplier scaling target inflation to establish wage inflation threshold buffer. */
    public const WAGE_INFLATION_THRESHOLD  = 2.00;
    /** Multiplier scaling excess wage inflation with stock beta to compute variable cost penalty. */
    public const WAGE_INFLATION_SCALAR     = 0.50;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Fat Tail Event Lore & Shock Thresholds ---
    /** Negative z-score threshold indicating major regulatory anti-trust fines or data breaches. */
    public const REGULATORY_FINE_Z_SCORE   = -2.50;
    /** Variable cost penalty applied during severe regulatory fines and legal compliance mandates. */
    public const REGULATORY_FINE_PENALTY   = 0.15;
    /** Negative z-score threshold indicating severe user churn and platform defection. */
    public const SEVERE_CHURN_Z_SCORE      = -2.00;
    /** Variable cost penalty applied during severe customer churn events. */
    public const SEVERE_CHURN_PENALTY      = 0.05;
    /** Positive z-score threshold indicating breakthrough viral user adoption and network effects. */
    public const VIRAL_GROWTH_Z_SCORE      = 2.50;
    /** Top-line revenue multiplier applied during breakthrough viral user growth. */
    public const VIRAL_GROWTH_REV_MULT     = 1.10;

    // --- Analyst Visibility & Error ---
    // Moved to getCoverageProfile() — see MarketConsensusEngine.

    // --- Innovation Competition Moat & Capital Structure ---
    /** Operating margin mean reversion speed: fast speed reflects rapid technological disruption and competition. */
    public const TECH_REVERSION_SPEED      = 5.0;
    /** Minimum WACC arbitrage spread required before under-leveraged recapitalization is permitted. */
    public const WACC_ARBITRAGE_THRESHOLD  = 0.03;
    /** Minimum interest coverage ratio required to permit recapitalization for intangible asset software models. */
    public const MIN_RECAP_ICR_FLOOR       = 15.0;
    /** Maximum debt tolerance threshold fraction triggering under-leveraged status. */
    public const UNDERLEVERAGED_DEBT_RATIO = 0.50;

    // --- SaaS ARR Operating Leverage & Software Platform Reinvestment Physics ---
    /** Variable margin sensitivity to SaaS ARR expansion (zero marginal cost of software delivery). */
    public const SAAS_OPERATING_LEVERAGE      = 0.022;
    /** Quarterly margin decay rate per unit of underinvestment below software maintenance CapEx. */
    public const TECH_DEBT_DECAY_RATE         = 0.020;
    /** Quarterly margin gain scalar per unit of logarithmic cloud platform modernization. */
    public const PLATFORM_MODERNIZATION_GAIN_RATE = 0.012;
    /** Structural minimum operating margin floor under severe software tech debt and customer churn. */
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.15;
    /** Structural maximum operating margin ceiling for cloud software monopolies. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.55;

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::SubscriptionRevenueWeight->value => self::SUBSCRIPTION_REVENUE_WEIGHT,
            ModelParam::AdvertisingRevenueWeight->value  => self::ADVERTISING_REVENUE_WEIGHT,
            ModelParam::CloudInfrastructureWeight->value => 0.00,
            ModelParam::AdvertisingCyclicality->value     => self::ADVERTISING_CYCLICALITY_SCALAR,
            ModelParam::MonopolyAggression->value         => 0.5,
        ]);

        $rawCloudWeight      = $params[ModelParam::CloudInfrastructureWeight];
        $adCyclicalityScalar = $params[ModelParam::AdvertisingCyclicality];

        $aggression = max(0.0, min(1.0, $params[ModelParam::MonopolyAggression]));
        // Risk vs Reward Trade-off:
        // Reward: Lower variable costs (higher margins) via aggressive pricing and data harvesting
        $marginBonus = $aggression * 0.10; // Up to 1000 bps baseline margin expansion

        // Risk: Massive amplification of regulatory scrutiny
        // At aggression=1.0, Z-score threshold shifts from -2.5 to -1.25 (frequent fines), and penalty severity is 1.5x.
        // At aggression=0.0, Z-score threshold shifts to -3.75 (nearly impossible), and penalty is 0.5x.
        $regulatoryZThreshold = self::REGULATORY_FINE_Z_SCORE * (1.5 - $aggression);
        $regulatorySeverity   = self::REGULATORY_FINE_PENALTY * (0.5 + $aggression);

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);

        $targetWeights = [
            'subscription' => $params[ModelParam::SubscriptionRevenueWeight],
            'advertising'  => $params[ModelParam::AdvertisingRevenueWeight],
        ];
        if ($rawCloudWeight > 0.0) {
            $targetWeights['cloud_infrastructure'] = $rawCloudWeight;
        }

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights($targetWeights);

        $subWeight   = $activeWeights['subscription'];
        $adWeight    = $activeWeights['advertising'];
        $cloudWeight = $activeWeights['cloud_infrastructure'] ?? 0.0;

        // Independent stream Z-scores with AR(1) persistence
        $subscriptionZ = $streams->generateZ('subscription', 0.45); // Enterprise SaaS ARR & Cloud compute contract volume
        $adZ           = $streams->generateZ('advertising', 0.30); // Digital advertising auction demand & impression volume
        $eventZ        = $streams->generateZ('event', 0.10); // Fat-tail regulatory antitrust / data breach Z-score

        // Macro advertising cyclicality (marketing budgets expand with positive output gap, collapse in recessions)
        $outputGap = $macroState->outputGapEma;
        $adCyclicality = $outputGap * $adCyclicalityScalar;

        // Fat Tail Risk: Data Breaches, Anti-Trust, and Viral Breakthroughs
        $regulatoryShock = 0.0;
        $eventType       = null;

        if ($eventZ < $regulatoryZThreshold) {
            $regulatoryShock = $regulatorySeverity; // Massive antitrust / surveillance fine
            $eventType = ShockEvent::REGULATORY_FINE;
        } elseif ($eventZ < self::SEVERE_CHURN_Z_SCORE) {
            $regulatoryShock = self::SEVERE_CHURN_PENALTY;
            $eventType = ShockEvent::SEVERE_CHURN;
        } elseif ($eventZ > self::VIRAL_GROWTH_Z_SCORE) {
            $eventType = ShockEvent::VIRAL_GROWTH;
        }

        // Blended dual-stream revenue (SaaS Subscription vs. Digital Advertising & Platform Usage)
        // Subscription ARR has lower baseline volatility (0.6x scalar), whereas Ads take the full swing plus cyclicality
        $subscriptionRevenue = max(0.0, $expectedRevenue * $subWeight
            * (1.0 + ($subscriptionZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.6))));

        $viralMultiplier = ($eventType === ShockEvent::VIRAL_GROWTH) ? self::VIRAL_GROWTH_REV_MULT : 1.0;
        $adRevenue = max(0.0, $expectedRevenue * $adWeight
            * (1.0 + ($adZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $adCyclicality)
            * $viralMultiplier);

        $streamRevenues = [
            'subscription' => $subscriptionRevenue,
            'advertising'  => $adRevenue,
        ];

        $cloudRevenue = 0.0;
        $cloudZ = 0.0;
        if ($cloudWeight > 0.0) {
            $cloudZ = $streams->generateZ('cloud_infrastructure', 0.60); // High persistence, sticky enterprise contracts
            $cloudRevenue = max(0.0, $expectedRevenue * $cloudWeight * (1.0 + ($cloudZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.4)));
            $streamRevenues['cloud_infrastructure'] = $cloudRevenue;
        }

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // Supply Chain Immunity vs. Continuous Talent Inflation:
        // Tech companies don't buy steel or oil, they pay for engineers and cloud compute.
        $inflation = $macroState->inflationEma;
        $wageInflationPenalty = max(0.0, ($inflation - MacroEngine::TARGET_INFLATION)) * abs((float) $stock->getBeta()) * self::WAGE_INFLATION_SCALAR;

        // SaaS ARR Operating Leverage:
        // ARR expansion ($subscriptionZ > 0) creates positive operating leverage due to zero marginal cost of software delivery.
        $saasOperatingLeverageShift = -self::SAAS_OPERATING_LEVERAGE * $subscriptionZ * $subWeight;

        // Crucially, antitrust and data privacy regulatory penalties apply proportionally to the Advertising
        // & Platform data-harvesting stream ($adWeight), insulating enterprise subscription margins.
        $adCostAddon = $regulatoryShock * $adWeight;
        $rawMargin = $realizedVariableMargin + $wageInflationPenalty + $saasOperatingLeverageShift + $adCostAddon - $marginBonus;
        $clampedMargin = $this->clampMargin($rawMargin);

        // Primary shock Z-score selects the most extreme driver across streams
        $primaryShockZ = $subscriptionZ;
        if (abs($adZ) > abs($primaryShockZ)) {
            $primaryShockZ = $adZ;
        }
        if (abs($eventZ) > abs($primaryShockZ)) {
            $primaryShockZ = $eventZ;
        }

        $observableShockZ = (($subscriptionZ * $subWeight) + ($adZ * $adWeight)) * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);

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

    public function getMarginReversionSpeed(): float
    {
        return self::TECH_REVERSION_SPEED; // Rapid innovation cycles and intense technological competition erode excess margins quickly
    }

    public function isUnderLeveraged(float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool
    {
        // Intangible asset-heavy businesses (Tech) have a natural optimal capital structure near zero debt
        // due to high financial distress costs. They should only recapitalize under extreme WACC arbitrage
        // (Ke > Kd + 3.0%) and extraordinary cash flow safety (ICR > 15.0).
        if ($costOfEquity <= ($effectiveCostOfDebt + self::WACC_ARBITRAGE_THRESHOLD)) {
            return false;
        }
        if ($interestCoverage < self::MIN_RECAP_ICR_FLOOR) {
            return false;
        }
        return $currentDebtRatio < ($targetDebtTolerance * self::UNDERLEVERAGED_DEBT_RATIO);
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            // Software tech debt & customer churn decay toward floor
            $decayRate = self::TECH_DEBT_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            // Cloud ARR platform modernization expands SaaS margin ceiling
            $modGain = self::PLATFORM_MODERNIZATION_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
}
