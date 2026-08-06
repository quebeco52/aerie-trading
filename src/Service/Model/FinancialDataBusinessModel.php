<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Financial Data & Stock Exchanges (Rating Agencies, Market Data).
 * 
 * Financial Physics:
 * - Asset-light, ultra-high margin monopolies.
 * - Revenue is incredibly sticky due to mandatory recurring subscriptions and licensing fees.
 * - Immune to supply chain inflation (they sell digital data, not physical goods).
 * - Exceptionally low idiosyncratic variance.
 */
class FinancialDataBusinessModel extends StandardCorporateBusinessModel
{
    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00, 'reversion_speed' => 0.10, 'moat_spread' => 0.025, 'nwc_intensity' => -0.05, 'capex_completion_rate' => 1.0];
    }
    public function getSecularGrowthRate(Stock $stock): float { return 0.05; }
    public function getCapexCyclicality(): float { return 0.5; }
    public function getSurpriseBlendWeights(): array { return ['eps_weight' => 0.60, 'revenue_weight' => 0.40]; }

    // --- Dual-Stream Financial Data Architecture ---
    /** Baseline fraction of revenue derived from recurring multi-year terminal & rating subscriptions. */
    public const SUBSCRIPTION_REVENUE_WEIGHT = 0.85;
    /** Baseline fraction of revenue derived from capital markets issuance ratings & transaction feed volume. */
    public const TRANSACTION_REVENUE_WEIGHT  = 0.15;

    // --- Revenue & Shock Physics ---
    /** Volatility multiplier for top-line revenue shocks in subscription data models. */
    public const REVENUE_VARIANCE_SCALAR   = 0.05;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Analyst Visibility & Error ---
    // Moved to getCoverageProfile() — see MarketConsensusEngine.

    // --- Subscription & Transaction Revenue Streams ---
    /** Operating margin mean reversion speed: slower speed reflects high switching costs and data monopoly moat. */
    public const MONOPOLY_REVERSION_SPEED  = 2.0;

    // --- Rating Issuance Operating Leverage ---
    /** Variable margin sensitivity to incremental debt rating & API transaction feed volume. */
    public const TRANSACTION_LEVERAGE_SENSITIVITY = 0.020;

    // --- Data Platform Reinvestment & Monopoly Moat Physics ---
    /** Quarterly margin decay rate per unit of software/platform underinvestment below replacement. */
    public const PLATFORM_DECAY_RATE          = 0.015;
    /** Quarterly margin gain scalar per unit of logarithmic data platform modernization. */
    public const DATA_MONOPOLY_GAIN_RATE      = 0.008;
    /** Structural minimum operating margin floor under severe platform tech debt. */
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.20;
    /** Structural maximum operating margin ceiling for proprietary financial data monopolies. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.65;

    // --- Asset-Light Subscription Capital Structure Rails ---
    /** Minimum WACC arbitrage spread required before under-leveraged recapitalization is permitted. */
    public const WACC_ARBITRAGE_THRESHOLD     = 0.02;
    /** Minimum interest coverage ratio required to permit recapitalization for subscription monopolies. */
    public const MIN_RECAP_ICR_FLOOR          = 6.0;
    /** Maximum debt tolerance threshold fraction triggering under-leveraged status. */
    public const UNDERLEVERAGED_DEBT_RATIO    = 0.60;

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            'subscription_revenue_weight' => self::SUBSCRIPTION_REVENUE_WEIGHT,
            'transaction_revenue_weight'  => self::TRANSACTION_REVENUE_WEIGHT,
        ]);

        $subscriptionWeight = $params['subscription_revenue_weight'];
        $transactionWeight  = $params['transaction_revenue_weight'];

        // Independent stream Z-scores
        $subscriptionZ = $mathUtility->generateStandardNormal(); // Recurring seat subscriptions & data licenses
        $transactionZ  = $mathUtility->generateStandardNormal(); // Debt issuance credit rating mandates & API usage

        $subscriptionRevenue = $expectedRevenue * $subscriptionWeight * (1.0 + ($subscriptionZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));
        $transactionRevenue  = $expectedRevenue * $transactionWeight * (1.0 + ($transactionZ * ($baselineVol * (self::REVENUE_VARIANCE_SCALAR * 5.0))));
        $actualRevenue       = max(0.0, $subscriptionRevenue + $transactionRevenue);

        // Rating Issuance Operating Leverage:
        // High transaction/rating volume ($transactionZ) provides strong positive operating leverage because incremental debt ratings have near-zero marginal cost.
        $operatingLeverageShift = -self::TRANSACTION_LEVERAGE_SENSITIVITY * $transactionZ * $transactionWeight;

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $operatingLeverageShift);

        $primaryShockZ = abs($transactionZ) > abs($subscriptionZ) ? $transactionZ : $subscriptionZ;
        $observableShockZ = ($subscriptionZ * $subscriptionWeight + $transactionZ * $transactionWeight) * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: null,
        );
    }

    public function getCoverageProfile(): \App\DTO\SectorCoverageProfile
    {
        // Subscriber count reporting gives analysts high visibility (~80%).
        return new \App\DTO\SectorCoverageProfile(baseVisibility: 0.80, errorStdDev: 0.10);
    }

    public function getMarginReversionSpeed(): float
    {
        return self::MONOPOLY_REVERSION_SPEED; // High switching costs and data monopoly moat
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            // Tech debt & feed latency decay toward software baseline
            $decayRate = self::PLATFORM_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            // Platform modernization expands data monopoly margin ceiling
            $modGain = self::DATA_MONOPOLY_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }

    public function isUnderLeveraged(float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool
    {
        if ($costOfEquity <= ($effectiveCostOfDebt + self::WACC_ARBITRAGE_THRESHOLD)) {
            return false;
        }
        if ($interestCoverage < self::MIN_RECAP_ICR_FLOOR) {
            return false;
        }
        return $currentDebtRatio < ($targetDebtTolerance * self::UNDERLEVERAGED_DEBT_RATIO);
    }
}