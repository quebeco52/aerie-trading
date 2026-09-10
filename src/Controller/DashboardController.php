<?php


namespace App\Controller;

use App\Entity\Etf;
use App\Entity\Stock;
use App\Entity\User;
use App\Entity\UserEtf;
use App\Entity\UserStock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
     * Renders the user's portfolio, including holdings, cash balance, performance metrics,
     * asset allocations, active orders, and trade execution history.
     */
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(
        EntityManagerInterface $entityManager,
        \App\Service\User\CostBasisCalculator $costBasis,
        \App\Service\User\DividendIncomeCalculator $dividendIncome
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Fetch holdings with joined assets to completely eliminate N+1 queries
        $stockHoldings = $entityManager->createQuery(
            'SELECT us, s FROM App\Entity\UserStock us JOIN us.stock s WHERE us.user = :user'
        )->setParameter('user', $user)->getResult();

        $etfHoldings = $entityManager->createQuery(
            'SELECT ue, e FROM App\Entity\UserEtf ue JOIN ue.etf e WHERE ue.user = :user'
        )->setParameter('user', $user)->getResult();

        // Fetch all filled trade orders for this user to compute Average Cost Basis
        $filledOrders = $entityManager->createQuery(
            'SELECT o FROM App\Entity\TradeOrder o WHERE o.user = :user AND o.status = :status ORDER BY o.createdAt ASC'
        )->setParameter('user', $user)->setParameter('status', 'FILLED')->getResult();

        $costBasisMap = $costBasis->calculate($filledOrders);

        // Dividend cash received, per ticker and for the lifetime of the account. Keyed by ticker rather
        // than by position because it includes income from shares since sold: the cash was received and
        // belongs in total P&L even though the position behind it is gone.
        $dividendMap = $dividendIncome->totalsByTicker($user);
        $totalDividendIncome = array_sum($dividendMap);

        $cashBalance = (float) $user->getCashBalance();
        $totalStocksValue = 0.0;
        $totalEtfsValue = 0.0;
        $totalInvestedCost = 0.0;
        $sectorValues = [];
        $holdingsData = [];

        foreach ($stockHoldings as $holding) {
            $stock = $holding->getStock();
            $ticker = $stock->getTicker();
            $currentPrice = (float) $stock->getPrice();
            $quantity = $holding->getQuantity();
            if ($quantity <= 0) {
                continue;
            }

            $marketValue = $currentPrice * $quantity;
            $totalStocksValue += $marketValue;

            $avgCost = $costBasisMap[$ticker] ?? $currentPrice;
            $positionCost = $avgCost * $quantity;
            $totalInvestedCost += $positionCost;

            $unrealizedPnL = $marketValue - $positionCost;
            $unrealizedPnLPercent = $positionCost > 0 ? ($unrealizedPnL / $positionCost) * 100 : 0.0;

            $sector = $stock->getSector() ?? 'General';
            $sectorValues[$sector] = ($sectorValues[$sector] ?? 0.0) + $marketValue;

            $holdingsData[] = [
                'type' => 'STOCK',
                'ticker' => $ticker,
                'name' => $stock->getName(),
                'sector' => $sector,
                'quantity' => $quantity,
                'price' => $currentPrice,
                'avgCost' => $avgCost,
                'totalCost' => $positionCost,
                'marketValue' => $marketValue,
                'unrealizedPnL' => $unrealizedPnL,
                'unrealizedPnLPercent' => $unrealizedPnLPercent,
                'dividendsReceived' => $dividendMap[$ticker] ?? 0.0,
                'isBankrupt' => $stock->isBankrupt(),
                'weight' => 0.0, // Calculated after total portfolio value is known
            ];
        }

        foreach ($etfHoldings as $holding) {
            $etf = $holding->getEtf();
            $ticker = $etf->getTicker();
            $currentPrice = (float) $etf->getPrice();
            $quantity = $holding->getQuantity();
            if ($quantity <= 0) {
                continue;
            }

            $marketValue = $currentPrice * $quantity;
            $totalEtfsValue += $marketValue;

            $avgCost = $costBasisMap[$ticker] ?? $currentPrice;
            $positionCost = $avgCost * $quantity;
            $totalInvestedCost += $positionCost;

            $unrealizedPnL = $marketValue - $positionCost;
            $unrealizedPnLPercent = $positionCost > 0 ? ($unrealizedPnL / $positionCost) * 100 : 0.0;

            $sectorValues['Market Index (ETF)'] = ($sectorValues['Market Index (ETF)'] ?? 0.0) + $marketValue;

            $holdingsData[] = [
                'type' => 'ETF',
                'ticker' => $ticker,
                'name' => $etf->getName(),
                'sector' => 'Market Index',
                'quantity' => $quantity,
                'price' => $currentPrice,
                'avgCost' => $avgCost,
                'totalCost' => $positionCost,
                'marketValue' => $marketValue,
                'unrealizedPnL' => $unrealizedPnL,
                'unrealizedPnLPercent' => $unrealizedPnLPercent,
                'dividendsReceived' => $dividendMap[$ticker] ?? 0.0,
                'isBankrupt' => false,
                'weight' => 0.0,
            ];
        }

        // Value working in open limit orders. A BUY has already debited the cash to escrow and a SELL has
        // already removed the shares from the holdings above, so both have to be added back or the headline
        // net worth falls the moment an order is placed and jumps back when it is cancelled.
        $conn = $entityManager->getConnection();
        $escrowRow = $conn->fetchAssociative(
            "SELECT
                COALESCE(SUM(CASE WHEN o.action = 'BUY' THEN COALESCE(o.limit_price, 0) * o.quantity ELSE 0 END), 0) AS escrowed_cash,
                COALESCE(SUM(CASE WHEN o.action = 'SELL' THEN o.quantity * COALESCE(s.price, e.price, 0) ELSE 0 END), 0) AS escrowed_shares
             FROM trade_orders o
             LEFT JOIN stocks s ON s.ticker = o.ticker AND o.asset_type = 'STOCK'
             LEFT JOIN etfs   e ON e.ticker = o.ticker AND o.asset_type = 'ETF'
             WHERE o.user_id = :user_id AND o.status = 'OPEN'",
            ['user_id' => $user->getId()]
        ) ?: ['escrowed_cash' => 0.0, 'escrowed_shares' => 0.0];

        $escrowedCash = (float) $escrowRow['escrowed_cash'];
        $escrowedShareValue = (float) $escrowRow['escrowed_shares'];
        $escrowedTotal = $escrowedCash + $escrowedShareValue;

        $totalPortfolioValue = $cashBalance + $totalStocksValue + $totalEtfsValue + $escrowedTotal;
        $totalUnrealizedPnL = ($totalStocksValue + $totalEtfsValue) - $totalInvestedCost;
        $totalUnrealizedPnLPercent = $totalInvestedCost > 0 ? ($totalUnrealizedPnL / $totalInvestedCost) * 100 : 0.0;

        // Calculate weights for holdings
        foreach ($holdingsData as &$h) {
            $h['weight'] = $totalPortfolioValue > 0 ? ($h['marketValue'] / $totalPortfolioValue) * 100 : 0.0;
        }
        unset($h);

        // Sort holdings by market value descending
        usort($holdingsData, fn($a, $b) => $b['marketValue'] <=> $a['marketValue']);

        // Query Open Limit Orders
        $openOrders = $entityManager->createQuery(
            'SELECT o FROM App\Entity\TradeOrder o WHERE o.user = :user AND o.status = :status ORDER BY o.createdAt DESC'
        )->setParameter('user', $user)->setParameter('status', 'OPEN')->getResult();

        // Query Historical Trades (up to 50 newest)
        $tradeHistory = $entityManager->createQuery(
            'SELECT o FROM App\Entity\TradeOrder o WHERE o.user = :user AND o.status IN (:statuses) ORDER BY o.createdAt DESC'
        )->setParameter('user', $user)->setParameter('statuses', ['FILLED', 'CANCELLED'])->setMaxResults(50)->getResult();

        // Prepare Sector Diversification percentages
        $sectorBreakdown = [];
        $investedTotal = $totalStocksValue + $totalEtfsValue;
        foreach ($sectorValues as $sectorName => $val) {
            $sectorBreakdown[] = [
                'name' => $sectorName,
                'value' => $val,
                'percent' => $investedTotal > 0 ? ($val / $investedTotal) * 100 : 0.0,
                'portfolioPercent' => $totalPortfolioValue > 0 ? ($val / $totalPortfolioValue) * 100 : 0.0,
            ];
        }
        usort($sectorBreakdown, fn($a, $b) => $b['value'] <=> $a['value']);

        // Asset Allocation
        $allocation = [
            'stocks' => $totalStocksValue,
            'stocksPercent' => $totalPortfolioValue > 0 ? ($totalStocksValue / $totalPortfolioValue) * 100 : 0.0,
            'etfs' => $totalEtfsValue,
            'etfsPercent' => $totalPortfolioValue > 0 ? ($totalEtfsValue / $totalPortfolioValue) * 100 : 0.0,
            'cash' => $cashBalance,
            'cashPercent' => $totalPortfolioValue > 0 ? ($cashBalance / $totalPortfolioValue) * 100 : 0.0,
            'escrow' => $escrowedTotal,
            'escrowPercent' => $totalPortfolioValue > 0 ? ($escrowedTotal / $totalPortfolioValue) * 100 : 0.0,
        ];

        return $this->render('dashboard/index.html.twig', [
            'user' => $user,
            'holdings' => $holdingsData,
            'portfolioValue' => $totalPortfolioValue,
            'totalInvested' => $totalStocksValue + $totalEtfsValue,
            'totalInvestedCost' => $totalInvestedCost,
            'totalUnrealizedPnL' => $totalUnrealizedPnL,
            'totalUnrealizedPnLPercent' => $totalUnrealizedPnLPercent,
            'cashBalance' => $cashBalance,
            'escrowedCash' => $escrowedCash,
            'escrowedShareValue' => $escrowedShareValue,
            'openOrders' => $openOrders,
            'tradeHistory' => $tradeHistory,
            'sectorBreakdown' => $sectorBreakdown,
            'allocation' => $allocation,
            'totalDividendIncome' => $totalDividendIncome,
            'dividendPayments' => $dividendIncome->recentPayments($user),
        ]);
    }

    /**
     * API endpoint returning historical portfolio NAV (Net Asset Value) performance points.
     */
    #[Route('/api/portfolio/history', name: 'api_portfolio_history', methods: ['GET'])]
    public function history(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->json([], Response::HTTP_UNAUTHORIZED);
        }

        $range = $request->query->get('range', '1m');
        $limits = [
            '1w' => 100,
            '1m' => 400,
            '1y' => 1500,
            'max' => 5000,
        ];
        $limit = $limits[$range] ?? 400;

        $conn = $entityManager->getConnection();
        $sql = 'SELECT total_value, recorded_at FROM portfolio_history WHERE user_id = :user_id ORDER BY recorded_at DESC LIMIT ' . (int)$limit;
        $rows = $conn->fetchAllAssociative($sql, ['user_id' => $user->getId()]);

        if (empty($rows)) {
            // Provide at least one point with current portfolio balance
            $currentVal = (float) $user->getCashBalance();
            return $this->json([[
                'price' => $currentVal,
                'recorded_at' => (new \DateTime())->format('Y-m-d H:i:s'),
            ]]);
        }

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'price' => (float) $row['total_value'],
                'recorded_at' => $row['recorded_at'],
            ];
        }

        return $this->json(array_reverse($results));
    }
}
