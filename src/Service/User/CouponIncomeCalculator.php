<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Coupon and redemption cash a user has received, read back from the ledger BondLedgerService writes.
 *
 * The bond counterpart to DividendIncomeCalculator, and separate from it for the same reason that class is
 * separate from CostBasisCalculator: cost basis is what was paid, income is cash received against it, and a
 * caller wanting total return combines clear numbers rather than one overloaded one.
 *
 * It matters more here than it does for equities. A bond bought at par and held to maturity returns exactly
 * its coupon stream and nothing else, so a portfolio that counts only the price leg reports a flat or losing
 * position on a trade that made money.
 */
final class CouponIncomeCalculator
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Lifetime coupon cash received, per ticker.
     *
     * Redemptions are excluded: returning face is the closing leg of the position, not income earned on it,
     * and counting it as income would report a par-bought bond as having returned 100% of its own principal.
     *
     * @return array<string, float> ticker => coupon cash received.
     */
    public function totalsByTicker(User $user): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT ticker, SUM(amount) AS total
             FROM coupon_payment
             WHERE user_id = :user_id AND payment_type = 'COUPON'
             GROUP BY ticker",
            ['user_id' => $user->getId()]
        );

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row['ticker']] = (float) $row['total'];
        }

        return $totals;
    }

    /**
     * The user's most recent bond cash events, newest first, for the income feed.
     *
     * Redemptions are included here, unlike in the totals: the feed is a record of what hit the account, and
     * a bond maturing is something the holder needs to see.
     *
     * @return list<array{ticker: string, paymentType: string, bondsHeld: int, amountPerBond: float, amount: float, paidAt: string}>
     */
    public function recentPayments(User $user, int $limit = 25): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT ticker, payment_type, bonds_held, amount_per_bond, amount, paid_at
             FROM coupon_payment
             WHERE user_id = :user_id
             ORDER BY paid_at DESC, id DESC
             LIMIT ' . max(1, $limit),
            ['user_id' => $user->getId()]
        );

        $payments = [];
        foreach ($rows as $row) {
            $payments[] = [
                'ticker' => (string) $row['ticker'],
                'paymentType' => (string) $row['payment_type'],
                'bondsHeld' => (int) $row['bonds_held'],
                'amountPerBond' => (float) $row['amount_per_bond'],
                'amount' => (float) $row['amount'],
                'paidAt' => (string) $row['paid_at'],
            ];
        }

        return $payments;
    }
}
