<?php

declare(strict_types=1);

namespace App\Service\Model\Trait;

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
    public function getEffectiveReturn(Stock $stock): float
    {
        return (float) ($stock->getCurrentRoe() ?: $stock->getBaselineRoe());
    }

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
        ?\App\DTO\MacroStateDTO $macroState = null,
        float $depreciation = 0.0
    ): float {
        $kappa = $this->getReversionSpeed();
        $moatSpread = $this->getMoatSpread();

        $equity = (float) $stock->getTotalEquity();
        $truePostTaxReturn = $equity > 0 ? ($actualTotalNetIncome / $equity) * 4.0 : 0.0;

        $stock->setCurrentRoe((string) max(-0.50, min(1.0, $truePostTaxReturn)));

        $oldTtm = (float) $stock->getRoeTtm();
        $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * \App\Service\Math\FinancialConstants::TTM_SMOOTHING_NEW_WEIGHT) + ($oldTtm * \App\Service\Math\FinancialConstants::TTM_SMOOTHING_OLD_WEIGHT);
        $scaledKappa = $kappa / (defined('static::TTM_ROE_WEIGHT') ? static::TTM_ROE_WEIGHT : 0.50);

        $saturationPenalty = 0.0;
        if ($macroState !== null) {
            $saturationPenalty = \App\Service\Math\CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, max(1.0, $equity), $macroState);
        }

        $effectiveMoat = max(0.0, $moatSpread - $saturationPenalty);
        $newTtm += \App\Service\Math\MathUtility::getInstance()->calculateReversionPull($newTtm, $costOfEquity, $scaledKappa, $effectiveMoat);
        $stock->setRoeTtm((string) max(-0.50, min(1.0, $newTtm)));

        return max(-0.50, min(1.0, $truePostTaxReturn));
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
        $limit = $targetDebtTolerance > 0.0 ? $targetDebtTolerance : $this->getWholesaleLeverageLimit();
        return $currentDebtRatio < ($limit * 0.50);
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
        $wholesaleTolerance = $this->getWholesaleLeverageLimit() > 0 ? $this->getWholesaleLeverageLimit() : $health->debtTolerance;
        $bankEquityLimit = $health->debtTolerance;

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

    public function getDebtMaturityRolloverRate(): float
    {
        return \App\Service\Math\FinancialConstants::DEFAULT_QUARTERLY_DEBT_ROLLOVER;
    }

    public function getDeleveragingEvaluationDebt(float $totalDebt, float $wholesaleDebt): float
    {
        return $wholesaleDebt;
    }

    public function getDeleveragingEvaluationLimit(float $macroDebtTolerance): float
    {
        return $this->getWholesaleLeverageLimit() > 0 ? $this->getWholesaleLeverageLimit() : $macroDebtTolerance;
    }

    public function isFinancial(): bool { return true; }

    /**
     * The loans, securities and other assets the institution earns its yield on, net of the losses it
     * already expects. Read from the earning-asset ledger once it is open; before that (a firm that has
     * never reported) it is what the funding must have been deployed into: equity plus all funding less
     * the cash still idle, which is the identity the ledger is seeded from.
     */
    public function resolveEarningAssets(Stock $stock, ?float $currentTreasury = null): float
    {
        if ($stock->hasEarningAssetLedger()) {
            return max(1.0, $stock->getNetEarningAssets());
        }

        $treasury = $currentTreasury ?? (float) $stock->getCorporateTreasury();
        $effectiveEquity = max(1.0, (float) $stock->getTotalEquity());

        return max($effectiveEquity, $effectiveEquity + (float) $stock->getTotalDebt() - $treasury);
    }
    public function getMinIcr(): float { return 1.05; }
    public function getBankruptEquityThreshold(): float { return 2.0; }
    public function getDistressEquityThreshold(): float { return 4.0; }
    public function getWarningEquityThreshold(): float { return 6.0; }
    public function getWholesaleLeverageLimit(): float { return 1.0; }
    public function getDividendCrisisIcr(): float { return 1.05; }
    public function getBuybackMinIcr(): float { return 1.15; }
    public function getReversionSpeed(): float { return 0.18; }
    public function getMoatSpread(): float { return 0.00; }
    public function getWorkingCapitalIntensity(Stock $stock): float { return 0.0; }
    public function getCapExCompletionRate(Stock $stock): float { return 1.0; }
    public function getPhysicalCapital(Stock $stock): float { return (float) $stock->getTotalEquity(); }

    /**
     * A balance sheet business has no trade cycle: it holds loans and securities, not receivables and stock.
     *
     * @return array{dso: float, dio: float, dpo: float}
     */
    public function getWorkingCapitalDays(Stock $stock): array { return ['dso' => 0.0, 'dio' => 0.0, 'dpo' => 0.0]; }

    /**
     * A bank's branches and core systems are immaterial next to its balance sheet, so financial models keep
     * depreciating the equity proxy rather than maintaining a plant ledger they would never use.
     */
    public function getDepreciableBase(Stock $stock): float { return $this->getPhysicalCapital($stock); }
    public function allowsPhysicalOrganicCapex(): bool { return false; }
    public function getReturnBasisIncome(Stock $stock, float $quarterlyNopat, float $actualTotalNetIncome): float { return $actualTotalNetIncome; }
    public function appliesDistressPremiumToCostOfEquity(): bool { return true; }
    public function shouldForceDeleveragingOnJunkOrHoarding(): bool { return false; }
    public function calculateStructuralEps(float $bookValuePerShare, float $structuralRoic, float $revenuePerShare, float $riskFreeRate, ?float $investedCapitalPerShare = null): float
    {
        return $bookValuePerShare * $structuralRoic;
    }

    public function getRegulatoryDividendCap(Stock $stock, float $currentTreasury): ?float
    {
        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 10.0;
        $leverageRatio = (float) $stock->getDebtToEquityRatio();
        $leverageOvershoot = $leverageRatio / $equityLimit;

        if ($leverageOvershoot >= 1.25) {
            return 0.0;
        } elseif ($leverageOvershoot >= 1.15) {
            return 0.30;
        } elseif ($leverageOvershoot >= 1.05) {
            return 0.60;
        }

        return null;
    }

    public function checkBuybackRegulatoryLockout(Stock $stock, float $currentTreasury): ?bool
    {
        $regulatoryCap = $this->getRegulatoryDividendCap($stock, $currentTreasury);
        if ($regulatoryCap !== null && $regulatoryCap <= 0.0) {
            return true;
        }

        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 10.0;
        $buybackLockoutThreshold = max(1.0, $equityLimit - 1.0) + 0.5;

        if ((float) $stock->getDebtToEquityRatio() > $buybackLockoutThreshold) {
            return true;
        }

        return false;
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
