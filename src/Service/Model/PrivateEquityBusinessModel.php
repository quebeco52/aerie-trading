<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;

/**
 * Earnings strategy for Private Equity & Alternative Asset Managers.
 * 
 * Financial Physics:
 * - Base revenue comes from sticky AUM management fees.
 * - Massive volatility comes from "Carried Interest" (performance fees) and deal exits.
 * - Thrives during economic expansions and cheap credit (easy to IPO/sell targets).
 * - Suffers "deal droughts" during recessions when credit freezes and exits are impossible.
 */
class PrivateEquityBusinessModel extends AssetManagementBusinessModel
{
    // --- Carried Interest & Deal Flow Physics ---
    /** Output gap multiplier scaling carried interest revenue during economic booms. */
    public const DEAL_FLOW_BOOM_MULT       = 3.00;
    /** Output gap multiplier scaling deal drought severity during economic downturns. */
    public const DEAL_FLOW_BUST_MULT       = 1.50;
    /** Volatility multiplier for top-line revenue shocks in carried interest asset models. */
    public const REVENUE_VARIANCE_SCALAR   = 0.15;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    // --- LBO Financing Freeze & Efficiency Floor ---
    /** Baseline credit spread above which LBO debt financing becomes restrictive for buyout exits. */
    public const LBO_CREDIT_SPREAD_BASELINE = 0.020;
    /** Sensitivity scalar translating excess credit spread into deal exit drag. */
    public const LBO_SPREAD_FREEZE_SCALAR   = 4.00;
    /** Policy rate threshold above which high interest rates chill LBO M&A exit activity. */
    public const LBO_RATE_FREEZE_THRESHOLD  = 0.045;
    /** Sensitivity scalar translating high policy rates into deal exit drag. */
    public const LBO_RATE_FREEZE_SCALAR     = 2.00;
    /** Structural minimum operating cost-to-revenue ratio for private equity platform overhead. */
    public const MIN_EFFICIENCY_RATIO       = 0.30;

    // --- Event Lore Thresholds ---
    /** Positive output gap threshold triggering deal boom carried interest lore. */
    public const LORE_BOOM_GAP_THRESHOLD   = 0.020;
    /** Positive z-score threshold required during economic booms to trigger exit lore. */
    public const LORE_BOOM_Z_SCORE         = 1.50;
    /** Negative output gap threshold triggering deal drought lore. */
    public const LORE_BUST_GAP_THRESHOLD   = -0.020;
    /** Negative z-score threshold required during recessions to trigger drought lore. */
    public const LORE_BUST_Z_SCORE         = -1.50;

    // --- Analyst Visibility & Error ---
    /** Base analyst visibility into opaque portfolio exits and carried interest fees. */
    public const ANALYST_BASE_VISIBILITY   = 0.20;
    /** Standard deviation of analyst estimation error for quarterly carried interest revenue. */
    public const ANALYST_ERROR_STD_DEV     = 0.05;

    // --- Buybacks & Capital Deployment ---
    /** Fraction of excess cash allocated to buybacks for mega-hoarder private equity firms. */
    public const MEGA_BUYBACK_CASH_SHARE   = 0.30;
    /** Fraction of excess cash allocated to buybacks for standard private equity firms. */
    public const STANDARD_BUYBACK_SHARE    = 0.10;
    /** Minimum fraction of newly issued debt that must be deployed into bolt-on acquisitions. */
    public const DEBT_CAPEX_DEPLOYMENT     = 0.90;

    // --- Aggressive LBO Borrowing Rails ---
    /** Baseline probability of initiating debt expansion when credit spreads are neutral. */
    public const DEBT_EXPANSION_BASE_PROB  = 0.70;
    /** Multiplier scaling debt expansion probability with spread attractiveness. */
    public const DEBT_EXPANSION_PROB_MULT  = 0.20;
    /** Baseline aggressiveness fraction for new debt issuance in leveraged buyout models. */
    public const DEBT_EXPANSION_BASE_AGGR  = 0.10;
    /** Multiplier scaling debt issuance aggressiveness with spread attractiveness. */
    public const DEBT_EXPANSION_AGGR_MULT  = 0.30;

    // --- Liquidity & Dry Powder Reserves ---
    /** Threshold ratio of excess cash over total debt triggering hoarder status for LBO firms. */
    public const HOARDER_THRESHOLD         = 0.15;
    /** Threshold ratio of excess cash over total debt triggering mega-hoarder status for LBO firms. */
    public const MEGA_HOARDER_THRESHOLD    = 0.30;
    /** Target operating cash reserve ratio applied to corporate operating base and wholesale debt. */
    public const TARGET_OPERATING_BUFFER   = 0.10;
    /** Minimum emergency operating cash reserve ratio applied to corporate operating base and wholesale debt. */
    public const MIN_OPERATING_BUFFER      = 0.05;

    // --- ROE Annualization & Smoothing ---
    /** Annualization multiplier applied to quarterly net income to derive annualized ROE. */
    public const ROE_ANNUALIZATION_MULT    = 4.00;
    /** Minimum allowable ROE floor to prevent catastrophic negative overflow. */
    public const MIN_ROE_CLAMP             = -0.50;
    /** Maximum allowable ROE ceiling to prevent unrealistic hyperinflation. */
    public const MAX_ROE_CLAMP             = 1.00;
    /** Weight given to current quarter ROE when updating trailing twelve-month ROE EMA. */
    public const ROE_TTM_EMA_WEIGHT        = 0.20;
    /** Weight given to historical trailing twelve-month ROE when updating ROE EMA. */
    public const ROE_TTM_HIST_WEIGHT       = 0.80;

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();

