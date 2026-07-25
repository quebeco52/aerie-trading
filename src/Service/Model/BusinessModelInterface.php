<?php

namespace App\Service\Model;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\DTO\SectorCoverageProfile;
use App\Entity\Stock;
use App\Service\Math\MathUtility;

/**
 * Interface that defines the core financial physics required to process 
 * earnings and balance sheet evolutions for specific industries.
 */
interface BusinessModelInterface
{
    public function getTargetMetrics(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): array;
    public function computeActualFinancials(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): ActualFinancialsDTO;

    /** Returns the analyst coverage profile for this sector (consumed by MarketConsensusEngine). */
    public function getCoverageProfile(): SectorCoverageProfile;

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array;
    public function calculateInterestIncome(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility): float;
    public function getEffectiveTaxRate(float $macroTaxRate): float;

    /**
     * Calculates the annualized economic return (ROIC or ROE).
     * @param float $nopat Quarterly NOPAT (for non-financials) or Quarterly Net Income (for financials).
     * @param float $investedCapital Annual/structural invested capital or equity base.
     */
    public function calculateEconomicReturn(Stock $stock, float $nopat, float $investedCapital): float;

    /**
     * Updates and annualizes dynamic ROIC/ROE from quarterly outcomes.
     * @param float $actualTotalNetIncome Quarterly net income.
     * @param float $ebit Quarterly EBIT.
     */
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate, float $wacc = 0.08): float;

    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float;
    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float;
    public function evaluateHoardingStatus(float $treasury, float $targetCashReserves, float $operatingBase, float $totalDebt): array;
    public function calculateDepositBeta(float $totalDebt, float $equity, float $equityLimit, float $customerDeposits): float;
    public function calculateCapacityModifier(float $totalDebt, float $equity, float $equityLimit, ?float $coreLiabilities = null): float;
    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float;

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array;
    public function getInterestCoverage(float $ebit, float $interestExpense, float $depreciation = 0.0): float;
    public function calculateCashYield(MacroStateDTO $macroState): float;
    public function getDebtExpansionAggressiveness(float $spreadMultiplier, float $totalDebt = 0.0, float $customerDeposits = 0.0): array;
    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float;
    public function getUnfundedExpansionCapacity(float $baseCapacity, float $excessCash): float;
    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float;
    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float;
    public function getSustainableDividendBase(Stock $stock, float $quarterlyEps, float $investedCapital, float $depRate): float;
    public function getMarginReversionSpeed(): float;
    public function processPassiveLiabilityGrowth(Stock $stock, MacroStateDTO $macroState, array &$state, MathUtility $mathUtility): void;
    public function isUnderLeveraged(float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool;
    public function getWorkingCapitalIntensity(Stock $stock): float;
    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void;

    /** Annualized secular organic revenue growth rate (Solow Growth Model). */
    public function getSecularGrowthRate(Stock $stock): float;

    /** Multiplier for how violently CapEx responds to the output gap (Samuelson Accelerator). */
    public function getCapexCyclicality(): float;

    /** Sector-specific weights for EPS vs Revenue surprise blend. Returns ['eps_weight' => float, 'revenue_weight' => float] */
    public function getSurpriseBlendWeights(): array;

    /** Returns the true economic return metric (ROIC for Corporate, ROE for Financials). */
    public function getTrueReturn(Stock $stock): float;

    /** Returns the hurdle rate for capital allocation (WACC for Corporate, Cost of Equity for Financials). */
    public function getHurdleRate(\App\DTO\DebtHealthDTO $health): float;

    /** Returns the primary capital basis for evaluation (Invested Capital for Corporate, Equity for Financials). */
    public function getEvaluationCapital(float $equity, float $investedCapital): float;

    /** Returns the capital base used to determine expansion limits (Invested Capital for Corporate, Equity + Debt for Financials). */
    public function getExpansionCapacityBasis(float $equity, float $totalDebt, float $investedCapital): float;

    /** Returns the maximum organic growth speed limit for the business model. */
    public function getMaxOrganicGrowthSpeed(bool $isHoarder, bool $isMegaHoarder): float;

    /** Calculates the maximum debt issuance capacity based on Income Statement (Corporate) or Balance Sheet (Financial) constraints. */
    public function calculateDebtExpansionCapacity(float $equity, float $totalDebt, float $wholesaleDebt, \App\DTO\DebtHealthDTO $health, float $newBorrowingRate, float $ebit, float $depreciation): float;

    /** Returns the Loss Given Default (LGD) for the Merton Default Model. */
    public function getLossGivenDefault(): float;

    /** Returns the minimum absolute ICR buffer required to be considered underleveraged and safe from distress. */
    public function getRequiredIcrBuffer(): float;

    /** Returns the maximum percentage of debt that should be issued at a floating rate. */
    public function getMaxFloatingDebtRatio(): float;

    /** Returns the debt value to use when evaluating deleveraging targets (Total Debt for Corporate, Wholesale Debt for Financials). */
    public function getDeleveragingEvaluationDebt(float $totalDebt, float $wholesaleDebt): float;

    /** Returns the leverage limit to use when evaluating deleveraging targets. */
    public function getDeleveragingEvaluationLimit(array $modelThresholds, float $macroDebtTolerance): float;

    /** Returns ['gross_cost_of_debt' => float, 'total_interest_cost' => float] for tax shield calculations. */
    public function getDebtCostMetrics(\App\DTO\DebtMetricsDTO $debtMetrics, float $currentDebt, float $wholesaleDebt, float $interestExpense): array;

    /** Returns the true capital financing debt (Current Debt - Treasury for Corporate, Wholesale Debt for Financials). */
    public function getNetDebtCapital(float $currentDebt, float $wholesaleDebt, float $treasury): float;

    /** Returns the Levered Beta. (Unchanged for Financials, Hamada Equation for Corporate). */
    public function calculateLeveredBeta(float $baseBeta, float $impliedTaxShieldRate, float $effectiveDebtToEquity, MathUtility $mathUtility): float;

    /** Returns true if the business model requires an alternative Z-Score calculation (e.g. Financials, REITs). */
    public function requiresAlternativeZScore(): bool;

    /** Adjusts the M&A deal type (e.g. converting LBOs to Strategic Acquisitions for Financials). */
    public function getAcquisitionType(string $defaultType): string;

    /** Applies a sector-specific cap to the total purchase price of an M&A deal. */
    public function applyMaSpendCap(float $purchasePrice, float $equity, bool $isMegaHoarder, bool $isEmpireBuilder): float;

    /** Blends the target's economic return (ROIC/ROE) into the acquirer's structural DNA. */
    public function blendAcquisitionDNA(Stock $acquirer, float $oldCapitalBase, float $purchasePrice, float $effectiveTargetRoic, float $totalNewCapital): void;

    /** Calculates the amount of equity lost during a divestiture. */
    public function calculateDivestedEquity(Stock $seller, float $divestedFraction, float $currentEquity, float $currentDebt, float $treasury, float $investedCapital, float $lostDebt): float;

    /** Sheds sector-specific liabilities (e.g. Customer Deposits) during a divestiture. */
    public function shedDivestedLiabilities(Stock $seller, float $divestedFraction, float $currentTreasury): void;

    /** Boosts structural efficiency (ROIC/ROE) after shedding toxic assets in a divestiture. */
    public function boostStructuralEfficiency(Stock $seller, float $divestedFraction, float $bumpMultiplier): void;

    /** Returns the M&A strategy profile for a given CEO archetype. */
    public function getMaArchetypeStrategy(string $archetype): array;
}
