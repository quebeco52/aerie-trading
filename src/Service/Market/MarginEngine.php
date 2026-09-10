<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\MarginStatusDTO;
use App\Entity\User;
use App\Service\Math\FinancialConstants;
use App\Service\User\Portfolio;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Regulation T margin accounting: what an account is worth, what it must keep, and when it gets called.
 *
 * Leverage is what makes a market position able to end an account rather than merely dent it. Without it a
 * player can lose their stake and no more, so every position is survivable and nothing ever forces a hand.
 *
 * Equity is cash plus long market value, less the borrowed cash and less what is owed on shorts. Short
 * sale proceeds are already in cash, which is why they do not appear as a separate term: crediting them
 * again would show a short as free money at the instant it is opened.
 *
 * Assets working in open orders count. They have left the balances the holdings tables report, but they
 * have not left the account, and treating the move as a loss called accounts for placing an order.
 */
final class MarginEngine
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Marks an account against live prices.
     *
     * One aggregate query rather than a walk over the holdings: this runs for every account on the margin
     * sweep, and loading positions to add up numbers the database can add up is the difference between a
     * sweep that finishes inside a tick and one that does not.
     */
    public function status(User $user): MarginStatusDTO
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            "SELECT
                 COALESCE(SUM(CASE WHEN us.quantity > 0 THEN us.quantity * s.price ELSE 0 END), 0) AS long_value,
                 COALESCE(SUM(CASE WHEN us.quantity < 0 THEN -us.quantity * s.price ELSE 0 END), 0) AS short_value
             FROM user_stocks us
             JOIN stocks s ON us.stock_id = s.id
             WHERE us.user_id = :user_id",
            ['user_id' => $user->getId()]
        ) ?: ['long_value' => 0.0, 'short_value' => 0.0];

        $etfValue = (float) $this->entityManager->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(ue.quantity * e.price), 0) FROM user_etfs ue JOIN etfs e ON ue.etf_id = e.id WHERE ue.user_id = :user_id',
            ['user_id' => $user->getId()]
        );

        $bondValue = (float) $this->entityManager->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(ub.quantity * b.price), 0) FROM user_bonds ub JOIN bonds b ON ub.bond_id = b.id WHERE ub.user_id = :user_id',
            ['user_id' => $user->getId()]
        );

        // The open book, on the same definition net asset value uses. Working an order moves the assets out
        // of the balances the queries above sum, and reading that as a loss is how placing a legal order
        // called an account that had not moved: a resting BUY has already left cash_balance, a resting SELL
        // has already left user_stocks, and neither has changed what the account owns.
        $escrow = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT d.escrow_cash, d.escrow_long FROM (' . Portfolio::OPEN_ORDER_ESCROW_DETAIL_SQL . ') d WHERE d.user_id = :user_id',
            ['user_id' => $user->getId()]
        ) ?: [];

        $escrowCash = (float) ($escrow['escrow_cash'] ?? 0.0);
        $escrowLong = (float) ($escrow['escrow_long'] ?? 0.0);

        return $this->evaluate(
            cash: (float) $user->getCashBalance() + $escrowCash,
            longMarketValue: (float) $row['long_value'] + $etfValue + $bondValue + $escrowLong,
            shortMarketValue: (float) $row['short_value'],
            marginDebit: (float) $user->getMarginDebit(),
            openBuyCommitment: $escrowCash
        );
    }

    /**
     * The same arithmetic against explicit balances, so a prospective trade can be tested before it is done.
     *
     * Cash and long market value are the account's, reserved orders included: escrowed cash is still cash
     * and escrowed shares are still shares, so an open order changes neither equity nor the requirement.
     * What it does change is capacity, which is what $openBuyCommitment carries.
     *
     * @param float $openBuyCommitment Cash already committed to resting buy orders.
     */
    public function evaluate(
        float $cash,
        float $longMarketValue,
        float $shortMarketValue,
        float $marginDebit,
        float $openBuyCommitment = 0.0
    ): MarginStatusDTO {
        $equity = $cash + $longMarketValue - $marginDebit - $shortMarketValue;

        $maintenance = (FinancialConstants::MAINTENANCE_MARGIN_LONG * $longMarketValue)
            + (FinancialConstants::MAINTENANCE_MARGIN_SHORT * $shortMarketValue);

        // What is left after the current book is collateralized, geared up by the initial requirement. At a
        // 50% requirement a dollar of free equity supports two dollars of new position.
        //
        // Resting buys come off the top. They are not positions yet, so they do not consume equity, but the
        // capacity behind them is spoken for: without this the same free equity backs every order placed
        // against it, and an account can work ten orders it can only afford one of.
        $initialRequirement = FinancialConstants::INITIAL_MARGIN_REQUIREMENT * ($longMarketValue + $shortMarketValue);
        $buyingPower = max(0.0, ($equity - $initialRequirement) / FinancialConstants::INITIAL_MARGIN_REQUIREMENT - $openBuyCommitment);

        return new MarginStatusDTO(
            cash: $cash,
            longMarketValue: $longMarketValue,
            shortMarketValue: $shortMarketValue,
            marginDebit: $marginDebit,
            equity: $equity,
            maintenanceRequirement: $maintenance,
            buyingPower: $buyingPower,
        );
    }

    /**
     * Whether an account can open a given amount of new exposure, and how it would be funded.
     *
     * A cash account is the special case rather than the general one: with margin disabled the answer is
     * simply whether the settled cash covers it.
     *
     * @param float $notional Value of the position being opened.
     * @return bool Whether the account may take it on.
     */
    public function canOpen(User $user, float $notional): bool
    {
        if (!$user->isMarginEnabled()) {
            return $notional <= (float) $user->getCashBalance();
        }

        return $notional <= $this->status($user)->buyingPower + 1e-6;
    }

    /**
     * Currency of long positions that must be sold to clear a margin call.
     *
     * Selling a long reduces the market value and pays down the debit by the same amount, so equity is
     * unchanged by the sale itself while the requirement falls with the position. Solving equity >= rate x
     * (LMV - X) for X gives LMV - equity/rate.
     *
     * The rate liquidated to is the maintenance requirement plus a buffer, not the requirement itself.
     * Restoring exactly the minimum leaves the account one tick of adverse movement from being called
     * again, and again after that, which is a worse outcome for the holder than selling slightly more once.
     */
    public function liquidationNotional(MarginStatusDTO $status): float
    {
        if (!$status->isCalled()) {
            return 0.0;
        }

        $targetRate = FinancialConstants::MAINTENANCE_MARGIN_LONG + FinancialConstants::LIQUIDATION_EQUITY_BUFFER;
        $required = $status->longMarketValue - ($status->equity / max(0.01, $targetRate));

        return min(
            $status->longMarketValue * FinancialConstants::MAX_LIQUIDATION_FRACTION,
            max(0.0, $required)
        );
    }
}
