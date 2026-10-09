<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\BusinessModelInterface;

use App\Service\Model\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Retail Insurance (Life, Property & Casualty).
 *
 * Financial Physics:
 * - Insulates tail-risk by passing it up to Reinsurance.
 * - Revenue is split between short-tail Property & Casualty premiums and highly sticky, long-duration Life Insurance premiums.
 * - P&C carries the catastrophe claims and the competitive pricing cycle.
 * - Life & Annuities is long-duration and immune to weather catastrophes.
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

    // --- Loss Reserves ---
    /** Policy reserves per unit of annual life premium: ACLI Fact Book 2025, individual life reserves $1.72T on direct premium $175B (2024). */
    public const LIFE_RESERVE_TO_PREMIUM_RATIO = 9.9;

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

        // Independent stream Z-scores; claims fall on the property and casualty book alone.
        $pcZ    = $streams->generateZ('property_casualty_premiums', 0.25);
        $lifeZ  = $streams->generateZ('life_insurance_premiums', 0.50);
        $claims = $this->resolveExcessClaims($streams, $macroState, $pcWeight, $catThreshold, $catScalar);

        $pcRevenue   = max(0.0, $expectedRevenue * $pcWeight * (1.0 + ($pcZ * ($baselineVol * self::PC_VARIANCE_SCALAR))));
        $lifeRevenue = max(0.0, $expectedRevenue * $lifeWeight * (1.0 + ($lifeZ * ($baselineVol * self::LIFE_VARIANCE_SCALAR))));

        $streamRevenues = [
            'property_casualty_premiums' => $pcRevenue,
            'life_insurance_premiums'    => $lifeRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // The tail is ceded upward: the cover takes everything above the retention on the P&C book and
        // charges a reinstatement premium for the limit it used.
        $recovery = $this->resolveCatastropheRecovery($claims['gross'], $pcWeight);
        $reinstatementPremium = $recovery > 0.0 ? self::REINSTATEMENT_PREMIUM_RATE * $pcWeight : 0.0;

        // A primary carrier renews its book annually and holds the harder rate well past the loss; the rate
        // reaches the P&L through the pricing-power multiplier, so a capital shortfall is not discounted here.
        $surplusDeficitRatio = $this->resolveSurplusDeficitRatio($expectedRevenue, (float) $stock->getTotalEquity());
        $this->advanceUnderwritingCycle($streams, $stock, $macroState, $surplusDeficitRatio, $recovery > 0.0);

        $clampedMargin = $this->clampMargin($realizedVariableMargin + $claims['excess'] - $recovery + $reinstatementPremium);
        // The baseline cost ratio splits as the parent's does; the claims above it are losses.
        // Life policies carry a policy reserve, not unearned premium, and the catastrophe draw falls on the P&C book alone.
        $lifeBenefits = $lifeRevenue * $this->resolveBaselineLossRatio($realizedVariableMargin, $fixedCosts, $actualRevenue);
        $kpis = $this->bookUnderwritingResult($streams, $actualRevenue, $clampedMargin, $realizedVariableMargin * self::BASE_EXPENSE_RATIO_SHARE, $reinstatementPremium, $fixedCosts, $pcRevenue, $lifeBenefits);

        $eventType = $this->resolveClaimEvent($claims['gross'], $pcWeight, $recovery > 0.0);

        $structuralClaimShock = $claims['claimZ'] < $catThreshold ? $claims['claimZ'] : 0.0;
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
            kpis: $kpis,
        );
    }

    /**
     * The P&C book's unearned premium and loss reserves and the life book's policy reserve, each weighted by the
     * premium its book writes.
     *
     * @return array{unearned: float, loss: float, life: float}
     */
    public function resolveReserveRatios(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::LifeReserveToPremiumRatio->value => self::LIFE_RESERVE_TO_PREMIUM_RATIO,
        ]);
        $lifeShare = $this->resolveLifePremiumShare($stock);

        return [
            'unearned' => (1.0 - $lifeShare) * self::POLICY_TERM_YEARS / 2.0,
            'loss' => (1.0 - $lifeShare) * self::LOSS_RESERVE_TO_PREMIUM_RATIO,
            'life' => $lifeShare * $params[ModelParam::LifeReserveToPremiumRatio],
        ];
    }

    public function resolveLifePremiumShare(Stock $stock): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::PropertyCasualtyWeight->value => self::PROPERTY_CASUALTY_WEIGHT,
            ModelParam::LifeAndAnnuityWeight->value   => self::LIFE_INSURANCE_WEIGHT,
        ]);
        $lifeWeight = $params[ModelParam::LifeAndAnnuityWeight];

        return $lifeWeight / max(0.01, $params[ModelParam::PropertyCasualtyWeight] + $lifeWeight);
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
