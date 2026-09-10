<?php

namespace App\Controller;

use App\Entity\TradeOrder;
use App\Service\Market\TradeExecutionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller responsible for handling the execution of stock trades.
 * * Access is restricted to authenticated users only.
 */
#[IsGranted('ROLE_USER')]
class TradeController extends AbstractController
{
    /**
     * Executes a trade order (BUY or SELL) for a specific stock.
     */
    #[Route('/trade/execute', name: 'app_trade_execute', methods: ['POST'])]
    public function execute(Request $request, TradeExecutionService $tradeExecutionService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->redirectToRoute('app_login');
        }
        
        $ticker = $request->request->get('ticker');
        $action = $request->request->get('action');
        $orderType = $request->request->get('orderType', 'MARKET');
        $quantity = (int) $request->request->get('quantity');
        $limitPrice = $request->request->get('limitPrice') ?: null;

        $csrfToken = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('execute_trade', $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect($request->headers->get('referer') ?? '/');
        }

        if ($quantity <= 0 || $quantity > 1000000000) {
            $this->addFlash('error', 'Invalid quantity. Order must be between 1 and 1,000,000,000 shares.');
            return $this->redirect($request->headers->get('referer') ?? '/');
        }

        try {
            $tradeExecutionService->executeOrder($user, $ticker, $action, $orderType, $quantity, $limitPrice);
            $verb = match ($action) {
                'SHORT' => 'Sold short',
                'COVER' => 'Covered',
                'SELL' => 'Sold',
                default => 'Bought',
            };
            $this->addFlash('success', "{$verb} {$quantity} shares of {$ticker}.");
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($request->headers->get('referer') ?? '/');
    }

    /**
     * Turns margin borrowing and short selling on or off for the account.
     *
     * Off by default and opted into deliberately. Leverage changes what a losing position can do to an
     * account — from denting it to ending it — and that is not a capability to hand someone silently.
     *
     * Switching it off requires the account to be flat on both borrowed cash and borrowed stock, because
     * the alternative is an account holding positions it is no longer permitted to hold.
     */
    #[Route('/trade/margin', name: 'app_trade_margin', methods: ['POST'])]
    public function toggleMargin(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->redirectToRoute('app_login');
        }

        if (!$this->isCsrfTokenValid('toggle_margin', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token. Please try again.');

            return $this->redirect($request->headers->get('referer') ?? '/dashboard');
        }

        $enable = $request->request->get('enable') === '1';

        if (!$enable) {
            $hasDebit = (float) $user->getMarginDebit() > 0.0;
            $hasShorts = (int) $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM user_stocks WHERE user_id = :user_id AND quantity < 0',
                ['user_id' => $user->getId()]
            ) > 0;

            if ($hasDebit || $hasShorts) {
                $this->addFlash('error', 'Close every short position and repay the margin loan before disabling margin.');

                return $this->redirect($request->headers->get('referer') ?? '/dashboard');
            }
        }

        $user->setMarginEnabled($enable);
        $entityManager->flush();

        $this->addFlash('success', $enable
            ? 'Margin enabled. Borrowed positions can lose more than the cash behind them.'
            : 'Margin disabled.');

        return $this->redirect($request->headers->get('referer') ?? '/dashboard');
    }

    #[Route('/trade/cancel/{id}', name: 'app_trade_cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request, TradeExecutionService $tradeExecutionService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->redirectToRoute('app_login');
        }

        $csrfToken = $request->request->get('_token');
        
        if (!$this->isCsrfTokenValid('cancel_trade_'.$id, $csrfToken)) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirect($request->headers->get('referer') ?? '/');
        }

        try {
            $tradeExecutionService->cancelOrder($user, $id);
            $this->addFlash('success', "Order cancelled successfully and funds/shares refunded.");
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($request->headers->get('referer') ?? '/');
    }
}