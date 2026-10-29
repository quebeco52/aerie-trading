<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Model\ModelParam;
use App\Service\Model\StreamContext;

/**
 * Earnings strategy for a life and pensions insurer: protection policies, annuities and pension buyouts, and savings
 * funds held for policyholders.
 *
 * Financial Physics:
 * - A spread business: the premiums build a policy reserve many times their size, invested mostly in long bonds, and
 *   the firm keeps what the bonds earn over what the policies credit.
 * - Guarantees: older policies promise a minimum crediting rate, so when the book's yield falls toward it the spread
 *   on those reserves closes (the Japanese life insurers of the 1990s, the German ones of the 2010s).
 * - Annuities and buyouts: a high long rate makes an income for life cheaper to buy and leaves company pension schemes
 *   funded well enough to hand to an insurer, so single premiums follow the long rate over its trend.
 * - Savings funds: unit-linked and pension savings are invested for the policyholder, outside the reserve, and earn
 *   an annual fee on their value, which follows the markets.
 * - No property or casualty book, so no catastrophe claims and no underwriting cycle on its rates.
 */
class LifeInsurerBusinessModel extends InsuranceBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Policies in force
     * keep paying premium through a recession; only new protection sales slow.
     */
    public const OPERATING_CYCLICALITY = 0.30;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.75;
    public const BASE_COVERAGE_ERROR = 0.06;

    /** Stock-based compensation as a share of revenue: Insurance (Life) at 0.48% (Damodaran, January 2026). */
    public const STOCK_COMPENSATION_INTENSITY = 0.0048;

    // --- Revenue Streams ---
    /** Protection: regular premiums on term, whole-life and group life cover (35%). */
    public const PROTECTION_WEIGHT = 0.35;
    /** Annuities and pension buyouts: single premiums for an income for life (45%). */
    public const ANNUITY_WEIGHT = 0.45;
    /** Savings funds: annual management fees on unit-linked and pension savings held for policyholders (20%). */
    public const SAVINGS_FEE_WEIGHT = 0.20;

    // --- Revenue Noise ---
    /** Volatility multiplier for protection premiums, renewed on policies in force. */
    public const PROTECTION_VARIANCE_SCALAR = 0.05;
    /** Volatility multiplier for annuity and buyout premiums: a few large schemes can make a quarter. */
    public const ANNUITY_VARIANCE_SCALAR = 0.25;
    /** Volatility multiplier for savings fees, charged on balances that move only with the markets and flows. */
    public const SAVINGS_VARIANCE_SCALAR = 0.05;

    // --- Annuity & Buyout Demand ---
    /**
     * Single premiums per unit of the thirty-year yield over its trend, in log: +16% per point. UK bulk buyouts went
     * from about £28B a year in 2019-21 to £49B in 2023 and individual annuity sales rose by about as much as long gilt
     * yields rose 3.5 points (LCP; ABI).
     */
    public const ANNUITY_YIELD_SEMI_ELASTICITY = 16.0;
    /** Years over which the long yield becomes the normal buyout pricing is read against: past the rate cycle. */
    public const ANNUITY_YIELD_TREND_YEARS = 5.0;
    /** Persisted multi-year trend of the thirty-year yield. */
    public const STATE_ANNUITY_YIELD_TREND = 'state:life:annuity_yield_trend';

    // --- Savings Funds ---
    /**
     * Equity share of the policyholders' savings funds, the rest bonds and cash: the fee follows the market at this
     * share of its move over trend.
     */
    public const SAVINGS_EQUITY_SHARE = 0.60;

    // --- Crediting Guarantees ---
    /** Minimum crediting rate the older policies promise (2.5%), between the 2.25% and 2.75% German maximum rates. */
    public const GUARANTEED_CREDITING_RATE = 0.025;
    /** Share of the policy reserves written with that guarantee: none unless the firm carries an older book. */
    public const GUARANTEED_RESERVE_SHARE = 0.0;

    // --- Loss Reserves ---
    /**
     * Policy reserves per unit of annual premium across a whole life and annuity book: about six, as US life insurers'
     * reserves stand to their premiums and annuity considerations together (ACLI Fact Book). Individual life alone
     * runs 9.9 (RetailInsuranceBusinessModel), but single annuity premiums carry less reserve per unit.
     */
    public const LIFE_RESERVE_TO_PREMIUM_RATIO = 6.0;

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        $activeWeights = $streams->resolveActiveStreamWeights(self::targetStreamWeights());

        $protectionZ = $streams->generateZ('protection', 0.50);
        $annuityZ    = $streams->generateZ('annuities', 0.25);
        $savingsZ    = $streams->generateZ('savings_fees', 0.50);

        $streamRevenues = [
            'protection'   => max(0.0, $expectedRevenue * $activeWeights['protection'] * (1.0 + ($protectionZ * $baselineVol * self::PROTECTION_VARIANCE_SCALAR))),
            'annuities'    => max(0.0, $expectedRevenue * $activeWeights['annuities'] * (1.0 + ($annuityZ * $baselineVol * self::ANNUITY_VARIANCE_SCALAR) + $this->resolveAnnuityDemandShift($macroState, $streams))),
            'savings_fees' => max(0.0, $expectedRevenue * $activeWeights['savings_fees'] * (1.0 + ($savingsZ * $baselineVol * self::SAVINGS_VARIANCE_SCALAR) + (self::SAVINGS_EQUITY_SHARE * $macroState->equityWealthDeviation()))),
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // The capacity book (franchise, written share) is carried as every underwriter carries it; a life book's
        // policies are contractual, so the share it writes never falls.
        $surplusDeficitRatio = $this->resolveSurplusDeficitRatio($stock, $macroState, $mathUtility);
        $this->advanceUnderwritingCycle($streams, $stock, $macroState, $mathUtility, $surplusDeficitRatio, false);

        // Premiums buy benefits, which build the policy reserve; savings fees buy administration and build none.
        $clampedMargin = $this->clampMargin($realizedVariableMargin);
        $premiums = $streamRevenues['protection'] + $streamRevenues['annuities'];
        $lifeBenefits = $premiums * $this->resolveBaselineLossRatio($realizedVariableMargin, $fixedCosts, $actualRevenue);
        $kpis = $this->bookUnderwritingResult($streams, $actualRevenue, $clampedMargin, $realizedVariableMargin * self::BASE_EXPENSE_RATIO_SHARE, 0.0, $fixedCosts, 0.0, $lifeBenefits);
        $streams->registerState(self::STATE_INCURRED_CLAIMS, max(0.0, $lifeBenefits));

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            kpis: $kpis,
        );
    }

    /**
     * Single premiums against normal: the thirty-year yield over its own multi-year trend, which opens at the first
     * reading and is carried.
     */
    private function resolveAnnuityDemandShift(MacroStateDTO $macroState, StreamContext $streams): float
    {
        $longYield = $macroState->yield30yEma;
        $trend = $streams->getPersistedState(self::STATE_ANNUITY_YIELD_TREND, $longYield);
        $streams->registerState(self::STATE_ANNUITY_YIELD_TREND, TimeSeries::calculateDistributedLag($trend, $longYield, FinancialConstants::QUARTER_YEARS, self::ANNUITY_YIELD_TREND_YEARS));

        return exp(self::ANNUITY_YIELD_SEMI_ELASTICITY * ($longYield - $trend)) - 1.0;
    }

    /**
     * The rate credited on the policy reserves: the guaranteed share at no less than its guarantee, the rest at the
     * book yield less the spread, never below the statutory floor.
     */
    protected function resolveLifeCreditingRate(Stock $stock, MacroStateDTO $macroState): float
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::GuaranteedCreditingRate->value => self::GUARANTEED_CREDITING_RATE,
            ModelParam::GuaranteedReserveShare->value  => self::GUARANTEED_RESERVE_SHARE,
        ]);
        $current = parent::resolveLifeCreditingRate($stock, $macroState);
        $guaranteedShare = max(0.0, min(1.0, $params[ModelParam::GuaranteedReserveShare]));

        return ($guaranteedShare * max($params[ModelParam::GuaranteedCreditingRate], $current)) + ((1.0 - $guaranteedShare) * $current);
    }

    /** Every policy is contractual and none is property or casualty: the whole book is the life book. */
    public function resolveLifePremiumShare(Stock $stock): float
    {
        return 1.0;
    }

    /** Only the premiums build a policy reserve; the savings fees carry none. */
    public function resolveReserveRatios(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::LifeReserveToPremiumRatio->value => self::LIFE_RESERVE_TO_PREMIUM_RATIO,
        ]);

        return ['unearned' => 0.0, 'loss' => 0.0, 'life' => self::premiumShare() * $params[ModelParam::LifeReserveToPremiumRatio]];
    }

    /** The reserve is held at its ratio to revenue on the benefits the premiums incur, a share of revenue. */
    public function resolveLifeReserveRunoffYears(Stock $stock): float
    {
        return self::resolveRunoffYearsAtTrend(
            $this->resolveReserveRatios($stock)['life'],
            $this->resolveStructuralLossRatio($stock) * self::premiumShare(),
        );
    }

    /** No property or casualty premium for the market's rate reading. */
    public function propertyCasualtyPremiumReading(Stock $stock): array
    {
        return ['premium' => 0.0, 'rate_level' => 1.0];
    }

    /** Share of revenue that is premium rather than fees. */
    private static function premiumShare(): float
    {
        return self::PROTECTION_WEIGHT + self::ANNUITY_WEIGHT;
    }

    /** @return array{protection: float, annuities: float, savings_fees: float} */
    private static function targetStreamWeights(): array
    {
        return [
            'protection'   => self::PROTECTION_WEIGHT,
            'annuities'    => self::ANNUITY_WEIGHT,
            'savings_fees' => self::SAVINGS_FEE_WEIGHT,
        ];
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
            'equity_wealth_ratio',
            'equity_wealth_trend',
            'output_gap_ema',
            'output_gap_lag_6m',
            'perceived_neutral_rate',
            'policy_rate_ema',
            'potential_gdp_index',
            'gdp_deflator',
            'yield_10y_ema',
            'yield_30y_ema',
        ];
    }
}
