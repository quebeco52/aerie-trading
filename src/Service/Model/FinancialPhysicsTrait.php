<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Stock;

/**
 * Encapsulates the core financial physics overrides for financial institutions 
 * (Banks, Insurers, Clearing Houses, etc.).
 *
 * This implements the "God Object Refactor", offloading the `isFinancial` branching
 * into native Strategy implementations.
 */
trait FinancialPhysicsTrait
{
    public function getTrueReturn(Stock $stock): float
    {
        return (float) $stock->getRoeTtm();
    }

    public function calculateEconomicReturn(Stock $stock, float $quarterlyNopatOrIncome, float $investedCapital): float
    {
        $equity = (float) $stock->getTotalEquity();
        return $equity > 0 ? ($quarterlyNopatOrIncome / $equity) * 4.0 : 0.0;
    }

    public function updateDynamicRoic(
        Stock $stock,
        float $actualTotalNetIncome,
        float $investedCapital,
        float $ebit,
        float $corporateTaxRate,
        float $wacc = 0.08,
        float $costOfEquity = 0.10,
        ?\App\DTO\MacroStateDTO $macroState = null
    ): float {
        $thresholds = $this->getModelThresholds();
        $kappa = $thresholds['reversion_speed'] ?? 0.18;
        $moatSpread = $thresholds['moat_spread'] ?? 0.00;

        $equity = (float) $stock->getTotalEquity();
        $truePostTaxReturn = $equity > 0 ? ($actualTotalNetIncome / $equity) * 4.0 : 0.0;

        $stock->setCurrentRoe((string) max(-0.50, min(1.0, $truePostTaxReturn)));

        $oldTtm = (float) $stock->getRoeTtm();
        $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * \App\Service\Math\FinancialConstants::TTM_SMOOTHING_NEW_WEIGHT) + ($oldTtm * \App\Service\Math\FinancialConstants::TTM_SMOOTHING_OLD_WEIGHT);
        $scaledKappa = $kappa / (defined('static::TTM_ROE_WEIGHT') ? static::TTM_ROE_WEIGHT : 0.50);
        $math = new \App\Service\Math\MathUtility();

        $saturationPenalty = 0.0;
        if ($macroState !== null) {
            $metrics = new \App\Service\Math\CorporateMetrics();
            $saturationPenalty = $metrics->calculateMarketSaturationPenalty($stock, max(1.0, $equity), $macroState);
        }

        $effectiveMoat = max(0.0, $moatSpread - $saturationPenalty);
        $newTtm += $math->calculateReversionPull($newTtm, $costOfEquity, $scaledKappa, $effectiveMoat);
        $stock->setRoeTtm((string) max(-0.50, min(1.0, $newTtm)));

