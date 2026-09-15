<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User;

/**
 * The one rule for moving cash in and out of an account.
 *
 * Both directions sweep before they touch the balance, in the order a real account does: a payment draws
 * the settled balance to zero before it borrows, and a receipt pays the loan down before it credits. Nobody
 * pays margin interest while holding idle cash, and nobody holds a debit they have the cash to clear.
 *
 * It lives here rather than inside any one desk because three of them need it — the equity path, the option
 * path and option settlement — and each had its own copy. They agreed when they were written, which is
 * exactly the condition under which the next correction gets applied to two of the three and an option
 * trade quietly starts funding itself differently from a stock trade.
 *
 * The scales are not arbitrary and not interchangeable: cash carries four decimal places because a fill can
 * be struck at a fraction of a cent, while the margin loan carries the two its column stores, so rounding
 * it further would accumulate a balance the database cannot represent.
 */
final class CashLedger
{
    /** Cash is held to the precision a fill can be struck at. */
    private const CASH_SCALE = 4;

    /** The margin loan is held to the precision its column stores. */
    private const DEBIT_SCALE = 2;

    /**
     * Pays cash out, borrowing whatever the settled balance does not cover.
     *
     * @param string $amount Positive consideration, as a bcmath string.
     */
    public function debit(User $user, string $amount): void
    {
        $cash = (string) $user->getCashBalance();
        $fromCash = \bccomp($cash, $amount, self::CASH_SCALE) >= 0 ? $amount : $cash;

        $user->setCashBalance(\bcsub($cash, $fromCash, self::CASH_SCALE));

        $borrowed = \bcsub($amount, $fromCash, self::CASH_SCALE);

        if (\bccomp($borrowed, '0.0000', self::CASH_SCALE) > 0) {
            $user->setMarginDebit(\bcadd((string) $user->getMarginDebit(), $borrowed, self::DEBIT_SCALE));
        }
    }

    /**
     * Takes cash in, paying down any borrowing first.
     *
     * @param string $amount Positive consideration, as a bcmath string.
     */
    public function credit(User $user, string $amount): void
    {
        $debit = (string) $user->getMarginDebit();
        $repaid = \bccomp($debit, $amount, self::CASH_SCALE) >= 0 ? $amount : $debit;

        if (\bccomp($repaid, '0.0000', self::CASH_SCALE) > 0) {
            $user->setMarginDebit(\bcsub($debit, $repaid, self::DEBIT_SCALE));
        }

        $user->setCashBalance(\bcadd(
            (string) $user->getCashBalance(),
            \bcsub($amount, $repaid, self::CASH_SCALE),
            self::CASH_SCALE
        ));
    }
}
