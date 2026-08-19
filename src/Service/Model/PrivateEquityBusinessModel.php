<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Private Equity & Alternative Asset Managers.
 * 
 * Financial Physics:
 * - Base revenue comes from sticky AUM management fees.
 * - Massive volatility comes from "Carried Interest" (performance fees) and deal exits.
 * - Mark-to-Market (MTM): Principal balance sheet investments suffer brutal unrealized markdown penalties during credit freezes.
 * - Thrives during economic expansions and cheap credit (easy to IPO/sell targets).
 * - Suffers "deal droughts" during recessions when credit freezes and exits are impossible.
 */
class PrivateEquityBusinessModel extends AssetManagementBusinessModel
{
    public function getModelThresholds(): array
    {
        return ['min_icr' => 1.05, 'bankrupt_equity' => 2.0,  'distress_equity' => 4.0,  'warning_equity' => 6.0,  'wholesale_leverage_limit' => 2.5, 'dividend_crisis_icr' => 1.05, 'buyback_min_icr' => 1.15, 'reversion_speed' => 0.18, 'moat_spread' => 0.010, 'nwc_intensity' => 0.0, 'capex_completion_rate' => 1.0];
    }

    public const MIN_LEVERAGE_AGGRESSION = 0.15;
    public const MAX_LEVERAGE_AGGRESSION = 1.00;
    public const CARRY_LEVERAGE_AMPLIFIER_SCALAR = 0.30;
    public const DEBT_GATE_LBO_DRAG_THRESHOLD = 0.30;
    public const DEBT_GATE_DROUGHT_SCALAR = 0.25;
    public const DEAL_FLOW_BOOM_MULT       = 3.00;
    public const DEAL_FLOW_BUST_MULT       = 1.50;
    public const REVENUE_VARIANCE_SCALAR   = 0.15;
    public const MAX_VARIABLE_MARGIN_CLAMP = 1.50;
    public const MIN_VARIABLE_MARGIN_CLAMP = 0.01;

    public const LBO_CREDIT_SPREAD_BASELINE = 0.020;
    public const LBO_SPREAD_FREEZE_SCALAR   = 4.00;
    public const LBO_RATE_FREEZE_THRESHOLD  = 0.045;
    public const LBO_RATE_FREEZE_SCALAR     = 2.00;
    public const MIN_EFFICIENCY_RATIO       = 0.30;

    public const HURDLE_RATE_Z_CLIFF = -0.50;
    public const HURDLE_CREDIT_SPREAD_SCALAR = 10.0;
    
    // --- Principal MTM Physics ---
    /** Sensitivity of Principal balance sheet investments to macro Output Gap (Multiple Expansion/Contraction). */
    public const PRINCIPAL_MTM_MACRO_SCALAR = 2.50;
    /** Base variable margin penalty applied when illiquid portfolio companies must be marked down. */
    public const PORTFOLIO_MARKDOWN_PENALTY = 0.15;

    public const LORE_BOOM_GAP_THRESHOLD   = 0.020;
    public const LORE_BOOM_Z_SCORE         = 1.50;
    public const LORE_BUST_GAP_THRESHOLD   = -0.020;
    public const LORE_BUST_Z_SCORE         = -1.50;

    public const MEGA_BUYBACK_CASH_SHARE   = 0.30;
    public const STANDARD_BUYBACK_SHARE    = 0.10;
    public const DEBT_CAPEX_DEPLOYMENT     = 0.90;

    public const DEBT_EXPANSION_BASE_PROB  = 0.70;
    public const DEBT_EXPANSION_PROB_MULT  = 0.20;
    public const DEBT_EXPANSION_BASE_AGGR  = 0.10;
    public const DEBT_EXPANSION_AGGR_MULT  = 0.30;

    public const HOARDER_THRESHOLD         = 0.15;
    public const MEGA_HOARDER_THRESHOLD    = 0.30;
    public const TARGET_OPERATING_BUFFER   = 0.10;
    public const MIN_OPERATING_BUFFER      = 0.05;

    public const ROE_ANNUALIZATION_MULT    = 4.00;
    public const MIN_ROE_CLAMP             = -0.50;
    public const MAX_ROE_CLAMP             = 1.00;
    public const ROE_TTM_EMA_WEIGHT        = 0.20;
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

