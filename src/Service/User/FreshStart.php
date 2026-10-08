<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\TradeOrder;
use App\Entity\User;
use App\Service\Macro\MacroStateProvider;
use App\Service\Market\Trading\TradeExecutionService;
use App\Service\Season\SeasonService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Closes an account's book and reopens it with starting capital, for a player who has lost too much to go on.
 *
 * Only long positions are cleared directly. A short moves the name's short interest and borrow, and an option moves
 * the contract's open interest, so both must be closed through the market first; the player does that. Open orders
 * are cancelled through the trade desk so escrow is returned and the order book's bounds are rewritten. The
 * account's order, income and value history goes with the book, since cost basis is rebuilt from filled orders. The
 * open season is forfeited: a restart is not a second attempt at the same season.
 */
final class FreshStart
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TradeExecutionService $tradeExecution,
        private readonly SeasonService $seasons,
        private readonly MacroStateProvider $macroStates,
        private readonly Portfolio $portfolio,
    ) {}

    /** Why the account cannot start afresh yet, or null when it can. */
    public function blocker(User $user): ?string
    {
        $conn = $this->entityManager->getConnection();

        if ((int) $conn->fetchOne('SELECT COUNT(*) FROM user_stocks WHERE user_id = :u AND quantity < 0', ['u' => $user->getId()]) > 0) {
            return 'Buy back your short positions first.';
        }

        if ((int) $conn->fetchOne('SELECT COUNT(*) FROM user_options WHERE user_id = :u AND quantity <> 0', ['u' => $user->getId()]) > 0) {
            return 'Close your option positions first.';
        }

        return null;
    }

    /** The cash a restart pays today. */
    public function startingCapital(): string
    {
        return SeasonService::startingCapital($this->macroStates->liveState()->consumerPriceLevel);
    }

    public function restart(User $user): void
    {
        $blocker = $this->blocker($user);
        if ($blocker !== null) {
            throw new \RuntimeException($blocker);
        }

        foreach ($this->entityManager->getRepository(TradeOrder::class)->findBy(['user' => $user, 'status' => TradeOrder::STATUS_OPEN]) as $order) {
            $this->tradeExecution->cancelOrder($user, (int) $order->getId());
        }

        $conn = $this->entityManager->getConnection();
        $capital = $this->startingCapital();

        $conn->transactional(function () use ($conn, $user, $capital): void {
            $id = ['u' => $user->getId()];
            foreach (['user_stocks', 'user_etfs', 'user_bonds', 'trade_orders', 'dividend_payment', 'coupon_payment', 'portfolio_history'] as $table) {
                $conn->executeStatement("DELETE FROM {$table} WHERE user_id = :u", $id);
            }
            $conn->executeStatement(
                'UPDATE users SET cash_balance = :cash, margin_debit = 0 WHERE id = :u',
                ['cash' => $capital, 'u' => $user->getId()]
            );
        });

        $this->entityManager->refresh($user);
        $this->seasons->forfeit($user);
        $this->portfolio->recordUserSnapshot($user);
        $this->entityManager->flush();
    }
}
