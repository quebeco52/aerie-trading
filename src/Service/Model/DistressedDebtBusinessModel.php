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
 * Earnings strategy for Distressed Debt, Special Situations, and Turnaround Funds.
 * 
 * Financial Physics:
 * - Counter-Cyclical Special Situations: Thrives during systemic recessions and credit crises.
 * - Tri-Stream Architecture:
 *      1. Restructuring Advisory Fees: Steady retainer-based fee income earned during bankruptcy workouts.
 *      2. Distressed Asset Recovery: High-yield gains from buying defaulted senior secured debt at discounts.
 *      3. Loan-to-Own Equity Gains: Asymmetric capital gains upon post-reorganization equity emergence.
 * - Credit Spread & Recession Multipliers: When corporate credit spreads blow out above 2.5%,
 *   distressed asset acquisition opportunities and liquidation yields surge non-linearly.
 * - During prolonged bull markets with tight credit spreads, earnings remain defensive as the fund hoards dry powder.
 */
class DistressedDebtBusinessModel extends AssetManagementBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.90;
    public const BASE_COVERAGE_ERROR = 0.05;
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.80;

    public function getModelThresholds(): array
    {
        return ['min_icr' => 1.05, 'bankrupt_equity' => 2.0,  'distress_equity' => 4.0,  'warning_equity' => 6.0,  'wholesale_leverage_limit' => 0.5,  'dividend_crisis_icr' => 1.05, 'buyback_min_icr' => 1.15, 'reversion_speed' => 0.18, 'moat_spread' => 0.005, 'nwc_intensity' => 0.0, 'capex_completion_rate' => 1.0];
    }

    // --- Special Situations & Macro Triggers ---
    /** Baseline macro credit spread fallback when macroeconomic state data is absent. */
    public const DEFAULT_CREDIT_SPREAD_FALLBACK = 0.015;
    /** Credit spread blowout threshold above which distressed asset acquisition opportunities explode. */
    public const SPREAD_BLOWOUT_THRESHOLD       = 0.025;
    /** Negative output gap threshold indicating severe recessionary distress and corporate defaults. */
    public const RECESSION_GAP_THRESHOLD        = -0.015;
    /** Sensitivity multiplier translating excess credit spreads into turnaround revenue surge. */
    public const SPREAD_SURGE_SCALAR            = 20.00;
    /** Sensitivity multiplier translating economic contraction depth into turnaround revenue surge. */
    public const RECESSION_SURGE_SCALAR         = 5.00;
    /** Positive output gap threshold indicating tight credit spreads in roaring bull markets. */
    public const BULL_MARKET_GAP_THRESHOLD      = 0.020;
    /** Revenue contraction multiplier during prolonged bull markets with tight credit spreads. */
    public const BULL_MARKET_REVENUE_DRAG       = -0.10;

    // --- Stream Weights & Variances ---
    public const RESTRUCTURING_ADVISORY_WEIGHT = 0.40;
    public const ASSET_RECOVERY_WEIGHT         = 0.60;

    public const ADVISORY_VARIANCE_SCALAR = 0.15;
    public const RECOVERY_VARIANCE_SCALAR = 0.45;
    public const REVENUE_VARIANCE_SCALAR  = 0.20;

    // --- Tail Risk Events ---
    public const LORE_RESTRUCTURING_Z_SCORE     = 1.00;

    // --- Dry Powder Liquidity Rules ---
    public const DRY_POWDER_HOARDER_THRESHOLD   = 0.40;
    public const DRY_POWDER_MEGA_THRESHOLD      = 0.70;

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);

        // Counter-Cyclical Credit Spread Trigger
        $creditSpread = $macroState->macroCreditSpread;
        $outputGap = $macroState->outputGapEma;

        $distressMultiplier = 0.0;
        $eventType = null;

        // When credit spreads exceed 2.5% or output gap is negative, distressed debt opportunities explode
        if ($creditSpread > self::SPREAD_BLOWOUT_THRESHOLD || $outputGap < self::RECESSION_GAP_THRESHOLD) {
            $distressMultiplier = ($creditSpread - self::DEFAULT_CREDIT_SPREAD_FALLBACK) * self::SPREAD_SURGE_SCALAR + abs(min(0.0, $outputGap)) * self::RECESSION_SURGE_SCALAR;
        } elseif ($outputGap > self::BULL_MARKET_GAP_THRESHOLD && $creditSpread < self::DEFAULT_CREDIT_SPREAD_FALLBACK) {
            $distressMultiplier = self::BULL_MARKET_REVENUE_DRAG;
        }

        $params = $this->resolveModelParameters($stock, [
            ModelParam::RestructuringAdvisoryWeight->value => self::RESTRUCTURING_ADVISORY_WEIGHT,
            ModelParam::TurnaroundGainsWeight->value       => self::ASSET_RECOVERY_WEIGHT,
            ModelParam::LoanToOwnGainsWeight->value        => 0.00,
        ]);
        $rawLoanToOwnWeight = $params[ModelParam::LoanToOwnGainsWeight];

        $targetWeights = [
            'restructuring_advisory' => $params[ModelParam::RestructuringAdvisoryWeight],
            'turnaround_recovery'    => $params[ModelParam::TurnaroundGainsWeight],
        ];
        if ($rawLoanToOwnWeight > 0.0) {
            $targetWeights['loan_to_own'] = $rawLoanToOwnWeight;
        }

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights($targetWeights);

        $advisoryWeight  = $activeWeights['restructuring_advisory'];
        $recoveryWeight  = $activeWeights['turnaround_recovery'];
        $loanToOwnWeight = $activeWeights['loan_to_own'] ?? 0.0;

        // Independent stream Z-scores
        $advisoryZ = $streams->generateZ('restructuring_advisory', 0.50);
        $recoveryZ = $streams->generateZ('turnaround_recovery', 0.15);

        if ($recoveryZ > self::LORE_RESTRUCTURING_Z_SCORE && $distressMultiplier > 0.0) {
            $eventType = ShockEvent::DISTRESSED_DEBT_RESTRUCTURING;
        }

        $advisoryRevenue = max(0.0, $expectedRevenue * $advisoryWeight * (1.0 + ($advisoryZ * ($baselineVol * self::ADVISORY_VARIANCE_SCALAR))));
        $recoveryRevenue = max(0.0, $expectedRevenue * $recoveryWeight * (1.0 + ($recoveryZ * ($baselineVol * self::RECOVERY_VARIANCE_SCALAR)) + $distressMultiplier));

        $streamRevenues = [
            'restructuring_advisory' => $advisoryRevenue,
            'turnaround_recovery'    => $recoveryRevenue,
        ];

        $loanToOwnRevenue = 0.0;
        $loanToOwnZ = 0.0;
        if ($loanToOwnWeight > 0.0) {
            $loanToOwnZ = $streams->generateZ('loan_to_own', 0.15);
            $loanToOwnRevenue = max(0.0, $expectedRevenue * $loanToOwnWeight * (1.0 + ($loanToOwnZ * $baselineVol * self::RECOVERY_VARIANCE_SCALAR * 1.5) + ($distressMultiplier * 1.5)));
            $streamRevenues['loan_to_own'] = $loanToOwnRevenue;
        }

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        $clampedMargin = $this->clampMargin($realizedVariableMargin);

        $primaryShockZ = $streams->resolveDominantShockZ([$recoveryZ, $advisoryZ, $loanToOwnWeight > 0.0 ? $loanToOwnZ : 0.0]);

        $observableShockZ = ($advisoryZ * $advisoryWeight * self::ADVISORY_VARIANCE_SCALAR * $baselineVol) +
            ($recoveryZ * $recoveryWeight * self::RECOVERY_VARIANCE_SCALAR * $baselineVol) +
            ($distressMultiplier * $recoveryWeight);

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

    public function evaluateHoardingStatus(float $treasury, float $targetCashReserves, float $operatingBase, float $totalDebt): array
    {
        $excessCash = max(0.0, $treasury - $targetCashReserves);
        return [
            'excess_cash'     => $excessCash,
            'is_hoarder'      => $excessCash > ($operatingBase * self::DRY_POWDER_HOARDER_THRESHOLD),
            'is_mega_hoarder' => $excessCash > ($operatingBase * self::DRY_POWDER_MEGA_THRESHOLD),
        ];
    }
}
