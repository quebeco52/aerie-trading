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
 * Earnings strategy for Private Equity & Alternative Asset Managers.
 *
 * Financial Physics:
 * - Base revenue comes from sticky AUM management fees.
 * - Massive volatility comes from "Carried Interest" (performance fees) and deal exits.
 * - LBO Cost of Debt Elasticity: Deal flow and multiples compress as total debt financing costs rise.
 * - Mark-to-Market (MTM): Principal balance sheet investments suffer unrealized markdown penalties during credit freezes.
 * - Rescue Capital Drag: High policy rates force the GP to inject capital into distressed portfolio companies, inflating variable costs.
 */
class PrivateEquityBusinessModel extends AssetManagementBusinessModel
{
    // --- Balance Sheet Realism ---
    /** Stock-based compensation as a fraction of revenue (ASC 718): non-cash, added back to FCF, settled in new shares. Deal team deferrals settle in manager equity. */
    public const STOCK_COMPENSATION_INTENSITY = 0.05;

    // --- Labor Intensity ---
    /** Labor share of the fixed cost base exposed to the Beveridge wage squeeze. Deal team compensation dominates private equity overhead. */
    public const FIXED_COST_LABOR_SHARE = 0.70;

        public function getWholesaleLeverageLimit(): float { return 2.5; }

    // --- Leverage & Aggression Physics ---
    /** Minimum leverage aggression multiplier when the firm is unlevered. */
    public const MIN_LEVERAGE_AGGRESSION = 0.15;
    /** Maximum leverage aggression multiplier when the firm hits its equity limit. */
    public const MAX_LEVERAGE_AGGRESSION = 1.00;
    /** Amplifier for carried interest based on the amount of wholesale leverage deployed. */
    public const CARRY_LEVERAGE_AMPLIFIER_SCALAR = 0.80;

    // --- Cost of Debt & LBO Elasticity ---
    /** Baseline macro credit spread (~200bps) for normal LBO conditions. */
    public const LBO_CREDIT_SPREAD_BASELINE = 0.020;
    /** Baseline policy rate (~4.5%) threshold above which LBO financing becomes distressed. */
    public const LBO_RATE_FREEZE_THRESHOLD  = 0.045;
    /** Elasticity scalar: How LBO multiples compress and exits freeze as total Cost of Debt rises. */
    public const LBO_COST_OF_DEBT_ELASTICITY = 7.50;
    /** Z-score cliff where economic conditions trigger a complete miss of the hurdle rate, wiping out carry. */
    public const HURDLE_RATE_Z_CLIFF = -1.00;
    /** Sensitivity of the hurdle rate cliff to widening credit spreads. */
    public const HURDLE_CREDIT_SPREAD_SCALAR = 10.0;
    /** Multiplier for variable costs (rescue capital) when policy rates choke portfolio companies. */
    public const RESCUE_CAPITAL_COST_SCALAR = 1.00;
    /** Gating penalty on LBO debt syndication when banks tighten credit standards (SLOOS). */
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
    /** Baseline VIX threshold above which illiquid PE co-investments suffer valuation drags. */
    public const SEED_VIX_THRESHOLD = 0.20;
    /** Sensitivity of PE cash yields to severe VIX market panics. */
    public const SEED_VIX_SENSITIVITY = 0.50;
    /** Allocation percentage of PE excess cash deployed into internal sponsor equity co-investments. */
    public const PORTFOLIO_EQUITY_ALLOCATION = 0.20;
    /** Allocation percentage of PE excess cash deployed into safe debt and fixed income reserves. */
    public const PORTFOLIO_BOND_ALLOCATION = 0.80;

    // --- Debt Gating & Hoarding ---
    /** Multiple compression threshold that triggers a total freeze on new LBO debt issuance. */
    public const DEBT_GATE_LBO_DRAG_THRESHOLD = 0.30;
    /** Scalar applied to debt expansion capacity during a severe deal drought. */
    public const DEBT_GATE_DROUGHT_SCALAR = 0.25;
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

    // --- ROE Smoothing Constants ---
    /** Multiplier to annualize quarterly return on equity metrics. */
    public const ROE_ANNUALIZATION_MULT    = 4.00;
    /** Absolute lower bound clamp for realized ROE to prevent math explosions. */
    public const MIN_ROE_CLAMP             = -0.50;
    /** Absolute upper bound clamp for realized ROE. */
    public const MAX_ROE_CLAMP             = 1.00;
    /** Weight assigned to the current quarter's ROE when blending the TTM EMA. */
    public const ROE_TTM_EMA_WEIGHT        = 0.20;
    /** Weight assigned to the historical TTM ROE when blending the TTM EMA. */
    public const ROE_TTM_HIST_WEIGHT       = 0.80;

