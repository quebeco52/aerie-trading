<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;

/**
 * Earnings strategy for Real Estate Investment Trusts (REITs).
 * 
 * Financial Physics:
 * - Evaluated on Funds From Operations (FFO) rather than standard Net Income.
 * - Zero entity-level corporate tax (pass-through entity).
 * - Highly stable, recurring revenue from long-term leases.
 * - Target yields (Cap Rates) loosely track the 10-year Treasury yield.
 */
class ReitBusinessModel extends StandardCorporateBusinessModel
{
    // --- Cap Rate & Portfolio Turnover Rails ---
    /** Quarterly portfolio turnover rate reflecting 7 to 10 year commercial leases. */
    public const PORTFOLIO_TURNOVER_RATE    = 0.025;
    /** Default 10Y Treasury yield fallback when macroeconomic state data is missing. */
    public const DEFAULT_10Y_YIELD_FALLBACK = 0.04;
    /** Maximum allowable cap rate ceiling to prevent unrealistic property devaluation. */
    public const MAX_CAP_RATE_CLAMP         = 0.15;
    /** Spread over 10Y Treasury yield used to define the upper cap rate boundary. */
    public const CAP_RATE_CEILING_SPREAD    = 0.12;
    /** Default property depreciation rate fallback if sector configuration is absent. */
    public const DEFAULT_DEPRECIATION_RATE  = 0.05;

    // --- EBIT Yield & ROIC Blending ---
    /** Weight given to historical target EBIT yield when blending with TTM ROIC. */
    public const TARGET_EBIT_WEIGHT         = 0.70;
    /** Weight given to TTM ROIC when blending with historical target EBIT yield. */
    public const TTM_ROIC_WEIGHT            = 0.30;
    /** Annualization multiplier applied to quarterly Net Operating Income (NOI). */
    public const ROIC_ANNUALIZATION_MULT    = 4.00;
    /** Minimum allowable ROIC floor to prevent catastrophic negative overflow. */
    public const MIN_ROIC_CLAMP             = -0.50;
    /** Maximum allowable ROIC ceiling to prevent unrealistic hyperinflation. */
    public const MAX_ROIC_CLAMP             = 1.00;
    /** Weight given to current quarter ROIC when updating trailing twelve-month ROIC EMA. */
    public const ROIC_TTM_EMA_WEIGHT        = 0.25;
    /** Weight given to historical trailing twelve-month ROIC when updating ROIC EMA. */
    public const ROIC_TTM_HIST_WEIGHT       = 0.75;

    // --- Revenue & Vacancy Shock Physics ---
    /** Volatility multiplier for top-line revenue shocks in stable multi-year lease models. */
    public const REVENUE_VARIANCE_SCALAR    = 0.05;
    /** Fraction of excess CPI inflation captured directly into revenue via automatic rent escalators. */
    public const RENT_ESCALATOR_CAPTURE     = 0.80;
    /** Tenant default z-score threshold triggering severe commercial vacancy penalties. */
    public const VACANCY_Z_THRESHOLD        = -1.50;
    /** Variable cost penalty multiplier applied during severe anchor tenant defaults. */
    public const VACANCY_LOSS_SCALAR        = 0.08;
    /** Benign leasing environment z-score threshold triggering minor margin bonuses. */
    public const BENIGN_LEASING_Z_FLOOR     = 1.00;
    /** Sensitivity scale for variable cost reduction during exceptionally strong occupancy environments. */
    public const LEASING_BONUS_SCALE        = 0.015;
    /** Sensitivity of property yield and cap rates to 10Y Treasury yields above baseline. */
    public const CAP_RATE_SPREAD_SENSITIVITY = 1.20;
    /** Variable margin penalty scaling with refinancing headwinds on maturing commercial property debt. */
    public const REFINANCING_WALL_DRAG      = 0.25;
    /** Structural minimum operating cost-to-revenue ratio reflecting property maintenance and leasing commissions. */
    public const MIN_EFFICIENCY_RATIO       = 0.35;
    /** Upper clamp for realized variable margin. */
    public const MAX_VARIABLE_MARGIN_CLAMP  = 1.50;
    /** Lower clamp for realized variable margin. */
    public const MIN_VARIABLE_MARGIN_CLAMP  = 0.01;

