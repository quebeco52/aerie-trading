<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\DTO\DebtExpansionAppetiteDTO;
use App\DTO\SectorPhysicsResult;

use App\Entity\Stock;

use App\Service\Event\ShockEvent;
use App\Service\Market\Bond\RatingMethodology;
use App\Service\Math\Distributions;
use App\Service\Math\FixedIncome;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;

/**
 * Earnings strategy for Private Equity & Alternative Asset Managers.
 *
 * Financial Physics:
 * - Base revenue comes from sticky AUM management fees.
 * - Massive volatility comes from "Carried Interest" (performance fees) and deal exits.
 * - LBO Cost of Debt Elasticity: Deal multiples compress as buyout debt costs more than sponsors underwrite it at
 *   through the cycle, and expand as it costs less.
 * - Mark-to-Market (MTM): Principal balance sheet investments suffer unrealized markdown penalties during credit
 *   freezes.
 * - Rescue Capital Drag: A floating rate well above its through-the-cycle level forces the GP to inject capital into
 *   distressed portfolio companies, inflating variable costs.
 */
class PrivateEquityBusinessModel extends AssetManagementBusinessModel
{
    // --- Credit Rating ---
    /** Invests its own balance sheet: rated on debt to adjusted total equity. */
    public const RATING_METHODOLOGY = RatingMethodology::BalanceSheetInvestor;

    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Exit windows and LBO
     * financing open and shut with the cycle.
     */
    public const OPERATING_CYCLICALITY = 1.40;

    // --- Balance Sheet Realism ---
    /**
     * Stock-based compensation (ASC 718) as a share of revenue: Investments & Asset Management, 5.08% of revenue, 283
     * firms (Damodaran, Employee data by industry, US, January 2026).
     */
    public const STOCK_COMPENSATION_INTENSITY = 0.0508;

    // --- Labor Intensity ---
    /**
     * Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Deal team compensation dominates
     * private equity overhead.
     */
    public const FIXED_COST_LABOR_SHARE = 0.70;

    // --- Leverage & Aggression Physics ---
    /** Minimum leverage aggression multiplier when the firm is unlevered. */
    public const MIN_LEVERAGE_AGGRESSION = 0.15;
    /** Maximum leverage aggression multiplier when the firm hits its equity limit. */
    public const MAX_LEVERAGE_AGGRESSION = 1.00;
    /** Amplifier for carried interest based on the amount of wholesale leverage deployed. */
    public const CARRY_LEVERAGE_AMPLIFIER_SCALAR = 0.80;

    // --- Cost of Debt & LBO Elasticity ---
    /**
     * The sponsor's OWN through-the-cycle funding spread (IG): the management company borrows as a corporate, not as a
     * buyout target.
     */
    public const SPONSOR_CREDIT_SPREAD_BASELINE = MacroConstants::BASE_CREDIT_SPREAD;
    /**
     * Carry-deal multiple lost per unit of buyout debt cost over its through-the-cycle level, and gained per unit
     * under it (2.27: 100 bps dearer takes 2.3% off).
     */
    public const LBO_COST_OF_DEBT_ELASTICITY = 2.27;
    /** Z-score cliff where economic conditions trigger a complete miss of the hurdle rate, wiping out carry. */
    public const HURDLE_RATE_Z_CLIFF = -1.00;
    /** Sensitivity of the hurdle rate cliff to widening credit spreads. */
    public const HURDLE_CREDIT_SPREAD_SCALAR = 10.0;
    /** Variable cost ratio per unit of floating rate past the headroom, on the carry stream's share (1.0). */
    public const RESCUE_CAPITAL_COST_SCALAR = 1.00;
    /**
     * Rise of the floating reference rate over its through-the-cycle level that buyouts' interest cover and rate hedges
     * absorb before the sponsor must inject capital (100 bps).
     */
    public const RESCUE_RATE_HEADROOM = 0.01;
    /**
     * LBO financing per unit of net tightening in bank lending standards, both ways: +0.2 shrinks carry-deal multiples
     * 5%.
     */
    public const SLOOS_LBO_GATING_SCALAR = 0.25;

