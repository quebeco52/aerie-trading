<?php

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;

/**
 * Interface that defines the core financial physics required to process 
 * earnings and balance sheet evolutions for specific industries.
 */
interface BusinessModelInterface
{
    public function getTargetMetrics(Stock $stock, array &$macroState, MathUtility $mathUtility): array;
    /**
     * @return array{actual_revenue: float, actual_variable_costs: float, analyst_expected_revenue: float, analyst_expected_variable_costs: float, ebit: float, primary_shock_z: float, event_lore: string|null}
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array;
    public function getMacroPhysics(Stock $stock, array &$macroState): array;
    public function calculateInterestIncome(Stock $stock, array &$macroState, MathUtility $mathUtility): float;
    public function getEffectiveTaxRate(float $macroTaxRate): float;
    public function calculateEconomicReturn(Stock $stock, float $nopat, float $investedCapital): float;
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate): float;

    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float;
    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float;
    public function evaluateHoardingStatus(float $treasury, float $targetCashReserves, float $operatingBase, float $totalDebt): array;
    public function calculateDepositBeta(float $totalDebt, float $equity, float $equityLimit, float $customerDeposits): float;
    public function calculateCapacityModifier(float $totalDebt, float $equity, float $equityLimit, ?float $coreLiabilities = null): float;
    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float;

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array;
    public function getInterestCoverage(float $ebit, float $interestExpense, float $depreciation = 0.0): float;
    public function calculateCashYield(array &$macroState): float;
    public function getDebtExpansionAggressiveness(float $spreadMultiplier): array;
    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float;
    public function getUnfundedExpansionCapacity(float $baseCapacity, float $excessCash): float;
    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float;
    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps): float;
    public function getSustainableDividendBase(Stock $stock, float $quarterlyEps, float $investedCapital, float $depRate): float;
    public function getMarginReversionSpeed(): float;
    public function processPassiveLiabilityGrowth(Stock $stock, array &$macroState, array &$state, MathUtility $mathUtility): void;
    public function isUnderLeveraged(bool $isFinancial, float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool;
    public function getWorkingCapitalIntensity(Stock $stock): float;
    public function applyAssetDepreciationDecay(Stock $stock, float $reinvestmentRatio, float $dt): void;
}
