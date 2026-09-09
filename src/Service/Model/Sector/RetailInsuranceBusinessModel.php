<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Retail Insurance (Life, Property & Casualty).
 * 
 * Financial Physics:
 * - Insulates tail-risk by passing it up to Reinsurance.
 * - Revenue is split between short-tail Property & Casualty premiums and highly sticky, long-duration Life Insurance premiums.
 * - P&C is sensitive to catastrophe claims, replacement inflation, and competitive pricing cycles.
 * - Life & Annuities is long-duration, immune to weather catastrophes, and sensitive to 10Y interest rate term spreads.
 * - Inherits all standard insurance physics (float, Kenney Rule) from InsuranceBusinessModel.
 */
class RetailInsuranceBusinessModel extends InsuranceBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Personal lines are renewed regardless of the cycle. */
    public const OPERATING_CYCLICALITY = 0.70;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.70;
    public const BASE_COVERAGE_ERROR = 0.10;

    // --- Stream Weights ---
    /** Baseline fraction of revenue from short-tail Property & Casualty premiums. */
    public const PROPERTY_CASUALTY_WEIGHT = 0.55;
    /** Baseline fraction of revenue from long-duration Life & Annuity premiums. */
    public const LIFE_INSURANCE_WEIGHT = 0.45;

    // --- Stream Variance Scalars ---
    /** Volatility multiplier for short-tail P&C premium volume shocks. */
    public const PC_VARIANCE_SCALAR = 0.18;
    /** Volatility multiplier for sticky, long-duration Life insurance premium cash flows. */
    public const LIFE_VARIANCE_SCALAR = 0.05;

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::PropertyCasualtyWeight->value => self::PROPERTY_CASUALTY_WEIGHT,
            ModelParam::LifeAndAnnuityWeight->value   => self::LIFE_INSURANCE_WEIGHT,
            ModelParam::CatastropheZThreshold->value  => self::CATASTROPHE_Z_THRESHOLD,
            ModelParam::CatastropheLossScalar->value  => self::CATASTROPHE_LOSS_SCALAR,
        ]);

        $pcWeight     = $params[ModelParam::PropertyCasualtyWeight];
        $lifeWeight   = $params[ModelParam::LifeAndAnnuityWeight];
        $catThreshold = $params[ModelParam::CatastropheZThreshold];
        $catScalar    = $params[ModelParam::CatastropheLossScalar];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights([
            'property_casualty_premiums' => $params[ModelParam::PropertyCasualtyWeight],
            'life_insurance_premiums'    => $params[ModelParam::LifeAndAnnuityWeight],
        ]);

        $pcWeight     = $activeWeights['property_casualty_premiums'];
        $lifeWeight   = $activeWeights['life_insurance_premiums'];

        // Independent stream Z-scores
        $pcZ    = $streams->generateZ('property_casualty_premiums', 0.25);
        $lifeZ  = $streams->generateZ('life_insurance_premiums', 0.50);
        $claimZ = $streams->generateExogenousZ('claim', 0.05);

        // Life & Annuities spreads benefit from a steep yield curve (spread over guaranteed crediting rates)
        $yield10y = $macroState->yield10yEma;
        $policyRate = $macroState->policyRateEma;
        $termSpread = max(-0.02, min(0.04, $yield10y - $policyRate));
        $lifeSpreadBonus = $termSpread * 1.5;

        $pcRevenue   = max(0.0, $expectedRevenue * $pcWeight * (1.0 + ($pcZ * ($baselineVol * self::PC_VARIANCE_SCALAR))));
        $lifeRevenue = max(0.0, $expectedRevenue * $lifeWeight * (1.0 + ($lifeZ * ($baselineVol * self::LIFE_VARIANCE_SCALAR)) + $lifeSpreadBonus));
        
        $streamRevenues = [
            'property_casualty_premiums' => $pcRevenue,
            'life_insurance_premiums'    => $lifeRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // The Combined Ratio Shock applies primarily to Property & Casualty operations
        $underwritingShock = $claimZ < $catThreshold
            ? abs($claimZ) * $catScalar * $pcWeight
            : ($claimZ > self::BENIGN_CLAIM_Z_FLOOR ? self::BENIGN_CLAIM_BONUS * $pcWeight : 0.0);

        // Kenney Rule Hard-Market Capital Recovery
        $equity = (float) $stock->getTotalEquity();
        $targetSurplus = $expectedRevenue / self::KENNEY_CAPACITY_RATIO;
        $surplusDeficitRatio = $targetSurplus > 0.0 ? max(0.0, ($targetSurplus - $equity) / $targetSurplus) : 0.0;
        $hardMarketRecoveryDiscount = min(0.25, $surplusDeficitRatio * 0.30 * $pcWeight);

        $reinsuranceSurcharge = 0.0;
        if ($claimZ < self::REINSURANCE_ATTACHMENT_Z) {
            $underwritingShock = min($underwritingShock, self::MAX_REINSURED_LOSS_SHOCK * $pcWeight);
            $reinsuranceSurcharge = self::REINSURANCE_HARD_MARKET_RATE * $pcWeight;
        }

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $underwritingShock + $reinsuranceSurcharge - $hardMarketRecoveryDiscount);

        $eventType = null;
        if ($claimZ < self::REINSURANCE_ATTACHMENT_Z) {
            $eventType = ShockEvent::REINSURANCE_ATTACHMENT_BREACH;
        } elseif ($claimZ < self::LORE_SYSTEMIC_DISASTER_Z) {
            $eventType = ShockEvent::CATASTROPHIC_CLAIM_LOSSES;
        } elseif ($claimZ < self::LORE_ELEVATED_CLAIMS_Z) {
            $eventType = ShockEvent::ELEVATED_CLAIM_PAYOUTS;
        }

        $structuralClaimShock = abs($claimZ) > abs($catThreshold) ? $claimZ : 0.0;
        $primaryShockZ = $streams->resolveDominantShockZ([$structuralClaimShock, $pcZ, $lifeZ]);

        $pcBase = max(1.0, $expectedRevenue * $pcWeight);
        $pcShock = ($pcRevenue - $pcBase) / $pcBase;

        $lifeBase = max(1.0, $expectedRevenue * $lifeWeight);
        $lifeShock = ($lifeRevenue - $lifeBase) / $lifeBase;

        $observableShockZ = ($pcShock * $pcWeight) + ($lifeShock * $lifeWeight);

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
