<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\Entity\Stock;
use App\Entity\User;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The periodic sweep that makes leverage cost something and, eventually, take something.
 *
 * Four things happen here, in an order that matters: borrowed cash accrues interest, borrowed stock accrues
 * a fee, accounts that no longer meet their maintenance requirement are liquidated, and shorts in names
 * whose lendable supply has run out are bought in. Charges come first so a position is called on what it
 * actually costs to keep, not on what it cost before the bill arrived.
 *
 * A short squeeze needs no code of its own. A rising price takes equity from shorts, which calls them;
 * covering pushes the price further through the impact model; utilization of the remaining borrow climbs,
 * so the fee climbs; and past the recall threshold the stock is taken back whether the account is solvent
 * or not. Every link is priced somewhere in this sweep or in MarginEngine, and none of it is scripted.
 */
final class ForcedLiquidationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MarginEngine $marginEngine,
        private readonly SecuritiesLendingDesk $lendingDesk,
        private readonly TradeExecutionService $tradeExecution,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Runs one sweep.
     *
     * Deliberately outside the tick transaction. A liquidation is an order like any other — it crosses the
     * spread, pays its own impact, and moves the price for everyone — so it goes through the same execution
     * path rather than teleporting stock out of an account at mid. That path opens its own transaction, and
     * nesting it inside the market tick would couple one account's forced sale to the whole market's state.
     *
     * @param float $policyRate The prevailing policy rate; margin is lent at a spread over it.
     * @param float $dt         Elapsed simulated time in years since the last sweep.
     * @return array{interest: float, borrowFees: float, liquidated: int, boughtIn: int}
     */
    public function sweep(float $policyRate, float $dt): array
    {
        if ($dt <= 0.0) {
            return ['interest' => 0.0, 'borrowFees' => 0.0, 'liquidated' => 0, 'boughtIn' => 0];
        }

        $interest = $this->accrueMarginInterest($policyRate, $dt);
        $borrowFees = $this->accrueBorrowFees($dt);

        $liquidated = 0;
        $boughtIn = 0;

        foreach ($this->accountsAtRisk() as $user) {
            $boughtIn += $this->processBuyIns($user);
            $liquidated += $this->processMarginCall($user);
        }

        return [
            'interest' => $interest,
            'borrowFees' => $borrowFees,
            'liquidated' => $liquidated,
            'boughtIn' => $boughtIn,
        ];
    }

    /**
     * Charges interest on borrowed cash at the policy rate plus the brokerage's spread.
     *
     * The mirror of Portfolio::accrueCashInterest, which pays the sweep rate on idle balances. Compounded
     * continuously to match it and the rest of the engine's time stepping.
     */
    private function accrueMarginInterest(float $policyRate, float $dt): float
    {
        $rate = max(0.0, $policyRate) + FinancialConstants::MARGIN_LOAN_SPREAD;
        $periodRate = exp($rate * $dt) - 1.0;

        if ($periodRate <= 0.0) {
            return 0.0;
        }

        $connection = $this->entityManager->getConnection();

        $charged = (float) $connection->fetchOne(
            'SELECT COALESCE(SUM(ROUND(margin_debit * :rate, 2)), 0) FROM users WHERE margin_debit > 0',
            ['rate' => $periodRate]
        );

        $connection->executeStatement(
            'UPDATE users SET margin_debit = ROUND(margin_debit * (1 + :rate), 2) WHERE margin_debit > 0',
            ['rate' => $periodRate]
        );

        return $charged;
    }

    /**
     * Charges the borrow fee on every open short, priced from each name's own utilization.
     *
     * Per stock rather than per position, because the fee is a property of the name: everyone short the
     * same stock pays the same rate, and it is that shared rate rising with utilization that turns a
     * crowded short into an expensive one.
     */
    private function accrueBorrowFees(float $dt): float
    {
        $connection = $this->entityManager->getConnection();

        $shortedStockIds = $connection->fetchFirstColumn(
            'SELECT DISTINCT stock_id FROM user_stocks WHERE quantity < 0'
        );

        $total = 0.0;

        foreach ($shortedStockIds as $stockId) {
            $stock = $this->entityManager->getRepository(Stock::class)->find($stockId);
            if (!$stock instanceof Stock) {
                continue;
            }

            $feeRate = $this->lendingDesk->borrowFee($stock);
            $periodFee = (float) $stock->getPrice() * $feeRate * $dt;

            if ($periodFee <= 0.0) {
                continue;
            }

            $total += (float) $connection->fetchOne(
                'SELECT COALESCE(SUM(ROUND(-quantity * :fee, 4)), 0) FROM user_stocks WHERE stock_id = :stock_id AND quantity < 0',
                ['fee' => $periodFee, 'stock_id' => $stockId]
            );

            // The running total on the position is what a holder is shown; the cash leaves immediately,
            // which is what makes a fee rise actually bite rather than accumulate somewhere invisible.
            $connection->executeStatement(
                'UPDATE user_stocks SET borrow_accrued = borrow_accrued + ROUND(-quantity * :fee, 4)
                 WHERE stock_id = :stock_id AND quantity < 0',
                ['fee' => $periodFee, 'stock_id' => $stockId]
            );

            $connection->executeStatement(
                'UPDATE users u
                 INNER JOIN user_stocks us ON us.user_id = u.id
                 SET u.cash_balance = u.cash_balance - ROUND(-us.quantity * :fee, 2)
                 WHERE us.stock_id = :stock_id AND us.quantity < 0',
                ['fee' => $periodFee, 'stock_id' => $stockId]
            );
        }

        return $total;
    }

    /**
     * Accounts carrying leverage, which are the only ones a sweep can do anything to.
     *
     * A written contract is the third way to carry it, alongside a margin loan and a short sale, and it is
     * the one that leaves no other trace: the premium the account received PAID DOWN its debit, and it holds
     * no negative stock position, so an account short nothing but naked calls would have been invisible to
     * this query — the one account whose loss is unbounded would be the one the sweep never looked at.
     *
     * @return list<User>
     */
    private function accountsAtRisk(): array
    {
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT u.id
             FROM users u
             LEFT JOIN user_stocks us ON us.user_id = u.id AND us.quantity < 0
             LEFT JOIN user_options uo ON uo.user_id = u.id AND uo.quantity < 0
             WHERE u.margin_debit > 0 OR us.id IS NOT NULL OR uo.id IS NOT NULL'
        );

        $users = [];
        foreach ($ids as $id) {
            $user = $this->entityManager->getRepository(User::class)->find($id);
            if ($user instanceof User) {
                $users[] = $user;
            }
        }

        return $users;
    }

    /**
     * Buys in shorts in names whose lendable supply has run out.
     *
     * A recall is not a margin call. It lands on whoever is short, however well collateralized and however
     * far ahead, because the lender wants the stock back. That is what makes a genuine squeeze inescapable
     * rather than merely expensive — an account cannot simply post more equity and wait it out.
     */
    private function processBuyIns(User $user): int
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT s.ticker, s.id AS stock_id, us.quantity
             FROM user_stocks us
             JOIN stocks s ON us.stock_id = s.id
             WHERE us.user_id = :user_id AND us.quantity < 0',
            ['user_id' => $user->getId()]
        );

        $boughtIn = 0;

        foreach ($rows as $row) {
            $stock = $this->entityManager->getRepository(Stock::class)->find($row['stock_id']);
            if (!$stock instanceof Stock) {
                continue;
            }

            $quantity = (int) $this->lendingDesk->buyInQuantity($stock, abs((float) $row['quantity']));
            if ($quantity <= 0) {
                continue;
            }

            if ($this->forceOrder($user, (string) $row['ticker'], 'COVER', $quantity, 'buy-in')) {
                $boughtIn++;
            }
        }

        return $boughtIn;
    }

    /**
     * Sells enough of an account's longs to clear a maintenance call.
     *
     * Largest position first, because each sale pays its own execution cost and a call cleared in one trade
     * costs the account less than the same notional worked across five.
     */
    private function processMarginCall(User $user): int
    {
        $status = $this->marginEngine->status($user);

        if (!$status->isCalled()) {
            return 0;
        }

        $this->logger->info('Margin call', [
            'user' => $user->getId(),
            'equity' => $status->equity,
            'required' => $status->maintenanceRequirement,
        ]);

        // Written contracts are bought back FIRST, and the order is not a preference. A written call is
        // collateralized by stock the account holds, so selling that stock turns a covered position into a
        // naked one and RAISES the requirement the sale was meant to reduce — a sweep that reached for the
        // shares first could liquidate an entire account without ever clearing the call. Closing the
        // contract releases the requirement outright, and it is also the only leg here whose loss is
        // unbounded, which is the other reason a desk closes it before anything else.
        $closed = $this->closeWrittenOptions($user);

        if ($closed > 0) {
            $status = $this->marginEngine->status($user);

            if (!$status->isCalled()) {
                return $closed;
            }
        }

        $remaining = $this->marginEngine->liquidationNotional($status);
        if ($remaining <= 0.0) {
            return $closed;
        }

        $positions = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT s.ticker, us.quantity, s.price
             FROM user_stocks us
             JOIN stocks s ON us.stock_id = s.id
             WHERE us.user_id = :user_id AND us.quantity > 0
             ORDER BY (us.quantity * s.price) DESC',
            ['user_id' => $user->getId()]
        );

        $sold = $closed;

        foreach ($positions as $position) {
            if ($remaining <= 0.0) {
                break;
            }

            $price = (float) $position['price'];
            if ($price <= 0.0) {
                continue;
            }

            $quantity = (int) min((float) $position['quantity'], ceil($remaining / $price));
            if ($quantity <= 0) {
                continue;
            }

            if ($this->forceOrder($user, (string) $position['ticker'], 'SELL', $quantity, 'margin call')) {
                $remaining -= $quantity * $price;
                $sold++;
            }
        }

        return $sold;
    }

    /**
     * Buys back written contracts until the account is no longer called, or until there are none left.
     *
     * The requirement a written contract carries is a function of the underlying rather than of the
     * contract's own price, so there is no notional to subtract as each one closes the way there is for a
     * stock sale: the account is simply re-marked after each buy-back and the sweep stops as soon as it is
     * solvent again. Positions are taken largest liability first, which is the closest ordering to largest
     * requirement without pricing every contract twice.
     *
     * @return int Contracts positions closed.
     */
    private function closeWrittenOptions(User $user): int
    {
        $written = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT oc.ticker, -uo.quantity AS contracts
             FROM user_options uo
             JOIN option_contracts oc ON uo.option_contract_id = oc.id
             WHERE uo.user_id = :user_id
               AND uo.quantity < 0
               AND oc.status = :active
             ORDER BY (-uo.quantity * oc.price) DESC',
            ['user_id' => $user->getId(), 'active' => \App\Entity\OptionContract::STATUS_ACTIVE]
        );

        $closed = 0;

        foreach ($written as $position) {
            $contracts = (int) $position['contracts'];

            if ($contracts <= 0) {
                continue;
            }

            if (!$this->forceOrder($user, (string) $position['ticker'], 'COVER', $contracts, 'margin call')) {
                continue;
            }

            $closed++;

            if (!$this->marginEngine->status($user)->isCalled()) {
                break;
            }
        }

        return $closed;
    }

    /**
     * Sends a forced order down the ordinary execution path.
     *
     * Through the same service a player's own order uses, so it crosses the spread, pays its own impact and
     * moves the price for everyone else. A liquidation that settled at mid would be a rescue rather than a
     * liquidation, and it is precisely the forced selling into a falling market that makes a cascade.
     *
     * A refusal is logged and swallowed: the desk may decline on size, and an account that cannot be
     * liquidated this sweep is simply called again on the next one.
     */
    private function forceOrder(User $user, string $ticker, string $action, int $quantity, string $reason): bool
    {
        try {
            $this->tradeExecution->executeOrder($user, $ticker, $action, 'MARKET', $quantity);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Forced order failed', [
                'user' => $user->getId(),
                'ticker' => $ticker,
                'action' => $action,
                'quantity' => $quantity,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
