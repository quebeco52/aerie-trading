<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Data\ModelParam;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Real Estate Investment Trusts (REITs).
 */
class ReitBusinessModel extends StandardCorporateBusinessModel
{
    // --- Analyst Visibility & Error ---
    /** Base analyst visibility into predictable contracted commercial real estate cash flows. */
    public const BASE_COVERAGE_VISIBILITY = 0.70;
    /** Standard deviation of Wall Street analyst error when estimating REIT revenues. */
    public const BASE_COVERAGE_ERROR = 0.10;

    public function getModelThresholds(): array
    {
        return ['min_icr' => 1.05, 'bankrupt_equity' => 10.0, 'distress_equity' => 20.0, 'warning_equity' => 30.0, 'wholesale_leverage_limit' => 2.0,  'dividend_crisis_icr' => 1.05, 'buyback_min_icr' => 1.15, 'reversion_speed' => 0.20, 'moat_spread' => 0.005, 'nwc_intensity' => 0.0, 'capex_completion_rate' => 0.125];
    }

    // --- Cap Rate & Portfolio Turnover Rails ---
    /** Fraction of property portfolio acquired/divested per quarter adjusting baseline cap rate. */
    public const PORTFOLIO_TURNOVER_RATE    = 0.025;
    /** Fallback benchmark 10-year Treasury yield when macro state yield is unavailable. */
    public const DEFAULT_10Y_YIELD_FALLBACK = 0.04;
    /** Absolute maximum cap rate clamp floor to prevent unrealistically high property yields. */
    public const MAX_CAP_RATE_CLAMP         = 0.15;
    /** Maximum spread buffer above 10-year Treasury yield allowed for market cap rates. */
    public const CAP_RATE_CEILING_SPREAD    = 0.12;
    /** Default annual property depreciation rate for real estate asset write-offs. */
    public const DEFAULT_DEPRECIATION_RATE  = 0.05;

    // --- EBIT Yield & ROIC Blending ---
    /** Weight of target net operating income yield when blending with historical ROIC. */
    public const TARGET_EBIT_WEIGHT         = 0.50;
    /** Weight of trailing twelve month ROIC when blending with target EBIT yield. */
    public const TTM_ROIC_WEIGHT            = 0.50;
    /** Annualization multiplier applied to quarterly Net Operating Income / Invested Capital. */
    public const ROIC_ANNUALIZATION_MULT    = 4.00;
    /** Minimum allowable ROIC floor for distressed real estate portfolios. */
    public const MIN_ROIC_CLAMP             = -0.50;
    /** Maximum allowable ROIC ceiling to prevent unrealistic runaway property yields. */
    public const MAX_ROIC_CLAMP             = 1.00;
    /** Weight given to current quarter NOI return when updating ROIC EMA. */
    public const ROIC_TTM_EMA_WEIGHT        = 0.25;
    /** Weight given to historical trailing twelve month ROIC when updating ROIC EMA. */
    public const ROIC_TTM_HIST_WEIGHT       = 0.75;

    // --- Revenue & Vacancy Shock Physics ---
    /** Base volatility scalar applied to sticky commercial lease revenues. */
    public const REVENUE_VARIANCE_SCALAR        = 0.05;
    /** Volatility multiplier for daily-rate hospitality and hotel revenues. */
    public const HOSPITALITY_VARIANCE_SCALAR    = 1.50;
    /** Volatility multiplier for securitized mortgage and debt packaging income. */
    public const SECURITIZATION_VARIANCE_SCALAR = 2.00;
    /** Volatility multiplier for longevity-linked bond yields and pension assets. */
    public const LONGEVITY_VARIANCE_SCALAR      = 0.25;

