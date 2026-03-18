<?php

namespace App\Controller;

use App\Entity\Stock;
use App\Entity\Etf;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller responsible for the main landing page and market dashboard.
 */
class HomeController extends AbstractController
{
    /**
     * Displays the home page with the current market overview.
     *
     * @param EntityManagerInterface $entityManager The entity manager for database queries.
     *
     * @return Response Returns the rendered home page view.
     */
    #[Route('/', name: 'app_home')]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $etf = $entityManager->getRepository(Etf::class)->findOneBy(['ticker' => 'LBI']);

        $stocks = $entityManager->getRepository(Stock::class)->findAll();

        $marketData = [];
        foreach ($stocks as $stock) {
            $price = $stock->getPrice();
            $shares = $stock->getSharesOutstanding();
            $marketCap = $price * $shares;

            $marketData[] = [
                'ticker' => $stock->getTicker(),
                'name' => $stock->getName(),
                'sector' => $stock->getSector(),
                'price' => $price,
                'marketCap' => $marketCap
            ];
        }


        usort($marketData, fn($a, $b) => $b['marketCap'] <=> $a['marketCap']);

        return $this->render('home/index.html.twig', [
            'etf' => $etf,
            'stocks' => $marketData
        ]);
    }

    /**
     * API endpoint to retrieve the latest market data, often used for live client-side updates.
     *
     * @param EntityManagerInterface $entityManager The entity manager for database queries.
     *
     * @return Response Returns a JSON response containing ETF and stock overview data.
     */
    #[Route('/api/market', name: 'api_market')]
    public function apiMarket(EntityManagerInterface $entityManager): Response
    {
        $etf = $entityManager->getRepository(Etf::class)->findOneBy(['ticker' => 'LBI']);
        $stocks = $entityManager->getRepository(Stock::class)->findAll();

        $marketData = [];
        foreach ($stocks as $stock) {
            $price = (float) $stock->getPrice();
            $shares = (float) $stock->getSharesOutstanding();
            $marketCap = $price * $shares;
            
            $marketData[] = [
                'ticker' => $stock->getTicker(),
                'name' => $stock->getName(),
                'sector' => $stock->getSector(),
                'price' => number_format($price, 2),
                'marketCapRaw' => $marketCap,
                // Format the Billions in PHP so JS doesn't have to do the math!
                'marketCap' => number_format($marketCap / 1000000000, 2) . 'B', 
            ];
        }

        // Sort descending
        usort($marketData, fn($a, $b) => $b['marketCapRaw'] <=> $a['marketCapRaw']);

        return $this->json([
            'etf' => [
                'price' => $etf ? number_format((float) $etf->getPrice(), 2) : '0.00'
            ],
            'stocks' => $marketData
        ]);
    }
}
