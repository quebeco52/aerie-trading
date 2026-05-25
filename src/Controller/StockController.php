<?php

namespace App\Controller;

use App\Entity\Stock;
use App\Entity\Etf;
use App\Entity\User;
use App\Entity\UserStock;
use App\Entity\StockEvent;
use Doctrine\ORM\EntityManagerInterface;
use Redis;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller responsible for handling stock and ETF views and historical data.
 */
class StockController extends AbstractController
{
    /**
     * Displays the detailed view for a specific stock or ETF.
     *
     * @param string                 $ticker      The ticker symbol of the asset.
     * @param EntityManagerInterface $entityManager The entity manager for database operations.
     *
     * @return Response Returns the rendered view with asset details.
     */
    #[Route('/stock/{ticker}', name: 'app_stock_view')]
    public function view(string $ticker, EntityManagerInterface $entityManager, \Redis $redis): Response
    {
        $isEtf = false;
        $asset = $entityManager->getRepository(Stock::class)->findOneBy(['ticker' => $ticker]);
        if (!$asset) {
            $asset = $entityManager->getRepository(Etf::class)->findOneBy(['ticker' => $ticker]);
            $isEtf = true;
        }
        if (!$asset) {
            throw $this->createNotFoundException('Ticker not found');
        }

        /** @var User|null $currentUser */
        $currentUser = $this->getUser();

        $macroStateJson = $redis->get('macroeconomic_state');
        $macroState = $macroStateJson ? json_decode($macroStateJson, true) : [
            'inflation' => 0.02,
            'output_gap' => 0.00,
            'policy_rate' => 0.04,
            'yield_10y' => 0.045,
            'nominal_gdp_index' => 1.0
        ];

        $userQuantity = 0;
        if ($currentUser) {
            $user = $entityManager->getRepository(User::class)->find($currentUser->getId());
            $userStock = $entityManager->getRepository(UserStock::class)->findOneBy(['user' => $user, 'stock' => $isEtf ? null : $asset]);
            $userQuantity = $userStock ? $userStock->getQuantity() : 0;
        }

        $marketCap = 0;
        $peRatio = null;
        $targetPE = 20.00;
        $marketShare = 0;
        $isLeveraged = false;
        $investedCapital = 0.0;



        if (!$isEtf) {
            $marketCap = (float) $asset->getPrice() * (float) $asset->getSharesOutstanding();
            $eps = (float) $asset->getEarningsPerShare();
            $peRatio = ($eps > 0) ? ((float) $asset->getPrice() / $eps) : null;

            $nominalGdpIndex = $macroState['nominal_gdp_index'] ?? 1.0;
            $baselineSectorTam = 1_000_000_000_000;
            $samRatio = (float) $asset->getSamRatio();
            $dynamicSam = $baselineSectorTam * $nominalGdpIndex * $samRatio;
            
            $isLeveraged = \App\Data\Sectors::INDUSTRY_METRICS[$asset->getIndustry() ?? 'General']['leveraged_industry'] ?? false;
            $investedCapital = $asset->getInvestedCapital();
            $evaluationCapital = $isLeveraged ? (float) $asset->getTotalEquity() : $asset->getInvestedCapital();
            
            $marketShare = min(0.9999, $evaluationCapital / max(1.0, $dynamicSam));
        }

        $generalInfo = $asset->getDescription();
        $quote = \App\Data\StockInfo::getQuote($ticker);

        $allAssets = [];
        if ($isEtf) {
            $allAssets = $entityManager->getRepository(Stock::class)->findAll();
        }

        $events = [];
        if (!$isEtf) {
            $events = $entityManager->getRepository(StockEvent::class)->findBy(
                ['stock' => $asset],
                ['recordedAt' => 'DESC'], // Newest first
                15 // Limit to 10
            );
        }

        $economicCycle = $redis->get('economy_state') ?: 'Expansion';

        return $this->render('stock/index.html.twig', [
            'asset' => $asset,
            'isEtf' => $isEtf,
            'isLeveraged' => $isLeveraged,
            'investedCapital' => $investedCapital,
            'userQuantity' => $userQuantity,
            'marketCap' => $marketCap,
            'peRatio' => $peRatio,
            'targetPE' => $targetPE,
            'generalInfo' => $generalInfo,
            'allAssets' => $allAssets,
            'events' => $events,
            'ticksPerYear' => (int) ($_ENV['SIM_TICKS_PER_YEAR'] ?? 14400),
            'economic_cycle' => $economicCycle,
            'macro' => $macroState,
            'marketShare' => $marketShare,
            'quote' => $quote
        ]);
    }