    // --- Event Lore Thresholds ---
    /** Severe vacancy z-score threshold indicating anchor tenant bankruptcies and commercial lease defaults. */
    public const LORE_ANCHOR_BANKRUPTCY_Z   = -2.00;
    /** Severe vacancy z-score threshold indicating elevated commercial vacancies and unpaid rent. */
    public const LORE_ELEVATED_VACANCY_Z    = -1.50;

    // --- Analyst Visibility & Error ---
    /** Base analyst visibility into tenant vacancies and lease renewals prior to quarterly earnings. */
    public const ANALYST_BASE_VISIBILITY    = 0.70;
    /** Standard deviation of analyst estimation error for quarterly tenant occupancy and default rates. */
    public const ANALYST_ERROR_STD_DEV      = 0.10;

    // --- Tax & Interest Coverage Rails ---
    /** Effective entity-level corporate tax rate for pass-through Real Estate Investment Trusts. */
    public const PASS_THROUGH_TAX_RATE      = 0.00;
    /** Infinite positive interest coverage fallback when interest expense is zero. */
    public const INFINITE_ICR_POS_FALLBACK  = 999.0;
    /** Infinite negative interest coverage fallback when interest expense is zero and FFO is negative. */
    public const INFINITE_ICR_NEG_FALLBACK  = -999.0;

    // --- Capital Reinvestment & Asset Depreciation Physics ---
    /** Quarterly efficiency decay rate per unit of underinvestment below replacement CapEx. */
    public const DEPRECIATION_DECAY_RATE      = 0.015;
    /** Quarterly efficiency gain scalar per unit of logarithmic overinvestment above replacement CapEx. */
    public const MODERNIZATION_GAIN_RATE      = 0.008;
    /** Structural minimum NOI operating margin floor under deferred property maintenance. */
    public const MIN_OPERATING_MARGIN_FLOOR   = 0.05;
    /** Structural maximum NOI operating margin ceiling for fully modernized Class-A real estate properties. */
    public const MAX_OPERATING_MARGIN_CEILING = 0.45;

    // --- Aggressive Property Acquisition Borrowing ---
    /** Baseline probability of initiating debt expansion to acquire new commercial properties. */
    public const DEBT_EXPANSION_BASE_PROB   = 0.80;
    /** Multiplier scaling debt expansion probability with spread attractiveness. */
    public const DEBT_EXPANSION_PROB_MULT   = 0.20;
    /** Baseline aggressiveness fraction for new debt issuance in property acquisition models. */
    public const DEBT_EXPANSION_BASE_AGGR   = 0.15;
    /** Multiplier scaling debt issuance aggressiveness with spread attractiveness. */
    public const DEBT_EXPANSION_AGGR_MULT   = 0.35;

    // --- Valuation & Lease Resistance Moat ---
    /** Weight given to capitalized earnings (FFO) in fair value calculations. */
    public const FAIR_VALUE_EARNINGS_WEIGHT = 0.60;
    /** Weight given to property Net Asset Value (NAV / Book Value) in fair value calculations. */
    public const FAIR_VALUE_BOOK_WEIGHT     = 0.40;
    /** Operating margin mean reversion speed: slower speed reflects multi-year commercial leases. */
    public const LEASE_REVERSION_SPEED      = 2.0;