    // --- Macro & Stream Physics ---
    /** Deal flow volume multiplier during macroeconomic output gap expansions. */
    public const DEAL_FLOW_BOOM_MULT       = 3.00;
    /** Deal flow volume multiplier during macroeconomic output gap contractions. */
    public const DEAL_FLOW_BUST_MULT       = 5.00;
    /** Sensitivity of PE deal exit velocity and carried interest realization to capital markets deal activity. */
    public const DEAL_ACTIVITY_EXIT_SCALAR = 0.50;
    /** Standard deviation multiplier for firm-wide revenue variance. */
    public const REVENUE_VARIANCE_SCALAR   = 0.15;
    /** Minimum structural operating cost-to-revenue ratio reflecting PE overhead. */
    public const MIN_EFFICIENCY_RATIO      = 0.30;

    // --- Stream Volatility Scalars ---
    /** Reduced volatility scalar for sticky management fees. */
    public const MGMT_BASE_VOLATILITY_SCALAR = 0.50;
    /** Volatility scalar for carried interest performance fees. */
    public const CARRY_BASE_VOLATILITY_SCALAR = 2.20;
    /** Volatility scalar for principal balance sheet investments. */
    public const PRINCIPAL_BASE_VOLATILITY_SCALAR = 2.00;

    // --- Principal MTM Physics ---
    /** Sensitivity of Principal balance sheet investments to macro Output Gap (Multiple Expansion/Contraction). */
    public const PRINCIPAL_MTM_MACRO_SCALAR = 2.00;
    /** Credit spread drag scalar applied to Mark-to-Market principal valuations. */
    public const PRINCIPAL_MTM_SPREAD_DRAG = 0.50;
    /** Base variable cost penalty applied when illiquid portfolio companies must be marked down. */
    public const PORTFOLIO_MARKDOWN_PENALTY = 0.15;

    // --- Corporate Treasury & Cash Yield ---
    /** Allocation percentage of PE excess cash deployed into internal sponsor equity co-investments. */
    public const PORTFOLIO_EQUITY_ALLOCATION = 0.20;
    /** Allocation percentage of PE excess cash deployed into safe debt and fixed income reserves. */
    public const PORTFOLIO_BOND_ALLOCATION = 0.80;

    // --- Debt Gating & Hoarding ---
    /** Multiple compression threshold that triggers a total freeze on new LBO debt issuance. */
    public const DEBT_GATE_LBO_DRAG_THRESHOLD = 0.30;
    /** Scalar applied to debt expansion capacity during a severe deal drought. */
    public const DEBT_GATE_DROUGHT_SCALAR = 0.25;
    /** A fund's hoard is dry powder measured against the debt it carries. */
    public const HOARDING_BASIS = 'total_debt';
    /** Cash-to-debt ratio threshold triggering standard hoarder status. */
    public const HOARDER_THRESHOLD         = 0.15;
    /** Cash-to-debt ratio threshold triggering mega hoarder status. */
    public const MEGA_HOARDER_THRESHOLD    = 0.30;
    /** Operating buffer ratio required for working capital. */
    public const TARGET_OPERATING_BUFFER   = 0.10;
    /** Minimum hard liquidity floor required for working capital. */
    public const MIN_OPERATING_BUFFER      = 0.05;

    // --- Buybacks & Capex ---
    /** Maximum fraction of excess cash deployed for buybacks if flagged as a mega hoarder. */
    public const MEGA_BUYBACK_CASH_SHARE   = 0.30;
    /** Standard fraction of excess cash deployed for opportunistic buybacks. */
    public const STANDARD_BUYBACK_SHARE    = 0.10;
    /** Fraction of issued wholesale debt successfully deployed into portfolio capex / acquisitions. */
    public const DEBT_CAPEX_DEPLOYMENT     = 0.90;

    // --- Debt Expansion Probabilities ---
    /** Base probability that the PE firm will tap wholesale markets for acquisition capital. */
    public const DEBT_EXPANSION_BASE_PROB  = 0.70;
    /** Spread multiplier effect on the probability of tapping debt markets. */
    public const DEBT_EXPANSION_PROB_MULT  = 0.20;
    /** Base aggressiveness size of debt tranches issued for LBOs. */
    public const DEBT_EXPANSION_BASE_AGGR  = 0.10;
    /** Spread multiplier effect on the aggressiveness of debt tranches. */
    public const DEBT_EXPANSION_AGGR_MULT  = 0.30;

