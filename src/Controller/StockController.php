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
    public function view(string $ticker, EntityManagerInterface $entityManager, MacroEngine $macroEngine): Response
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
            'targetPE' => $targetPE,
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

        // Redis cache for short timeframes stays exactly the same
        if (in_array($range, ['1w', '1m'])) {
            $limit = $range === '1w' ? 277 : 1200;
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

        // We will never ask MariaDB for more than 100,000 rows at once.
        $dbLimit = min($limit, 100000);
        $maxChartPoints = 5000;
        $conn = $entityManager->getConnection();

        // Fetch the target asset
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

        // Get the actual count up to limit to calculate the correct step size.
        $countSql = sprintf(
            'SELECT COUNT(id) FROM (SELECT id FROM %s WHERE %s = :id ORDER BY id DESC LIMIT %d) as sub',
            $tableName,
            $foreignKey,
            (int)$dbLimit
        );
        $actualCount = (int) $conn->fetchOne($countSql, ['id' => $targetId]);

        if ($actualCount === 0) {
            return $this->json([]);
        }

        // Calculate the downsampling step
        $step = 1;
        if ($actualCount > $maxChartPoints) {
            $step = (int) ceil($actualCount / $maxChartPoints);
        }

        // Fetch the downsampled data directly from the DB
        $sql = sprintf('
        WITH RankedData AS (
            SELECT 
                id, price, recorded_at,
                ROW_NUMBER() OVER(ORDER BY id DESC) as row_num
            FROM %s 
            WHERE %s = :id 
            LIMIT %d
            )
            SELECT id, price, recorded_at 
            FROM RankedData 
            WHERE row_num %% :step = 0 OR row_num = 1
            ORDER BY id DESC
        ', $tableName, $foreignKey, (int)$dbLimit);

        $results = $conn->fetchAllAssociative($sql, [
            'id'   => $targetId,
            'step' => $step
        ]);

        return $this->json(array_reverse($results));
    }
}
