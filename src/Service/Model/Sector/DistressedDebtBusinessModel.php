<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\SectorPhysicsResult;

use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\CreditRisk;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for Distressed Debt, Special Situations, and Turnaround Funds.
 *
 * Financial Physics:
 * - Counter-Cyclical Special Situations: Thrives during systemic recessions and credit crises.
 * - Tri-Stream Architecture:
 *      1. Restructuring Advisory Fees: Steady retainer-based fee income earned during bankruptcy workouts.
 *      2. Distressed Asset Recovery: High-yield gains from buying defaulted senior secured debt at discounts.
 *      3. Loan-to-Own Equity Gains: Asymmetric capital gains upon post-reorganization equity emergence.
 * - Distress against recent conditions: when spreads and defaults run above their trends or output falls short,
 *   recovery and loan-to-own gains rise toward the fund's capacity; when credit is easier than usual, the dry powder
 *   sits idle and they fall short of plan. A usual quarter is plan.
 */
class DistressedDebtBusinessModel extends AssetManagementBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Counter-cyclical
     * revenue, but capital raising follows the cycle.
     */
    public const OPERATING_CYCLICALITY = 0.90;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.90;
    public const BASE_COVERAGE_ERROR = 0.05;
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.80;

    public function getMoatSpread(): float
    {
        return 0.005;
    }

    // --- Special Situations & Macro Triggers ---
    /** Distress signal per unit of the IG spread over its decade trend: 100 bps over reads 0.20. */
    public const SPREAD_SURGE_SCALAR            = 20.00;
    /** Distress signal per unit of output gap, against the cycle: a -3% gap reads 0.15, a +3% boom -0.15. */
    public const RECESSION_SURGE_SCALAR         = 5.00;
    /**
     * Distress signal per unit of the HY spread over its trend (the IG trend at the HY multiple): 400 bps reads 0.40.
     */
    public const HY_SPREAD_SURGE_SCALAR         = 10.00;
    /**
     * Distress signal per unit of the corporate default rate over its measured trend, relative: defaults half again
     * the usual read 0.75, a third below it -0.50.
     */
    public const DEFAULT_RATE_SURGE_SCALAR      = 1.50;
    /** Revenue expansion the opportunity set approaches at the fund's capacity in severe distress (+125%). */
    public const MAX_DISTRESS_REVENUE_EXPANSION = 1.25;
    /** Distress signal at which half of the capacity expansion is realised. */
    public const DISTRESS_HALF_SATURATION_POINT = 1.00;
    /**
     * Revenue the idle dry powder forgoes as credit gets ever easier than usual (-50%): few new situations, but the
     * book still works out the ones it holds. Sized so the multiplier averages about zero over the cycle.
     */
    public const MAX_IDLE_DRY_POWDER_DRAG       = 0.50;

    // --- Stream Weights & Variances ---
    public const RESTRUCTURING_ADVISORY_WEIGHT = 0.40;
    public const ASSET_RECOVERY_WEIGHT         = 0.60;

    /**
     * Loan-to-own equity gains move 1.5x the recovery book's distress surge: the fund owns the reorganised equity, not
     * the discounted claim.
     */
    public const LOAN_TO_OWN_DISTRESS_LEVERAGE = 1.50;

    public const ADVISORY_VARIANCE_SCALAR = 0.15;
    public const RECOVERY_VARIANCE_SCALAR = 0.45;

    // --- Tail Risk Events ---
    public const LORE_RESTRUCTURING_Z_SCORE     = 1.00;

    // --- Dry Powder Liquidity Rules ---
    public const DRY_POWDER_HOARDER_THRESHOLD   = 0.40;
    public const DRY_POWDER_MEGA_THRESHOLD      = 0.70;

    /**
     * The situations the workout desks are staffed to: the distress opportunity set (spreads and defaults against
     * their decade trends, the output gap) on the recovery and loan-to-own books at their target weights. It reads
     * levels against slow trends, not quarter-on-quarter spread changes, so it counts situations rather than marks.
     * Restructuring advisory carries no macro term here, so it counts in the mix with no activity. Positive in a
     * slump, negative when credit is easier than usual: countercyclical.
     */
    public function resolveSectorActivityShift(Stock $stock, \App\DTO\MacroStateDTO $macroState): float
    {
        $weights = $this->resolveTargetStreamWeights($stock);
        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        $distressMultiplier = $this->resolveDistressMultiplier($macroState);

        return ((($weights['turnaround_recovery'] ?? 0.0) * $distressMultiplier)
            + (($weights['loan_to_own'] ?? 0.0) * $distressMultiplier * self::LOAN_TO_OWN_DISTRESS_LEVERAGE)) / $totalWeight;
    }

    /** @return array<string, float> */
    private function resolveTargetStreamWeights(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::RestructuringAdvisoryWeight->value => self::RESTRUCTURING_ADVISORY_WEIGHT,
            ModelParam::TurnaroundGainsWeight->value       => self::ASSET_RECOVERY_WEIGHT,
            ModelParam::LoanToOwnGainsWeight->value        => 0.00,
        ]);

        $targetWeights = [
            'restructuring_advisory' => $params[ModelParam::RestructuringAdvisoryWeight],
            'turnaround_recovery'    => $params[ModelParam::TurnaroundGainsWeight],
        ];
        if ($params[ModelParam::LoanToOwnGainsWeight] > 0.0) {
            $targetWeights['loan_to_own'] = $params[ModelParam::LoanToOwnGainsWeight];
        }

        return $targetWeights;
    }

    /**
     * Counter-cyclical opportunity set, measured against recent conditions: the IG spread over its decade trend, the
     * HY spread over that trend at the multiple HY is built at, defaults over their trend and the output gap, all
     * signed. A two-sided Michaelis-Menten curve maps the signal: distress fills the book toward the fund's capacity,
     * credit easier than usual leaves the dry powder idle toward the drag, and a usual quarter is plan.
     */
    private function resolveDistressMultiplier(\App\DTO\MacroStateDTO $macroState): float
    {
        $spreadTrend = $macroState->macroCreditSpreadTrend;
        $highYieldTrend = $spreadTrend * MacroConstants::HY_BASE_SPREAD_MULTIPLIER;

        $distressSignal = (($macroState->macroCreditSpread - $spreadTrend) * self::SPREAD_SURGE_SCALAR)
            + (($macroState->highYieldCreditSpreadEma - $highYieldTrend) * self::HY_SPREAD_SURGE_SCALAR)
            + ($macroState->corporateDefaultDeviation() * self::DEFAULT_RATE_SURGE_SCALAR)
            - ($macroState->outputGapEma * self::RECESSION_SURGE_SCALAR);

        return CreditRisk::calculateSignedDistressMultiplier(
            $distressSignal,
            self::MAX_DISTRESS_REVENUE_EXPANSION,
            self::DISTRESS_HALF_SATURATION_POINT,
            self::MAX_IDLE_DRY_POWDER_DRAG,
        );
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        $distressMultiplier = $this->resolveDistressMultiplier($macroState);
        $eventType = null;

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights($this->resolveTargetStreamWeights($stock));

        $advisoryWeight  = $activeWeights['restructuring_advisory'];
        $recoveryWeight  = $activeWeights['turnaround_recovery'];
        $loanToOwnWeight = $activeWeights['loan_to_own'] ?? 0.0;

        // Independent stream Z-scores
        $advisoryZ = $streams->generateZ('restructuring_advisory', 0.50);
        $recoveryZ = $streams->generateZ('turnaround_recovery', 0.15);

        if ($recoveryZ > self::LORE_RESTRUCTURING_Z_SCORE && $distressMultiplier > 0.0) {
            $eventType = ShockEvent::DISTRESSED_DEBT_RESTRUCTURING;
        }

        $advisoryRevenue = max(0.0, $expectedRevenue * $advisoryWeight * (1.0 + ($advisoryZ * ($baselineVol * self::ADVISORY_VARIANCE_SCALAR))));
        $recoveryRevenue = max(0.0, $expectedRevenue * $recoveryWeight * (1.0 + ($recoveryZ * ($baselineVol * self::RECOVERY_VARIANCE_SCALAR)) + $distressMultiplier));

        $streamRevenues = [
            'restructuring_advisory' => $advisoryRevenue,
            'turnaround_recovery'    => $recoveryRevenue,
        ];

        $loanToOwnRevenue = 0.0;
        $loanToOwnZ = 0.0;
        if ($loanToOwnWeight > 0.0) {
            $loanToOwnZ = $streams->generateZ('loan_to_own', 0.15);
            $loanToOwnRevenue = max(0.0, $expectedRevenue * $loanToOwnWeight * (1.0 + ($loanToOwnZ * $baselineVol * self::RECOVERY_VARIANCE_SCALAR * 1.5) + ($distressMultiplier * self::LOAN_TO_OWN_DISTRESS_LEVERAGE)));
            $streamRevenues['loan_to_own'] = $loanToOwnRevenue;
        }

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        $clampedMargin = $this->clampMargin($realizedVariableMargin);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    public function evaluateHoardingStatus(float $treasury, float $targetCashReserves, float $operatingBase, float $totalDebt): array
    {
        $excessCash = max(0.0, $treasury - $targetCashReserves);
        return [
            'excess_cash'     => $excessCash,
            'is_hoarder'      => $excessCash > ($operatingBase * self::DRY_POWDER_HOARDER_THRESHOLD),
            'is_mega_hoarder' => $excessCash > ($operatingBase * self::DRY_POWDER_MEGA_THRESHOLD),
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
            'corporate_default_rate_ema',
            'high_yield_credit_spread_ema',
            'macro_credit_spread',
            'macro_credit_spread_ema',
            'output_gap_ema',
            'yield_10y_ema',
        ];
    }
}
