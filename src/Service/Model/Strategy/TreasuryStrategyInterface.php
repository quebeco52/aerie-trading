<?php

declare(strict_types=1);

namespace App\Service\Model\Strategy;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;

interface TreasuryStrategyInterface
{
    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float;
    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float;
    public function evaluateHoardingStatus(float $treasury, float $targetCashReserves, float $operatingBase, float $totalDebt): array;
    public function calculateDepositBeta(float $totalDebt, float $equity, float $equityLimit, float $customerDeposits): float;
    /**
     * @param float|null $realizedWholesaleRate The blended wholesale funding rate actually realized on the debt
     *                                           side this tick (DebtMetricsDTO::$wholesaleRate), already carrying
     *                                           the dynamic Merton/BGG credit-spread widening. Strategies whose
     *                                           earning assets reprice directly off their own funding cost (e.g.
     *                                           prime brokerage margin loans) MUST use this instead of recomputing
     *                                           a static approximation, or the income and expense rails silently
     *                                           diverge under spread stress. Null when no live debt calc exists
     *                                           yet (e.g. market seed/reset bootstrapping).
     */
    public function calculateInterestIncome(Stock $stock, MacroStateDTO $macroState, MathUtility $mathUtility, ?float $realizedWholesaleRate = null): float;
    public function calculateCashYield(MacroStateDTO $macroState): float;
    /**
     * Whether funding held above the liquidity target is lent out every quarter as a matter of course.
     * True for a balance-sheet lender or float-taker, whose deposits are raw material and not a hoard;
     * false for a fund, whose uninvested cash is dry powder committed on its own judgement of the cycle.
     */
    public function deploysFundingIntoEarningAssets(): bool;
}
