<?php

namespace App\Controller;

use App\Entity\Stock;
use App\Entity\Etf;
use App\Service\Market\PriceChangeFeed;
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
    public function index(EntityManagerInterface $entityManager, \App\Service\Macro\MacroEngine $macroEngine, PriceChangeFeed $priceChangeFeed): Response
    {
        $etf = $entityManager->getRepository(Etf::class)->findOneBy(['ticker' => 'LBI']);
        $stocks = $entityManager->getRepository(Stock::class)->findAll();
        $macroState = $macroEngine->getLiveState();

        $marketData = $this->buildBaseMarketData($stocks, $priceChangeFeed->changeByTicker($stocks));
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
                'top_stocks' => array_slice($marketData, 0, 6), // Show a preview of the top 6 stocks
                'breadth'    => $this->buildBreadth($marketData),
                'macro'      => $macroState->toArray(),
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
    public function apiMarket(EntityManagerInterface $entityManager, PriceChangeFeed $priceChangeFeed): Response
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
        foreach ($this->buildBaseMarketData($stocks, $priceChangeFeed->changeByTicker($stocks)) as $row) {
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
                'changePercent' => $row['changePercent'],
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
     * @param array<string, float> $changes ticker => fractional change, absent when unbuffered
     * @return array<int, array{ticker: string, name: string, sector: string, price: float, shares: float, marketCap: float, treasury: float, equity: float, currentRoic: float, isBankrupt: bool, changePercent: float|null}>
     */
    private function buildBaseMarketData(array $stocks, array $changes = []): array
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
                // Null, not zero: "no history buffered yet" and "has not moved" are different
                // facts, and the table prints them differently.
                'changePercent' => $changes[$stock->getTicker()] ?? null,
            ];
        }
        return $marketData;
    }

    /**
     * Summarises the session for the signed-out landing page: how many names are up, how many
     * are down, and the single largest move either way.
     *
     * Names with nothing buffered yet are counted as unchanged rather than as decliners, so a
     * freshly started exchange does not read as a market-wide sell-off.
     *
     * @param array<int, array{ticker: string, changePercent: float|null, isBankrupt: bool}> $marketData
     * @return array{advancing: int, declining: int, unchanged: int, topMover: array{ticker: string, changePercent: float}|null}
     */
    private function buildBreadth(array $marketData): array
    {
        $advancing = 0;
        $declining = 0;
        $unchanged = 0;
        $topMover  = null;

        foreach ($marketData as $row) {
            $change = $row['changePercent'];

            if ($row['isBankrupt'] || $change === null || abs($change) < 0.00005) {
                $unchanged++;
                continue;
            }

            $change > 0 ? $advancing++ : $declining++;

            if ($topMover === null || abs($change) > abs($topMover['changePercent'])) {
                $topMover = ['ticker' => $row['ticker'], 'changePercent' => $change];
            }
        }

        return [
            'advancing' => $advancing,
            'declining' => $declining,
            'unchanged' => $unchanged,
            'topMover'  => $topMover,
        ];
    }
}
