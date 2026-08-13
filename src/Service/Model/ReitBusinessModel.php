<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Event\ShockEvent;
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
    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.70;
    public const BASE_COVERAGE_ERROR = 0.10;
    public function getModelThresholds(): array
    {
        return ['min_icr' => 1.05, 'bankrupt_equity' => 10.0, 'distress_equity' => 20.0, 'warning_equity' => 30.0, 'wholesale_leverage_limit' => 2.0,  'dividend_crisis_icr' => 1.05, 'buyback_min_icr' => 1.15, 'reversion_speed' => 0.20, 'moat_spread' => 0.005, 'nwc_intensity' => 0.0, 'capex_completion_rate' => 0.125];
    }
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
    public const TARGET_EBIT_WEIGHT         = 0.50;
    /** Weight given to TTM ROIC when blending with historical target EBIT yield. */
    public const TTM_ROIC_WEIGHT            = 0.50;
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
    // Moved to getCoverageProfile() — see MarketConsensusEngine.

    // --- REIT Reversion & Valuation ---
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
    public const FAIR_VALUE_EARNINGS_WEIGHT = 0.30;
    /** Weight given to property Net Asset Value (NAV / Book Value) in fair value calculations. */
    public const FAIR_VALUE_BOOK_WEIGHT     = 0.40;
    /** Weight given to Dividend Discount Model (DDM yield) in REIT fair value calculations. */
    public const FAIR_VALUE_DDM_WEIGHT      = 0.30;
    /** Operating margin mean reversion speed: slower speed reflects multi-year commercial leases. */
    public const LEASE_REVERSION_SPEED      = 2.0;

    /**
     * Real Estate Cap Rates are deeply tied to the 10-Year Treasury Yield.
     * As rates rise, property values effectively drop, demanding a higher yield.
     */
    public function getTargetMetrics(Stock $stock, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): array
    {
        $investedCapital = $stock->getInvestedCapital();
        $baselineRoic = max(0.01, (float) $stock->getBaselineRoic());

        // Real Estate Cap Rates are deeply tied to the 10-Year Treasury Yield plus a risk premium.
        $yield10y = $macroState->yield10yEma;
        $realEstateRiskPremium = $macroState->equityRiskPremium;
        $targetCapRate = $yield10y + $realEstateRiskPremium;

        // Baseline DNA change over time, but at a realistic physical rate.
        // Commercial leases (Office, Healthcare) are typically 7 to 10 years long.
        // This means a REIT only turns over about 2.5% of its portfolio per quarter.
        // If they do a bad M&A, they will be punished for YEARS before leases expire and reset to market rates!
        $portfolioTurnoverRate = self::PORTFOLIO_TURNOVER_RATE;
        $maxCapRate = max(self::MAX_CAP_RATE_CLAMP, $macroState->yield10yEma + self::CAP_RATE_CEILING_SPREAD);
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

        $metrics = new \App\Service\Math\CorporateMetrics();
        $saturationPenalty = $metrics->calculateMarketSaturationPenalty($stock, $investedCapital, $macroState);
        $waccBase = $macroState->policyRate + $macroState->equityRiskPremium;
        $effectiveRoic = max($waccBase, $targetEbitYield - $saturationPenalty);

        return [
            'invested_capital' => $investedCapital,
            'baseline_roic' => $effectiveRoic
        ];
    }

    /**
     * REIT revenues are incredibly stable due to multi-year binding leases.
     */
    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        // CPI Rent Escalators perfectly capture inflation dynamically.
        // We strip generic pricing power to prevent double-dipping.
        $physics['pricing_power_multiplier'] = 1.0;
        return $physics;
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $revenueZ = $mathUtility->generatePersistentZ($momentum['revenue'] ?? 0.0, 0.25);

        $params = $this->resolveModelParameters($stock, [
            'sticky_lease_weight'            => 0.85,
            'variable_hospitality_weight'    => 0.15,
            'securitization_income_weight'   => 0.00,
            'longevity_bond_yield_weight'    => 0.00,
        ]);
        $leaseWeight        = $params['sticky_lease_weight'];
        $hospitalityWeight  = $params['variable_hospitality_weight'];
        $securitizationWeight = $params['securitization_income_weight'];
        $longevityWeight    = $params['longevity_bond_yield_weight'];

        // REIT sticky lease revenues are incredibly stable due to multi-year binding contracts
        $leaseShock = $revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR);
        // Variable hospitality/parking revenues experience full cyclical variance
        $hospitalityShock = $revenueZ * ($baselineVol * 1.5);

        // The Inflation Hedge (CPI Rent Escalators):
        $inflation = $macroState->inflationEma;
        $excessInflation = max(0.0, $inflation - MacroEngine::TARGET_INFLATION);
        $rentEscalator = $excessInflation * self::RENT_ESCALATOR_CAPTURE;

        $leaseRevenue        = $expectedRevenue * $leaseWeight * (1.0 + $leaseShock + $rentEscalator);
        $hospitalityRevenue  = $expectedRevenue * $hospitalityWeight * (1.0 + $hospitalityShock);
        
        $securitizationRevenue = 0.0;
        $securitizationZ = 0.0;
        if ($securitizationWeight > 0.0) {
            $securitizationZ = $mathUtility->generatePersistentZ($momentum['securitization'] ?? 0.0, 0.40);
            $securitizationRevenue = $expectedRevenue * $securitizationWeight * (1.0 + ($securitizationZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * 2.0));
        }

        $longevityRevenue = 0.0;
        $longevityZ = 0.0;
        if ($longevityWeight > 0.0) {
            $longevityZ = $mathUtility->generatePersistentZ($momentum['longevity'] ?? 0.0, 0.60);
            $longevityRevenue = $expectedRevenue * $longevityWeight * (1.0 + ($longevityZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * 0.25));
        }

        $actualRevenue       = max(0.0, $leaseRevenue + $hospitalityRevenue + $securitizationRevenue + $longevityRevenue);

        // The Tenant Default Shock (Vacancy) & Refinancing Wall:
        // Deep recessions cause anchor tenants to break leases.
        // Higher 10Y Treasury yields increase property cap rates and debt refinancing drag.
        $tenantDefaultZ = $mathUtility->generatePersistentZ($momentum['tenant_default'] ?? 0.0, 0.20);
        $vacancyShock = $tenantDefaultZ < self::VACANCY_Z_THRESHOLD
            ? abs($tenantDefaultZ) * self::VACANCY_LOSS_SCALAR
            : ($tenantDefaultZ > self::BENIGN_LEASING_Z_FLOOR
                ? -($tenantDefaultZ - self::BENIGN_LEASING_Z_FLOOR) * self::LEASING_BONUS_SCALE
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

        $streamZ = [
            'revenue'        => $revenueZ,
            'tenant_default' => $tenantDefaultZ,
        ];
        
        $streamRevenue = [
            'lease'       => $leaseRevenue,
            'hospitality' => $hospitalityRevenue,
        ];

        if ($securitizationWeight > 0.0) {
            $streamZ['securitization'] = $securitizationZ;
            $streamRevenue['securitization_income'] = $securitizationRevenue;
        }
        
        if ($longevityWeight > 0.0) {
            $streamZ['longevity'] = $longevityZ;
            $streamRevenue['longevity_bond_yield'] = $longevityRevenue;
        }

        $result = new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            primaryShockZ: abs($tenantDefaultZ) > abs($revenueZ) ? $tenantDefaultZ : $revenueZ,
            // Rent escalators (inflation) and 10Y Treasury yields are 100% visible; vacancies ~70% visible
            observableShockZ: $rentEscalator,
            eventType: $eventType,
            streamZ: $streamZ,
            streamRevenue: $streamRevenue,
        );
        
        return $result;
    }

    /**
     * Wall Street evaluates REITs on FFO (Funds From Operations), not GAAP Net Income.
     * FFO = Net Income + Depreciation (since real estate generally appreciates, depreciation is an accounting fiction).
     */
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate, float $wacc = 0.08, float $costOfEquity = 0.10, ?\App\DTO\MacroStateDTO $macroState = null): float
    {
        $industry = $stock->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $thresholds = $this->getModelThresholds();
        $kappa = $thresholds['reversion_speed'] ?? 0.20;
        $moatSpread = $thresholds['moat_spread'] ?? 0.005;

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
        // Scale kappa so the blended target in getTargetMetrics moves at exactly $kappa
        $scaledKappa = $kappa / self::TTM_ROIC_WEIGHT;
        $math = new MathUtility();
        
        $saturationPenalty = 0.0;
        if ($macroState !== null) {
            $metrics = new \App\Service\Math\CorporateMetrics();
            $saturationPenalty = $metrics->calculateMarketSaturationPenalty($stock, abs($investedCapital), $macroState);
        }
        
        $newTtm += $math->calculateReversionPull($newTtm, $wacc - $saturationPenalty, $scaledKappa, $moatSpread);
        $stock->setRoicTtm((string) max(self::MIN_ROIC_CLAMP, min(self::MAX_ROIC_CLAMP, $newTtm)));

        return $truePostTaxReturn;
    }

    public function getEffectiveTaxRate(float $macroTaxRate): float
    {
        // REITs are pass-through entities and legally pay 0% corporate tax at the entity level.
        return self::PASS_THROUGH_TAX_RATE;
    }

    /**
     * Calculates the annualized economic return (ROIC) on NOI.
     * @param float $quarterlyNopat Quarterly NOPAT (multiplied by 4.0 inside via ROIC_ANNUALIZATION_MULT).
     * @param float $investedCapital Annual/structural invested capital.
     */
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
    public function getDebtExpansionAggressiveness(float $spreadMultiplier, float $totalDebt = 0.0, float $customerDeposits = 0.0, float $targetOperatingCash = 0.0, float $currentTreasury = 0.0): array
    {
        return [
            'probability' => self::DEBT_EXPANSION_BASE_PROB + ($spreadMultiplier * self::DEBT_EXPANSION_PROB_MULT), // Constantly hunting for property acquisitions
            'aggressiveness' => self::DEBT_EXPANSION_BASE_AGGR + (self::DEBT_EXPANSION_AGGR_MULT * $spreadMultiplier) // High leverage tolerance for commercial real estate
        ];
    }

    public function calculateDebtExpansionCapacity(float $equity, float $totalDebt, float $wholesaleDebt, \App\DTO\DebtHealthDTO $health, float $newBorrowingRate, float $ebit, float $depreciation): float
    {
        $evalDebt = $totalDebt;
        $evalTolerance = $health->debtTolerance;
        $balanceSheetCapacity = max(0.0, ($equity * $evalTolerance) - $evalDebt);

        $minimumIcr = ($this->getModelThresholds()['buyback_min_icr'] ?? 3.0) + 0.5;

        // REITs use FFO (EBIT + Depreciation) to cover interest, as depreciation is non-cash.
        $operatingIncome = $ebit + $depreciation;

        $maxTolerableInterest = max(0.0, $operatingIncome / $minimumIcr);
        $currentInterestExpense = $health->rawMetrics->interestExpense ?? 0.0;
        $availableInterestCapacity = max(0.0, $maxTolerableInterest - $currentInterestExpense);
        $incomeStatementCapacity = $newBorrowingRate > 0 ? ($availableInterestCapacity / $newBorrowingRate) : 0.0;

        return min($incomeStatementCapacity, $balanceSheetCapacity);
    }

    public function getMaxFloatingDebtRatio(): float
    {
        return 0.50; // REITs use floating rate debt significantly to fund construction and bridge loans
    }

    /**
     * REITs trade heavily on their Net Asset Value (NAV) / Book Value.
     */
    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float
    {
        if ($dividendSupportValue > 0.0) {
            return ($pbFairValue * self::FAIR_VALUE_BOOK_WEIGHT) + ($earningsValue * self::FAIR_VALUE_EARNINGS_WEIGHT) + ($dividendSupportValue * self::FAIR_VALUE_DDM_WEIGHT);
        }
        return ($pbFairValue * 0.40) + ($earningsValue * 0.60);
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
    public function requiresAlternativeZScore(): bool
    {
        return true;
    }
}

