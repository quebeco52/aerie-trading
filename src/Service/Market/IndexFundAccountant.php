<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\Etf;
use App\Service\Math\FinancialConstants;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps the books of an index fund, which are not the same thing as the index it tracks.
 *
 * An index is a number. A fund is a portfolio, and a portfolio has two things a number does not: it RECEIVES
 * the dividends its constituents pay, and it COSTS something to run. Without both, a share of the fund was a
 * claim on a price index — it lagged the basket it claimed to hold by the market's entire dividend yield,
 * every year, forever, and it did so silently because the chart still looked exactly like the index.
 *
 * One identity governs everything here:
 *
 *     fund price = index level x basket per share + accrued income
 *
 * BASKET PER SHARE is how many index units one fund share owns. It opens at one and falls only when the fund
 * has to sell something to meet a fee it could not pay out of income. Over time it drifts down by roughly
 * the expense ratio a year, which is the tracking difference a real factsheet reports and the reason a cheap
 * broad fund beats an expensive clever one at the same index.
 *
 * ACCRUED INCOME is dividend cash the fund has received and not yet passed on. It sits in the price until
 * the quarterly distribution, at which point it leaves as cash and the price drops by exactly what left.
 * Nobody gains or loses at that moment — which is the point, and is why the drop is not a return.
 *
 * The fee is charged the way a real fund charges it: against net assets, met out of income first and out of
 * the basket only where income falls short. That ordering is not cosmetic. It is what makes a distribution
 * NET of costs, as a real one is, rather than the fund paying out its gross income and quietly selling
 * holdings to cover its own fee.
 */
