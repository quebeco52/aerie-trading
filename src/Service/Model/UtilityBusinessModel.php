<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Regulated Utilities (Water, Power, Gas).
 * 
 * Financial Physics:
 * - Legal monopolies with "Rate Base" regulation.
 * - ROIC is practically legally capped. They grow absolute earnings by deploying massive CapEx.
 * - Revenue is hyper-stable (zero correlation to economic output gaps).
 * - Authorized rate hikes lag behind inflation, causing temporary margin compression during high inflation.
 */
class UtilityBusinessModel extends StandardCorporateBusinessModel
{
    public function getModelThresholds(): array
    {
        return ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00, 'reversion_speed' => 0.15, 'moat_spread' => 0.015, 'nwc_intensity' => 0.12, 'capex_completion_rate' => 0.125];
    }
    public function getSecularGrowthRate(Stock $stock): float { return 0.01; }
    public function getCapexCyclicality(): float { return 0.5; }
    public function getSurpriseBlendWeights(): array { return ['eps_weight' => 0.85, 'revenue_weight' => 0.15]; }

    // --- Dual-Stream Utility Rate Architecture ---
    /** Baseline fraction of revenue derived from regulated rate-base monopoly tariff distribution. */
    public const REGULATED_BASE_WEIGHT       = 0.85;
    /** Baseline fraction of revenue derived from unregulated merchant power generation and renewable PPAs. */
    public const UNREGULATED_MERCHANT_WEIGHT = 0.15;

    // --- Regulatory Lag & Macro Physics ---
    /** Macroeconomic demand shift sensitivity to output gap for essential utility monopolies. */
    public const MACRO_DEMAND_SCALAR       = 0.25;
    /** Pricing power multiplier applied to inflation reflecting delayed rate hike approvals. */
    public const PRICING_POWER_LAG_SCALAR  = 0.25;
    /** Inflation buffer above target inflation before regulatory lag penalties begin compressing margins. */
    public const REGULATORY_LAG_BUFFER     = 0.01;
    /** Variable margin penalty multiplier applied to inflation exceeding the lag threshold. */
    public const REGULATORY_LAG_PENALTY    = 0.80;

    // --- Revenue & Shock Physics ---
    /** Volatility multiplier for top-line revenue shocks in hyper-stable utility models. */
    public const REVENUE_VARIANCE_SCALAR   = 0.03;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- Analyst Visibility & Error ---
    // Moved to getCoverageProfile() — see MarketConsensusEngine.

    // --- Regulated Tariff & Merchant Power Capital Reinvestment Physics ---
    /** Operating margin mean reversion speed: slower speed reflects regulated rate of return structures. */
    public const REGULATED_REVERSION_SPEED = 2.0;

    // --- Utility Valuation & Consensus Weights ---
    /** Weight given to rate-base book value in regulated utility fair value consensus. */
    public const FAIR_VALUE_BOOK_WEIGHT     = 0.30;
    /** Weight given to earnings multiple in regulated utility fair value consensus. */
    public const FAIR_VALUE_EARNINGS_WEIGHT = 0.40;
    /** Weight given to Dividend Discount Model yield support in regulated utility fair value consensus. */
    public const FAIR_VALUE_DDM_WEIGHT      = 0.30;

    // --- Capital Reinvestment & Asset Depreciation Physics ---
    /** Quarterly efficiency decay rate per unit of underinvestment below replacement CapEx. */
    public const DEPRECIATION_DECAY_RATE      = 0.020;
    /** Quarterly efficiency gain scalar per unit of logarithmic overinvestment above replacement CapEx. */
    public const MODERNIZATION_GAIN_RATE      = 0.010;
    /** Structural minimum operating margin floor under extreme grid infrastructure aging. */
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.02;
    /** Structural maximum operating margin ceiling for state-of-the-art grid & generation infrastructure. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.30;

    // --- Merchant Power & Spark Spread Physics ---
    /** Variable margin sensitivity to unregulated merchant wholesale electricity and commodity readouts. */
    public const MERCHANT_MARGIN_SENSITIVITY  = 0.015;

    // --- Rate-Base CapEx & Capital Structure Rails ---
    /** Valuation discount applied when FCF is negative due to heavy rate-base infrastructure expansion. */
    public const UTILITY_CAPEX_BURN_DISCOUNT  = 0.92;
    /** Minimum interest coverage ratio required to permit recapitalization for stable utility monopolies. */
    public const MIN_RECAP_ICR_FLOOR          = 2.5;
    /** Minimum WACC arbitrage spread required before under-leveraged utility recapitalization is permitted. */
    public const WACC_ARBITRAGE_THRESHOLD     = 0.01;
    /** Maximum debt tolerance threshold fraction triggering under-leveraged status. */
    public const UNDERLEVERAGED_DEBT_RATIO    = 0.70;

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // Regulated Utilities are virtually immune to economic output gaps (people always need power/water)
        $outputGap = $macroState->outputGapEma;
        $beta = (float) $stock->getBeta();
        $physics['macro_demand_shift'] = $outputGap * $beta * self::MACRO_DEMAND_SCALAR;

        // Regulatory Lag: Utilities do get rate hikes to cover inflation, but they are delayed.
        $inflation = $macroState->inflationEma;
        $physics['pricing_power_multiplier'] = 1.0 + ($inflation * self::PRICING_POWER_LAG_SCALAR);

        return $physics;
    }

    /**
     * Regulated Utilities trade explosive top-line revenue variance for extreme bottom-line ROIC certainty.
     */
    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            'regulated_base_weight'       => self::REGULATED_BASE_WEIGHT,
            'unregulated_merchant_weight' => self::UNREGULATED_MERCHANT_WEIGHT,
        ]);

        $regulatedWeight   = $params['regulated_base_weight'];
        $unregulatedWeight = $params['unregulated_merchant_weight'];

        $momentum = $stock->getEarningsMomentumZ() ?? [];

        // Independent stream Z-scores with AR(1) persistence
        $regulatedZ   = $mathUtility->generatePersistentZ($momentum['regulated'] ?? 0.0, 0.15); // Regulated tariff distribution volume (weather / seasonal)
        $unregulatedZ = $mathUtility->generatePersistentZ($momentum['unregulated'] ?? 0.0, 0.20); // Merchant wholesale electricity & PPA trading

        $regulatedRevenue   = $expectedRevenue * $regulatedWeight * (1.0 + ($regulatedZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));
        $unregulatedRevenue = $expectedRevenue * $unregulatedWeight * (1.0 + ($unregulatedZ * ($baselineVol * (self::REVENUE_VARIANCE_SCALAR * 3.0))));
        $actualRevenue      = max(0.0, $regulatedRevenue + $unregulatedRevenue);

        // Regulatory Lag:
        // Applies specifically to regulated tariff distribution ($regulatedWeight).
        $inflation = $macroState->inflationEma;
        $lagThreshold = MacroEngine::TARGET_INFLATION + self::REGULATORY_LAG_BUFFER;
        $regulatoryLagPenalty = $inflation > $lagThreshold ? ($inflation - $lagThreshold) * self::REGULATORY_LAG_PENALTY * $regulatedWeight : 0.0;

        // Merchant Spark Spread Variance:
        // Unregulated merchant power and services experience wholesale margin volatility from power/fuel spread shifts.
        $merchantSpreadShift = -self::MERCHANT_MARGIN_SENSITIVITY * $unregulatedZ * $unregulatedWeight;

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $regulatoryLagPenalty + $merchantSpreadShift);

        $primaryShockZ = abs($unregulatedZ) > abs($regulatedZ) ? $unregulatedZ : $regulatedZ;
        $observableShockZ = ($regulatedZ * $regulatedWeight + $unregulatedZ * $unregulatedWeight) * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: null,
            streamZ: [
                'regulated'   => $regulatedZ,
                'unregulated' => $unregulatedZ,
            ],
            streamRevenue: [
                'regulated_tariff' => $regulatedRevenue,
                'unregulated'      => $unregulatedRevenue,
            ],
        );
    }

    public function getCoverageProfile(): \App\DTO\SectorCoverageProfile
    {
        // Regulated tariff schedules and EIA production data give analysts ~20% visibility.
        return new \App\DTO\SectorCoverageProfile(baseVisibility: 0.20, errorStdDev: 0.05);
    }

    public function getMarginReversionSpeed(): float
    {
        return self::REGULATED_REVERSION_SPEED; // Regulated utility rate of return structures resist margin compression
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            $decayRate = self::DEPRECIATION_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            $modGain = self::MODERNIZATION_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }

    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float
    {
        if ($fcfPerShare !== null && $fcfPerShare > 0.0) {
            $multiplier = $mathUtility->calculateDcfMultiplier($liveWacc, self::DCF_TERMINAL_GROWTH_RATE);
            $annualFcf = $fcfPerShare * 4.0;
            $dcfFairValue = min(max(0.01, $annualFcf * $multiplier), $peFairValue * self::MAX_DCF_TO_PE_CAP_MULT);
            return ($peFairValue + $dcfFairValue) / 2.0;
        }
        // During rate-base infrastructure expansion cycles, value utilities on their expanded rate base potential
        return $fcfPerShare !== null ? max($revenueFloorValue, $peFairValue * self::UTILITY_CAPEX_BURN_DISCOUNT) : max($revenueFloorValue, $peFairValue);
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        if ($dividendSupportValue > 0.0) {
            return ($pbFairValue * self::FAIR_VALUE_BOOK_WEIGHT) + ($earningsValue * self::FAIR_VALUE_EARNINGS_WEIGHT) + ($dividendSupportValue * self::FAIR_VALUE_DDM_WEIGHT);
        }
        return parent::calculateFairValue($earningsValue, $pbFairValue, $normalizedEps, 0.0);
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

