<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for Retail Insurance (Life, Property & Casualty).
 *
 * Financial Physics:
 * - Insulates tail-risk by passing it up to Reinsurance.
 * - Revenue is split between short-tail Property & Casualty premiums and highly sticky, long-duration Life Insurance
 *   premiums.
 * - P&C carries the catastrophe claims and the competitive pricing cycle.
 * - Life & Annuities is long-duration and immune to weather catastrophes.
 * - Inherits all standard insurance physics (float, Kenney Rule) from InsuranceBusinessModel.
 */
class RetailInsuranceBusinessModel extends InsuranceBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Personal lines are
     * renewed regardless of the cycle.
     */
    public const OPERATING_CYCLICALITY = 0.70;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.70;
    public const BASE_COVERAGE_ERROR = 0.10;

    /**
     * Stock-based compensation (ASC 718) as a share of revenue, between Insurance (Prop/Cas.) at 0.41% and Insurance
     * (Life) at 0.48% (Damodaran, Employee data by industry, US, January 2026).
     */
    public const STOCK_COMPENSATION_INTENSITY = 0.0044;

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
    /**
     * Policy reserves per unit of annual life premium: ACLI Fact Book 2025, individual life reserves $1.72T on direct
     * premium $175B (2024).
     */
    public const LIFE_RESERVE_TO_PREMIUM_RATIO = 9.9;

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::PropertyCasualtyWeight->value => self::PROPERTY_CASUALTY_WEIGHT,
            ModelParam::LifeAndAnnuityWeight->value   => self::LIFE_INSURANCE_WEIGHT,
            ModelParam::CatastropheZThreshold->value  => self::CATASTROPHE_Z_THRESHOLD,
            ModelParam::CatastropheLossScalar->value  => self::CATASTROPHE_LOSS_SCALAR,
        ]);

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
        $claims = $this->resolveExcessClaims($streams, $macroState, 1.0, $catThreshold, $catScalar);

        $pcRevenue   = max(0.0, $expectedRevenue * $pcWeight * (1.0 + ($pcZ * ($baselineVol * self::PC_VARIANCE_SCALAR))));
        $lifeRevenue = max(0.0, $expectedRevenue * $lifeWeight * (1.0 + ($lifeZ * ($baselineVol * self::LIFE_VARIANCE_SCALAR))));

        $streamRevenues = [
            'property_casualty_premiums' => $pcRevenue,
            'life_insurance_premiums'    => $lifeRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // The tail is ceded upward: the cover takes the P&C claims above the retention up to its limit, for a premium
        // every quarter and a reinstatement premium for the limit it used. The catastrophe terms are struck per unit
        // of the P&C book's premium at the base rate and land on its volume.
        $volume = $this->resolveBookVolumeShare($stock, $macroState, $pcRevenue, $actualRevenue);
        $layer = $this->resolveCatastropheLayer($catThreshold, $catScalar);
        $recovery = $this->resolveCatastropheRecovery($claims['gross'], $layer);
        $reinstatementPremium = ($this->resolveCededPremium($layer) + $this->resolveReinstatementPremium($layer, $recovery)) * $volume;

        // A primary carrier renews its book annually and holds the harder rate well past the loss; the rate
        // reaches the P&L through the pricing-power multiplier, so a capital shortfall is not discounted here.
        $surplusDeficitRatio = $this->resolveSurplusDeficitRatio($stock, $macroState, $mathUtility);
        $this->advanceUnderwritingCycle($streams, $stock, $macroState, $mathUtility, $surplusDeficitRatio, $recovery > 0.0);

        // Claims inflation the filed rates have not caught up with lands on the P&C losses; life benefits are fixed in
        // the policy.
        $unrecoveredClaims = $realizedVariableMargin * (1.0 - self::BASE_EXPENSE_RATIO_SHARE) * $pcWeight * $this->resolveUnrecoveredClaimsCost($streams, $macroState);
        $clampedMargin = $this->clampMargin($realizedVariableMargin + $unrecoveredClaims + (($claims['excess'] - $recovery) * $volume) + $reinstatementPremium);
        // The baseline cost ratio splits as the parent's does; the claims above it are losses. Life policies carry a
        // policy reserve, not unearned premium, and the catastrophe draw falls on the P&C book alone.
        $lifeBenefits = $lifeRevenue * $this->resolveBaselineLossRatio($realizedVariableMargin, $fixedCosts, $actualRevenue);
        $kpis = $this->bookUnderwritingResult($streams, $actualRevenue, $clampedMargin, $realizedVariableMargin * self::BASE_EXPENSE_RATIO_SHARE, $reinstatementPremium, $fixedCosts, $pcRevenue, $lifeBenefits);

        $eventType = $this->resolveClaimEvent($claims['gross'], 1.0, $recovery > 0.0);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
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
            'catastrophe_loss_index',
            'catastrophe_loss_index_ema',
            'core_goods_inflation_ema',
            'gdp_deflator',
            'output_gap_ema',
            'output_gap_lag_6m',
            'perceived_neutral_rate',
            'policy_rate_ema',
            'potential_gdp_index',
            'supercore_inflation_ema',
            'yield_10y_ema',
        ];
    }
}
