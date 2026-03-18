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

class StockController extends AbstractController
{
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

        $generalInfo = StockInfo::getDescription([
            'ticker' => $asset->getTicker(),
            'name' => $asset->getName(),
            'sector' => $isEtf ? 'ETF' : $asset->getSector(),
            'type' => $isEtf ? 'etf' : 'stock'
        ]);

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

    #[Route('/api/history', name: 'api_history')]
    public function history(Request $request, EntityManagerInterface $entityManager, \Redis $redis): JsonResponse
    {
        $ticker = $request->query->get('ticker');
        $range = $request->query->get('range', '1y');

        if (!$ticker) return $this->json([]);

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

        // USE RAW DBAL CONNECTION FOR BIG QUERIES
        $conn = $entityManager->getConnection();

        $stock = $entityManager->getRepository(Stock::class)->findOneBy(['ticker' => $ticker]);
        if ($stock) {
            $sql = 'SELECT id, price, recorded_at FROM stock_history WHERE stock_id = :id ORDER BY id DESC LIMIT ' . (int)$limit;
            $results = $conn->fetchAllAssociative($sql, ['id' => $stock->getId()]);
        } else {
            $etf = $entityManager->getRepository(Etf::class)->findOneBy(['ticker' => $ticker]);
            if (!$etf) return $this->json([]);

            $sql = 'SELECT id, price, recorded_at FROM etf_history WHERE etf_id = :id ORDER BY id DESC LIMIT ' . (int)$limit;
            $results = $conn->fetchAllAssociative($sql, ['id' => $etf->getId()]);
        }

        // DOWNSAMPLING ENGINE
        $maxChartPoints = 5000;
        $count = count($results);

        if ($count > $maxChartPoints) {
            $step = ceil($count / $maxChartPoints);
            $downsampled = [];

            for ($i = 0; $i < $count; $i += $step) {
                $downsampled[] = $results[$i];
            }

            // Compare IDs to ensure latest point is always included
            if ($downsampled[0]['id'] !== $results[0]['id']) {
                array_unshift($downsampled, $results[0]);
            }
            $results = $downsampled;
        }

        return $this->json(array_reverse($results));
    }
}
