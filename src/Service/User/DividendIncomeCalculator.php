<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Dividend cash a user has received, read back from the ledger CorporateLedgerService writes.
 *
 * Deliberately separate from CostBasisCalculator rather than folded into it. That class is the single
 * implementation behind every average-cost surface, and the reason it exists is that two surfaces once
 * computed cost differently and printed two P&Ls for one position. Cost basis is what was paid for the
 * shares; dividends are cash received against them and never change the basis. Keeping them apart means
 * a caller that wants total return combines two clear numbers instead of one overloaded one.
 */
final class DividendIncomeCalculator
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Lifetime dividend cash received, per ticker.
     *
     * Sums `amount` rather than shares x rate: amount is the figure that was actually credited to cash,
     * and the per-share columns are in pre-split units that are not comparable across a restatement.
     *
     * @return array<string, float> ticker => cash received, for every ticker that has ever paid this user.
     */
    public function totalsByTicker(User $user): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT ticker, SUM(amount) AS total
             FROM dividend_payment
             WHERE user_id = :user_id
             GROUP BY ticker',
            ['user_id' => $user->getId()]
        );

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row['ticker']] = (float) $row['total'];
        }

        return $totals;
    }

    /**
     * The user's most recent distributions, newest first, for the income feed.
     *
     * @return list<array{ticker: string, assetType: string, sharesHeld: int, dividendPerShare: float, amount: float, paidAt: string}>
     */
    public function recentPayments(User $user, int $limit = 25): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT ticker, asset_type, shares_held, dividend_per_share, amount, paid_at
             FROM dividend_payment
             WHERE user_id = :user_id
             ORDER BY paid_at DESC, id DESC
             LIMIT ' . max(1, $limit),
            ['user_id' => $user->getId()]
        );

        $payments = [];
        foreach ($rows as $row) {
            $payments[] = [
                'ticker' => (string) $row['ticker'],
                'assetType' => (string) $row['asset_type'],
                'sharesHeld' => (int) $row['shares_held'],
                'dividendPerShare' => (float) $row['dividend_per_share'],
                'amount' => (float) $row['amount'],
                'paidAt' => (string) $row['paid_at'],
            ];
        }

        return $payments;
    }
}
