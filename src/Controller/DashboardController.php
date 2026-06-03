<?php


namespace App\Controller;

use App\Entity\User;
use App\Entity\UserStock;
use App\Entity\UserEtf;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for the user's private portfolio dashboard.
 */
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    /**
     * Renders the user's portfolio, including holdings, cash balance, and performance history.
     */
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Fetch holdings with joined assets to completely eliminate N+1 lazy-loading queries
        $stockHoldings = $entityManager->createQuery(
            'SELECT us, s FROM App\Entity\UserStock us JOIN us.stock s WHERE us.user = :user'
        )->setParameter('user', $user)->getResult();

        $etfHoldings = $entityManager->createQuery(
            'SELECT ue, e FROM App\Entity\UserEtf ue JOIN ue.etf e WHERE ue.user = :user'
        )->setParameter('user', $user)->getResult();

        $portfolioValue = (float) $user->getCashBalance();
        $assetData = [];

        foreach ($stockHoldings as $holding) {
            $stock = $holding->getStock();
            $currentPrice = (float) $stock->getPrice();
            $quantity = $holding->getQuantity();
            $totalValue = $currentPrice * $quantity;

            $portfolioValue += $totalValue;

            $assetData[] = [
                'ticker' => $stock->getTicker(),
                'name' => $stock->getName(),
                'quantity' => $quantity,
                'price' => $currentPrice,
                'totalValue' => $totalValue,
            ];
        }

        foreach ($etfHoldings as $holding) {
            $etf = $holding->getEtf();
            $currentPrice = (float) $etf->getPrice();
            $quantity = $holding->getQuantity();
            $totalValue = $currentPrice * $quantity;

            $portfolioValue += $totalValue;

            $assetData[] = [
                'ticker' => $etf->getTicker(),
                'name' => $etf->getName(),
                'quantity' => $quantity,
                'price' => $currentPrice,
                'totalValue' => $totalValue,
            ];
        }

        return $this->render('dashboard/index.html.twig', [
            'user' => $user,
            'holdings' => $assetData,
            'portfolioValue' => $portfolioValue,
        ]);
    }
}