    /** Fraction of excess CPI inflation captured via contractual rent escalators. */
    public const RENT_ESCALATOR_CAPTURE         = 0.80;
    /** Z-score threshold below which elevated tenant defaults and vacancies trigger margin penalties. */
    public const VACANCY_Z_THRESHOLD            = -1.50;
    /** Scalar applied to tenant default Z-score severity to determine vacancy margin loss. */
    public const VACANCY_LOSS_SCALAR            = 0.08;
    /** Z-score floor above which strong leasing demand generates operational efficiency bonuses. */
    public const BENIGN_LEASING_Z_FLOOR         = 1.00;
    /** Scalar applied to leasing bonus Z-score above floor. */
    public const LEASING_BONUS_SCALE            = 0.015;
    /** Sensitivity of market cap rates to macroeconomic credit spread fluctuations. */
    public const CAP_RATE_SPREAD_SENSITIVITY    = 1.20;
    /** Operating margin drag per 100bps of 10-year Treasury yield above default fallback. */
    public const REFINANCING_WALL_DRAG          = 0.25;
    /** Minimum operating efficiency ratio (operating revenue / fixed costs) floor. */
    public const MIN_EFFICIENCY_RATIO           = 0.35;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP      = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP      = 0.01;

    // --- Event Lore Thresholds ---
    /** Severe tenant default Z-score triggering catastrophic commercial bankruptcy lore. */
    public const LORE_ANCHOR_BANKRUPTCY_Z   = -2.00;
    /** Moderate vacancy Z-score triggering elevated lease vacancy lore. */
    public const LORE_ELEVATED_VACANCY_Z    = -1.50;

    // --- REIT Reversion & Valuation ---
    /** IRC Section 857 corporate tax rate exemption for qualifying pass-through REITs. */
    public const PASS_THROUGH_TAX_RATE      = 0.00;
    /** Fallback positive interest coverage ratio when interest expense is zero. */
    public const INFINITE_ICR_POS_FALLBACK  = 999.0;
    /** Fallback negative interest coverage ratio when operating income and interest expense are zero/negative. */
    public const INFINITE_ICR_NEG_FALLBACK  = -999.0;

    // --- Capital Reinvestment & Asset Depreciation Physics ---
    /** Annual margin decay rate when capital reinvestment falls below depreciation maintenance. */
    public const DEPRECIATION_DECAY_RATE      = 0.015;
    /** Annual margin gain rate from property modernization when reinvestment exceeds depreciation. */
    public const MODERNIZATION_GAIN_RATE      = 0.008;
    /** Minimum structural operating margin floor after extended asset degradation. */
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.05;
    /** Maximum structural operating margin ceiling achievable through property modernization. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.45;

    // --- Aggressive Property Acquisition Borrowing ---
    /** Base probability of REIT expanding balance sheet debt to fund property acquisitions. */
    public const DEBT_EXPANSION_BASE_PROB   = 0.80;
    /** Sensitivity of debt expansion probability to favorable yield spread multiplier. */
    public const DEBT_EXPANSION_PROB_MULT   = 0.20;
    /** Base fraction of debt capacity utilized during property acquisition cycle. */
    public const DEBT_EXPANSION_BASE_AGGR   = 0.15;
    /** Sensitivity of debt expansion utilization to positive yield spreads. */
    public const DEBT_EXPANSION_AGGR_MULT   = 0.35;

    // --- Valuation & Lease Resistance Moat ---
    /** Weight given to earnings capitalization in REIT intrinsic fair value blending. */
    public const FAIR_VALUE_EARNINGS_WEIGHT = 0.30;
    /** Weight given to net asset value (P/B) in REIT intrinsic fair value blending. */
    public const FAIR_VALUE_BOOK_WEIGHT     = 0.40;
    /** Weight given to dividend discount model (DDM) in REIT intrinsic fair value blending. */
    public const FAIR_VALUE_DDM_WEIGHT      = 0.30;
    /** Half-life speed (quarters) at which long-term lease margins revert toward sector equilibrium. */
    public const LEASE_REVERSION_SPEED      = 2.0;