    private function calculateLeverageAggression(Stock $stock): float
    {
        $equity = (float) $stock->getTotalEquity();
        $debt = (float) $stock->getWholesaleDebt();
        if ($equity <= 0.0) {
            return self::MIN_LEVERAGE_AGGRESSION;
        }

        $actualLeverage = $debt / $equity;
        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? self::DEFAULT_EQUITY_LIMIT;
        $rawAggression = $actualLeverage / max(0.01, $equityLimit);

        return max(self::MIN_LEVERAGE_AGGRESSION, min(self::MAX_LEVERAGE_AGGRESSION, $rawAggression));
    }

    public function getTargetMetrics(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): array
    {
        $aggression = $this->calculateLeverageAggression($stock);

        $equity = (float) $stock->getTotalEquity();
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());

        $ttmRoe = (float) $stock->getRoeTtm();
        if ($ttmRoe !== 0.0) {
            $baselineRoe = ($baselineRoe * self::BASELINE_ROE_WEIGHT) + ($ttmRoe * self::TTM_ROE_WEIGHT);
        }

        $saturationPenalty = \App\Service\Math\CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, max(1.0, $equity), $macroState);
        $waccBase = $macroState->policyRate + $macroState->equityRiskPremium;
        $baselineRoe = max($waccBase, $baselineRoe - $saturationPenalty);

        $policyRate = $macroState->policyRateEma;
        $yield5y = $macroState->yield5yEma;
        $structuralSpread = (float) $stock->getCreditSpread();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        $taxRate = $macroState->corporateTaxRate;

        $wholesaleDebt = (float) $stock->getWholesaleDebt();
        $treasury = (float) $stock->getCorporateTreasury();

        $blendedWholesaleRate = ($floatingRatio * $policyRate) + ((1.0 - $floatingRatio) * $yield5y) + $structuralSpread;

        $industry = $stock->getIndustry() ?: 'General';
        $baseEquityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? self::DEFAULT_EQUITY_LIMIT;

        $effectiveEquityLimit = $baseEquityLimit * (0.5 + ($aggression * 0.5));
        $effectiveEquity = max(1.0, $equity);

        $actualLeverage = $effectiveEquity > 0 ? ($wholesaleDebt / $effectiveEquity) : 0.0;
        $allowedLeverage = min($actualLeverage, max(0.0, $effectiveEquityLimit));
        $optimalDebt = $effectiveEquity * $allowedLeverage;

        $optimalInterestExpense = $optimalDebt * $blendedWholesaleRate;

        $optimalOperatingNetIncome = $effectiveEquity * $baselineRoe;
        $optimalEbt = $optimalOperatingNetIncome / (1.0 - $taxRate);

        $operatingBase = $this->getOperatingBase($stock);
        $optimalOperatingCash = $this->calculateTargetOperatingCash($operatingBase, 0.0, $optimalDebt);
        $minOperatingCash = $this->calculateMinOperatingCash($operatingBase, 0.0, $optimalDebt);
        $optimalYieldingCash = max(0.0, $optimalOperatingCash - $minOperatingCash);
        $optimalInterestIncome = $optimalYieldingCash * max(0.0, $policyRate - \App\Service\Macro\MacroEngine::CASH_YIELD_SPREAD);

        $optimalEbit = $optimalEbt + $optimalInterestExpense - $optimalInterestIncome;
        $optimalEarningAssets = $effectiveEquity + $optimalDebt - $optimalOperatingCash;
        $structuralOperatingYield = $optimalEbit / max(1.0, $optimalEarningAssets);

        $earningAssets = max($effectiveEquity, $effectiveEquity + $wholesaleDebt - $treasury);

        $targetEbit = $earningAssets * $structuralOperatingYield;
        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        $minOperatingEbit = $earningAssets * self::MIN_OPERATING_EBIT_YIELD;
        $targetEbit = max($minOperatingEbit, $targetEbit);

        $unboundedRevenue = max(0.0, $targetEbit) / $stableMargin;
        $targetRevenue = min($unboundedRevenue, $earningAssets * self::MAX_TURNOVER_CAP);

        $impliedTurnover = $targetRevenue / max(1.0, $earningAssets);

        return [
            'invested_capital' => $earningAssets,
            'baseline_roic' => ($impliedTurnover * $stableMargin) * (1.0 - $taxRate)
        ];
    }

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::ManagementFeeWeight->value   => 0.35,
            ModelParam::CarriedInterestWeight->value => 0.65,
        ]);

        // Credit cycle pricing power: LBO multiples compress when the Cost of Debt spikes.
        $lboCostOfDebt = $macroState->policyRateEma + $macroState->macroCreditSpreadEma;
        $baselineCostOfDebt = self::LBO_RATE_FREEZE_THRESHOLD + self::LBO_CREDIT_SPREAD_BASELINE;
        $debtCostDelta = max(0.0, $lboCostOfDebt - $baselineCostOfDebt);
        $sloosLboDrag = max(0.0, $macroState->sloosTighteningIndexEma) * self::SLOOS_LBO_GATING_SCALAR;

        $multipleCompression = max(0.0, 1.0 - ($debtCostDelta * self::LBO_COST_OF_DEBT_ELASTICITY) - $sloosLboDrag);
        $blendedMultiplier = ($params[ModelParam::ManagementFeeWeight] * 1.0) + ($params[ModelParam::CarriedInterestWeight] * $multipleCompression);

        return [
            'macro_demand_shift'       => 0.0,
            'pricing_power_multiplier' => $blendedMultiplier,
        ];
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::ManagementFeeWeight->value        => 0.35,
            ModelParam::CarriedInterestWeight->value      => 0.65,
            ModelParam::PrincipalInvestmentsWeight->value => 0.00,
        ]);
        $rawPrincipalWeight = $params[ModelParam::PrincipalInvestmentsWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility);

        $targetWeights = [
            'management_fees'  => $params[ModelParam::ManagementFeeWeight],
            'carried_interest' => $params[ModelParam::CarriedInterestWeight],
        ];
        if ($rawPrincipalWeight > 0.0) {
            $targetWeights['principal_investments'] = $rawPrincipalWeight;
        }

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights($targetWeights);

        $mgmtWeight      = $activeWeights['management_fees'];
        $carryWeight     = $activeWeights['carried_interest'];
        $principalWeight = $activeWeights['principal_investments'] ?? 0.0;

        // 1. Independent stream Z-scores with tailored persistence
        $mgmtZ  = $streams->generateZ('management_fees', 0.50);
        $carryZ = $streams->generateZ('carried_interest', 0.15);
        $principalZ = $principalWeight > 0.0 ? $streams->generateZ('principal_investments', 0.20) : 0.0;

        // 2. GDP & Capital Markets Deal Flow Multiplier (Affects exit realizations)
        $outputGap = $macroState->outputGapEma;
        $dealActivityShift = ($macroState->dealActivityIndexEma - MacroEngine::DEAL_ACTIVITY_BASELINE) / MacroEngine::DEAL_ACTIVITY_BASELINE;
        $dealFlowMultiplier = ($outputGap > 0.0 ? ($outputGap * self::DEAL_FLOW_BOOM_MULT) : ($outputGap * self::DEAL_FLOW_BUST_MULT))
            + ($dealActivityShift * self::DEAL_ACTIVITY_EXIT_SCALAR);

        // 3. Cost of Debt LBO Elasticity (Multiple Compression)
        $creditSpread = $macroState->macroCreditSpreadEma;
        $policyRate   = $macroState->policyRateEma;

        $lboCostOfDebt = $policyRate + $creditSpread;
        $baselineCostOfDebt = self::LBO_RATE_FREEZE_THRESHOLD + self::LBO_CREDIT_SPREAD_BASELINE;
        $debtCostDelta = max(0.0, $lboCostOfDebt - $baselineCostOfDebt);
        $sloosLboDrag = max(0.0, $macroState->sloosTighteningIndexEma) * self::SLOOS_LBO_GATING_SCALAR;

        $multipleCompression = max(0.0, 1.0 - ($debtCostDelta * self::LBO_COST_OF_DEBT_ELASTICITY) - $sloosLboDrag);
        $lboFinancingDrag = 1.0 - $multipleCompression; // % frozen

        // 4. Credit-Condition Hurdle Cliff
        $spreadFreezeDrag = max(0.0, ($creditSpread - self::LBO_CREDIT_SPREAD_BASELINE) * self::HURDLE_CREDIT_SPREAD_SCALAR)
            + ($sloosLboDrag * 0.50);
        $hurdleRateZCliff = self::HURDLE_RATE_Z_CLIFF + $spreadFreezeDrag;
        $economicCondition = $carryZ + $dealFlowMultiplier;

        $blendedMultiplier = ($mgmtWeight * 1.0) + ($carryWeight * $multipleCompression) + ($principalWeight * 1.0);
        $optimalRevenue = $blendedMultiplier > 0 ? $expectedRevenue / $blendedMultiplier : $expectedRevenue;

        // 5. Leverage Amplifier
        $actualLeverage = (float) $stock->getTotalEquity() > 0 ? ((float) $stock->getWholesaleDebt() / (float) $stock->getTotalEquity()) : 0.0;
        $leverageAmplifier = 1.0 + ($actualLeverage * self::CARRY_LEVERAGE_AMPLIFIER_SCALAR);

        // --- Clamped Multi-Stream Revenue ---
        $mgmtRevenue = max(0.0, $optimalRevenue * $mgmtWeight * (1.0 + ($mgmtZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * self::MGMT_BASE_VOLATILITY_SCALAR))));

        $carriedInterestRevenue = max(0.0, $optimalRevenue * $carryWeight
            * (1.0 + ($economicCondition * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * self::CARRY_BASE_VOLATILITY_SCALAR) * $leverageAmplifier))
            * $multipleCompression);

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

        // --- 7. Cost & Margin Physics ---
        // Rescue Capital Drag: High policy rates choke highly levered portfolio companies, requiring costly capital injections.
        $rescueCapitalDrag = max(0.0, ($policyRate - self::LBO_RATE_FREEZE_THRESHOLD) * self::RESCUE_CAPITAL_COST_SCALAR) * $carryWeight;

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

        $observableShockZ = $expectedRevenue > 0.0 ? (($actualRevenue - $expectedRevenue) / $expectedRevenue) : 0.0;

        $primaryShockZ = $streams->resolveDominantShockZ([$carryZ, $mgmtZ, $principalWeight > 0.0 ? $principalZ : 0.0]);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    public function getCoverageProfile(\App\Entity\Stock $stock): \App\DTO\SectorCoverageProfile
    {
        return new \App\DTO\SectorCoverageProfile(
            baseVisibility: 0.20,
            errorStdDev: 0.05,
            eventBaseVisibility: 0.85,
            eventMinVisibility: 0.60
        );
    }

    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float
    {
        return $isMegaHoarder ? $excessCash * self::MEGA_BUYBACK_CASH_SHARE : max(0.0, min($excessCash * self::STANDARD_BUYBACK_SHARE, $retainedEarningsThisQuarter));
    }

    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float
    {
        return max($organicSpend, $debtIssued * self::DEBT_CAPEX_DEPLOYMENT);
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier, float $totalDebt = 0.0, float $customerDeposits = 0.0, float $targetOperatingCash = 0.0, float $currentTreasury = 0.0): array
    {
        return [
            'probability' => self::DEBT_EXPANSION_BASE_PROB + ($spreadMultiplier * self::DEBT_EXPANSION_PROB_MULT),
            'aggressiveness' => self::DEBT_EXPANSION_BASE_AGGR + (self::DEBT_EXPANSION_AGGR_MULT * $spreadMultiplier)
        ];
    }

    public function evaluateHoardingStatus(float $treasury, float $targetCashReserves, float $operatingBase, float $totalDebt): array
    {
        $excessCash = max(0.0, $treasury - $targetCashReserves);
        return [
            'excess_cash'     => $excessCash,
            'is_hoarder'      => $excessCash > ($totalDebt * self::HOARDER_THRESHOLD),
            'is_mega_hoarder' => $excessCash > ($totalDebt * self::MEGA_HOARDER_THRESHOLD),
        ];
    }

    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return max($operatingBase * self::TARGET_OPERATING_BUFFER, $wholesaleDebt * self::TARGET_OPERATING_BUFFER);
    }

    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return max($operatingBase * self::MIN_OPERATING_BUFFER, $wholesaleDebt * self::MIN_OPERATING_BUFFER);
    }

    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate, float $wacc = 0.08, float $costOfEquity = 0.10, ?\App\DTO\MacroStateDTO $macroState = null, float $depreciation = 0.0): float
    {
        $kappa = $this->getReversionSpeed();
        $moatSpread = $this->getMoatSpread();

        $equity = (float) $stock->getTotalEquity();
        $truePostTaxReturn = $equity > 0 ? ($actualTotalNetIncome / $equity) * self::ROE_ANNUALIZATION_MULT : 0.0;

        $stock->setCurrentRoe((string) max(self::MIN_ROE_CLAMP, min(self::MAX_ROE_CLAMP, $truePostTaxReturn)));

        $oldTtm = (float) $stock->getRoeTtm();
        $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * self::ROE_TTM_EMA_WEIGHT) + ($oldTtm * self::ROE_TTM_HIST_WEIGHT);

        $scaledKappa = $kappa / self::TTM_ROE_WEIGHT;

        $saturationPenalty = 0.0;
        if ($macroState !== null) {
            $saturationPenalty = \App\Service\Math\CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, max(1.0, $equity), $macroState);
        }

        $theoreticalTarget = ($costOfEquity + $moatSpread) - $saturationPenalty;
        $flooredTarget = max($costOfEquity, $theoreticalTarget);
        $effectiveMoat = $flooredTarget - $costOfEquity;

        $newTtm += MathUtility::getInstance()->calculateReversionPull($newTtm, $costOfEquity, $scaledKappa, $effectiveMoat);
        $stock->setRoeTtm((string) max(self::MIN_ROE_CLAMP, min(self::MAX_ROE_CLAMP, $newTtm)));

        return $truePostTaxReturn;
    }

    public function getAcquisitionType(string $defaultType): string
    {
        return 'LEVERAGED BUYOUT';
    }

    public function calculateCashYield(\App\DTO\MacroStateDTO $macroState): float
    {
        $yield10y = $macroState->yield10yEma;
        $outputGap = $macroState->outputGapEma;

        $bondReturn = $yield10y;
        $equityReturn = self::BASE_EQUITY_RETURN + ($outputGap * self::EQUITY_RETURN_GAP_MULT * 1.5);

        // PE firms deploy excess cash into safe short-term sovereign debt and sponsor commitments (80% Bonds / 20% Equities)
        return max(0.0, (self::PORTFOLIO_BOND_ALLOCATION * $bondReturn) + (self::PORTFOLIO_EQUITY_ALLOCATION * $equityReturn));
    }

    public function calculateInterestIncome(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility, ?float $realizedWholesaleRate = null): float
    {
        $operatingBase = $this->getOperatingBase($stock);
        $minCash = $this->calculateMinOperatingCash($operatingBase, 0.0, (float) $stock->getWholesaleDebt());
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - $minCash);

        $baseYield = $this->calculateCashYield($macroState);

        // Severe VIX market panic drag on illiquid PE investments
        $vixEma = $macroState->marketVolatilityEma;
        $vixDrag = max(0.0, ($vixEma - self::SEED_VIX_THRESHOLD) * self::SEED_VIX_SENSITIVITY);

        $effectiveYield = $baseYield - (self::PORTFOLIO_EQUITY_ALLOCATION * $vixDrag);

        return $excessCash * $effectiveYield;
    }

    public function calculateDebtExpansionCapacity(float $equity, float $totalDebt, float $wholesaleDebt, \App\DTO\DebtHealthDTO $health, float $newBorrowingRate, float $ebit, float $depreciation): float
    {
        $baseCapacity = parent::calculateDebtExpansionCapacity($equity, $totalDebt, $wholesaleDebt, $health, $newBorrowingRate, $ebit, $depreciation);

        $firmCreditSpread = $health->rawMetrics->dynamicSpread ?? self::LBO_CREDIT_SPREAD_BASELINE;

        // Gate capacity using the firm's specific credit standing versus normal LBO baselines
        $spreadFreezeDrag = max(0.0, ($firmCreditSpread - self::LBO_CREDIT_SPREAD_BASELINE) * self::HURDLE_CREDIT_SPREAD_SCALAR);

        if ($spreadFreezeDrag > self::DEBT_GATE_LBO_DRAG_THRESHOLD) {
            $baseCapacity *= self::DEBT_GATE_DROUGHT_SCALAR;
        }

        return $baseCapacity;
    }

    public function isUnderLeveraged(float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool
    {
        $limit = $targetDebtTolerance > 0.0 ? $targetDebtTolerance : $this->getWholesaleLeverageLimit();
        return $currentDebtRatio < ($limit * 0.85);
    }

    public function supportsUnderleveragedDebtExpansion(): bool
    {
        return true;
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
            'macro_credit_spread_ema',
            'market_volatility_ema',
            'output_gap_ema',
            'policy_rate_ema',
            'sloos_tightening_index_ema',
            'yield_10y_ema',
        ];
    }
}
