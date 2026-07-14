<?php

declare(strict_types=1);

namespace App\Service\Model;

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
 * - When corporate credit spreads blow out, the fund deploys capital to buy defaulted corporate bonds and distressed assets for pennies on the dollar.
 * - As markets recover or restructure, these assets yield extraordinary turnaround ROE (30% to 50%+).
 * - During prolonged bull markets with tight credit spreads, earnings remain modest and defensive as the fund hoards dry powder.
 */
class DistressedDebtBusinessModel extends AssetManagementBusinessModel
{
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

    // --- Revenue & Shock Physics ---
    /** Volatility multiplier for top-line revenue shocks in special situation portfolios. */
    public const REVENUE_VARIANCE_SCALAR        = 0.20;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP      = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP      = 0.01;

    // --- Event Lore Thresholds ---
    /** Positive z-score threshold required during credit blowout to trigger turnaround restructuring lore. */
    public const LORE_RESTRUCTURING_Z_SCORE     = 1.00;

    // --- Dry Powder Liquidity Rules ---
    /** Threshold ratio of excess cash over operating base triggering hoarder status for dry powder funds. */
    public const DRY_POWDER_HOARDER_THRESHOLD   = 0.40;
    /** Threshold ratio of excess cash over operating base triggering mega-hoarder status for dry powder funds. */
    public const DRY_POWDER_MEGA_THRESHOLD      = 0.70;

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $revenueZ = $mathUtility->generateStandardNormal();

        // Counter-Cyclical Credit Spread Trigger
        $creditSpread = $macroState->macroCreditSpread;
        $outputGap = $macroState->outputGapEma;

        $distressMultiplier = 0.0;
        $eventType = null;

        // When credit spreads exceed 2.5% or output gap is negative, distressed debt opportunities explode
        if ($creditSpread > self::SPREAD_BLOWOUT_THRESHOLD || $outputGap < self::RECESSION_GAP_THRESHOLD) {
            $distressMultiplier = ($creditSpread - self::DEFAULT_CREDIT_SPREAD_FALLBACK) * self::SPREAD_SURGE_SCALAR + abs(min(0.0, $outputGap)) * self::RECESSION_SURGE_SCALAR;
            if ($revenueZ > self::LORE_RESTRUCTURING_Z_SCORE) {
                $eventType = ShockEvent::DISTRESSED_DEBT_RESTRUCTURING;
            }
        } elseif ($outputGap > self::BULL_MARKET_GAP_THRESHOLD && $creditSpread < self::DEFAULT_CREDIT_SPREAD_FALLBACK) {
            // Tight credit spreads in roaring bull markets reduce distressed supply
            $distressMultiplier = self::BULL_MARKET_REVENUE_DRAG;
        }

        $params = $this->resolveModelParameters($stock, [
            'advisory_fee_weight'   => 0.40,
            'asset_recovery_weight' => 0.60,
        ]);
        $advisoryWeight = $params['advisory_fee_weight'];
        $recoveryWeight = $params['asset_recovery_weight'];

        $advisoryRevenue = $expectedRevenue * $advisoryWeight * (1.0 + ($revenueZ * ($baselineVol * 0.5)));
        $recoveryRevenue = $expectedRevenue * $recoveryWeight * (1.0 + ($revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $distressMultiplier);
        $actualRevenue   = max(0.0, $advisoryRevenue + $recoveryRevenue);

        $clampedMargin = min(self::MAX_VARIABLE_MARGIN_CLAMP, max(self::MIN_VARIABLE_MARGIN_CLAMP, $realizedVariableMargin));

        // observableShockZ: macro credit spreads and corporate default rates are public data (~90% visibility).
        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $revenueZ,
            observableShockZ: $distressMultiplier,
            eventType: $eventType,
        );
    }

    public function getCoverageProfile(): \App\DTO\SectorCoverageProfile
    {
        // Macro credit spreads and default rates are fully public (~90% visible, 80% floor).
        return new \App\DTO\SectorCoverageProfile(baseVisibility: 0.90, errorStdDev: 0.05, minVisibility: 0.80);
    }

    public function evaluateHoardingStatus(float $treasury, float $targetCashReserves, float $operatingBase, float $totalDebt): array
    {
        $excessCash = max(0.0, $treasury - $targetCashReserves);
        return [
            'excess_cash'     => $excessCash,
            // Distressed debt funds intentionally hold massive cash reserves ("dry powder") to deploy during market crashes.
            // We allow them a much higher cash buffer before triggering hoarding penalties.
            'is_hoarder'      => $excessCash > ($operatingBase * self::DRY_POWDER_HOARDER_THRESHOLD),
            'is_mega_hoarder' => $excessCash > ($operatingBase * self::DRY_POWDER_MEGA_THRESHOLD),
        ];
    }
}

