<?php

namespace App\Controller;

use App\Entity\Stock;
use App\Entity\User;
use App\Entity\UserStock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller responsible for handling stock trade executions.
 *
 * This controller processes Buy and Sell orders submitted by users.
 * It validates sufficient funds (for buys) or sufficient shares (for sells),
 * updates the user's cash balance and portfolio holdings, and persists
 * the transaction state to the database.
 */
#[IsGranted('ROLE_USER')]
class TradeController extends AbstractController
{
    /**
     * Executes a trade order (Buy or Sell).
     *
     * @param Request                $request The HTTP request containing trade details (ticker, action, quantity).
     * @param EntityManagerInterface $em      The Doctrine Entity Manager for database transactions.
     *
     * @return Response Redirects back to the referrer page with a success or error flash message.
     */
    #[Route('/trade/execute', name: 'app_trade_execute', methods: ['POST'])]
    public function execute(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        
        $ticker = $request->request->get('ticker');
        $action = $request->request->get('action');
        $quantity = (int) $request->request->get('quantity');

        if ($quantity <= 0) {
            $this->addFlash('error', 'Invalid quantity. You must trade at least 1 share.');
            return $this->redirect($request->headers->get('referer') ?? '/');
        }

        // Fetch the stock
        $stock = $em->getRepository(Stock::class)->findOneBy(['ticker' => $ticker]);
        if (!$stock) {
            $this->addFlash('error', 'Asset not found on the Aerie Exchange.');
            return $this->redirect('/');
        }

        $livePrice = (float) $stock->getPrice();
        $totalValue = $livePrice * $quantity;
        $currentCash = (float) $user->getCashBalance();

        $userStock = $em->getRepository(UserStock::class)->findOneBy([
            'user' => $user,
            'stock' => $stock
        ]);

        if ($action === 'BUY') {
            if ($currentCash < $totalValue) {
                $this->addFlash('error', 'Insufficient funds for this transaction.');
                return $this->redirect($request->headers->get('referer'));
            }

            $user->setCashBalance((string)($currentCash - $totalValue));

            if (!$userStock) {
                $userStock = new UserStock();
                $userStock->setUser($user);
                $userStock->setStock($stock);
                $userStock->setQuantity(0);
                $em->persist($userStock);
            }

            // Add the shares
            $userStock->setQuantity($userStock->getQuantity() + $quantity);
            $this->addFlash('success', "Successfully purchased {$quantity} shares of {$ticker}.");

        } elseif ($action === 'SELL') {
            // Check if they have enough shares to sell
            if (!$userStock || $userStock->getQuantity() < $quantity) {
                $this->addFlash('error', 'You do not own enough shares to execute this sale.');
                return $this->redirect($request->headers->get('referer'));
            }

            $user->setCashBalance((string)($currentCash + $totalValue));
            
            $userStock->setQuantity($userStock->getQuantity() - $quantity);

            // Clean up the database row if they sold out of their entire position
            if ($userStock->getQuantity() === 0) {
                $em->remove($userStock);
            }
            
            $this->addFlash('success', "Successfully sold {$quantity} shares of {$ticker}.");
            
        } else {
            $this->addFlash('error', 'Invalid order type.');
            return $this->redirect($request->headers->get('referer'));
        }

        // Save everything to the database
        $em->persist($user);
        $em->flush();

        return $this->redirect($request->headers->get('referer'));
    }
}