final class IndexFundAccountant
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Carries the fund's books forward by one tick: the fee it owes, and the dividends it received.
     *
     * @param float $indexLevel          The level the fund tracks, before the fund's own accounting.
     * @param float $incomePerIndexUnit  Dividend cash the index's members paid this tick, per index unit.
     * @param float $dt                  Elapsed simulated time in years.
     */
    public function accrue(Etf $fund, float $indexLevel, float $incomePerIndexUnit, float $dt): void
    {
        $basket = $fund->getBasketPerShare();
        $accrued = $fund->getAccruedIncome();

        if ($dt > 0.0 && $fund->getExpenseRatio() > 0.0) {
            // Continuously accrued against net assets, which is what a fee schedule quoted as an annual
            // percentage means. Charged on a level rather than compounded onto one, it would be a different
            // number at every tick rate.
            $netAssets = max(0.0, ($indexLevel * $basket) + $accrued);
            $fee = $netAssets * (1.0 - exp(-$fund->getExpenseRatio() * $dt));

            $fromIncome = min($accrued, $fee);
            $accrued -= $fromIncome;

            // What income could not cover is met by selling basket. This is the only thing that moves the
            // basket, and it is why the tracking difference is a RATCHET: a fund never buys its fee back.
            $shortfall = $fee - $fromIncome;
            if ($shortfall > 0.0 && $indexLevel > 0.0 && $basket > 0.0) {
                $basket = max(0.0, $basket - ($shortfall / $indexLevel));
            }

            // What the fund has charged over its life, whichever pocket it came out of. Recorded separately
            // because the basket only shows the part that had to be met by selling, and in an ordinary
            // market income covers the fee every time — so the basket would sit at one and the fund would
            // look free while quietly taking its fee out of every distribution.
            $fund->setCumulativeFeesPaid($fund->getCumulativeFeesPaid() + $fee);
        }

        // What the fund actually collected. It owns `basket` index units, so it receives that many times
        // the index's dividend — not the whole index's, which is the error that would have the fund paying
        // out income on holdings it sold years ago to cover its fees.
        if ($incomePerIndexUnit > 0.0) {
            $accrued += $incomePerIndexUnit * $basket;
        }

        $fund->setBasketPerShare($basket);
        $fund->setAccruedIncome($accrued);
    }

    /**
     * Pays the accrued income out to everyone holding the fund, and empties the accrual.
     *
     * The price falls by the distribution on the same tick, because the cash genuinely left the fund. That
     * is an ex-distribution drop and not a loss: the holder has the cash instead.
     *
     * @return float The rate paid per share; zero when there was nothing worth paying.
     */
    public function distribute(Etf $fund, \DateTimeInterface $paidAt): float
    {
        $perShare = $fund->getAccruedIncome();

        // Below the threshold the cash stays accrued rather than writing a ledger row per holder for an
        // amount that rounds to nothing. It is not forfeited; it is paid next quarter.
        if ($perShare < FinancialConstants::FUND_MINIMUM_DISTRIBUTION) {
            return 0.0;
        }

        $this->creditHolders($fund, $perShare, $paidAt);

        $fund->setAccruedIncome(0.0);
        $fund->recordDistribution($perShare, $paidAt);

        return $perShare;
    }

    /**
     * Writes the ledger rows and credits the cash, exactly as CorporateLedgerService does for a company.
     *
     * Two statements rather than one because the credit is derived FROM the ledger rows: rounding then
     * happens once, at write time, and the sum of dividend_payment.amount equals the cash actually paid by
     * construction rather than by two queries agreeing.
     *
     * Units held are user_etfs plus the units sitting in escrow behind an open SELL, and nothing else. A
     * SELL has already had its units removed from user_etfs by the trade engine, so they are added back or
     * the seller is underpaid for units they still own until the order fills. An open BUY is deliberately
     * excluded: it has escrowed CASH, not units, so paying on it would let anyone park a limit buy far below
     * market and collect distributions indefinitely on a fund they never bought.
     *
     * There is no short leg. TradeExecutionService rejects a SHORT on anything but an equity, so nobody can
     * owe this distribution; if that ever changes, a short holder must be debited here the way a short
     * stockholder is, or a high-yield fund becomes free to be short.
     */
    private function creditHolders(Etf $fund, float $perShare, \DateTimeInterface $paidAt): void
    {
        $conn = $this->entityManager->getConnection();
        $paidAtStr = $paidAt->format('Y-m-d H:i:s');

        $conn->executeStatement(
            "INSERT INTO dividend_payment
                 (user_id, asset_type, ticker, shares_held, dividend_per_share, amount, paid_at)
             SELECT holdings.user_id,
                    'ETF',
                    :ticker,
                    holdings.total_units,
                    :rate,
                    ROUND(holdings.total_units * :rate, 2),
                    :paid_at
             FROM (
                 SELECT user_id, SUM(total_qty) AS total_units
                 FROM (
                     SELECT user_id, quantity AS total_qty
                     FROM user_etfs
                     WHERE etf_id = :etf_id AND quantity > 0
                     UNION ALL
                     SELECT user_id, quantity AS total_qty
                     FROM trade_orders
                     WHERE ticker = :ticker AND status = 'OPEN' AND action = 'SELL'
                 ) combined_units
                 GROUP BY user_id
             ) holdings
             WHERE ROUND(holdings.total_units * :rate, 2) > 0",
            [
                'ticker' => $fund->getTicker(),
                'rate' => $perShare,
                'etf_id' => $fund->getId(),
                'paid_at' => $paidAtStr,
            ]
        );

        // Credited from the rows just written. The unique index on (user_id, ticker, paid_at) both makes
        // this join an index lookup and turns an accidental replay of the same ex-date into a constraint
        // violation rather than a silent double credit.
        $conn->executeStatement(
            "UPDATE users u
             INNER JOIN dividend_payment d
                     ON d.user_id = u.id
                    AND d.ticker  = :ticker
                    AND d.paid_at = :paid_at
             SET u.cash_balance = u.cash_balance + d.amount",
            ['ticker' => $fund->getTicker(), 'paid_at' => $paidAtStr]
        );
    }
}
