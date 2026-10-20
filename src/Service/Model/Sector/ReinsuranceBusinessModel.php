<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for Global Reinsurance & Alternative Risk Transfer.
 *
 * Financial Physics:
 * - Absorbs extreme, low-frequency high-severity tail risks (hurricanes, earthquakes, industrial disasters).
 * - Revenue is split between Treaty Reinsurance Premiums (proportional quota-share / excess-of-loss)
 *   and Insurance-Linked Securities (ILS) fees on the catastrophe-bond principal it arranges for investors.
 * - Treaty premiums benefit from global hard-market pricing power following severe industry catastrophe losses.
 * - As sponsor of its own bond, it pays the coupon for the share of its tail the bond's investors take; when a
 *   storm reaches the bond, the investors' principal is written down and the fee base with it, rebuilt as new bonds
 *   are issued.
 * - Inherits all standard insurance physics (float, Kenney Rule) from InsuranceBusinessModel.
 */
class ReinsuranceBusinessModel extends InsuranceBusinessModel
{
    // --- District Catastrophe Exposure ---
    /**
     * Expected catastrophe claims as a share of treaty premium in an average district year: Swiss Re's 2024 large nat
     * cat budget of $1.8B against ~$22.9B of P&C Re premiums earned (~8%).
     */
    public const DISTRICT_CATASTROPHE_LOAD = 0.08;

    // --- Loss Reserves ---
    /**
     * Loss and LAE reserves per unit of treaty premium earned: Swiss Re P&C Re 2023 unpaid claims $58.6B on premiums
     * earned $22.9B.
     */
    public const TREATY_LOSS_RESERVE_TO_PREMIUM_RATIO = 2.56;

    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Treaty volume follows
     * primary premiums with a lag.
     */
    public const OPERATING_CYCLICALITY = 0.80;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.80;
    public const BASE_COVERAGE_ERROR = 0.10;

    // --- Stream Weights ---
    /** Baseline fraction of revenue from core proportional and excess-of-loss treaty reinsurance: nearly all of it. */
    public const TREATY_REINSURANCE_WEIGHT = 0.97;
    /**
     * Baseline fraction of revenue from ILS structuring and management fees on the catastrophe-bond principal it
     * arranges: about 3%, what the reinsurers with the largest third-party capital vehicles earn in fees against their
     * premiums (RenaissanceRe's capital-partner fees). At 35-40% a reinsurer would need many times its own premium in
     * outstanding bonds.
     */
    public const CAT_BOND_WEIGHT = 0.03;

    // --- Physics & Variances ---
    /** Volatility multiplier for treaty reinsurance volume. */
    public const TREATY_VARIANCE_SCALAR = 0.20;
    /** Volatility multiplier for catastrophe bond and ILS alternative capital spreads. */
    public const CAT_BOND_VARIANCE_SCALAR = 0.10;
    /** Fraction of tail severity above attachment absorbed by Cat Bond ILS alternative capital collateral. */
    public const CAT_BOND_ATTACHMENT_SHIELD_SHARE = 0.40;

    // --- ILS Principal ---
    /**
     * Years over which written-down catastrophe-bond principal is replaced by new issuance: about one, as the market
     * recapitalised within a year of 2022's losses (record issuance in 2023).
     */
    public const ILS_REISSUANCE_YEARS = 1.0;
    /** Persisted catastrophe-bond principal outstanding, as a share of the full programme: the ILS fee base. */
    public const STATE_ILS_PRINCIPAL_INDEX = 'state:ils:principal_index';

    // --- Margin Clamps ---
    /**
     * Maximum variable margin clamp for reinsurers to allow extreme tail-risk claim payouts within 1-in-250 year PML
     * limits.
     */
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
        $claims   = $this->resolveExcessClaims($streams, $macroState, 1.0, $catThreshold, $catScalar);

        // Above the retention, the bond's collateral takes its share of the tail, and the investors' principal is
        // written down by the share of the layer the storm used.
        $layer = $this->resolveCatastropheLayer($catThreshold, $catScalar);
        $tailAboveRetention = $this->resolveCatastropheRecovery($claims['gross'], $layer);
        $coverAttached = $tailAboveRetention > 0.0;
        $catBondShield = $tailAboveRetention * self::CAT_BOND_ATTACHMENT_SHIELD_SHARE;
        // As sponsor it pays the bond's investors the coupon for that share, at the cover's price on its own layer.
        $shieldPremium = self::CAT_BOND_ATTACHMENT_SHIELD_SHARE * $this->resolveCededPremium($layer);
        $principalIndex = $this->advanceIlsPrincipal($streams, $tailAboveRetention, $layer);

        // Rates on a renewed treaty stay hard for years after the capital that withdrew has come back. The
        // harder rate reaches treaty revenue through the pricing-power multiplier, which the cost base does
        // not follow; a capital shortfall is not added to revenue again here.
        $surplusDeficitRatio = $this->resolveSurplusDeficitRatio($stock, $macroState, $mathUtility);
        $this->advanceUnderwritingCycle($streams, $stock, $macroState, $mathUtility, $surplusDeficitRatio, $coverAttached);

