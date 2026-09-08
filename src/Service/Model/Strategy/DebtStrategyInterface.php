<?php

declare(strict_types=1);

namespace App\Service\Model\Strategy;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;

interface DebtStrategyInterface
{
    public function calculateCapacityModifier(float $totalDebt, float $equity, float $equityLimit, ?float $coreLiabilities = null): float;
    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array;
    public function getInterestCoverage(float $ebit, float $interestExpense, float $depreciation = 0.0): float;
    public function getDebtExpansionAggressiveness(float $spreadMultiplier, float $totalDebt = 0.0, float $customerDeposits = 0.0, float $targetOperatingCash = 0.0, float $currentTreasury = 0.0): array;
    public function getUnfundedExpansionCapacity(float $baseCapacity, float $excessCash): float;
    public function isUnderLeveraged(float $currentDebtRatio, float $targetDebtTolerance, float $interestCoverage, float $minIcr, float $costOfEquity, float $effectiveCostOfDebt): bool;
    public function supportsUnderleveragedDebtExpansion(): bool;
    public function getHurdleRate(DebtHealthDTO $health): float;
    public function getExpansionCapacityBasis(float $equity, float $totalDebt, float $investedCapital): float;
    public function calculateDebtExpansionCapacity(float $equity, float $totalDebt, float $wholesaleDebt, DebtHealthDTO $health, float $newBorrowingRate, float $ebit, float $depreciation): float;
    public function getLossGivenDefault(): float;
    public function getRequiredIcrBuffer(): float;
    public function getMaxFloatingDebtRatio(): float;
    /**
     * Fraction of the fixed-rate debt stock that matures and is refinanced at the current market rate each
     * quarter. This is the ONLY channel through which the yield curve reaches a firm's interest expense:
     * long-tenor issuers (utilities, telecoms, REITs) roll slowly, so rate shocks reach them later and linger.
     * Interest is a financing cost below EBIT and must never be re-applied as an operating margin drag.
     */
    public function getDebtMaturityRolloverRate(): float;
    public function getDeleveragingEvaluationDebt(float $totalDebt, float $wholesaleDebt): float;
    public function getDeleveragingEvaluationLimit(float $macroDebtTolerance): float;
    public function getDebtCostMetrics(DebtMetricsDTO $debtMetrics, float $currentDebt, float $wholesaleDebt, float $interestExpense): array;
    public function getNetDebtCapital(float $currentDebt, float $wholesaleDebt, float $treasury): float;
    public function calculateLeveredBeta(float $baseBeta, float $impliedTaxShieldRate, float $effectiveDebtToEquity, MathUtility $mathUtility): float;
    public function requiresAlternativeZScore(): bool;
    public function processPassiveLiabilityGrowth(Stock $stock, MacroStateDTO $macroState, array &$state, MathUtility $mathUtility): void;
    public function getMinIcr(): float;
    public function getBankruptEquityThreshold(): float;
    public function getDistressEquityThreshold(): float;
    public function getWarningEquityThreshold(): float;
    public function getWholesaleLeverageLimit(): float;
    public function getDividendCrisisIcr(): float;
    public function getBuybackMinIcr(): float;
    public function appliesDistressPremiumToCostOfEquity(): bool;
    public function shouldForceDeleveragingOnJunkOrHoarding(): bool;
}