    public function getTargetMetrics(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): array
    {
        $investedCapital = $stock->getInvestedCapital();
        $baselineRoic = max(0.01, (float) $stock->getBaselineRoic());

        $yield10y = $macroState->yield10yEma;
        $realEstateRiskPremium = $macroState->equityRiskPremium;
        $targetCapRate = $yield10y + $realEstateRiskPremium;

        $portfolioTurnoverRate = self::PORTFOLIO_TURNOVER_RATE;
        $maxCapRate = max(self::MAX_CAP_RATE_CLAMP, $macroState->yield10yEma + self::CAP_RATE_CEILING_SPREAD);
        $blendedCapRate = min($maxCapRate, ($baselineRoic * (1.0 - $portfolioTurnoverRate)) + ($targetCapRate * $portfolioTurnoverRate));
        $stock->setBaselineRoic((string) max(0.01, $blendedCapRate));

        $industry = $stock->getIndustry() ?: 'General';
        $customDepreciation = (float) $stock->getDepreciationRate();
        $depreciationRate = $customDepreciation > 0.0 ? $customDepreciation : (\App\Data\Sectors::INDUSTRY_METRICS[$industry]['depreciation'] ?? self::DEFAULT_DEPRECIATION_RATE);

        $targetEbitYield = max(0.01, $blendedCapRate - $depreciationRate);

        $ttmRoic = (float) $stock->getRoicTtm();
        if ($ttmRoic !== 0.0) {
            $targetEbitYield = ($targetEbitYield * self::TARGET_EBIT_WEIGHT) + ($ttmRoic * self::TTM_ROIC_WEIGHT);
        }

        $metrics = new \App\Service\Math\CorporateMetrics();
        $saturationPenalty = $metrics->calculateMarketSaturationPenalty($stock, $investedCapital, $macroState);
        $waccBase = $macroState->policyRate + $macroState->equityRiskPremium;
        $effectiveRoic = max($waccBase, $targetEbitYield - $saturationPenalty);

        return [
            'invested_capital' => $investedCapital,
            'baseline_roic' => $effectiveRoic
        ];
    }

    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        $physics['pricing_power_multiplier'] = 1.0;
        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::StickyLeaseWeight->value            => 0.85,
            ModelParam::VariableHospitalityWeight->value    => 0.15,
            ModelParam::SecuritizationIncomeWeight->value   => 0.00,
            ModelParam::LongevityBondYieldWeight->value     => 0.00,
        ]);

        $rawSecuritizationWeight = $params[ModelParam::SecuritizationIncomeWeight];
        $rawLongevityWeight      = $params[ModelParam::LongevityBondYieldWeight];

        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = new \App\DTO\StreamContext($momentum, $mathUtility);

        $targetWeights = [
            'lease'       => $params[ModelParam::StickyLeaseWeight],
            'hospitality' => $params[ModelParam::VariableHospitalityWeight],
        ];
        if ($rawSecuritizationWeight > 0.0) {
            $targetWeights['securitization_income'] = $rawSecuritizationWeight;
        }
        if ($rawLongevityWeight > 0.0) {
            $targetWeights['longevity_bond_yield'] = $rawLongevityWeight;
        }

        // --- Dynamic Revenue Mix Drift with Strategic Mean Reversion ---
        $activeWeights = $streams->resolveActiveStreamWeights($targetWeights);

        $leaseWeight          = $activeWeights['lease'];
        $hospitalityWeight    = $activeWeights['hospitality'];
        $securitizationWeight = $activeWeights['securitization_income'] ?? 0.0;
        $longevityWeight      = $activeWeights['longevity_bond_yield'] ?? 0.0;

        $revenueZ     = $streams->generateZ('lease', 0.25);
        $hospitalityZ = $streams->generateZ('hospitality', 0.25);

        // Core Revenue Shocks
        $leaseShock       = $revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);
        $hospitalityShock = $hospitalityZ * ($baselineVol * self::HOSPITALITY_VARIANCE_SCALAR);

        // The Inflation Hedge (CPI Rent Escalators)
        $inflation = $macroState->inflationEma;
        $excessInflation = max(0.0, $inflation - MacroEngine::TARGET_INFLATION);
        $rentEscalator = $excessInflation * self::RENT_ESCALATOR_CAPTURE;

        // --- Clamped Revenue Streams ---
        $leaseRevenue       = max(0.0, $expectedRevenue * $leaseWeight * (1.0 + $leaseShock + $rentEscalator));
        $hospitalityRevenue = max(0.0, $expectedRevenue * $hospitalityWeight * (1.0 + $hospitalityShock));

        $streamRevenues = [
            'lease'       => $leaseRevenue,
            'hospitality' => $hospitalityRevenue,
        ];

        $securitizationRevenue = 0.0;
        $securitizationZ       = 0.0;
        $securitizationShock   = 0.0;
        if ($securitizationWeight > 0.0) {
            $securitizationZ       = $streams->generateZ('securitization_income', 0.40);
            $securitizationShock   = $securitizationZ * $baselineVol * self::SECURITIZATION_VARIANCE_SCALAR;
            $securitizationRevenue = max(0.0, $expectedRevenue * $securitizationWeight * (1.0 + $securitizationShock));
            $streamRevenues['securitization_income'] = $securitizationRevenue;
        }

        $longevityRevenue = 0.0;
        $longevityZ       = 0.0;
        $longevityShock   = 0.0;
        if ($longevityWeight > 0.0) {
            $longevityZ       = $streams->generateZ('longevity_bond_yield', 0.60);
            $longevityShock   = $longevityZ * $baselineVol * self::LONGEVITY_VARIANCE_SCALAR;
            $longevityRevenue = max(0.0, $expectedRevenue * $longevityWeight * (1.0 + $longevityShock));
            $streamRevenues['longevity_bond_yield'] = $longevityRevenue;
        }

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        // --- Margin Penalties ---
        $tenantDefaultZ = $streams->generateZ('tenant_default', 0.20);
        $vacancyShock = $tenantDefaultZ < self::VACANCY_Z_THRESHOLD
            ? abs($tenantDefaultZ) * self::VACANCY_LOSS_SCALAR
            : ($tenantDefaultZ > self::BENIGN_LEASING_Z_FLOOR
                ? - ($tenantDefaultZ - self::BENIGN_LEASING_Z_FLOOR) * self::LEASING_BONUS_SCALE
                : 0.0);

        $yield10y = $macroState->yield10yEma;
        $refinancingDrag = max(0.0, ($yield10y - self::DEFAULT_10Y_YIELD_FALLBACK) * self::REFINANCING_WALL_DRAG);

        $minVariableMargin = max(0.01, self::MIN_EFFICIENCY_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));
        $clampedMargin = $this->clampMargin($realizedVariableMargin + $vacancyShock + $refinancingDrag, $minVariableMargin);

        $eventType = null;
        if ($tenantDefaultZ < self::LORE_ANCHOR_BANKRUPTCY_Z) {
            $eventType = ShockEvent::REIT_TENANT_BANKRUPTCIES;
        } elseif ($tenantDefaultZ < self::LORE_ELEVATED_VACANCY_Z) {
            $eventType = ShockEvent::REIT_ELEVATED_VACANCIES;
        }

        // Aggregate observable revenue shock percentage across active streams (MarketConsensusEngine applies coverage visibility)
        $observableShockZ = 
            (($leaseShock + $rentEscalator) * $leaseWeight) +
            ($hospitalityShock * $hospitalityWeight) +
            ($securitizationShock * $securitizationWeight) +
            ($longevityShock * $longevityWeight);

        // Determine primary shock Z across dominant stream or tenant default
        $primaryShockZ = $revenueZ;
        $maxAbsZ = abs($revenueZ);

        if (abs($hospitalityZ) > $maxAbsZ) {
            $maxAbsZ = abs($hospitalityZ);
            $primaryShockZ = $hospitalityZ;
        }
        if ($securitizationWeight > 0.0 && abs($securitizationZ) > $maxAbsZ) {
            $maxAbsZ = abs($securitizationZ);
            $primaryShockZ = $securitizationZ;
        }
        if ($longevityWeight > 0.0 && abs($longevityZ) > $maxAbsZ) {
            $maxAbsZ = abs($longevityZ);
            $primaryShockZ = $longevityZ;
        }
        if (abs($tenantDefaultZ) > $maxAbsZ) {
            $primaryShockZ = $tenantDefaultZ;
        }

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: $primaryShockZ,
            observableShockZ: $observableShockZ,
            eventType: $eventType,
            isPublicEvent: $eventType !== null ? true : null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate, float $wacc = 0.08, float $costOfEquity = 0.10, ?\App\DTO\MacroStateDTO $macroState = null): float
    {
        $industry = $stock->getIndustry() ?: 'General';
        $thresholds = $this->getModelThresholds();
        $kappa = $thresholds['reversion_speed'] ?? 0.20;
        $moatSpread = $thresholds['moat_spread'] ?? 0.005;

        $customDepreciation = (float) $stock->getDepreciationRate();
        $depreciationRate = $customDepreciation > 0.0 ? $customDepreciation : (\App\Data\Sectors::INDUSTRY_METRICS[$industry]['depreciation'] ?? self::DEFAULT_DEPRECIATION_RATE);

        $absoluteDepreciation = $investedCapital * $depreciationRate;
        $quarterlyDepreciation = $absoluteDepreciation / 4.0;

        $noi = $ebit + $quarterlyDepreciation;

        $truePostTaxReturn = $investedCapital > 0 ? ($noi / $investedCapital) * self::ROIC_ANNUALIZATION_MULT : 0.0;

        $stock->setCurrentRoic((string) max(self::MIN_ROIC_CLAMP, min(self::MAX_ROIC_CLAMP, $truePostTaxReturn)));

        $oldTtm = (float) $stock->getRoicTtm();
        $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * self::ROIC_TTM_EMA_WEIGHT) + ($oldTtm * self::ROIC_TTM_HIST_WEIGHT);
        $scaledKappa = $kappa / self::TTM_ROIC_WEIGHT;
        $math = new MathUtility();

        $saturationPenalty = 0.0;
        if ($macroState !== null) {
            $metrics = new \App\Service\Math\CorporateMetrics();
            $saturationPenalty = $metrics->calculateMarketSaturationPenalty($stock, abs($investedCapital), $macroState);
        }

        $effectiveMoat = max(0.0, $moatSpread - $saturationPenalty);
        $newTtm += $math->calculateReversionPull($newTtm, $wacc, $scaledKappa, $effectiveMoat);
        $stock->setRoicTtm((string) max(self::MIN_ROIC_CLAMP, min(self::MAX_ROIC_CLAMP, $newTtm)));

        return $truePostTaxReturn;
    }

    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        return self::PASS_THROUGH_TAX_RATE;
    }

    public function calculateEconomicReturn(Stock $stock, float $quarterlyNopat, float $investedCapital): float
    {
        $industry = $stock->getIndustry() ?: 'General';
        $customDepreciation = (float) $stock->getDepreciationRate();
        $depreciationRate = $customDepreciation > 0.0 ? $customDepreciation : (\App\Data\Sectors::INDUSTRY_METRICS[$industry]['depreciation'] ?? self::DEFAULT_DEPRECIATION_RATE);

        $absoluteDepreciation = $investedCapital * $depreciationRate;
        $quarterlyDepreciation = $absoluteDepreciation / 4.0;
        $noi = $quarterlyNopat + $quarterlyDepreciation;

        return $investedCapital > 0 ? ($noi / $investedCapital) * self::ROIC_ANNUALIZATION_MULT : 0.0;
    }

    public function getInterestCoverage(float $ebit, float $interestExpense, float $depreciation = 0.0, float $interestIncome = 0.0): float
    {
        // FIX: Added $interestIncome to prevent distressed ICR flags when holding massive M&A cash hoards.
        $ffo = $ebit + $depreciation + $interestIncome;
        return $interestExpense > 0 ? ($ffo / $interestExpense) : ($ffo > 0 ? self::INFINITE_ICR_POS_FALLBACK : self::INFINITE_ICR_NEG_FALLBACK);
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier, float $totalDebt = 0.0, float $customerDeposits = 0.0, float $targetOperatingCash = 0.0, float $currentTreasury = 0.0): array
    {
        return [
            'probability' => self::DEBT_EXPANSION_BASE_PROB + ($spreadMultiplier * self::DEBT_EXPANSION_PROB_MULT),
            'aggressiveness' => self::DEBT_EXPANSION_BASE_AGGR + (self::DEBT_EXPANSION_AGGR_MULT * $spreadMultiplier)
        ];
    }

    public function calculateDebtExpansionCapacity(float $equity, float $totalDebt, float $wholesaleDebt, \App\DTO\DebtHealthDTO $health, float $newBorrowingRate, float $ebit, float $depreciation): float
    {
        $evalDebt = $totalDebt;
        $evalTolerance = $health->debtTolerance;
        $balanceSheetCapacity = max(0.0, ($equity * $evalTolerance) - $evalDebt);

        $minimumIcr = ($this->getModelThresholds()['buyback_min_icr'] ?? 3.0) + 0.5;

        $operatingIncome = $ebit + $depreciation;

        $maxTolerableInterest = max(0.0, $operatingIncome / $minimumIcr);
        $currentInterestExpense = $health->rawMetrics->interestExpense ?? 0.0;
        $availableInterestCapacity = max(0.0, $maxTolerableInterest - $currentInterestExpense);
        $incomeStatementCapacity = $newBorrowingRate > 0 ? ($availableInterestCapacity / $newBorrowingRate) : 0.0;

        return min($incomeStatementCapacity, $balanceSheetCapacity);
    }

    public function getMaxFloatingDebtRatio(): float
    {
        return 0.50;
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        if ($dividendSupportValue > 0.0) {
            return ($pbFairValue * self::FAIR_VALUE_BOOK_WEIGHT) + ($earningsValue * self::FAIR_VALUE_EARNINGS_WEIGHT) + ($dividendSupportValue * self::FAIR_VALUE_DDM_WEIGHT);
        }
        return ($pbFairValue * 0.40) + ($earningsValue * 0.60);
    }

    public function getSustainableDividendBase(Stock $stock, float $quarterlyEps, float $investedCapital, float $depRate): float
    {
        $shares = max(1.0, (float) $stock->getSharesOutstanding());
        $quarterlyDepreciationPerShare = (($investedCapital * $depRate) / $shares) / 4.0;
        return $quarterlyEps + $quarterlyDepreciationPerShare;
    }

    public function getMarginReversionSpeed(): float
    {
        return self::LEASE_REVERSION_SPEED;
    }

    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void
    {
        $timeScale = $dt / 0.25;
        $currentMargin = (float) $stock->getOperatingMargin();

        if ($reinvestmentRatio < 1.0) {
            $decayRate = self::DEPRECIATION_DECAY_RATE * (1.0 - $reinvestmentRatio) * $timeScale;
            $updatedMargin = max(self::MIN_OPERATING_MARGIN_FLOOR, $currentMargin - ($currentMargin * $decayRate));
            $stock->setOperatingMargin((string) $updatedMargin);
        } elseif ($reinvestmentRatio > 1.0) {
            $modGain = self::MODERNIZATION_GAIN_RATE * log($reinvestmentRatio) * $timeScale;
            $updatedMargin = min(
                self::MAX_OPERATING_MARGIN_CEILING,
                $currentMargin + ((self::MAX_OPERATING_MARGIN_CEILING - $currentMargin) * $modGain)
            );
            $stock->setOperatingMargin((string) $updatedMargin);
        }
    }
    public function requiresAlternativeZScore(): bool
    {
        return true;
    }
}
