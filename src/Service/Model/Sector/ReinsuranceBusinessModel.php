<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;

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
    // --- District Catastrophe Exposure ---
    /** Expected catastrophe claims as a share of treaty premium in an average district year: Swiss Re's 2024 large nat cat budget of $1.8B against ~$22.9B of P&C Re premiums earned (~8%). */
    public const DISTRICT_CATASTROPHE_LOAD = 0.08;

    // --- Loss Reserves ---
    /** Float per unit of treaty premium earned: Swiss Re P&C Re 2023 unpaid claims $58.6B on premiums earned $22.9B (2.56), plus an unearned share of ~0.5 (group UPR split by premiums written). */
    public const TREATY_RESERVE_TO_PREMIUM_RATIO = 3.0;

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
        // Claims fall on the treaty book: the reinsurer's share of the district season plus its own large losses.
        $claims   = $this->resolveExcessClaims($streams, $macroState, $treatyWeight, $catThreshold, $catScalar);

        // Above the retention, cat bond collateral absorbs part of the tail and the ILS fee and coupon revenue
        // takes the default haircut.
        $tailAboveRetention = $this->resolveCatastropheRecovery($claims['gross'], $treatyWeight);
        $coverAttached = $tailAboveRetention > 0.0;
        $catBondShield = $tailAboveRetention * self::CAT_BOND_ATTACHMENT_SHIELD_SHARE;
        $catBondMultiplier = $coverAttached ? (1.0 - self::CAT_BOND_DEFAULT_HAIRCUT) : 1.0;

        // Rates on a renewed treaty stay hard for years after the capital that withdrew has come back. The
        // harder rate reaches treaty revenue through the pricing-power multiplier, which the cost base does
        // not follow; a capital shortfall is not added to revenue again here.
        $surplusDeficitRatio = $this->resolveSurplusDeficitRatio($expectedRevenue, (float) $stock->getTotalEquity());
        $this->advanceUnderwritingCycle($streams, $stock, $macroState, $surplusDeficitRatio, $coverAttached);

        $treatyRevenue  = max(0.0, $expectedRevenue * $treatyWeight * (1.0 + ($treatyZ * ($baselineVol * self::TREATY_VARIANCE_SCALAR))));
        $catBondRevenue = max(0.0, $expectedRevenue * $catBondWeight * (1.0 + ($catBondZ * ($baselineVol * self::CAT_BOND_VARIANCE_SCALAR))) * $catBondMultiplier);
        
        $streamRevenues = [
            'treaty_reinsurance' => $treatyRevenue,
            'catastrophe_bonds'  => $catBondRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $claims['excess'] - $catBondShield);
        $this->registerIncurredClaims($streams, $actualRevenue, $clampedMargin);

        $eventType = $this->resolveClaimEvent($claims['gross'], $treatyWeight, $coverAttached);

        $structuralClaimShock = $claims['claimZ'] < $catThreshold ? $claims['claimZ'] : 0.0;
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

    /** Only the treaty book carries reserves: ILS structuring and management fees are earned as they are billed. */
    public function resolveReserveToPremiumRatio(Stock $stock): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::TreatyReinsuranceWeight->value => self::TREATY_REINSURANCE_WEIGHT,
            ModelParam::CatBondSpreadWeight->value     => self::CAT_BOND_WEIGHT,
        ]);
        $treatyWeight = $params[ModelParam::TreatyReinsuranceWeight];

        return self::TREATY_RESERVE_TO_PREMIUM_RATIO * $treatyWeight / max(0.01, $treatyWeight + $params[ModelParam::CatBondSpreadWeight]);
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
            'catastrophe_loss_index_ema',
            'market_volatility_ema',
            'nominal_gdp_index',
            'output_gap_ema',
            'output_gap_lag_6m',
            'perceived_neutral_rate',
            'policy_rate_ema',
            'yield_10y_ema',
        ];
    }
}
