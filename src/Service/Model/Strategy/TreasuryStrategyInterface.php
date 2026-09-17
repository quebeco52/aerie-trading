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
     * Amortized cost of the duration-bearing investment securities this firm holds, which is what the curve
     * remarks each quarter (ASC 320). Zero for a business whose treasury is cash and money-market paper —
     * which is most of them, and the honest answer for a fund holding dry powder.
     *
     * Opted into rather than assumed, because a mark on a book the model never claimed to hold is an
     * invented loss. Only the models that already price a long fixed-income tranche return anything here.
     *
     * @param float|null $currentTreasury Cash as of this point in the quarter, when the caller holds a figure
     *                                    fresher than the entity's.
     */
    public function resolveSecuritiesBook(Stock $stock, ?float $currentTreasury = null): float;

    /**
     * Share of that book which reprices with the curve and therefore carries no duration: floating-rate
     * paper, and whatever the firm's own asset-liability management has swapped to floating.
     */
    public function getSecuritiesFloatingShare(Stock $stock): float;

    /**
     * Portfolio duration in years this kind of institution runs when the seed does not name one. Zero for a
     * business holding no duration-bearing paper.
     */
    public function getDefaultSecuritiesDuration(): float;

    /**
     * Whether funding held above the liquidity target is lent out every quarter as a matter of course.
     * True for a balance-sheet lender or float-taker, whose deposits are raw material and not a hoard;
     * false for a fund, whose uninvested cash is dry powder committed on its own judgement of the cycle.
     */
    public function deploysFundingIntoEarningAssets(): bool;
}
