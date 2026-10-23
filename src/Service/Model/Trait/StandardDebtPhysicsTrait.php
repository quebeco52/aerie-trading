<?php

declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\DTO\DebtCostDTO;
use App\DTO\DebtExpansionAppetiteDTO;

use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Market\Bond\RatingMethodology;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\Valuation;

trait StandardDebtPhysicsTrait
{
    public function getInterestCoverage(float $ebit, float $interestExpense, float $depreciation = 0.0): float
    {
        return $interestExpense > 0 ? ($ebit / $interestExpense) : ($ebit > 0 ? 999.0 : -999.0);
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier, float $totalDebt = 0.0, float $customerDeposits = 0.0, float $targetOperatingCash = 0.0, float $currentTreasury = 0.0): DebtExpansionAppetiteDTO
    {
        return new DebtExpansionAppetiteDTO(probability: 0.40 + ($spreadMultiplier * 0.50), aggressiveness: 0.05 + (0.35 * $spreadMultiplier));
    }

    public function getUnfundedExpansionCapacity(float $baseCapacity, float $excessCash): float
    {
        return $baseCapacity;
    }

    /**
     * The sector's share of the debt tolerance: intangible-heavy software and biotech, whose distress costs are
     * high, target a low one, and regulated utilities, whose rate base secures their cash flows, a high one.
     */
    public function getLeverageTarget(float $targetDebtTolerance): float
    {
        return $targetDebtTolerance * (float) static::UNDERLEVERAGED_DEBT_RATIO;
    }

    /**
     * Static trade-off theory (Kraus & Litzenberger 1973; Myers 1984): debt is worth adding while its tax shield
     * outweighs the expected cost of distress, so a firm is under-levered below its target leverage when its
     * coverage can carry more. Equity costing more than debt is no reason to borrow: it always does, and
     * borrowing raises it (Modigliani & Miller 1958, Proposition II).
     */
    public function isUnderLeveraged(float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr): bool
    {
        $icrFloor = static::MIN_RECAP_ICR_FLOOR !== null
            ? (float) static::MIN_RECAP_ICR_FLOOR
            : max(FinancialConstants::MIN_ABSOLUTE_ICR_BUFFER, $minIcr * FinancialConstants::REQUIRED_ICR_SAFETY_MULT);

        return $interestCoverage >= $icrFloor && $currentDebtRatio < $this->getLeverageTarget($targetDebtTolerance);
    }

    /**
     * An operating company recapitalizes through a debt-financed repurchase; borrowing into its treasury recapitalizes
     * nothing.
     */
    public function supportsUnderleveragedDebtExpansion(): bool
    {
        return false;
    }

    public function getHurdleRate(DebtHealthDTO $health): float
    {
        return $health->wacc;
    }

    public function getExpansionCapacityBasis(float $equity, float $totalDebt, float $investedCapital): float
    {
        return $investedCapital;
    }

    public function calculateDebtExpansionCapacity(float $equity, float $totalDebt, float $wholesaleDebt, DebtHealthDTO $health, float $newBorrowingRate, float $ebit, float $depreciation): float
    {
        $evalDebt = $totalDebt;
        $evalTolerance = $health->debtTolerance;
        $balanceSheetCapacity = max(0.0, ($equity * $evalTolerance) - $evalDebt);

        $minimumIcr = $this->getBuybackMinIcr() + 0.5;

        $maxTolerableInterest = max(0.0, $ebit / $minimumIcr);
        $currentInterestExpense = $health->rawMetrics->interestExpense ?? 0.0;
        $availableInterestCapacity = max(0.0, $maxTolerableInterest - $currentInterestExpense);
        $incomeStatementCapacity = $newBorrowingRate > 0 ? ($availableInterestCapacity / $newBorrowingRate) : 0.0;

        return min($incomeStatementCapacity, $balanceSheetCapacity);
    }

    /**
     * A listed firm's notes are senior unsecured: Moody's ultimate recovery 47.4% (1987-2021),
     * CorporateDefaultService::RECOVERY_SENIOR_UNSECURED. A financial's wholesale notes too: central bank support is
     * liquidity, not recovery.
     */
    public function getLossGivenDefault(): float
    {
        return 1.0 - \App\Service\Market\Bond\CorporateDefaultService::RECOVERY_SENIOR_UNSECURED;
    }

    public function getRequiredIcrBuffer(): float
    {
        return 1.5;
    }

    public function getDebtMaturityRolloverRate(): float
    {
        return static::DEBT_MATURITY_ROLLOVER_RATE;
    }

    public function getDeleveragingEvaluationDebt(float $totalDebt, float $wholesaleDebt): float
    {
        return $totalDebt;
    }

    public function getDeleveragingEvaluationLimit(float $macroDebtTolerance): float
    {
        return $macroDebtTolerance;
    }

    public function getMarginalCostOfDebt(DebtMetricsDTO $debtMetrics): float
    {
        return $debtMetrics->currentMarketRate;
    }

    public function getDebtCostMetrics(DebtMetricsDTO $debtMetrics, float $currentDebt, float $wholesaleDebt, float $interestExpense): DebtCostDTO
    {
        $grossCostOfDebt = $currentDebt > 0 ? ($interestExpense / $currentDebt) : $debtMetrics->currentMarketRate;
        return new DebtCostDTO(grossCostOfDebt: $grossCostOfDebt, totalInterestCost: $interestExpense);
    }

    public function getNetDebtCapital(float $currentDebt, float $wholesaleDebt, float $treasury): float
    {
        return max(0.0, $currentDebt - $treasury);
    }

    public function calculateLeveredBeta(float $baseBeta, float $impliedTaxShieldRate, float $effectiveDebtToEquity): float
    {
        return Valuation::calculateLeveredBeta($baseBeta, $impliedTaxShieldRate, $effectiveDebtToEquity);
    }

    public function getRatingMethodology(): RatingMethodology
    {
        return static::RATING_METHODOLOGY;
    }

    /**
     * No client money held apart. An operating company holds none, and a deposit is not custody: a bank lends it out
     * and owes it back out of its own estate, so it belongs in the balance sheet a capital ratio is struck on. Only a
     * model that genuinely segregates client assets overrides this.
     */
    public function getSegregatedCustodyLiabilities(Stock $stock): float
    {
        return 0.0;
    }

    public function processPassiveLiabilityGrowth(Stock $stock, MacroStateDTO $macroState, array &$state, MathUtility $mathUtility): void {}

    public function getMinIcr(): float
    {
        return static::MIN_ICR;
    }

    public function getBankruptEquityThreshold(): float
    {
        return static::BANKRUPT_EQUITY_THRESHOLD;
    }

    public function getDistressEquityThreshold(): float
    {
        return static::DISTRESS_EQUITY_THRESHOLD;
    }

    public function getWarningEquityThreshold(): float
    {
        return static::WARNING_EQUITY_THRESHOLD;
    }

    public function getWholesaleLeverageLimit(): float
    {
        return static::WHOLESALE_LEVERAGE_LIMIT;
    }

    public function getDividendCrisisIcr(): float
    {
        return static::DIVIDEND_CRISIS_ICR;
    }

    public function getBuybackMinIcr(): float
    {
        return static::BUYBACK_MIN_ICR;
    }

    public function shouldForceDeleveragingOnJunkOrHoarding(): bool
    {
        return true;
    }
}
