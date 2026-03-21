<?php


namespace App\Controller;

use App\Entity\User;
use App\Entity\UserStock;
use App\Entity\PortfolioHistory;
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

        // Fetch user's specific stock holdings
        $holdings = $entityManager->getRepository(UserStock::class)->findBy(['user' => $user]);

        $portfolioValue = (float) $user->getCashBalance();
        $stockData = [];

        foreach ($holdings as $holding) {
            $stock = $holding->getStock();
            $currentPrice = (float) $stock->getPrice();
            $quantity = $holding->getQuantity();
            $totalValue = $currentPrice * $quantity;

            $portfolioValue += $totalValue;

            $stockData[] = [
                'ticker' => $stock->getTicker(),
                'name' => $stock->getName(),
                'quantity' => $quantity,
                'price' => $currentPrice,
                'totalValue' => $totalValue,
            ];
        }

        // Fetch historical performance for the chart
        $history = $entityManager->getRepository(PortfolioHistory::class)->findBy(
            ['user' => $user],
            ['recordedAt' => 'ASC']
        );

        return $this->render('dashboard/index.html.twig', [
            'user' => $user,
            'holdings' => $stockData,
            'portfolioValue' => $portfolioValue,
            'history' => $history,
        ]);
    }
}