    /**
     * API endpoint to retrieve historical price data for charting.
     *
     * @param Request                $request       The HTTP request containing 'ticker' and 'range' parameters.
     * @param EntityManagerInterface $entityManager The entity manager.
     * @param \Redis                 $redis         The Redis instance for caching short-term data.
     *
     * @return JsonResponse Returns a JSON array of historical data points.
     */
    #[Route('/api/history', name: 'api_history')]
    public function history(Request $request, EntityManagerInterface $entityManager, \Redis $redis): JsonResponse
    {
        $ticker = $request->query->get('ticker');
        $range = $request->query->get('range', '1y');

        if (!$ticker) return $this->json([]);

        $ticksPerYear = (int) ($_ENV['SIM_TICKS_PER_YEAR'] ?? 14400);
        $ticksPerMonth = (int) ceil($ticksPerYear / 12);
        $ticksPerWeek = (int) ceil($ticksPerYear / 52);

        // Redis cache for short timeframes
        if (in_array($range, ['1w', '1m'])) {
            $limit = $range === '1w' ? $ticksPerWeek : $ticksPerMonth;
            $cacheKey = "chart_buffer:{$ticker}";
            $redisData = $redis->lRange($cacheKey, 0, $limit - 1);
            $results = [];
            foreach ($redisData as $jsonStr) {
                $results[] = json_decode($jsonStr, true);
            }
            return $this->json(array_reverse($results));
        }

        $ranges = [
            '3m'  => 1200,
            '6m'  => 2400,
            '1y'  => 4800,
            '3y'  => 14400,
            '5y'  => 24000,
            '10y' => 48000,
            'max' => 999999
        ];
        $limit = $ranges[$range] ?? 14400;

        $dbLimit = min($limit, 500000);
        $maxChartPoints = 5000;
        $conn = $entityManager->getConnection();

        // Fetch the target asset ID
        $stock = $entityManager->getRepository(Stock::class)->findOneBy(['ticker' => $ticker]);
        if ($stock) {
            $targetId = $stock->getId();
            $tableName = 'stock_history';
            $foreignKey = 'stock_id';
        } else {
            $etf = $entityManager->getRepository(Etf::class)->findOneBy(['ticker' => $ticker]);
            if (!$etf) return $this->json([]);

            $targetId = $etf->getId();
            $tableName = 'etf_history';
            $foreignKey = 'etf_id';
        }

        // Count rows to determine step size
        $countSql = sprintf(
            'SELECT COUNT(id) FROM (SELECT id FROM %s WHERE %s = :id ORDER BY id DESC LIMIT %d) as sub',
            $tableName,
            $foreignKey,
            (int)$dbLimit
        );
        $actualCount = (int) $conn->fetchOne($countSql, ['id' => $targetId]);

        if ($actualCount === 0) return $this->json([]);

        $step = 1;
        if ($actualCount > $maxChartPoints) {
            $step = (int) ceil($actualCount / $maxChartPoints);
        }

        // simple query
        $sql = sprintf(
            'SELECT id, price, recorded_at FROM %s WHERE %s = :id ORDER BY recorded_at DESC LIMIT %d',
            $tableName,
            $foreignKey,
            (int)$dbLimit
        );

        $stmt = $conn->executeQuery($sql, ['id' => $targetId]);

        $results = [];
        $rowIndex = 0;

        // Stream the rows one by one.
        foreach ($stmt->iterateAssociative() as $row) {
            // Keep the very first row (newest price), then every Nth row
            if ($rowIndex === 0 || $rowIndex % $step === 0) {
                $results[] = $row;
            }
            $rowIndex++;
        }

        return $this->json(array_reverse($results));
    }

    /**
     * API endpoint to retrieve sparse, quarterly fundamental data for overlays.
     */
    #[Route('/api/fundamentals', name: 'api_fundamentals')]
    public function fundamentals(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $ticker = $request->query->get('ticker');
        if (!$ticker) return $this->json([]);

        $stock = $entityManager->getRepository(Stock::class)->findOneBy(['ticker' => $ticker]);
        if (!$stock) return $this->json([]); // ETFs don't have corporate reports

        $conn = $entityManager->getConnection();

        // Fetch all fundamental reports for this stock, oldest to newest (for charting)
        $sql = '
            SELECT net_income, equity, total_debt, treasury, roic, shares, recorded_at, interest_expense, blended_rate, dynamic_spread, revenue, interest_income, capital_expenditures, wacc, eva, dividend_paid, stock_buybacks, return_on_equity, cost_of_equity, capital_ratio, customer_deposit_ratio, operating_margin
            FROM corporate_report 
            WHERE stock_id = :id 
            ORDER BY recorded_at ASC
        ';

        $stmt = $conn->executeQuery($sql, ['id' => $stock->getId()]);
        $results = $stmt->fetchAllAssociative();

        return $this->json($results);
    }
}
