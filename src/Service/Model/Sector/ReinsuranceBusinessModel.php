<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

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
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Treaty volume follows primary premiums with a lag. */
    public const OPERATING_CYCLICALITY = 0.80;

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
    /** Fraction of tail severity above attachment absorbed by Cat Bond ILS alternative capital collateral. */
    public const CAT_BOND_ATTACHMENT_SHIELD_SHARE = 0.40;

    // --- Margin Clamps ---
    /** Maximum variable margin clamp for reinsurers to allow extreme tail-risk claim payouts within 1-in-250 year PML limits. */
    public const MAX_REINSURANCE_MARGIN_CLAMP = 1.65;

    public function clampMargin(float $rawMargin, float $minMargin = 0.01, float $maxMargin = self::MAX_REINSURANCE_MARGIN_CLAMP): float
    {
        return min($maxMargin, max($minMargin, $rawMargin));
    }

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
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'treaty_reinsurance' => $params[ModelParam::TreatyReinsuranceWeight],
            'catastrophe_bonds'  => $params[ModelParam::CatBondSpreadWeight],
        ]);

        $treatyWeight  = $activeWeights['treaty_reinsurance'];
        $catBondWeight = $activeWeights['catastrophe_bonds'];

        // Independent stream Z-scores
        $treatyZ  = $streams->generateZ('treaty_reinsurance', 0.30);
        $catBondZ = $streams->generateZ('catastrophe_bonds', 0.15);
        $claimZ   = $streams->generateExogenousZ('claim', 0.05);

        // Catastrophe Risk Beta & Combined Ratio Shock
        $frequencyBeta = self::CATASTROPHE_Z_THRESHOLD / min(-0.1, $catThreshold);
        $severityBeta  = $catScalar / self::CATASTROPHE_LOSS_SCALAR;
        $catRiskBeta   = $frequencyBeta * $severityBeta;
        $benignBonus   = self::BENIGN_CLAIM_BONUS * $catRiskBeta;

        // Underwriting claim shock applies to the Treaty Reinsurance book
        $underwritingShock = $claimZ < $catThreshold
            ? abs($claimZ) * $catScalar * $treatyWeight
            : ($claimZ > self::BENIGN_CLAIM_Z_FLOOR ? $benignBonus * $treatyWeight : 0.0);

        // Kenney Rule Hard-Market Capital Recovery:
        // Post-catastrophe capital depletion triggers massive rate increases on treaty reinsurance renewals.
        $equity = (float) $stock->getTotalEquity();
        $targetSurplus = $expectedRevenue / self::KENNEY_CAPACITY_RATIO;
        $surplusDeficitRatio = $targetSurplus > 0.0 ? max(0.0, ($targetSurplus - $equity) / $targetSurplus) : 0.0;
        $hardMarketPricingBonus = min(0.35, $surplusDeficitRatio * 0.40 * $catRiskBeta);

        // Cat Bond Principal / Yield Haircut during extreme catastrophe attachment:
        // When attachment points breach, Cat Bond collateral shields the ILS tranche by absorbing tail severity,
        // while ILS management and coupon fee revenue suffers the default haircut.
        $catBondMultiplier = 1.0;
        if ($claimZ < self::REINSURANCE_ATTACHMENT_Z) {
            $catBondMultiplier = (1.0 - self::CAT_BOND_DEFAULT_HAIRCUT);
            $excessTailShock = (abs($claimZ) - abs(self::REINSURANCE_ATTACHMENT_Z)) * $catScalar * $treatyWeight;
            $underwritingShock -= ($excessTailShock * self::CAT_BOND_ATTACHMENT_SHIELD_SHARE);
        }

        $treatyRevenue  = max(0.0, $expectedRevenue * $treatyWeight * (1.0 + ($treatyZ * ($baselineVol * self::TREATY_VARIANCE_SCALAR)) + $hardMarketPricingBonus));
        $catBondRevenue = max(0.0, $expectedRevenue * $catBondWeight * (1.0 + ($catBondZ * ($baselineVol * self::CAT_BOND_VARIANCE_SCALAR))) * $catBondMultiplier);
        
        $streamRevenues = [
            'treaty_reinsurance' => $treatyRevenue,
            'catastrophe_bonds'  => $catBondRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $underwritingShock);

        $eventType = null;
        if ($claimZ < self::REINSURANCE_ATTACHMENT_Z) {
            $eventType = ShockEvent::REINSURANCE_ATTACHMENT_BREACH;
        } elseif ($claimZ < self::LORE_SYSTEMIC_DISASTER_Z) {
            $eventType = ShockEvent::CATASTROPHIC_CLAIM_LOSSES;
        } elseif ($claimZ < self::LORE_ELEVATED_CLAIMS_Z) {
            $eventType = ShockEvent::ELEVATED_CLAIM_PAYOUTS;
        }

        $structuralClaimShock = abs($claimZ) > abs($catThreshold) ? $claimZ : 0.0;
        $primaryShockZ = $streams->resolveDominantShockZ([$structuralClaimShock, $treatyZ, $catBondZ]);

        $treatyBase = max(1.0, $expectedRevenue * $treatyWeight);
        $treatyShock = ($treatyRevenue - $treatyBase) / $treatyBase;
        $catBondBase = max(1.0, $expectedRevenue * $catBondWeight);
        $catBondShock = ($catBondRevenue - $catBondBase) / $catBondBase;
        $observableShockZ = ($treatyShock * $treatyWeight) + ($catBondShock * $catBondWeight);

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

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     * Inherits InsuranceBusinessModel::getMacroPhysics()/getTargetMetrics() in full — own
     * calculateSectorPhysics() is purely Z-driven and reads no macro field directly.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [
            'inflation_ema',
            'market_volatility_ema',
            'output_gap_ema',
            'policy_rate_ema',
            'yield_10y_ema',
        ];
    }
}