        return $truePostTaxReturn;
    }

    public function calculateCapacityModifier(float $totalDebt, float $equity, float $equityLimit, ?float $coreLiabilities = null): float
    {
        return 1.0;
    }

    public function getInterestCoverage(float $ebit, float $interestExpense, float $depreciation = 0.0): float
    {
        return $interestExpense > 0 ? ($ebit / $interestExpense) : ($ebit > 0 ? 999.0 : -999.0);
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier, float $totalDebt = 0.0, float $customerDeposits = 0.0, float $targetOperatingCash = 0.0, float $currentTreasury = 0.0): array
    {
        return [
            'probability' => 0.40 + ($spreadMultiplier * 0.50),
            'aggressiveness' => 0.05 + (0.35 * $spreadMultiplier)
        ];
    }

    public function getUnfundedExpansionCapacity(float $baseCapacity, float $excessCash): float
    {
        return $baseCapacity;
    }

    public function isUnderLeveraged(float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool
    {
        $equityLimit = $this->getModelThresholds()['equity_limit'] ?? 3.0;
        return $currentDebtRatio < ($equityLimit * 0.50);
    }

    public function supportsUnderleveragedDebtExpansion(): bool
    {
        return false;
    }

    public function processPassiveLiabilityGrowth(Stock $stock, \App\DTO\MacroStateDTO $macroState, array &$state, \App\Service\Math\MathUtility $mathUtility): void {}

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array
    {
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $interestExpense = ($debt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($debt * $floatingRatio * $floatingInterestRate);
        $wholesaleRate = $debt > 0 ? ($interestExpense / $debt) : $currentMarketFixedRate;
        return ['interest_expense' => $interestExpense, 'wholesale_rate' => $wholesaleRate];
    }

    public function getHurdleRate(\App\DTO\DebtHealthDTO $health): float
    {
        return $health->costOfEquity ?? 0.10;
    }

    public function getEvaluationCapital(float $equity, float $investedCapital): float
    {
        return $equity;
    }

    public function getExpansionCapacityBasis(float $equity, float $totalDebt, float $investedCapital): float
    {
        return $equity + $totalDebt;
    }

    public function getMaxOrganicGrowthSpeed(bool $isHoarder, bool $isMegaHoarder): float
    {
        return $isMegaHoarder ? 0.35 : ($isHoarder ? 0.20 : 0.12);
    }

    public function calculateDebtExpansionCapacity(float $equity, float $totalDebt, float $wholesaleDebt, \App\DTO\DebtHealthDTO $health, float $newBorrowingRate, float $ebit, float $depreciation): float
    {
        $modelThresholds = $this->getModelThresholds();
        $wholesaleTolerance = $modelThresholds['wholesale_leverage_limit'] ?? $health->debtTolerance;
        $bankEquityLimit = $modelThresholds['equity_limit'] ?? 10.0;

        $wholesaleCapacity = max(0.0, ($equity * $wholesaleTolerance) - $wholesaleDebt);
        $totalCapacity = max(0.0, ($equity * $bankEquityLimit) - $totalDebt);

        return min($wholesaleCapacity, $totalCapacity);
    }

    public function getLossGivenDefault(): float
    {
        return 0.30; // Financials carry highly leveraged balance sheets but have central bank support
    }

    public function getRequiredIcrBuffer(): float
    {
        return 0.05; // Financials run on razor thin ICRs because debt is their raw material
    }

    public function getMaxFloatingDebtRatio(): float
    {
        return 0.80; // Financials heavily rely on floating rate wholesale debt/deposits
    }

    public function getDeleveragingEvaluationDebt(float $totalDebt, float $wholesaleDebt): float
    {
        return $wholesaleDebt;
    }

    public function getDeleveragingEvaluationLimit(array $modelThresholds, float $macroDebtTolerance): float
    {
        return $modelThresholds['wholesale_leverage_limit'] ?? $macroDebtTolerance;
    }

    public function getDebtCostMetrics(\App\DTO\DebtMetricsDTO $debtMetrics, float $currentDebt, float $wholesaleDebt, float $interestExpense): array
    {
        // Use the already-computed wholesale rate from the debt metrics DTO.
        // DO NOT divide total interestExpense by wholesaleDebt — that attributes deposit interest to wholesale,
        // inflating cost-of-debt to junk bond levels for deposit-heavy banks.
        $wholesaleRate = $debtMetrics->wholesaleRate;
        $wholesaleInterest = $wholesaleRate * $wholesaleDebt;
        return [
            'gross_cost_of_debt' => $wholesaleRate,
            'total_interest_cost' => $wholesaleInterest
        ];
    }

    public function getNetDebtCapital(float $currentDebt, float $wholesaleDebt, float $treasury): float
    {
        return $wholesaleDebt;
    }

    public function calculateLeveredBeta(float $baseBeta, float $impliedTaxShieldRate, float $effectiveDebtToEquity, \App\Service\Math\MathUtility $mathUtility): float
    {
        // Banks and Financials inherently price their massive structural leverage into their baseline Beta.
        // Re-levering a bank using the Hamada equation creates a Cost of Equity death spiral!
        return $baseBeta;
    }

    public function requiresAlternativeZScore(): bool
    {
        return true;
    }

    public function getAcquisitionType(string $defaultType): string
    {
        return 'STRATEGIC ACQUISITION'; // Financials don't do LBOs or Conglomerate Expansion
    }

    public function applyMaSpendCap(float $purchasePrice, float $equity, bool $isMegaHoarder, bool $isEmpireBuilder): float
    {
        if (!$isMegaHoarder && !$isEmpireBuilder) {
            return min($purchasePrice, $equity * \App\Service\Corporate\MergerAndAcquisitionEngine::MA_FINANCIAL_EQUITY_CAP);
        }
        return $purchasePrice;
    }

    public function blendAcquisitionDNA(Stock $acquirer, float $oldCapitalBase, float $purchasePrice, float $effectiveTargetRoic, float $totalNewCapital): void
    {
        $oldBaselineRoe = (float) $acquirer->getBaselineRoe();
        $blendedBaselineRoe = (($oldCapitalBase * $oldBaselineRoe) + ($purchasePrice * $effectiveTargetRoic)) / $totalNewCapital;
        $acquirer->setBaselineRoe((string) max(0.01, $blendedBaselineRoe));
    }

    public function calculateDivestedEquity(Stock $seller, float $divestedFraction, float $currentEquity, float $currentDebt, float $treasury, float $investedCapital, float $lostDebt): float
    {
        $currentDeposits = (float) $seller->getCustomerDeposits();
        $totalLoans = $currentEquity + $currentDebt + $currentDeposits - $treasury;
        $lostLoans = $totalLoans * $divestedFraction;
        return $lostLoans - $lostDebt;
    }

    public function shedDivestedLiabilities(Stock $seller, float $divestedFraction, float $currentTreasury): void
    {
        $currentDeposits = (float) $seller->getCustomerDeposits();
        $lostDeposits = $currentDeposits * $divestedFraction;
        $seller->setCustomerDeposits((string) max(0.0, $currentDeposits - $lostDeposits));

        // In fractional reserve banking, deposits are backed by the loan book, not pure cash.
        // We transfer the proportional share of the existing cash reserves, not the absolute deposit value.
        // Note: We use the pre-sale $currentTreasury to calculate the divested portion.
        $lostCashReserves = $currentTreasury * $divestedFraction;
        $seller->setCorporateTreasury((string) max(0.0, ((float) $seller->getCorporateTreasury()) - $lostCashReserves));
    }

    public function boostStructuralEfficiency(Stock $seller, float $divestedFraction, float $bumpMultiplier): void
    {
        $baselineRoe = (float) $seller->getBaselineRoe();
        $roeBump = $baselineRoe * ($divestedFraction * $bumpMultiplier);
        $seller->setBaselineRoe((string) ($baselineRoe + $roeBump));
    }
}