        $treatyRevenue  = max(0.0, $expectedRevenue * $treatyWeight * (1.0 + ($treatyZ * ($baselineVol * self::TREATY_VARIANCE_SCALAR))));
        $catBondRevenue = max(0.0, $expectedRevenue * $catBondWeight * (1.0 + ($catBondZ * ($baselineVol * self::CAT_BOND_VARIANCE_SCALAR))) * $principalIndex);

        $streamRevenues = [
            'treaty_reinsurance' => $treatyRevenue,
            'catastrophe_bonds'  => $catBondRevenue,
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // Treaty losses are the cedants' claims, inflating with theirs while the treaty rate was set at the last
        // renewal.
        $unrecoveredClaims = $realizedVariableMargin * (1.0 - self::BASE_EXPENSE_RATIO_SHARE) * $treatyWeight * $this->resolveUnrecoveredClaimsCost($streams, $macroState);
        // The treaty book carries the whole cost line; the ILS fees carry only its expense share, as a fee earns no
        // claims.
        $baseCostRatio = $actualRevenue > 0.0
            ? $realizedVariableMargin * ($treatyRevenue + ($catBondRevenue * self::BASE_EXPENSE_RATIO_SHARE)) / $actualRevenue
            : $realizedVariableMargin;
        // The catastrophe terms are struck per unit of treaty premium at the base rate and land on the treaty book's
        // volume: a hard market's rate is not more storms.
        $volume = $this->resolveBookVolumeShare($stock, $macroState, $treatyRevenue, $actualRevenue);
        $clampedMargin = $this->clampMargin($baseCostRatio + $unrecoveredClaims + (($claims['excess'] - $catBondShield + $shieldPremium) * $volume));
        // The baseline cost ratio splits as the parent's does; the claims above it are losses.
        $kpis = $this->bookUnderwritingResult($streams, $actualRevenue, $clampedMargin, $realizedVariableMargin * self::BASE_EXPENSE_RATIO_SHARE, $shieldPremium * $volume, $fixedCosts, $treatyRevenue);

        $eventType = $this->resolveClaimEvent($claims['gross'], 1.0, $coverAttached);

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
     * The catastrophe-bond principal outstanding, as a share of the full programme: written down by the share of the
     * layer this quarter's storm used, and rebuilt toward the programme as new bonds are issued. The fees are charged
     * on it, so a storm that takes a tenth of the layer takes a tenth of the fee base, not the flat half it took.
     *
     * @param array{retention: float, limit: float, expected_recovery: float} $layer
     */
    private function advanceIlsPrincipal(\App\Service\Model\StreamContext $streams, float $tailAboveRetention, array $layer): float
    {
        $writtenDown = $streams->getPersistedState(self::STATE_ILS_PRINCIPAL_INDEX, 1.0)
            * (1.0 - ($layer['limit'] > 0.0 ? min(1.0, $tailAboveRetention / $layer['limit']) : 0.0));
        $principal = TimeSeries::calculateDistributedLag($writtenDown, 1.0, FinancialConstants::QUARTER_YEARS, self::ILS_REISSUANCE_YEARS);
        $streams->registerState(self::STATE_ILS_PRINCIPAL_INDEX, $principal);

        return $writtenDown;
    }

    /** Losses incurred per unit of revenue: the treaty book's, as the ILS fees incur none. */
    public function resolveStructuralLossRatio(Stock $stock): float
    {
        return parent::resolveStructuralLossRatio($stock) * $this->resolveTreatyShare($stock);
    }

    /** The treaty book's share of revenue at the stream weights. */
    private function resolveTreatyShare(Stock $stock): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::TreatyReinsuranceWeight->value => self::TREATY_REINSURANCE_WEIGHT,
            ModelParam::CatBondSpreadWeight->value     => self::CAT_BOND_WEIGHT,
        ]);

        return $params[ModelParam::TreatyReinsuranceWeight] / max(0.01, $params[ModelParam::TreatyReinsuranceWeight] + $params[ModelParam::CatBondSpreadWeight]);
    }

    /**
     * Only the treaty book carries reserves, unearned premium on annual treaties and the claims it has incurred: ILS
     * structuring and management fees are earned as they are billed.
     *
     * @return array{unearned: float, loss: float, life: float}
     */
    public function resolveReserveRatios(Stock $stock): array
    {
        $treatyShare = $this->resolveTreatyShare($stock);

        return [
            'unearned' => $treatyShare * self::POLICY_TERM_YEARS / 2.0,
            'loss' => $treatyShare * self::TREATY_LOSS_RESERVE_TO_PREMIUM_RATIO,
            'life' => 0.0,
        ];
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
