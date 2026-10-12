<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;

/**
 * Earnings strategy for managed-care health plans: insurers that collect premiums from employers and pay their members'
 * medical bills.
 *
 * Financial Physics:
 * - Membership is employer coverage, so it falls with unemployment above its natural rate.
 * - Premiums are filed a year at a time on claims experience about two years old, projected forward at the cost trend
 *   seen then. Claims are paid at today's reimbursement rates, so the medical cost ratio rises when the cost trend
 *   runs ahead of the trend priced in, and returns to target as the book renews (the health underwriting cycle).
 * - The medical loss ratio floor (45 CFR 158) rebates premium whenever the three-year loss ratio falls below it, so a
 *   cost slowdown is handed back to members while a cost surge is the plan's to absorb.
 * - Claims are paid about seven weeks after care, and the plan invests that float until it does.
 */
class HealthInsurerBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /** Elasticity of volumes and costs to the macro cycle. The cycle reaches a health plan through enrollment and the medical cost trend this model reads, so the engine's output-gap terms would count it twice (band floor). */
    public const OPERATING_CYCLICALITY = 0.20;

    // --- Pricing ---
    /** Premiums are priced off the medical cost trend and claims are paid at the administered reimbursement rate, so both inflate with it. */
    public const PRICING_INFLATION_BASIS = 'reimbursement_rate_growth';
    /** Premium filings cover projected claims at the target loss ratio, so medical inflation passes into premium one for one; the lag is the underwriting cycle below. */
    public const PRICING_ELASTICITY = 1.00;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base: claims, underwriting and distribution payroll, as for any insurer. */
    public const FIXED_COST_LABOR_SHARE = InsuranceBusinessModel::FIXED_COST_LABOR_SHARE;

    // --- Underwriting Cycle ---
    /** Years from the midpoint of the claims experience a premium is priced on to the midpoint of the period it is charged for: 24 months (Medica 2022 individual filing; UnitedHealthcare 2018 small-group filing). */
    public const RATING_LAG_YEARS = 2.0;
    /** Years over which the book is repriced to its target loss ratio: group policies renew annually. */
    public const POLICY_TERM_YEARS = 1.0;
    /** Stream-state key: the log gap between the medical cost ratio and the ratio the book was priced at. */
    public const STATE_LOSS_RATIO_GAP = 'state:loss_ratio_gap';
    /** Stream-state key: the annual medical cost trend premiums in force were priced on. */
    public const STATE_PRICED_TREND = 'state:priced_trend';

    // --- Medical Loss Ratio Floor (45 CFR 158) ---
    /** Minimum medical loss ratio in the large group market (45 CFR 158.210); small group and individual plans are held to 80%. */
    public const MLR_FLOOR = 0.85;
    /** Points the floor's own definition adds to the reported medical cost ratio in the large group market: taxes and fees taken out of premium 2.0, quality spending added to claims 0.7 (Herbold, Milliman 2012, on 2010 statements). */
    public const MLR_ADJUSTMENT = 0.027;
    /** Mean age in years of the ratio the floor is tested on: the reporting year and the two before it, weighed alike (45 CFR 158.220(b)), so an average carrying the same mean age. */
    public const MLR_AVERAGING_YEARS = 1.5;
    /** Stream-state key: the averaged medical cost ratio the floor is tested on. */
    public const STATE_AVERAGE_LOSS_RATIO = 'state:average_loss_ratio';

    // --- Enrollment ---
    /** Employer-sponsored enrollment lost per unit of unemployment above its natural rate: own-employer coverage falls 1.97% per point (Cawley, Moriya & Simon 2015, men; women and children showed no significant change). */
    public const ENROLLMENT_UNEMPLOYMENT_ELASTICITY = 1.97;
    /** Quarterly persistence of the plan's own membership shock (the standard corporate default). */
    public const MEMBERSHIP_SHOCK_PERSISTENCE = 0.25;

    // --- Claims Float ---
    /** Days of medical costs owed as claims payable: UnitedHealth 50.6 in 2019 (medical costs payable over annual medical costs, 10-K). */
    public const DAYS_CLAIMS_PAYABLE = 50.6;

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        return [
            'macro_demand_shift' => $this->resolveEnrollmentShift($macroState),
            ...$this->resolvePricingMultipliers($stock, $macroState),
        ];
    }

    /** Members lost to unemployment above its natural rate, as a share of the book. */
    public function resolveEnrollmentShift(MacroStateDTO $macroState): float
    {
        return -self::ENROLLMENT_UNEMPLOYMENT_ELASTICITY * $macroState->unemploymentGap();
    }

    /** The reported medical cost ratio below which the floor rebates premium. */
    public static function reportedLossRatioFloor(): float
    {
        return self::MLR_FLOOR - self::MLR_ADJUSTMENT;
    }

    /**
     * The claims float is the whole trade cycle: premiums arrive before the care they pay for, and claims are paid
     * DAYS_CLAIMS_PAYABLE after it. As a share of revenue it is negative.
     */
    public function getWorkingCapitalIntensity(Stock $stock): float
    {
        return -self::DAYS_CLAIMS_PAYABLE / FinancialConstants::DAYS_PER_YEAR * $this->resolveSeededLossRatio($stock);
    }

    /** Payables days are struck on total cost, so the claims days are scaled by the claims' share of it. */
    public function getWorkingCapitalDays(Stock $stock): array
    {
        $costRatio = max(0.01, 1.0 - (float) $stock->getOperatingMargin());

        return [
            'dso' => 0.0,
            'dio' => 0.0,
            'dpo' => self::DAYS_CLAIMS_PAYABLE * $this->resolveSeededLossRatio($stock) / $costRatio,
        ];
    }

    /**
     * A health plan invests the claims it owes until it pays them, as well as cash above its operating needs: the float
     * earns the cash yield whatever the operating buffer.
     */
    public function calculateInterestIncome(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility, ?float $realizedWholesaleRate = null): float
    {
        $cash = (float) $stock->getCorporateTreasury();
        $float = min($cash, max(0.0, (float) ($stock->getPayables() ?? 0.0)));
        $operatingBase = max((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity(), FinancialConstants::MIN_OPERATING_BASE_CASH);
        $excessCash = max(0.0, $cash - $float - $this->calculateTargetOperatingCash($operatingBase, 0.0, (float) $stock->getWholesaleDebt()));

        return ($float + $excessCash) * $this->calculateCashYield($macroState);
    }

    /** The medical cost ratio the plan was seeded at: its variable cost ratio, claims being its only variable cost. */
    private function resolveSeededLossRatio(Stock $stock): float
    {
        return $stock->getStructuralVariableMargin() !== null
            ? (float) $stock->getStructuralVariableMargin()
            : (1.0 - (float) $stock->getOperatingMargin()) * (1.0 - $stock->getFixedCostRatio());
    }

    protected function calculateSectorPhysics(
        Stock $stock,
        float $expectedRevenue,
        float $realizedVariableMargin,
        float $fixedCosts,
        float $baselineVol,
        MacroStateDTO $macroState,
        MathUtility $mathUtility
    ): SectorPhysicsResult {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $membershipZ = $streams->generateZ('premiums', self::MEMBERSHIP_SHOCK_PERSISTENCE);
        $premium = max(0.0, $expectedRevenue * (1.0 + ($membershipZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR)));

        // --- Underwriting Cycle ---
        // The cost ratio drifts at the gap between the trend claims run at and the trend premiums were priced on, and
        // returns to target as the book renews: dg/dt = (claims trend - priced trend) - g / policy term.
        $dt = EarningsEngine::QUARTERLY_TIME_STEP;
        $costTrend = $macroState->reimbursementRateGrowth;
        // A book with no history was priced at the steady-state update, so a trend already off it reaches the plan.
        $pricedTrend = $streams->getPersistedState(self::STATE_PRICED_TREND, MacroEngine::TARGET_INFLATION - MacroEngine::REIMBURSEMENT_PRODUCTIVITY_OFFSET);
        $gap = TimeSeries::calculateDistributedLag(
            currentLaggedValue: $streams->getPersistedState(self::STATE_LOSS_RATIO_GAP, 0.0),
            targetValue: ($costTrend - $pricedTrend) * self::POLICY_TERM_YEARS,
            dt: $dt,
            lagTimeConstant: self::POLICY_TERM_YEARS
        );
        $streams->registerState(self::STATE_LOSS_RATIO_GAP, $gap);
        $streams->registerState(self::STATE_PRICED_TREND, TimeSeries::calculateDistributedLag($pricedTrend, $costTrend, $dt, self::RATING_LAG_YEARS));
        $lossRatio = $realizedVariableMargin * exp($gap);

        // --- Medical Loss Ratio Floor ---
        // The rebate is the shortfall of the averaged ratio under the floor, paid on the year's premium (45 CFR 158.240).
        $averageLossRatio = TimeSeries::calculateDistributedLag(
            currentLaggedValue: $streams->getPersistedState(self::STATE_AVERAGE_LOSS_RATIO, $lossRatio),
            targetValue: $lossRatio,
            dt: $dt,
            lagTimeConstant: self::MLR_AVERAGING_YEARS
        );
        $streams->registerState(self::STATE_AVERAGE_LOSS_RATIO, $averageLossRatio);
        $rebateRate = max(0.0, self::reportedLossRatioFloor() - $averageLossRatio);
        $actualRevenue = $premium * (1.0 - $rebateRate);
        $streams->recordStreamShares(['premiums' => $actualRevenue]);

        // Claims are paid on the premium the members were charged; the rebate comes off revenue alone.
        $costRatio = $this->clampMargin($actualRevenue > 0.0 ? $lossRatio * $premium / $actualRevenue : $lossRatio);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $costRatio,
            primaryShockZ: $membershipZ,
            observableShockZ: $expectedRevenue > 0.0 ? ($premium / $expectedRevenue) - 1.0 : 0.0,
            streamZ: $streams->getStreamZ(),
            streamRevenue: ['premiums' => $actualRevenue],
            kpis: [
                'medical_cost_ratio' => $lossRatio,
                'premium_rebate' => $rebateRate,
            ],
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
            'nairu_ema',
            'reimbursement_rate_growth',
            'tips_breakeven_ema',
            'unemployment_rate_ema',
        ];
    }
}
