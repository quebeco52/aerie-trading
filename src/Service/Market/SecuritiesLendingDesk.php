<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\Stock;
use App\Service\Math\FinancialConstants;

/**
 * The borrow: how much stock can be lent, what it costs, and when lenders take it back.
 *
 * A short sale is a loan of stock, and the price of that loan is what makes shorting a position with a
 * running cost rather than a free mirror of a long. It is also where a squeeze comes from, and the reason
 * one does not need to be scripted: as a price rises, shorts lose equity and are called, covering pushes
 * the price higher still, utilization of the remaining lendable supply climbs, the fee climbs with it, and
 * the borrow eventually gets recalled outright. Every link in that chain is priced here or in MarginEngine;
 * none of it is an event someone wrote.
 */
final class SecuritiesLendingDesk
{
    /**
     * Shares available to borrow.
     *
     * A fraction of the float, not all of it: most holders do not lend, which is why a name becomes hard to
     * borrow long before anything like its whole float has been shorted.
     */
    public function lendableSupply(Stock $stock): float
    {
        $shares = (float) $stock->getSharesOutstanding();
        $float = max(0.0, min(1.0, (float) $stock->getPublicFloatPercentage()));
        $lendable = $stock->getLendableSupplyRatio() ?? FinancialConstants::DEFAULT_LENDABLE_SUPPLY_RATIO;

        return max(0.0, $shares * $float * max(0.0, min(1.0, $lendable)));
    }

    /** Shares still available to borrow after existing shorts. */
    public function availableToBorrow(Stock $stock): float
    {
        return max(0.0, $this->lendableSupply($stock) - (float) $stock->getShortInterestShares());
    }

    /** Share of the lendable supply already lent out. */
    public function utilization(Stock $stock): float
    {
        $supply = $this->lendableSupply($stock);

        if ($supply <= 0.0) {
            return 1.0;
        }

        return max(0.0, min(1.0, ((float) $stock->getShortInterestShares()) / $supply));
    }

    /**
     * Annualized cost of borrowing the stock, from how much of it is already lent.
     *
     * Flat at general collateral while supply is ample, then steepening sharply as the last of it goes. The
     * convexity is the point: a name at half utilization is barely more expensive than an untouched one,
     * and a name at ninety-five percent costs many times more. A linear fee would make the borrow a mild
     * tax at every level and no squeeze would ever develop.
     */
    public function borrowFee(Stock $stock): float
    {
        $utilization = $this->utilization($stock);
        $range = FinancialConstants::MAX_BORROW_FEE - FinancialConstants::GENERAL_COLLATERAL_BORROW_FEE;

        return FinancialConstants::GENERAL_COLLATERAL_BORROW_FEE
            + ($range * ($utilization ** FinancialConstants::BORROW_FEE_CONVEXITY));
    }

    /**
     * Borrow cost accrued on a position over an interval.
     *
     * @param float $shortShares Shares short, positive.
     * @param float $price       Current price.
     * @param float $dt          Elapsed simulated time in years.
     */
    public function accruedFee(Stock $stock, float $shortShares, float $price, float $dt): float
    {
        if ($shortShares <= 0.0 || $dt <= 0.0) {
            return 0.0;
        }

        return $shortShares * $price * $this->borrowFee($stock) * $dt;
    }

    /** Whether lenders are recalling stock in this name. */
    public function isRecalling(Stock $stock): bool
    {
        return $this->utilization($stock) >= FinancialConstants::BUY_IN_UTILIZATION_THRESHOLD;
    }

    /**
     * Shares of a given short position that a buy-in takes back.
     *
     * A recall is not a margin call: it can land on a short that is perfectly well collateralized and
     * winning. That is what makes a genuine squeeze inescapable rather than merely expensive, and it is why
     * the size is a fraction of the position rather than whatever the account can afford.
     *
     * @param float $shortShares Shares short, positive.
     */
    public function buyInQuantity(Stock $stock, float $shortShares): float
    {
        if (!$this->isRecalling($stock) || $shortShares <= 0.0) {
            return 0.0;
        }

        return min($shortShares, max(1.0, floor($shortShares * FinancialConstants::BUY_IN_FRACTION)));
    }
}