        $params = $this->resolveModelParameters($stock, [
            'management_fee_weight'   => 0.35,
            'carried_interest_weight' => 0.65,
        ]);
        $mgmtWeight  = $params['management_fee_weight'];
        $carryWeight = $params['carried_interest_weight'];

        // 1. GDP Deal Flow Multiplier:
        $outputGap = $macroState['output_gap_ema'] ?? ($macroState['output_gap'] ?? 0.0);
        $dealFlowMultiplier = $outputGap > 0.0 ? ($outputGap * self::DEAL_FLOW_BOOM_MULT) : ($outputGap * self::DEAL_FLOW_BUST_MULT);

        // 2. LBO Financing Freeze Drag (Credit Spread & Policy Rate):
        // Carried interest crystallization freezes when leveraged debt markets widen or short rates spike.
        $creditSpread = $macroState['macro_credit_spread_ema'] ?? ($macroState['macro_credit_spread'] ?? self::LBO_CREDIT_SPREAD_BASELINE);
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;

        $spreadFreezeDrag = max(0.0, ($creditSpread - self::LBO_CREDIT_SPREAD_BASELINE) * self::LBO_SPREAD_FREEZE_SCALAR);
        $rateFreezeDrag = max(0.0, ($policyRate - self::LBO_RATE_FREEZE_THRESHOLD) * self::LBO_RATE_FREEZE_SCALAR);
        $lboFinancingDrag = $spreadFreezeDrag + $rateFreezeDrag;

        $managementRevenue = $expectedRevenue * $mgmtWeight * (1.0 + ($revenueZ * ($baselineVol * 0.5)));
        $carryRevenue      = $expectedRevenue * $carryWeight * (1.0 + ($revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)) + $dealFlowMultiplier - $lboFinancingDrag);
        $actualRevenue     = max(0.0, $managementRevenue + max(0.0, $carryRevenue));

        // 3. Structural Efficiency Floor: Total Operating Costs (Fixed + Variable) / Revenue >= MIN_EFFICIENCY_RATIO.
        $minVariableMargin = max(0.01, self::MIN_EFFICIENCY_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));
        $clampedMargin = min(self::MAX_VARIABLE_MARGIN_CLAMP, max($minVariableMargin, $realizedVariableMargin));
        $actualVariableCosts = $actualRevenue * $clampedMargin;

        $eventLore = null;
        if ($outputGap > self::LORE_BOOM_GAP_THRESHOLD && $revenueZ > self::LORE_BOOM_Z_SCORE && $lboFinancingDrag === 0.0) {
            $eventLore = "Generated massive carried interest fees following a series of highly successful portfolio exits.";
        } elseif (($outputGap < self::LORE_BUST_GAP_THRESHOLD && $revenueZ < self::LORE_BUST_Z_SCORE) || $lboFinancingDrag > 0.10) {
            $eventLore = "Suffered a severe deal drought as frozen credit markets prevented portfolio exits.";
        }

        // Analyst Visibility
        // Macro GDP and credit spread drags are public (~100% visible), individual exits are opaque (~20% visible).
        $analystError = $mathUtility->generateStandardNormal() * self::ANALYST_ERROR_STD_DEV;
        $dynamicVisibility = min(1.0, max(0.0, self::ANALYST_BASE_VISIBILITY + $analystError));
        $analystExpectedRevenue = max(0.0, $expectedRevenue * (1.0 + $dealFlowMultiplier - $lboFinancingDrag + ($revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR) * $dynamicVisibility)));
        $analystExpectedVariableCosts = $analystExpectedRevenue * $clampedMargin;

        return [
            'actual_revenue'                  => $actualRevenue,
            'actual_variable_costs'           => $actualVariableCosts,
            'analyst_expected_revenue'        => $analystExpectedRevenue,
            'analyst_expected_variable_costs' => $analystExpectedVariableCosts,
            'ebit'                            => $actualRevenue - $fixedCosts - $actualVariableCosts,
            'primary_shock_z'                 => $revenueZ,
            'event_lore'                      => $eventLore
        ];
    }

    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float
    {
        // Private Equity uses extreme leverage. We must cap normal buybacks to recent earnings to prevent them from hollowing out their equity base.
        return $isMegaHoarder ? $excessCash * self::MEGA_BUYBACK_CASH_SHARE : max(0.0, min($excessCash * self::STANDARD_BUYBACK_SHARE, $retainedEarningsThisQuarter));
    }

    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float
    {
        // PE firms constantly inject capital into their portfolio companies (bolt-on acquisitions, restructuring costs).
        return max($organicSpend, $debtIssued * self::DEBT_CAPEX_DEPLOYMENT);
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier): array
    {
        // Highly aggressive borrowing to fuel buyouts and portfolio injections
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
            // Private Equity holds cash to deploy into leveraged buyouts.
            // We evaluate their hoard status against their massive debt load, not their base revenue.
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

    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate): float
    {
        $equity = (float) $stock->getTotalEquity();
        $truePostTaxReturn = $equity > 0 ? ($actualTotalNetIncome / $equity) * self::ROE_ANNUALIZATION_MULT : 0.0;

        $stock->setCurrentRoe((string) max(self::MIN_ROE_CLAMP, min(self::MAX_ROE_CLAMP, $truePostTaxReturn)));

        // Private Equity earnings are extremely lumpy due to massive, infrequent deal exits (Carried Interest).
        // Use a 0.20 smoothing factor to prevent violent P/E whipsaws during deal droughts.
        $oldTtm = (float) $stock->getRoeTtm();
        $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * self::ROE_TTM_EMA_WEIGHT) + ($oldTtm * self::ROE_TTM_HIST_WEIGHT);
        $stock->setRoeTtm((string) max(self::MIN_ROE_CLAMP, min(self::MAX_ROE_CLAMP, $newTtm)));

        return $truePostTaxReturn;
    }
}