    /**
     * Real Estate Cap Rates are deeply tied to the 10-Year Treasury Yield.
     * As rates rise, property values effectively drop, demanding a higher yield.
     */
    public function getTargetMetrics(Stock $stock, array &$macroState, MathUtility $mathUtility): array
    {
        $investedCapital = $stock->getInvestedCapital();
        $baselineRoic = max(0.01, (float) $stock->getBaselineRoic());

        // Real Estate Cap Rates are deeply tied to the 10-Year Treasury Yield plus a risk premium.
        $yield10y = $macroState['yield_10y_ema'] ?? ($macroState['yield_10y'] ?? self::DEFAULT_10Y_YIELD_FALLBACK);
        $realEstateRiskPremium = $macroState['equity_risk_premium'] ?? MacroEngine::BASE_EQUITY_RISK_PREMIUM;
        $targetCapRate = $yield10y + $realEstateRiskPremium;

        // Baseline DNA change over time, but at a realistic physical rate.
        // Commercial leases (Office, Healthcare) are typically 7 to 10 years long.
        // This means a REIT only turns over about 2.5% of its portfolio per quarter.
        // If they do a bad M&A, they will be punished for YEARS before leases expire and reset to market rates!
        $portfolioTurnoverRate = self::PORTFOLIO_TURNOVER_RATE;
        $maxCapRate = max(self::MAX_CAP_RATE_CLAMP, ($macroState['yield_10y_ema'] ?? self::DEFAULT_10Y_YIELD_FALLBACK) + self::CAP_RATE_CEILING_SPREAD);
        $blendedCapRate = min($maxCapRate, ($baselineRoic * (1.0 - $portfolioTurnoverRate)) + ($targetCapRate * $portfolioTurnoverRate));
        $stock->setBaselineRoic((string) max(0.01, $blendedCapRate));

        // Cap Rate represents NOI (Net Operating Income) yield.
        // However, the EarningsEngine targets EBIT (Earnings Before Interest & Taxes).
        // Since EBIT = NOI - Depreciation, we MUST subtract depreciation from the target 
        // to prevent REITs from mathematically double-counting depreciation and printing infinite FFO.
        $industry = $stock->getIndustry() ?: 'General';
        $customDepreciation = (float) $stock->getDepreciationRate();
        $depreciationRate = $customDepreciation > 0.0 ? $customDepreciation : (\App\Data\Sectors::INDUSTRY_METRICS[$industry]['depreciation'] ?? self::DEFAULT_DEPRECIATION_RATE);

        $targetEbitYield = max(0.01, $blendedCapRate - $depreciationRate);

        $ttmRoic = (float) $stock->getRoicTtm();
        if ($ttmRoic !== 0.0) {
            $targetEbitYield = ($targetEbitYield * self::TARGET_EBIT_WEIGHT) + ($ttmRoic * self::TTM_ROIC_WEIGHT);
        }

        return [
            'invested_capital' => $investedCapital,
            'baseline_roic' => $targetEbitYield
        ];
    }

    /**
     * REIT revenues are incredibly stable due to multi-year binding leases.
     */
    public function getMacroPhysics(Stock $stock, array &$macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        // CPI Rent Escalators perfectly capture inflation dynamically.
        // We strip generic pricing power to prevent double-dipping.
        $physics['pricing_power_multiplier'] = 1.0;
        return $physics;
    }

    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();

        $params = $this->resolveModelParameters($stock, [
            'sticky_lease_weight'         => 0.85,
            'variable_hospitality_weight' => 0.15,
        ]);
        $leaseWeight      = $params['sticky_lease_weight'];
        $hospitalityWeight = $params['variable_hospitality_weight'];

