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
 * Controller responsible for handling the execution of stock trades.
 * 
 * Access is restricted to authenticated users only.
 */
#[IsGranted('ROLE_USER')]
class TradeController extends AbstractController
{
    /**
     * Executes a trade order (BUY or SELL) for a specific stock.
     *
     * This method uses a database transaction to ensure data integrity and prevent race conditions.
     *
     * @param Request                $request The HTTP request containing trade details (ticker, action, quantity).
     * @param EntityManagerInterface $em      The entity manager for database operations.
     *
     * @return Response Redirects back to the referring page with a success or error flash message.
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

        // Prevents the background Ticker from splitting or bankrupting the stock mid-trade
        $em->getConnection()->beginTransaction();

        try {
            // Fetch the stock
            $stock = $em->getRepository(Stock::class)->findOneBy(['ticker' => $ticker]);
            if (!$stock) {
                throw new \Exception('Asset not found on the Aerie Exchange.');
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
                    throw new \Exception('Insufficient funds for this transaction.');
                }

                $user->setCashBalance((string)($currentCash - $totalValue));

                if (!$userStock) {
                    $userStock = new UserStock();
                    $userStock->setUser($user);
                    $userStock->setStock($stock);
                    $userStock->setQuantity(0);
                    $em->persist($userStock);
                }

                $userStock->setQuantity($userStock->getQuantity() + $quantity);
                $this->addFlash('success', "Successfully purchased {$quantity} shares of {$ticker}.");

            } elseif ($action === 'SELL') {
                if (!$userStock || $userStock->getQuantity() < $quantity) {
                    throw new \Exception('You do not own enough shares to execute this sale.');
                }

                $user->setCashBalance((string)($currentCash + $totalValue));
                $userStock->setQuantity($userStock->getQuantity() - $quantity);

                if ($userStock->getQuantity() === 0) {
                    $em->remove($userStock);
                }
                
                $this->addFlash('success', "Successfully sold {$quantity} shares of {$ticker}.");
                
            } else {
                throw new \Exception('Invalid order type.');
            }

            // Save everything and release the database lock!
            $em->persist($user);
            $em->flush();
            $em->getConnection()->commit();

        } catch (\Exception $e) {
            // If the user tries to exploit a glitch, cancel the trade entirely
            $em->getConnection()->rollBack();
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirect($request->headers->get('referer') ?? '/');
    }
}