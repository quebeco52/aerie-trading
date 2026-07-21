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
    public function getDebtExpansionAggressiveness(float $spreadMultiplier): array;
    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float;
    public function getUnfundedExpansionCapacity(float $baseCapacity, float $excessCash): float;
    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float;
    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps, float $dividendSupportValue = 0.0): float;
    public function getSustainableDividendBase(Stock $stock, float $quarterlyEps, float $investedCapital, float $depRate): float;
    public function getMarginReversionSpeed(): float;
    public function processPassiveLiabilityGrowth(Stock $stock, MacroStateDTO $macroState, array &$state, MathUtility $mathUtility): void;
    public function isUnderLeveraged(bool $isFinancial, float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool;
    public function getWorkingCapitalIntensity(Stock $stock): float;
    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void;
}