        // REIT sticky lease revenues are incredibly stable due to multi-year binding contracts
        $leaseShock = $revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);
        // Variable hospitality/parking revenues experience full cyclical variance
        $hospitalityShock = $revenueZ * ($baselineVol * 1.5);

        // The Inflation Hedge (CPI Rent Escalators):
        $inflation = $macroState['inflation_ema'] ?? MacroEngine::TARGET_INFLATION;
        $excessInflation = max(0.0, $inflation - MacroEngine::TARGET_INFLATION);
        $rentEscalator = $excessInflation * self::RENT_ESCALATOR_CAPTURE;

        $leaseRevenue        = $expectedRevenue * $leaseWeight * (1.0 + $leaseShock + $rentEscalator);
        $hospitalityRevenue  = $expectedRevenue * $hospitalityWeight * (1.0 + $hospitalityShock);
        $actualRevenue       = max(0.0, $leaseRevenue + $hospitalityRevenue);

        // The Tenant Default Shock (Vacancy) & Refinancing Wall:
        // Deep recessions cause anchor tenants to break leases.
        // Higher 10Y Treasury yields increase property cap rates and debt refinancing drag.
        $tenantDefaultZ = $mathUtility->generateStandardNormal();
        $vacancyShock = $tenantDefaultZ < self::VACANCY_Z_THRESHOLD
            ? abs($tenantDefaultZ) * self::VACANCY_LOSS_SCALAR
            : ($tenantDefaultZ > self::BENIGN_LEASING_Z_FLOOR
                ? -($tenantDefaultZ - self::BENIGN_LEASING_Z_FLOOR) * self::LEASING_BONUS_SCALE
                : 0.0);

        $yield10y = $macroState['yield_10y_ema'] ?? ($macroState['yield_10y'] ?? self::DEFAULT_10Y_YIELD_FALLBACK);
        $refinancingDrag = max(0.0, ($yield10y - self::DEFAULT_10Y_YIELD_FALLBACK) * self::REFINANCING_WALL_DRAG);

        $minVariableMargin = max(0.01, self::MIN_EFFICIENCY_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));
        $clampedMargin = min(self::MAX_VARIABLE_MARGIN_CLAMP, max($minVariableMargin, $realizedVariableMargin + $vacancyShock + $refinancingDrag));
        $actualVariableCosts = $actualRevenue * $clampedMargin;
        $ebit = $actualRevenue - $fixedCosts - $actualVariableCosts;

        $eventLore = null;
        if ($tenantDefaultZ < self::LORE_ANCHOR_BANKRUPTCY_Z) {
            $eventLore = "Suffered a sudden wave of anchor tenant bankruptcies and commercial lease defaults.";
        } elseif ($tenantDefaultZ < self::LORE_ELEVATED_VACANCY_Z) {
            $eventLore = "Elevated commercial vacancies and unpaid rent impacted quarterly NOI.";
        }

        // Analyst Visibility
        // Rent escalators (inflation) and 10Y Treasury yields are 100% visible. Tenant vacancies are partially public (~70% visibility).
        $analystExpectedRevenue = $expectedRevenue * (1.0 + $rentEscalator);
        $analystError = $mathUtility->generateStandardNormal() * self::ANALYST_ERROR_STD_DEV;
        $dynamicVisibility = min(1.0, max(0.0, self::ANALYST_BASE_VISIBILITY + $analystError));
        $expectedVacancyShock = $vacancyShock * $dynamicVisibility;
        $analystExpectedVariableCosts = $analystExpectedRevenue * min(self::MAX_VARIABLE_MARGIN_CLAMP, max($minVariableMargin, $realizedVariableMargin + $expectedVacancyShock + $refinancingDrag));

        return [
            'actual_revenue'                  => $actualRevenue,
            'actual_variable_costs'           => $actualVariableCosts,
            'analyst_expected_revenue'        => $analystExpectedRevenue,
            'analyst_expected_variable_costs' => $analystExpectedVariableCosts,
            'ebit'                            => $ebit,
            'primary_shock_z'                 => abs($tenantDefaultZ) > abs($revenueZ) ? $tenantDefaultZ : $revenueZ,
            'event_lore'                      => $eventLore
        ];
    }

    /**
     * Wall Street evaluates REITs on FFO (Funds From Operations), not GAAP Net Income.
     * FFO = Net Income + Depreciation (since real estate generally appreciates, depreciation is an accounting fiction).
     */
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate): float
    {
        $industry = $stock->getIndustry() ?: 'General';
        $customDepreciation = (float) $stock->getDepreciationRate();
        $depreciationRate = $customDepreciation > 0.0 ? $customDepreciation : (\App\Data\Sectors::INDUSTRY_METRICS[$industry]['depreciation'] ?? self::DEFAULT_DEPRECIATION_RATE);

        $absoluteDepreciation = $investedCapital * $depreciationRate;
        $quarterlyDepreciation = $absoluteDepreciation / 4.0;

        // Funds From Operations (FFO) / Net Operating Income (NOI):
        // Because real estate appreciates, GAAP depreciation is an accounting fiction.
        // We add it back to EBIT to calculate the true cash yield (Cap Rate) of the properties.
        $noi = $ebit + $quarterlyDepreciation;

        $truePostTaxReturn = $investedCapital > 0 ? ($noi / $investedCapital) * self::ROIC_ANNUALIZATION_MULT : 0.0;

        $stock->setCurrentRoic((string) max(self::MIN_ROIC_CLAMP, min(self::MAX_ROIC_CLAMP, $truePostTaxReturn)));

        $oldTtm = (float) $stock->getRoicTtm();
        $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * self::ROIC_TTM_EMA_WEIGHT) + ($oldTtm * self::ROIC_TTM_HIST_WEIGHT);
        $stock->setRoicTtm((string) max(self::MIN_ROIC_CLAMP, min(self::MAX_ROIC_CLAMP, $newTtm)));

        return $truePostTaxReturn;
    }

    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        // REITs are pass-through entities and legally pay 0% corporate tax at the entity level.
        return self::PASS_THROUGH_TAX_RATE;
    }

    public function calculateEconomicReturn(Stock $stock, float $nopat, float $investedCapital): float
    {
        $industry = $stock->getIndustry() ?: 'General';
        $customDepreciation = (float) $stock->getDepreciationRate();
        $depreciationRate = $customDepreciation > 0.0 ? $customDepreciation : (\App\Data\Sectors::INDUSTRY_METRICS[$industry]['depreciation'] ?? self::DEFAULT_DEPRECIATION_RATE);

        $absoluteDepreciation = $investedCapital * $depreciationRate;
        $quarterlyDepreciation = $absoluteDepreciation / 4.0;
        $noi = $nopat + $quarterlyDepreciation;

        return $investedCapital > 0 ? ($noi / $investedCapital) * self::ROIC_ANNUALIZATION_MULT : 0.0;
    }

    public function getInterestCoverage(float $ebit, float $interestExpense, float $depreciation = 0.0): float
    {
        // REITs evaluate their interest coverage on Funds From Operations (FFO) / NOI, not EBIT.
        // Because real estate appreciates, GAAP depreciation is an accounting fiction that artificially 
        // reduces EBIT. Adding it back reveals the true cash flow available to cover debt.
        $ffo = $ebit + $depreciation;
        return $interestExpense > 0 ? ($ffo / $interestExpense) : ($ffo > 0 ? self::INFINITE_ICR_POS_FALLBACK : self::INFINITE_ICR_NEG_FALLBACK);
    }

    /**
     * REITs pay out the vast majority of their income as dividends, leaving little retained earnings.
     * To grow their portfolio, they MUST aggressively issue debt to finance new property acquisitions.
     */
    public function getDebtExpansionAggressiveness(float $spreadMultiplier): array
    {
        return [
            'probability' => self::DEBT_EXPANSION_BASE_PROB + ($spreadMultiplier * self::DEBT_EXPANSION_PROB_MULT), // Constantly hunting for property acquisitions
            'aggressiveness' => self::DEBT_EXPANSION_BASE_AGGR + (self::DEBT_EXPANSION_AGGR_MULT * $spreadMultiplier) // High leverage tolerance for commercial real estate
        ];
    }

    /**
     * REITs trade heavily on their Net Asset Value (NAV) / Book Value.
     */
    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps): float
    {
        return ($earningsValue * self::FAIR_VALUE_EARNINGS_WEIGHT) + ($pbFairValue * self::FAIR_VALUE_BOOK_WEIGHT);
    }

    /**
     * For REITs, dividends are legally and economically paid out of Funds From Operations (FFO)
     * rather than GAAP net income, adding back non-cash property depreciation.
     */
    public function getSustainableDividendBase(Stock $stock, float $quarterlyEps, float $investedCapital, float $depRate): float
    {
        $shares = max(1.0, (float) $stock->getSharesOutstanding());
        $quarterlyDepreciationPerShare = (($investedCapital * $depRate) / $shares) / 4.0;
        return $quarterlyEps + $quarterlyDepreciationPerShare;
    }

    public function getMarginReversionSpeed(): float
    {
        return self::LEASE_REVERSION_SPEED; // Multi-year commercial leases resist short-term margin erosion
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
}