    // --- Event Lore Thresholds ---
    /** Output gap threshold triggering PE Carried Interest Surge lore. */
    public const LORE_BOOM_GAP_THRESHOLD   = 0.020;
    /** Z-score threshold triggering PE Carried Interest Surge lore. */
    public const LORE_BOOM_Z_SCORE         = 1.50;
    /** Output gap contraction threshold triggering PE Deal Drought lore. */
    public const LORE_BUST_GAP_THRESHOLD   = -0.020;
    /** Z-score contraction threshold triggering PE Deal Drought lore. */
    public const LORE_BUST_Z_SCORE         = -1.50;

    // --- Credit Thresholds ---
    /** Most wholesale debt the model carries per unit of equity (2.5x equity). */
    public const WHOLESALE_LEVERAGE_LIMIT = 2.5;

    private function calculateLeverageAggression(Stock $stock): float
    {
        $equity = (float) $stock->getTotalEquity();
        $debt = (float) $stock->getWholesaleDebt();
        if ($equity <= 0.0) {
            return self::MIN_LEVERAGE_AGGRESSION;
        }

        $actualLeverage = $debt / $equity;
        $equityLimit = \App\Data\Company\Sectors::equityLimit($stock->getIndustry());
        $rawAggression = $actualLeverage / max(0.01, $equityLimit);

        return max(self::MIN_LEVERAGE_AGGRESSION, min(self::MAX_LEVERAGE_AGGRESSION, $rawAggression));
    }

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::ManagementFeeWeight->value   => 0.35,
            ModelParam::CarriedInterestWeight->value => 0.65,
        ]);

        $multipleCompression = $this->resolveMultipleCompression($macroState);
        $blendedMultiplier = ($params[ModelParam::ManagementFeeWeight] * 1.0) + ($params[ModelParam::CarriedInterestWeight] * $multipleCompression);

        return [
            'macro_demand_shift'       => 0.0,
            'pricing_power_multiplier' => $blendedMultiplier,
        ];
    }

    /**
     * Carry-deal multiples against their trend: buyout debt floats at the reference rate plus the high-yield spread,
     * and sponsors pay more for a deal when it costs less than they underwrite it at through the cycle and less when it
     * costs more (Axelson, Jenkinson, Stromberg & Weisbach 2013). Bank lending standards ration it both ways.
     */
    private function resolveMultipleCompression(\App\DTO\MacroStateDTO $macroState): float
    {
        $debtCostGap = $this->resolveReferenceRateGap($macroState) + ($macroState->highYieldCreditSpreadEma - $macroState->highYieldCreditSpreadTrend);
        $sloosLboDrag = $macroState->sloosTighteningIndexEma * self::SLOOS_LBO_GATING_SCALAR;

        return max(0.0, 1.0 - ($debtCostGap * self::LBO_COST_OF_DEBT_ELASTICITY) - $sloosLboDrag);
    }

    /**
     * The floating reference rate over the level buyouts are underwritten at: the market's perceived neutral policy
     * rate plus the interbank spread's decade trend.
     */
    private function resolveReferenceRateGap(\App\DTO\MacroStateDTO $macroState): float
    {
        return $macroState->floatingReferenceRate() - FixedIncome::floatingReferenceRate($macroState->perceivedNeutralRate, $macroState->interbankLiquiditySpreadTrend);
    }

    /**
     * The deal flow the deal teams are staffed to: the exit and deal-activity cycle on the carry stream at
     * its target weight. The LBO financing freeze already reaches the cost base through the pricing-power
     * multiplier, management fees carry no macro term, and principal investments are a mark, so none counts.
     */
    public function resolveSectorActivityShift(Stock $stock, \App\DTO\MacroStateDTO $macroState): float
    {
        $weights = $this->resolveTargetStreamWeights($stock);
        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        return ($weights['carried_interest'] / $totalWeight)
            * $this->resolveDealFlowMultiplier($macroState)
            * $this->resolveCarryShockScale($stock, (float) $stock->getVolatility());
    }

    /** @return array<string, float> */
    private function resolveTargetStreamWeights(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::ManagementFeeWeight->value        => 0.35,
            ModelParam::CarriedInterestWeight->value      => 0.65,
            ModelParam::PrincipalInvestmentsWeight->value => 0.00,
        ]);

        $targetWeights = [
            'management_fees'  => $params[ModelParam::ManagementFeeWeight],
            'carried_interest' => $params[ModelParam::CarriedInterestWeight],
        ];
        if ($params[ModelParam::PrincipalInvestmentsWeight] > 0.0) {
            $targetWeights['principal_investments'] = $params[ModelParam::PrincipalInvestmentsWeight];
        }

        return $targetWeights;
    }

    /**
     * GDP and capital-markets deal flow, asymmetric across the cycle: exits and new deals dry up faster in a bust than
     * they surge in a boom.
     */
    private function resolveDealFlowMultiplier(\App\DTO\MacroStateDTO $macroState): float
    {
        $outputGap = $macroState->outputGapEma;
        $dealActivityShift = $macroState->dealActivityDeviation();

        return ($outputGap > 0.0 ? ($outputGap * self::DEAL_FLOW_BOOM_MULT) : ($outputGap * self::DEAL_FLOW_BUST_MULT))
            + ($dealActivityShift * self::DEAL_ACTIVITY_EXIT_SCALAR);
    }

    /**
     * E[(1 + sZ)·1{Z ≥ k}] for Z ~ N(0,1) at the trend cliff k: the share of target carry a trend fund books,
     * (1 − Φ(k)) + s·φ(k).
     */
    public static function expectedHurdleClearedCarry(float $carryShockScale): float
    {
        $cliff = self::HURDLE_RATE_Z_CLIFF;

        return (1.0 - Distributions::standardNormalCdf($cliff)) + ($carryShockScale * Distributions::standardNormalPdf($cliff));
    }

    /**
     * Revenue per unit of carry economic condition: the stream's volatility, amplified by the sponsor's own leverage.
     */
    private function resolveCarryShockScale(Stock $stock, float $baselineVol): float
    {
        $actualLeverage = (float) $stock->getTotalEquity() > 0 ? ((float) $stock->getWholesaleDebt() / (float) $stock->getTotalEquity()) : 0.0;
        $leverageAmplifier = 1.0 + ($actualLeverage * self::CARRY_LEVERAGE_AMPLIFIER_SCALAR);

        return $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::CARRY_BASE_VOLATILITY_SCALAR * $leverageAmplifier;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        // --- Revenue Mix ---
        $activeWeights = $streams->resolveActiveStreamWeights($this->resolveTargetStreamWeights($stock));

        $mgmtWeight      = $activeWeights['management_fees'];
        $carryWeight     = $activeWeights['carried_interest'];
        $principalWeight = $activeWeights['principal_investments'] ?? 0.0;

        // 1. Independent stream Z-scores with tailored persistence
        $mgmtZ  = $streams->generateZ('management_fees', 0.50);
        $carryZ = $streams->generateZ('carried_interest', 0.15);
        $principalZ = $principalWeight > 0.0 ? $streams->generateZ('principal_investments', 0.20) : 0.0;

        // 2. GDP & Capital Markets Deal Flow Multiplier (Affects exit realizations)
        $outputGap = $macroState->outputGapEma;
        $dealFlowMultiplier = $this->resolveDealFlowMultiplier($macroState);

        // 3. Cost of Debt LBO Elasticity (Multiple Compression)
        $multipleCompression = $this->resolveMultipleCompression($macroState);
        $lboFinancingDrag = 1.0 - $multipleCompression; // % frozen

        // 4. Credit-Condition Hurdle Cliff: exits clear the hurdle less often as the HY spread runs over its trend, and
        // more often under it.
        $sloosLboDrag = $macroState->sloosTighteningIndexEma * self::SLOOS_LBO_GATING_SCALAR;
        $spreadFreezeDrag = (($macroState->highYieldCreditSpreadEma - $macroState->highYieldCreditSpreadTrend) * self::HURDLE_CREDIT_SPREAD_SCALAR)
            + ($sloosLboDrag * 0.50);
        $hurdleRateZCliff = self::HURDLE_RATE_Z_CLIFF + $spreadFreezeDrag;
        $economicCondition = $carryZ + $dealFlowMultiplier;

        $blendedMultiplier = ($mgmtWeight * 1.0) + ($carryWeight * $multipleCompression) + ($principalWeight * 1.0);
        $optimalRevenue = $blendedMultiplier > 0 ? $expectedRevenue / $blendedMultiplier : $expectedRevenue;

        // --- Clamped Multi-Stream Revenue ---
        $mgmtRevenue = max(0.0, $optimalRevenue * $mgmtWeight * (1.0 + ($mgmtZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * self::MGMT_BASE_VOLATILITY_SCALAR))));

        // 5. Leverage-amplified carry, paid only in quarters the funds clear their hurdle. The payout is grossed up
        // by its expectation at trend, as a jump is compensated in drift: a fund whose deal flow and credit sit at
        // trend books its target carry on average, and only a worse-than-trend cycle widens the miss.
        $carryShockScale = $this->resolveCarryShockScale($stock, $baselineVol);
        $carriedInterestRevenue = max(0.0, $optimalRevenue * $carryWeight
            * (1.0 + ($economicCondition * $carryShockScale))
            * $multipleCompression) / self::expectedHurdleClearedCarry($carryShockScale);

        // Binary Cliff Check
        $missedHurdle = false;
        if ($economicCondition < $hurdleRateZCliff) {
            $carriedInterestRevenue = 0.0;
            $missedHurdle = true;
        }

        // 6. MTM Principal Investments
        $principalInvestmentsRevenue = 0.0;
        $streamRevenues = [
            'management_fees'  => $mgmtRevenue,
            'carried_interest' => $carriedInterestRevenue,
        ];

        if ($principalWeight > 0.0) {
            // Mark-to-Market Shift: Multiple Expansion in hot economies, penalized by wide credit spreads.
            $mtmShift = ($outputGap * self::PRINCIPAL_MTM_MACRO_SCALAR) - ($spreadFreezeDrag * self::PRINCIPAL_MTM_SPREAD_DRAG);

            $principalInvestmentsRevenue = max(0.0, $optimalRevenue * $principalWeight
                * (1.0 + ($principalZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::PRINCIPAL_BASE_VOLATILITY_SCALAR) + $mtmShift));
            $streamRevenues['principal_investments'] = $principalInvestmentsRevenue;
        }

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // --- 7. Cost & Margin Physics --- Rescue Capital Drag: a floating rate past the headroom over its
        // through-the-cycle level chokes highly levered portfolio companies, requiring costly capital injections.
        $rescueRateExcess = max(0.0, $this->resolveReferenceRateGap($macroState) - self::RESCUE_RATE_HEADROOM);
        $rescueCapitalDrag = $rescueRateExcess * self::RESCUE_CAPITAL_COST_SCALAR * $carryWeight;

        $minVariableMargin = max(0.01, self::MIN_EFFICIENCY_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));

        // --- Event Triggers & MTM Markdown Penalties ---
        $aggression = $this->calculateLeverageAggression($stock);
        $eventType = null;
        $markdownPenalty = 0.0;

        if ($missedHurdle) {
            $eventType = ShockEvent::PE_HURDLE_RATE_MISSED;
        } elseif ($multipleCompression < 0.50 && $aggression > 0.6) {
            $eventType = ShockEvent::PE_PORTFOLIO_MARKDOWN;

            // Markdown Event directly impacts the cost margin based on principal balance sheet exposure
            $markdownPenalty = self::PORTFOLIO_MARKDOWN_PENALTY * $principalWeight * $lboFinancingDrag;
        } elseif ($aggression > 0.85 && $lboFinancingDrag <= 0.001) {
            $eventType = ShockEvent::PE_LEVERAGE_RECAPITALIZATION;
        } elseif ($outputGap > self::LORE_BOOM_GAP_THRESHOLD && $carryZ > self::LORE_BOOM_Z_SCORE && $lboFinancingDrag <= 0.001) {
            $eventType = ShockEvent::PE_CARRIED_INTEREST_SURGE;
        } elseif (($outputGap < self::LORE_BUST_GAP_THRESHOLD && $carryZ < self::LORE_BUST_Z_SCORE) || $lboFinancingDrag > 0.10) {
            $eventType = ShockEvent::PE_DEAL_DROUGHT;
        }

        // Add friction drags to the variable cost margin (cost ratio)
        $rawMargin = $realizedVariableMargin + $rescueCapitalDrag + $markdownPenalty;
        $clampedMargin = $this->clampMargin($rawMargin, $minVariableMargin);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    // --- Analyst Coverage ---
    // Portfolio marks are opaque; a missed hurdle or a markdown is announced.
    /** Share of routine shocks analysts see (20%). */
    public const BASE_COVERAGE_VISIBILITY = 0.20;
    /** Standard deviation of the consensus forecast error (0.05). */
    public const BASE_COVERAGE_ERROR = 0.05;
    /** Floor on routine visibility (0%). */
    public const BASE_COVERAGE_MIN_VISIBILITY = 0.00;
    /** Visibility of a quarter with an announced event (85%). */
    public const EVENT_BASE_VISIBILITY = 0.85;
    /** Floor on visibility in an announced-event quarter (60%). */
    public const EVENT_MIN_VISIBILITY = 0.60;

    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float
    {
        return $isMegaHoarder ? $excessCash * self::MEGA_BUYBACK_CASH_SHARE : max(0.0, min($excessCash * self::STANDARD_BUYBACK_SHARE, $retainedEarningsThisQuarter));
    }

    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float
    {
        return max($organicSpend, $debtIssued * self::DEBT_CAPEX_DEPLOYMENT);
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier, float $totalDebt = 0.0, float $customerDeposits = 0.0, float $targetOperatingCash = 0.0, float $currentTreasury = 0.0): DebtExpansionAppetiteDTO
    {
        return new DebtExpansionAppetiteDTO(probability: self::DEBT_EXPANSION_BASE_PROB + ($spreadMultiplier * self::DEBT_EXPANSION_PROB_MULT), aggressiveness: self::DEBT_EXPANSION_BASE_AGGR + (self::DEBT_EXPANSION_AGGR_MULT * $spreadMultiplier));
    }

    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return max($operatingBase * self::TARGET_OPERATING_BUFFER, $wholesaleDebt * self::TARGET_OPERATING_BUFFER);
    }

    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return max($operatingBase * self::MIN_OPERATING_BUFFER, $wholesaleDebt * self::MIN_OPERATING_BUFFER);
    }

    public function getAcquisitionType(string $defaultType): string
    {
        return 'LEVERAGED BUYOUT';
    }

    public function calculateCashYield(\App\DTO\MacroStateDTO $macroState): float
    {
        $bondReturn = $macroState->yield10yEma;
        $equityReturn = $this->resolveEquitySleeveReturn($macroState, self::EQUITY_RETURN_GAP_MULT * self::LEVERAGED_SLEEVE_GAP_SCALE);

        // PE firms deploy excess cash into safe short-term sovereign debt and sponsor commitments (80% Bonds / 20%
        // Equities)
        return (self::PORTFOLIO_BOND_ALLOCATION * $bondReturn) + (self::PORTFOLIO_EQUITY_ALLOCATION * $equityReturn);
    }

    protected function resolveTreasuryEquityWeight(): float
    {
        return self::PORTFOLIO_EQUITY_ALLOCATION;
    }

    protected function resolveTreasuryEquityGapMultiplier(): float
    {
        return self::EQUITY_RETURN_GAP_MULT * self::LEVERAGED_SLEEVE_GAP_SCALE;
    }

    public function calculateDebtExpansionCapacity(float $equity, float $totalDebt, float $wholesaleDebt, \App\DTO\DebtHealthDTO $health, float $newBorrowingRate, float $ebit, float $depreciation): float
    {
        $baseCapacity = parent::calculateDebtExpansionCapacity($equity, $totalDebt, $wholesaleDebt, $health, $newBorrowingRate, $ebit, $depreciation);

        $firmCreditSpread = $health->rawMetrics->dynamicSpread ?? self::SPONSOR_CREDIT_SPREAD_BASELINE;

        // Gate capacity on the sponsor's own credit standing against its corporate (IG) baseline; the HY
        // baseline prices the portfolio companies' debt, not the management company's.
        $spreadFreezeDrag = max(0.0, ($firmCreditSpread - self::SPONSOR_CREDIT_SPREAD_BASELINE) * self::HURDLE_CREDIT_SPREAD_SCALAR);

        if ($spreadFreezeDrag > self::DEBT_GATE_LBO_DRAG_THRESHOLD) {
            $baseCapacity *= self::DEBT_GATE_DROUGHT_SCALAR;
        }

        return $baseCapacity;
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
            'deal_activity_index_ema',
            'high_yield_credit_spread_ema',
            'interbank_liquidity_spread_ema',
            'output_gap_ema',
            'perceived_neutral_rate',
            'policy_rate_ema',
            'sloos_tightening_index_ema',
            'yield_10y_ema',
        ];
    }
}
