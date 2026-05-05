<?php

namespace App\Controller;

use App\Entity\Stock;
use App\Entity\User;
use App\Entity\UserStock;
use App\Service\Portfolio;
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
    public function execute(Request $request, EntityManagerInterface $em, Portfolio $portfolio): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        
        $ticker = $request->request->get('ticker');
        $action = $request->request->get('action');
        $quantity = (int) $request->request->get('quantity');

        $csrfToken = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('execute_trade', $csrfToken)) {
            $this->addFlash('error', 'Invalid security token. Please try again.');
            return $this->redirect($request->headers->get('referer') ?? '/');
        }

        if ($quantity <= 0 || $quantity > 1000000000) {
            $this->addFlash('error', 'Invalid quantity. Order must be between 1 and 1,000,000,000 shares.');
            return $this->redirect($request->headers->get('referer') ?? '/');
        }

        // Prevents the background Ticker from splitting or bankrupting the stock mid-trade
        $em->getConnection()->beginTransaction();

        try {
            // Lock the user record to prevent race conditions (double spending)
            $em->lock($user, \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);

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
                $newQuantity = $userStock->getQuantity() - $quantity;
                $userStock->setQuantity($newQuantity);

                if ($newQuantity === 0) {
                    $em->remove($userStock);
                }
                
                $this->addFlash('success', "Successfully sold {$quantity} shares of {$ticker}.");
                
            } else {
                throw new \Exception('Invalid order type.');
            }

            // Save the trade to the database so the new cash/quantities exist
            $em->persist($user);
            $em->flush(); 

            // Record the historical snapshot using the fresh data
            $portfolio->recordUserSnapshot($user);

            // Save the snapshot and release the database lock!
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