        $metrics = new \App\Service\Math\CorporateMetrics();
        $saturationPenalty = $metrics->calculateMarketSaturationPenalty($stock, max(1.0, $equity), $macroState);
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

        // Credit cycle pricing power: LBO exits freeze when credit is expensive or rates are high.
        $creditSpread = $macroState->macroCreditSpreadEma;
        $policyRate   = $macroState->policyRateEma;

        $spreadFreezeDrag = max(0.0, ($creditSpread - self::LBO_CREDIT_SPREAD_BASELINE) * self::LBO_SPREAD_FREEZE_SCALAR);
        $rateFreezeDrag   = max(0.0, ($policyRate - self::LBO_RATE_FREEZE_THRESHOLD) * self::LBO_RATE_FREEZE_SCALAR);
        $multipleCompression = max(0.0, 1.0 - ($spreadFreezeDrag + $rateFreezeDrag));

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
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);

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
        $principalZ = $principalWeight > 0.0
            ? $streams->generateZ('principal_investments', 0.20)
            : 0.0;

        // 2. GDP Deal Flow Multiplier (Affects exit realizations)
        $outputGap = $macroState->outputGapEma;
        $dealFlowMultiplier = $outputGap > 0.0 ? ($outputGap * self::DEAL_FLOW_BOOM_MULT) : ($outputGap * self::DEAL_FLOW_BUST_MULT);

        // 3. LBO Financing Freeze Drag (Credit Spread & Policy Rate)
        $creditSpread = $macroState->macroCreditSpreadEma;
        $policyRate   = $macroState->policyRateEma;

        $spreadFreezeDrag = max(0.0, ($creditSpread - self::LBO_CREDIT_SPREAD_BASELINE) * self::LBO_SPREAD_FREEZE_SCALAR);
        $rateFreezeDrag   = max(0.0, ($policyRate - self::LBO_RATE_FREEZE_THRESHOLD) * self::LBO_RATE_FREEZE_SCALAR);
        $lboFinancingDrag = $spreadFreezeDrag + $rateFreezeDrag;

        $multipleCompression = max(0.0, 1.0 - $lboFinancingDrag);

        // 4. Credit-Condition Hurdle Cliff & Carry Condition
        $hurdleRateZCliff = self::HURDLE_RATE_Z_CLIFF - ($spreadFreezeDrag * self::HURDLE_CREDIT_SPREAD_SCALAR);
        $economicCondition = $carryZ + $dealFlowMultiplier;

        $blendedMultiplier = ($mgmtWeight * 1.0) + ($carryWeight * $multipleCompression) + ($principalWeight * 1.0);
        $optimalRevenue = $blendedMultiplier > 0 ? $expectedRevenue / $blendedMultiplier : $expectedRevenue;

        // 5. Leverage Amplifier
        $actualLeverage   = (float) $stock->getTotalEquity() > 0 ? ((float) $stock->getWholesaleDebt() / (float) $stock->getTotalEquity()) : 0.0;
        $leverageAmplifier = 1.0 + ($actualLeverage * self::CARRY_LEVERAGE_AMPLIFIER_SCALAR);

        // --- Clamped Multi-Stream Revenue ---
        $mgmtRevenue = max(0.0, $optimalRevenue * $mgmtWeight * (1.0 + ($mgmtZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.5))));

        $carriedInterestRevenue = max(0.0, $optimalRevenue * $carryWeight
            * (1.0 + ($economicCondition * ($baselineVol * self::REVENUE_VARIANCE_SCALAR * 1.5) * $leverageAmplifier))
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
            // Mark-to-Market Shift: Principal investments act like a highly levered equity portfolio.
            // If the output gap is hot, they get Multiple Expansion. If it crashes, MTM takes a hit.
            $mtmShift = ($outputGap * self::PRINCIPAL_MTM_MACRO_SCALAR) - ($spreadFreezeDrag * 0.5);

            $principalInvestmentsRevenue = max(0.0, $optimalRevenue * $principalWeight
                * (1.0 + ($principalZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * 2.0) + $mtmShift));
            $streamRevenues['principal_investments'] = $principalInvestmentsRevenue;
        }

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // --- 7. Cost & Margin Physics ---
        // Rescue Capital Drag: High policy rates choke highly levered portfolio companies, requiring costly capital injections.
        $rescueCapitalDrag = max(0.0, ($policyRate - self::LBO_RATE_FREEZE_THRESHOLD) * 1.5) * $carryWeight;

        $minVariableMargin = max(0.01, self::MIN_EFFICIENCY_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));

        // --- Event Triggers & MTM Markdown Penalties ---
        $aggression = $this->calculateLeverageAggression($stock);
        $eventType = null;
        $markdownPenalty = 0.0;

        if ($missedHurdle) {
            $eventType = ShockEvent::PE_HURDLE_RATE_MISSED;
        } elseif ($multipleCompression < 0.50 && $aggression > 0.6) {
            $eventType = ShockEvent::PE_PORTFOLIO_MARKDOWN;

            // Markdown Event directly impacts the operating margin based on principal balance sheet exposure
            $markdownPenalty = self::PORTFOLIO_MARKDOWN_PENALTY * $principalWeight * (1.0 - $multipleCompression);
        } elseif ($aggression > 0.85 && $lboFinancingDrag === 0.0) {
            $eventType = ShockEvent::PE_LEVERAGE_RECAPITALIZATION;
        } elseif ($outputGap > self::LORE_BOOM_GAP_THRESHOLD && $carryZ > self::LORE_BOOM_Z_SCORE && $lboFinancingDrag === 0.0) {
            $eventType = ShockEvent::PE_CARRIED_INTEREST_SURGE;
        } elseif (($outputGap < self::LORE_BUST_GAP_THRESHOLD && $carryZ < self::LORE_BUST_Z_SCORE) || $lboFinancingDrag > 0.10) {
            $eventType = ShockEvent::PE_DEAL_DROUGHT;
        }

        // Apply rescue capital and MTM markdowns directly to the margin
        $clampedMargin = $this->clampMargin($realizedVariableMargin - $rescueCapitalDrag - $markdownPenalty, $minVariableMargin);

        $observableShockZ = $expectedRevenue > 0.0 ? (($actualRevenue - $expectedRevenue) / $expectedRevenue) : 0.0;

        $primaryShockZ = abs($carryZ) > abs($mgmtZ) ? $carryZ : $mgmtZ;
        if ($principalWeight > 0.0 && abs($principalZ) > abs($primaryShockZ)) {
            $primaryShockZ = $principalZ;
        }

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
        // Macro GDP and credit spread drags are public; individual exits are opaque (~20% visible).
        // Hostile takeovers and mega LBO announcements are highly public events.
        return new \App\DTO\SectorCoverageProfile(
            baseVisibility: 0.20,
            errorStdDev: 0.05,
            eventBaseVisibility: 0.85,
            eventMinVisibility: 0.60
        );
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

    public function getDebtExpansionAggressiveness(float $spreadMultiplier, float $totalDebt = 0.0, float $customerDeposits = 0.0, float $targetOperatingCash = 0.0, float $currentTreasury = 0.0): array
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

    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate, float $wacc = 0.08, float $costOfEquity = 0.10, ?\App\DTO\MacroStateDTO $macroState = null): float
    {
        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $thresholds = $this->getModelThresholds();
        $kappa = $thresholds['reversion_speed'] ?? 0.18;
        $moatSpread = $thresholds['moat_spread'] ?? 0.01;

        $equity = (float) $stock->getTotalEquity();
        $truePostTaxReturn = $equity > 0 ? ($actualTotalNetIncome / $equity) * self::ROE_ANNUALIZATION_MULT : 0.0;

        $stock->setCurrentRoe((string) max(self::MIN_ROE_CLAMP, min(self::MAX_ROE_CLAMP, $truePostTaxReturn)));

        // Private Equity earnings are extremely lumpy due to massive, infrequent deal exits (Carried Interest).
        // Use a 0.20 smoothing factor to prevent violent P/E whipsaws during deal droughts.
        $oldTtm = (float) $stock->getRoeTtm();
        $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * self::ROE_TTM_EMA_WEIGHT) + ($oldTtm * self::ROE_TTM_HIST_WEIGHT);
        // Scale kappa so the blended target in getTargetMetrics moves at exactly $kappa
        $scaledKappa = $kappa / self::TTM_ROE_WEIGHT;
        $math = new MathUtility();

        $saturationPenalty = 0.0;
        if ($macroState !== null) {
            $metrics = new \App\Service\Math\CorporateMetrics();
            $saturationPenalty = $metrics->calculateMarketSaturationPenalty($stock, max(1.0, $equity), $macroState);
        }

        $effectiveMoat = max(0.0, $moatSpread - $saturationPenalty);
        $newTtm += $math->calculateReversionPull($newTtm, $costOfEquity, $scaledKappa, $effectiveMoat);
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
        $equityReturn = self::BASE_EQUITY_RETURN + ($outputGap * self::EQUITY_RETURN_GAP_MULT * 1.5); // Higher beta for PE co-investments

        // PE firms deploy excess cash into highly levered sponsor commitments (20% Bonds / 80% Equities)
        return max(0.0, (0.20 * $bondReturn) + (0.80 * $equityReturn));
    }

    public function calculateInterestIncome(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): float
    {
        $operatingBase = $this->getOperatingBase($stock);
        $minCash = $this->calculateMinOperatingCash($operatingBase, 0.0, (float) $stock->getWholesaleDebt());
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - $minCash);

        $baseYield = $this->calculateCashYield($macroState);

        // Severe VIX market panic drag on illiquid PE investments
        $vixEma = $macroState->marketVolatilityEma;
        $vixDrag = max(0.0, ($vixEma - self::SEED_VIX_THRESHOLD) * (self::SEED_VIX_SENSITIVITY * 2.0));

        $effectiveYield = $baseYield - (0.80 * $vixDrag);

        return $excessCash * $effectiveYield;
    }

    public function calculateDebtExpansionCapacity(float $equity, float $totalDebt, float $wholesaleDebt, \App\DTO\DebtHealthDTO $health, float $newBorrowingRate, float $ebit, float $depreciation): float
    {
        // First get the base balance sheet and income statement constrained capacity
        $baseCapacity = parent::calculateDebtExpansionCapacity($equity, $totalDebt, $wholesaleDebt, $health, $newBorrowingRate, $ebit, $depreciation);

        // Re-calculate the credit spread / LBO drag to gate debt issuance during credit freezes
        // We can infer the macro dynamic spread directly from the health metrics raw dynamicSpread.
        // The dynamicSpread in health->rawMetrics is the firm's total credit spread (merton + macro + baseline).
        // Since we are applying PE cycle physics, we use the firm's own dynamic spread.
        $firmCreditSpread = $health->rawMetrics->dynamicSpread ?? self::LBO_CREDIT_SPREAD_BASELINE;

        $spreadFreezeDrag = max(0.0, ($firmCreditSpread - self::LBO_CREDIT_SPREAD_BASELINE) * self::LBO_SPREAD_FREEZE_SCALAR);

        if ($spreadFreezeDrag > self::DEBT_GATE_LBO_DRAG_THRESHOLD) {
            // Apply drought scalar to expansion capacity when LBO exits are frozen
            $baseCapacity *= self::DEBT_GATE_DROUGHT_SCALAR;
        }

        return $baseCapacity;
    }

    public function isUnderLeveraged(float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool
    {
        // PE firms are structurally designed to maximize leverage for LBOs.
        // They consider themselves underleveraged if they are below 85% of their equity limit
        // (compared to the 50% baseline for standard financial firms).
        // Actual debt issuance is gated by calculateDebtExpansionCapacity during credit freezes.
        $equityLimit = $this->getModelThresholds()['equity_limit'] ?? self::DEFAULT_EQUITY_LIMIT;
        return $currentDebtRatio < ($equityLimit * 0.85);
    }

    /**
     * Private equity firms actively utilize wholesale debt and subscription credit facilities
     * to fund leveraged buyouts (LBOs) and balance-sheet portfolio acquisitions.
     */
    public function supportsUnderleveragedDebtExpansion(): bool
    {
        return true;
    }
}
