<?php

namespace App\Controller;

use App\Service\MacroEngine;
use App\Entity\Stock;
use App\Entity\Etf;
use App\Entity\User;
use App\Entity\UserStock;
use App\Entity\StockEvent;
use App\Data\SectorPE;
use App\Data\StockInfo;
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
     * @param MacroEngine            $macroEngine   The service for macro-economic data.
     *
     * @return Response Returns the rendered view with asset details.
     */
    #[Route('/stock/{ticker}', name: 'app_stock_view')]
    public function view(string $ticker, EntityManagerInterface $entityManager, MacroEngine $macroEngine, \Redis $redis): Response
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
        if (!$currentUser) {
            throw $this->createAccessDeniedException();
        }

        $macroStateJson = $redis->get('macroeconomic_state');
        $macroState = $macroStateJson ? json_decode($macroStateJson, true) : [
            'inflation' => 0.02, 'output_gap' => 0.00, 'policy_rate' => 0.04, 'yield_10y' => 0.045
        ];


        $user = $entityManager->getRepository(User::class)->find($currentUser->getId());

        $userStock = $entityManager->getRepository(UserStock::class)->findOneBy(['user' => $user, 'stock' => $isEtf ? null : $asset]);
        $userQuantity = $userStock ? $userStock->getQuantity() : 0;

        $marketCap = 0;
        $peRatio = 0;
        $targetPE = 20.00;

        $liveSectorPEs = $macroEngine->getLiveSectors();

        if (!$isEtf) {
            $marketCap = (float) $asset->getPrice() * (float) $asset->getSharesOutstanding();
            $eps = (float) $asset->getEarningsPerShare();
            $peRatio = ($eps > 0) ? ((float) $asset->getPrice() / $eps) : 0;
            $targetPE = $liveSectorPEs[$asset->getSector()] ?? 20.00;
        }

        $generalInfo = $asset->getDescription();

        $allAssets = [];
        if ($isEtf) {
            $allAssets = $entityManager->getRepository(Stock::class)->findAll();
        }

        $events = [];
        if (!$isEtf) {
            $events = $entityManager->getRepository(StockEvent::class)->findBy(
                ['stock' => $asset],
                ['recordedAt' => 'DESC'], // Newest first
                10 // Limit to 10
            );
        }

        $economicCycle = $redis->get('economy_state') ?: 'Expansion';

        return $this->render('stock/index.html.twig', [
            'asset' => $asset,
            'isEtf' => $isEtf,
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
            SELECT net_income, equity, total_debt, treasury, roic, shares, recorded_at 
            FROM corporate_report 
            WHERE stock_id = :id 
            ORDER BY recorded_at ASC
        ';

        $stmt = $conn->executeQuery($sql, ['id' => $stock->getId()]);
        $results = $stmt->fetchAllAssociative();

        return $this->json($results);
    }
}
