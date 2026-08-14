<?php

namespace App\Controller;

use App\Entity\TradeOrder;
use App\Service\Market\TradeExecutionService;
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
            $this->addFlash('success', "Order placed successfully for {$quantity} shares of {$ticker}.");
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($request->headers->get('referer') ?? '/');
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