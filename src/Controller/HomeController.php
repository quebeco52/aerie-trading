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
     * Displays the main landing page and market dashboard.
     *
     * Fetches the primary market ETF and a list of all available stocks,
     * calculates their current market capitalization, and sorts them from largest to smallest.
     *
     * @param EntityManagerInterface $entityManager The entity manager for database operations.
     *
     * @return Response Returns the rendered home page view with market data.
     */
    #[Route('/', name: 'app_home')]
    public function index(EntityManagerInterface $entityManager, \App\Service\Macro\MacroEngine $macroEngine): Response
    {
        $etf = $entityManager->getRepository(Etf::class)->findOneBy(['ticker' => 'LBI']);
        $stocks = $entityManager->getRepository(Stock::class)->findAll();
        $macroState = $macroEngine->getLiveState();

        $marketData = $this->buildBaseMarketData($stocks);
        usort($marketData, function ($a, $b) {
            if ($a['isBankrupt'] !== $b['isBankrupt']) {
                return $a['isBankrupt'] ? 1 : -1;
            }
            return $b['marketCap'] <=> $a['marketCap'];
        });

        // If the user is not logged in, render a dedicated landing page
        if (!$this->getUser()) {
            return $this->render('home/landing.html.twig', [
                'etf'        => $etf,
                'top_stocks' => array_slice($marketData, 0, 6) // Show a preview of the top 6 stocks
            ]);
        }

        return $this->render('home/index.html.twig', [
            'etf'    => $etf,
            'stocks' => $marketData,
            'macro'  => $macroState->toArray()
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
        $etf    = $entityManager->getRepository(Etf::class)->findOneBy(['ticker' => 'LBI']);
        $stocks = $entityManager->getRepository(Stock::class)->findAll();

        // Helper function for the API
        $formatLarge = function(float $val): string {
            if ($val >= 1_000_000_000_000) return number_format($val / 1_000_000_000_000, 2) . 'T';
            if ($val >= 1_000_000_000) return number_format($val / 1_000_000_000, 2) . 'B';
            return number_format($val / 1_000_000, 2) . 'M';
        };

        $marketData = [];
        foreach ($this->buildBaseMarketData($stocks) as $row) {
            $marketData[] = [
                'ticker'       => $row['ticker'],
                'name'         => $row['name'],
                'sector'       => $row['sector'],
                'price'        => number_format($row['price'], 2),
                'marketCapRaw' => $row['marketCap'],
                'marketCap'    => $formatLarge($row['marketCap']),
                'treasury'     => $formatLarge($row['treasury']),
                'equity'       => $formatLarge($row['equity']),
                'currentRoic'  => $row['currentRoic'],
                'is_bankrupt'  => $row['isBankrupt'],
            ];
        }

        usort($marketData, function ($a, $b) {
            if ($a['is_bankrupt'] !== $b['is_bankrupt']) {
                return $a['is_bankrupt'] ? 1 : -1;
            }
            return $b['marketCapRaw'] <=> $a['marketCapRaw'];
        });

        return $this->json([
            'etf'    => ['price' => $etf ? number_format((float) $etf->getPrice(), 2) : '0.00'],
            'stocks' => $marketData
        ]);
    }

    /**
     * Builds the base market data array shared across page and API endpoints.
     *
     * @param Stock[] $stocks
     * @return array<int, array{ticker: string, name: string, sector: string, price: float, shares: float, marketCap: float, treasury: float, equity: float, currentRoic: float, isBankrupt: bool}>
     */
    private function buildBaseMarketData(array $stocks): array
    {
        $marketData = [];
        foreach ($stocks as $stock) {
            $price     = (float) $stock->getPrice();
            $shares    = (float) $stock->getSharesOutstanding();
            $marketCap = $price * $shares;

            $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$stock->getIndustry() ?? 'General']['business_model'] ?? 'none';
            $isFinancial   = \App\Data\Sectors::isFinancial($businessModel);
            $effectiveRoic = $isFinancial
                ? ((float) $stock->getCurrentRoe() ?: (float) $stock->getBaselineRoe())
                : ((float) $stock->getCurrentRoic() ?: (float) $stock->getBaselineRoic());

            $marketData[] = [
                'ticker'      => $stock->getTicker(),
                'name'        => $stock->getName(),
                'sector'      => $stock->getSector(),
                'price'       => $price,
                'shares'      => $shares,
                'marketCap'   => $marketCap,
                'treasury'    => (float) $stock->getCorporateTreasury(),
                'equity'      => (float) $stock->getTotalEquity(),
                'currentRoic' => $effectiveRoic,
                'isBankrupt'  => $stock->isBankrupt(),
            ];
        }
        return $marketData;
    }

}
