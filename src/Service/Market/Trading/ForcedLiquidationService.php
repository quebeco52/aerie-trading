<?php

declare(strict_types=1);

namespace App\Service\Market\Trading;

use App\Entity\Notification;
use App\Entity\Stock;
use App\Entity\TradeOrder;
use App\Entity\User;
use App\Service\Math\Decimal;
use App\Service\Math\FinancialConstants;
use App\Service\Notification\PlayerNotifier;
use App\Service\User\CashLedger;
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
        private readonly ?PlayerNotifier $notifier = null,
        private readonly CashLedger $cashLedger = new CashLedger(),
    ) {}

    /** @var array<int, array<string, list<string>>> Forced orders filled this sweep, by account and origin. */
    private array $forced = [];

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

        $this->restateShortInterest();
        $this->netCashAgainstLoans();
        $interest = $this->accrueMarginInterest($policyRate, $dt);
        $borrowFees = $this->accrueBorrowFees($dt);

        $liquidated = 0;
        $boughtIn = 0;

        $this->forced = [];
        foreach ($this->accountsAtRisk() as $user) {
            $boughtIn += $this->processBuyIns($user);
            $liquidated += $this->processMarginCall($user);
        }
        $this->notifyForced();

        return [
            'interest' => $interest,
            'borrowFees' => $borrowFees,
            'liquidated' => $liquidated,
            'boughtIn' => $boughtIn,
        ];
    }

    /**
     * Puts every name's short interest back to what its accounts are short, before the fees and buy-ins priced off it.
     * Fills move the total as they go, but the web process and the ticker each move their own copy of the stock, so the
     * running figure drifts between them; read from the positions once a sweep, the drift never outlives the week.
     */
    public function restateShortInterest(): void
    {
        $shorted = $this->entityManager->getConnection()->fetchAllKeyValue(
            'SELECT s.id, COALESCE(-SUM(us.quantity), 0)
             FROM stocks s
             LEFT JOIN user_stocks us ON us.stock_id = s.id AND us.quantity < 0
             GROUP BY s.id',
        );

        foreach ($shorted as $stockId => $shares) {
            $stock = $this->entityManager->getRepository(Stock::class)->find($stockId);
            $restated = Decimal::format((float) $shares, 2);

            if ($stock instanceof Stock && $stock->getShortInterestShares() !== $restated) {
                $stock->setShortInterestShares($restated);
            }
        }

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE stocks s
             LEFT JOIN (SELECT stock_id, -SUM(quantity) AS shorted FROM user_stocks WHERE quantity < 0 GROUP BY stock_id) us
                ON us.stock_id = s.id
             SET s.short_interest_shares = COALESCE(us.shorted, 0)',
        );
    }

    /**
     * Nets every account whose cash sits beside its margin loan or below zero (CashLedger::net()), before the loan is
     * charged: the moves written to cash in bulk skip the ledger, so a dividend landed as cash while a debit was still
     * owed and a borrow fee drove cash negative, where it was charged nothing. Each account is locked and read fresh,
     * as a trade on it is.
     */
    private function netCashAgainstLoans(): void
    {
        $connection = $this->entityManager->getConnection();
        $ids = $connection->fetchFirstColumn('SELECT id FROM users WHERE cash_balance < 0 OR (cash_balance > 0 AND margin_debit > 0)');

        foreach ($ids as $id) {
            $connection->beginTransaction();
            try {
                $user = $this->entityManager->find(User::class, $id);
                if ($user instanceof User) {
                    $this->entityManager->lock($user, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                    $this->entityManager->refresh($user);
                    $this->cashLedger->net($user);
                    $this->entityManager->flush();
                }
                $connection->commit();
            } catch (\Throwable $e) {
                $connection->rollBack();
                $this->logger->warning('Could not net an account against its margin loan', ['user' => $id, 'error' => $e->getMessage()]);
            }
        }
    }

    /** The annual rate charged on borrowed cash: the policy rate (floored at zero) plus the brokerage's spread. */
    public static function marginLoanRate(float $policyRate): float
    {
        return max(0.0, $policyRate) + FinancialConstants::MARGIN_LOAN_SPREAD;
    }

    /**
     * Charges interest on borrowed cash at the policy rate plus the brokerage's spread.
     *
     * The mirror of Portfolio::accrueCashInterest, which pays the sweep rate on idle balances. Compounded
     * continuously to match it and the rest of the engine's time stepping.
     */
    private function accrueMarginInterest(float $policyRate, float $dt): float
    {
        $rate = self::marginLoanRate($policyRate);
        $periodRate = exp($rate * $dt) - 1.0;

        if ($periodRate <= 0.0) {
            return 0.0;
        }

        $connection = $this->entityManager->getConnection();

        $charged = (float) $connection->fetchOne(
            'SELECT COALESCE(SUM(ROUND(margin_debit * :rate, 2)), 0) FROM users WHERE margin_debit > 0',
            ['rate' => $periodRate],
        );

        $connection->executeStatement(
            'UPDATE users SET margin_debit = ROUND(margin_debit * (1 + :rate), 2) WHERE margin_debit > 0',
            ['rate' => $periodRate],
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
            'SELECT DISTINCT stock_id FROM user_stocks WHERE quantity < 0',
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
                ['fee' => $periodFee, 'stock_id' => $stockId],
            );

            // The running total on the position is what a holder is shown; the cash leaves immediately,
            // which is what makes a fee rise actually bite rather than accumulate somewhere invisible.
            $connection->executeStatement(
                'UPDATE user_stocks SET borrow_accrued = borrow_accrued + ROUND(-quantity * :fee, 4)
                 WHERE stock_id = :stock_id AND quantity < 0',
                ['fee' => $periodFee, 'stock_id' => $stockId],
            );

            $connection->executeStatement(
                'UPDATE users u
                 INNER JOIN user_stocks us ON us.user_id = u.id
                 SET u.cash_balance = u.cash_balance - ROUND(-us.quantity * :fee, 2)
                 WHERE us.stock_id = :stock_id AND us.quantity < 0',
                ['fee' => $periodFee, 'stock_id' => $stockId],
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
             WHERE u.margin_debit > 0 OR us.id IS NOT NULL OR uo.id IS NOT NULL',
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
            ['user_id' => $user->getId()],
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

            if ($this->forceOrder($user, (string) $row['ticker'], 'COVER', $quantity, TradeOrder::ORIGIN_BUY_IN)) {
                $boughtIn++;
            }
        }

        return $boughtIn;
    }

    /**
     * Closes enough of an account to clear a maintenance call: written contracts first, then whatever it holds, largest
     * position first, each sized to what is still short (MarginEngine::liquidationNotional()) and the account re-marked
     * after every order. Shares, funds, bonds and long contracts are sold and short stock is bought back: selling longs
     * alone left an account short nothing but stock to run on to any negative equity.
     *
     * Largest first, because each order pays its own execution cost and a call cleared in one trade costs the account
     * less than the same notional worked across five.
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

        // Written contracts are bought back first to eliminate short option assignment and naked margin risk.
        $closed = $this->closeWrittenOptions($user);

        foreach ($this->openPositions($user) as $position) {
            $status = $this->marginEngine->status($user);
            if (!$status->isCalled()) {
                break;
            }

            $unitValue = (float) $position['unit_value'];
            $held = (int) $position['quantity'];
            if ($unitValue <= 0.0 || $held === 0) {
                continue;
            }

            $side = $position['side'];
            $notional = $this->marginEngine->liquidationNotional($status, $side);
            $quantity = (int) min(abs($held), ceil($notional / $unitValue));
            if ($quantity <= 0) {
                continue;
            }

            if ($this->forceOrder($user, (string) $position['ticker'], $side === 'short' ? 'COVER' : 'SELL', $quantity, TradeOrder::ORIGIN_MARGIN_CALL)) {
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * Everything an account holds that a margin call can close, largest first: long and short stock, funds, bonds still
     * trading, and long contracts, each with the value of one unit and the side it is closed from.
     *
     * @return list<array{ticker: string, quantity: int|string, unit_value: float|string, side: string}>
     */
    private function openPositions(User $user): array
    {
        /** @var list<array{ticker: string, quantity: int|string, unit_value: float|string, side: string}> */
        return $this->entityManager->getConnection()->fetchAllAssociative(
            "SELECT p.ticker, p.quantity, p.unit_value, p.side FROM (
                 SELECT s.ticker, us.quantity, s.price AS unit_value, IF(us.quantity < 0, 'short', 'long') AS side
                 FROM user_stocks us JOIN stocks s ON us.stock_id = s.id
                 WHERE us.user_id = :user_id AND us.quantity <> 0
                 UNION ALL
                 SELECT e.ticker, ue.quantity, e.price, 'long'
                 FROM user_etfs ue JOIN etfs e ON ue.etf_id = e.id
                 WHERE ue.user_id = :user_id AND ue.quantity > 0
                 UNION ALL
                 SELECT b.ticker, ub.quantity, b.price, 'long'
                 FROM user_bonds ub JOIN bonds b ON ub.bond_id = b.id
                 WHERE ub.user_id = :user_id AND ub.quantity > 0 AND b.status = :bond_active
                 UNION ALL
                 SELECT oc.ticker, uo.quantity, oc.price * :multiplier, 'option'
                 FROM user_options uo JOIN option_contracts oc ON uo.option_contract_id = oc.id
                 WHERE uo.user_id = :user_id AND uo.quantity > 0 AND oc.status = :option_active
             ) p
             ORDER BY ABS(p.quantity * p.unit_value) DESC",
            [
                'user_id' => $user->getId(),
                'bond_active' => \App\Entity\Bond::STATUS_ACTIVE,
                'option_active' => \App\Entity\OptionContract::STATUS_ACTIVE,
                'multiplier' => FinancialConstants::OPTION_CONTRACT_MULTIPLIER,
            ],
        );
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
            ['user_id' => $user->getId(), 'active' => \App\Entity\OptionContract::STATUS_ACTIVE],
        );

        $closed = 0;

        foreach ($written as $position) {
            $contracts = (int) $position['contracts'];

            if ($contracts <= 0) {
                continue;
            }

            if (!$this->forceOrder($user, (string) $position['ticker'], 'COVER', $contracts, TradeOrder::ORIGIN_MARGIN_CALL)) {
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
            $assetType = $this->tradeExecution->executeOrder($user, $ticker, $action, 'MARKET', $quantity, null, null, $reason)->assetType;

            if ($user->getId() !== null) {
                $unit = $assetType === 'OPTION' ? ($quantity === 1 ? ' contract' : ' contracts') : '';
                $this->forced[$user->getId()][$reason][] = sprintf(
                    '%s %s %s%s',
                    $action === 'SELL' ? 'sold' : 'bought back',
                    number_format($quantity),
                    $ticker,
                    $unit,
                );
            }

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

    /** One message per account and cause, listing what was sold or bought back in its name this sweep. */
    private function notifyForced(): void
    {
        if ($this->notifier === null || $this->forced === []) {
            return;
        }

        foreach ($this->forced as $userId => $byOrigin) {
            foreach ($byOrigin as $origin => $lines) {
                $isBuyIn = $origin === TradeOrder::ORIGIN_BUY_IN;
                $this->notifier->queue(
                    $userId,
                    $isBuyIn ? Notification::KIND_BUY_IN : Notification::KIND_MARGIN_CALL,
                    $isBuyIn
                        ? 'Buy-in: the lender recalled stock you had sold short'
                        : 'Margin call: your broker closed positions to meet the maintenance requirement',
                    ucfirst(implode('; ', $lines)) . '.',
                    null,
                    '/dashboard',
                );
            }
        }

        $this->forced = [];
        $this->notifier->publish();
    }
}
