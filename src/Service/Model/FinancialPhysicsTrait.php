<?php

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

        $wholesaleCapacity = max(0.0, ($equity * $wholesaleTolerance) - $wholesaleDebt);
        $totalCapacity = max(0.0, ($equity * $health->debtTolerance) - $totalDebt);

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
        $grossCostOfDebt = $wholesaleDebt > 0 ? ($interestExpense / $wholesaleDebt) : $debtMetrics->wholesaleRate;
        return [
            'gross_cost_of_debt' => $grossCostOfDebt,
            'total_interest_cost' => $grossCostOfDebt * $wholesaleDebt
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

    public function getMaArchetypeStrategy(string $archetype): array
    {
        if ($archetype === 'empire_builder') {
            return ['prob' => 0.050, 'spend' => 0.80, 'type' => 'STRATEGIC ACQUISITION', 'use_leverage' => true, 'use_stock' => false];
        }
        if ($archetype === 'mega_hoarder') {
            return ['prob' => 0.015, 'spend' => 0.60, 'type' => 'STRATEGIC ACQUISITION', 'use_leverage' => false, 'use_stock' => false];
        }
        if ($archetype === 'hoarder') {
            return ['prob' => 0.020, 'spend' => 0.40, 'type' => 'STRATEGIC ACQUISITION', 'use_leverage' => false, 'use_stock' => false];
        }
        return ['prob' => 0.035, 'spend' => 0.40, 'type' => 'STRATEGIC ACQUISITION', 'use_leverage' => true, 'use_stock' => false];
    }
}
