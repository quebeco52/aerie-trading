<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Global Reinsurance & Alternative Risk Transfer.
 * 
 * Financial Physics:
 * - Absorbs extreme, low-frequency high-severity tail risks (hurricanes, earthquakes, industrial disasters).
 * - Revenue is split between Treaty Reinsurance Premiums (proportional quota-share / excess-of-loss)
 *   and Catastrophe Bonds / Insurance-Linked Securities (ILS) management fees and coupon spreads.
 * - Treaty premiums benefit from global hard-market pricing power following severe industry catastrophe losses.
 * - Catastrophe Bonds earn high risk coupon spreads during benign periods, but suffer principal write-downs
 *   when catastrophic claim attachment points are breached.
 * - Inherits all standard insurance physics (float, Kenney Rule) from InsuranceBusinessModel.
 */
class ReinsuranceBusinessModel extends InsuranceBusinessModel
{
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.80;
    public const BASE_COVERAGE_ERROR = 0.10;

    // --- Stream Weights ---
    /** Baseline fraction of revenue from core proportional and excess-of-loss treaty reinsurance. */
    public const TREATY_REINSURANCE_WEIGHT = 0.65;
    /** Baseline fraction of revenue from catastrophe bond structuring fees and ILS risk spreads. */
    public const CAT_BOND_WEIGHT = 0.35;

    // --- Physics & Variances ---
    /** Volatility multiplier for treaty reinsurance volume. */
    public const TREATY_VARIANCE_SCALAR = 0.20;
    /** Volatility multiplier for catastrophe bond and ILS alternative capital spreads. */
    public const CAT_BOND_VARIANCE_SCALAR = 0.10;
    /** Haircut applied to cat bond collateral spread revenues when major attachment points breach. */
    public const CAT_BOND_DEFAULT_HAIRCUT = 0.50;

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::TreatyReinsuranceWeight->value => self::TREATY_REINSURANCE_WEIGHT,
            ModelParam::CatBondSpreadWeight->value     => self::CAT_BOND_WEIGHT,
            ModelParam::CatastropheZThreshold->value   => self::CATASTROPHE_Z_THRESHOLD,
            ModelParam::CatastropheLossScalar->value   => self::CATASTROPHE_LOSS_SCALAR,
        ]);

        $treatyWeight  = $params[ModelParam::TreatyReinsuranceWeight];
        $catBondWeight = $params[ModelParam::CatBondSpreadWeight];
        $catThreshold  = $params[ModelParam::CatastropheZThreshold];
        $catScalar     = $params[ModelParam::CatastropheLossScalar];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);

        // Independent stream Z-scores
        $treatyZ  = $streams->generateZ('treaty_reinsurance', 0.30);
        $catBondZ = $streams->generateZ('catastrophe_bonds', 0.15);
        $claimZ   = $streams->generateZ('claim', 0.05);

        // Catastrophe Risk Beta & Combined Ratio Shock
        $frequencyBeta = self::CATASTROPHE_Z_THRESHOLD / min(-0.1, $catThreshold);
        $severityBeta  = $catScalar / self::CATASTROPHE_LOSS_SCALAR;
        $catRiskBeta   = $frequencyBeta * $severityBeta;
        $benignBonus   = self::BENIGN_CLAIM_BONUS * $catRiskBeta;

        $underwritingShock = $claimZ < $catThreshold
            ? abs($claimZ) * $catScalar
            : ($claimZ > self::BENIGN_CLAIM_Z_FLOOR ? $benignBonus : 0.0);

        // Kenney Rule Hard-Market Capital Recovery:
        // Post-catastrophe capital depletion triggers massive rate increases on treaty reinsurance renewals.
        $equity = (float) $stock->getTotalEquity();
        $targetSurplus = $expectedRevenue / self::KENNEY_CAPACITY_RATIO;
        $surplusDeficitRatio = $targetSurplus > 0.0 ? max(0.0, ($targetSurplus - $equity) / $targetSurplus) : 0.0;
        $hardMarketPricingBonus = min(0.35, $surplusDeficitRatio * 0.40 * $catRiskBeta);

        // Cat Bond Principal / Yield Haircut during extreme catastrophe attachment
        $catBondMultiplier = 1.0;
        if ($claimZ < self::REINSURANCE_ATTACHMENT_Z) {
            $catBondMultiplier = (1.0 - self::CAT_BOND_DEFAULT_HAIRCUT);
        }

        $treatyRevenue  = max(0.0, $expectedRevenue * $treatyWeight * (1.0 + ($treatyZ * ($baselineVol * self::TREATY_VARIANCE_SCALAR)) + $hardMarketPricingBonus));
        $catBondRevenue = max(0.0, $expectedRevenue * $catBondWeight * (1.0 + ($catBondZ * ($baselineVol * self::CAT_BOND_VARIANCE_SCALAR))) * $catBondMultiplier);
        $actualRevenue  = max(0.0, $treatyRevenue + $catBondRevenue);

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $underwritingShock - $hardMarketPricingBonus);

        $eventType = null;
        if ($claimZ < self::REINSURANCE_ATTACHMENT_Z) {
            $eventType = ShockEvent::REINSURANCE_ATTACHMENT_BREACH;
        } elseif ($claimZ < self::LORE_SYSTEMIC_DISASTER_Z) {
            $eventType = ShockEvent::CATASTROPHIC_CLAIM_LOSSES;
        } elseif ($claimZ < self::LORE_ELEVATED_CLAIMS_Z) {
            $eventType = ShockEvent::ELEVATED_CLAIM_PAYOUTS;
        }

        $structuralClaimShock = abs($claimZ) > abs($catThreshold) ? $claimZ : 0.0;
        $primaryShockZ = abs($structuralClaimShock) > abs($treatyZ) ? $structuralClaimShock : $treatyZ;
        if (abs($catBondZ) > abs($primaryShockZ)) {
            $primaryShockZ = $catBondZ;
        }

        $observableShockZ = ($treatyZ * $treatyWeight * self::TREATY_VARIANCE_SCALAR) + ($hardMarketPricingBonus * $treatyWeight);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: [
                'treaty_reinsurance' => $treatyRevenue,
                'catastrophe_bonds'  => $catBondRevenue,
            ],
        );
    }
}
