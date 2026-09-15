<?php


namespace App\Controller;

use App\Entity\User;
use App\Service\Math\FinancialConstants;
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
    // --- Portfolio Display ---

    /** Settled orders listed in the trade history; older fills are portfolio history, not a working record. */
    private const TRADE_HISTORY_ROWS = 50;

    /**
     * Renders the user's portfolio, including holdings, cash balance, performance metrics,
     * asset allocations, active orders, and trade execution history.
     */
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(
        EntityManagerInterface $entityManager,
        \App\Repository\HoldingRepository $holdings,
        \App\Repository\TradeOrderRepository $orders,
        \App\Service\User\CostBasisCalculator $costBasis,
        \App\Service\User\DividendIncomeCalculator $dividendIncome,
        \App\Service\User\CouponIncomeCalculator $couponIncome,
        \App\Service\Market\MarginEngine $marginEngine
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // The holdings queries fetch-join their assets, so the loops below do not issue a query per row.
        $stockHoldings = $holdings->findStockHoldings($user);
        $etfHoldings = $holdings->findEtfHoldings($user);
        $bondHoldings = $holdings->findBondHoldings($user);

        $filledOrders = $orders->findFilledForUser($user);

        $costBasisMap = $costBasis->calculate($filledOrders);

        // Dividend cash received, per ticker and for the lifetime of the account. Keyed by ticker rather
        // than by position because it includes income from shares since sold: the cash was received and
        // belongs in total P&L even though the position behind it is gone.
        $dividendMap = $dividendIncome->totalsByTicker($user);

        // Coupon cash is income in exactly the same sense a dividend is, so it belongs in the same headline
        // total. Kept in its own map because the two come from different ledgers.
        $couponMap = $couponIncome->totalsByTicker($user);
        $totalDividendIncome = array_sum($dividendMap) + array_sum($couponMap);

        $cashBalance = (float) $user->getCashBalance();
        $totalStocksValue = 0.0;
        $totalEtfsValue = 0.0;
        $totalBondsValue = 0.0;
        $totalInvestedCost = 0.0;
        $sectorValues = [];
        $holdingsData = [];

        foreach ($stockHoldings as $holding) {
            $stock = $holding->getStock();
            $ticker = $stock->getTicker();
            $currentPrice = (float) $stock->getPrice();
            $quantity = (int) $holding->getQuantity();
            if ($quantity === 0) {
                continue;
            }

            // Negative for a short, and the arithmetic below needs no special case: market value is the
            // obligation, cost is the proceeds, and their difference is profit in either direction. What
            // does need care is the denominator of the percentage and the exposure the allocation reads.
            $isShort = $quantity < 0;

            $marketValue = $currentPrice * $quantity;
            $totalStocksValue += $marketValue;

            $avgCost = $costBasisMap[$ticker] ?? $currentPrice;
            $positionCost = $avgCost * $quantity;
            $totalInvestedCost += $positionCost;

            $unrealizedPnL = $marketValue - $positionCost;
            $unrealizedPnLPercent = abs($positionCost) > 0.0 ? ($unrealizedPnL / abs($positionCost)) * 100 : 0.0;

            // Gross, not net: a short is exposure to a sector, not an offset against a long in it, and a
            // signed sum would let a paired book report itself as holding nothing at all.
            $sector = $stock->getSector() ?? 'General';
            $sectorValues[$sector] = ($sectorValues[$sector] ?? 0.0) + abs($marketValue);

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
                'isShort' => $isShort,
                'borrowAccrued' => (float) $holding->getBorrowAccrued(),
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

        foreach ($bondHoldings as $holding) {
            $bond = $holding->getBond();
            $ticker = $bond->getTicker();
            $quantity = $holding->getQuantity();
            if ($quantity <= 0) {
                continue;
            }

            // Marked at the dirty price, which is what the position would actually realise: a buyer pays the
            // holder for the coupon accrued since the last payment.
            $currentPrice = (float) $bond->getPrice();
            $marketValue = $currentPrice * $quantity;
            $totalBondsValue += $marketValue;

            $avgCost = $costBasisMap[$ticker] ?? $currentPrice;
            $positionCost = $avgCost * $quantity;
            $totalInvestedCost += $positionCost;

            $unrealizedPnL = $marketValue - $positionCost;
            $unrealizedPnLPercent = $positionCost > 0 ? ($unrealizedPnL / $positionCost) * 100 : 0.0;

            $sectorValues['Sovereign Debt'] = ($sectorValues['Sovereign Debt'] ?? 0.0) + $marketValue;

            $holdingsData[] = [
                'type' => 'BOND',
                'ticker' => $ticker,
                'name' => $bond->getName(),
                'sector' => 'Sovereign Debt',
                'quantity' => $quantity,
                'price' => $currentPrice,
                'avgCost' => $avgCost,
                'totalCost' => $positionCost,
                'marketValue' => $marketValue,
                'unrealizedPnL' => $unrealizedPnL,
                'unrealizedPnLPercent' => $unrealizedPnLPercent,
                'dividendsReceived' => $couponMap[$ticker] ?? 0.0,
                'isBankrupt' => false,
                'weight' => 0.0,
                'yieldToMaturity' => (float) $bond->getYieldToMaturity(),
                'modifiedDuration' => (float) $bond->getModifiedDuration(),
                'couponRate' => (float) $bond->getCouponRate(),
            ];
        }

        // Value working in open limit orders. A BUY has already debited the cash to escrow and a SELL has
        // already removed the shares from the holdings above, so both have to be added back or the headline
        // net worth falls the moment an order is placed and jumps back when it is cancelled.
        $conn = $entityManager->getConnection();
        $escrowRow = $conn->fetchAssociative(
            "SELECT
                COALESCE(SUM(CASE WHEN o.action = 'BUY' THEN COALESCE(o.limit_price, 0) * o.quantity ELSE 0 END), 0) AS escrowed_cash,
                COALESCE(SUM(CASE WHEN o.action = 'SELL' THEN o.quantity * COALESCE(s.price, e.price, b.price, 0) ELSE 0 END), 0) AS escrowed_shares
             FROM trade_orders o
             LEFT JOIN stocks s ON s.ticker = o.ticker AND o.asset_type = 'STOCK'
             LEFT JOIN etfs   e ON e.ticker = o.ticker AND o.asset_type = 'ETF'
             LEFT JOIN bonds  b ON b.ticker = o.ticker AND o.asset_type = 'BOND'
             WHERE o.user_id = :user_id AND o.status = 'OPEN'",
            ['user_id' => $user->getId()]
        ) ?: ['escrowed_cash' => 0.0, 'escrowed_shares' => 0.0];

        $escrowedCash = (float) $escrowRow['escrowed_cash'];
        $escrowedShareValue = (float) $escrowRow['escrowed_shares'];
        $escrowedTotal = $escrowedCash + $escrowedShareValue;

        // The option book, on the same signed definition every other net-worth surface uses: a long contract
        // is an asset and a written one a liability. Left out, this headline disagreed with the account's own
        // NAV chart on the same page and with its leaderboard rank, both of which count it.
        $totalOptionsValue = (float) $conn->fetchOne(
            'SELECT COALESCE(SUM(uo.quantity * oc.price * ' . FinancialConstants::OPTION_CONTRACT_MULTIPLIER . '), 0)
             FROM user_options uo
             JOIN option_contracts oc ON uo.option_contract_id = oc.id
             WHERE uo.user_id = :user_id',
            ['user_id' => $user->getId()]
        );

        // Borrowed cash is spent but still owed, so it comes back out of the headline figure.
        $marginDebit = (float) $user->getMarginDebit();
        $totalPortfolioValue = $cashBalance - $marginDebit + $totalStocksValue + $totalEtfsValue + $totalBondsValue + $totalOptionsValue + $escrowedTotal;
        $totalUnrealizedPnL = ($totalStocksValue + $totalEtfsValue + $totalBondsValue) - $totalInvestedCost;
        $totalUnrealizedPnLPercent = $totalInvestedCost > 0 ? ($totalUnrealizedPnL / $totalInvestedCost) * 100 : 0.0;

        // Calculate weights for holdings
        foreach ($holdingsData as &$h) {
            $h['weight'] = $totalPortfolioValue > 0 ? ($h['marketValue'] / $totalPortfolioValue) * 100 : 0.0;
        }
        unset($h);

        // Sort holdings by market value descending
        usort($holdingsData, fn($a, $b) => $b['marketValue'] <=> $a['marketValue']);

        $openOrders = $orders->findOpenForUser($user);
        $tradeHistory = $orders->findSettledForUser($user, self::TRADE_HISTORY_ROWS);

        // Prepare Sector Diversification percentages
        $sectorBreakdown = [];
        // Gross exposure, matching what the sector values were summed as. A net total would divide the
        // sector shares by a smaller number than they were built from and push them past 100%.
        $investedTotal = array_sum($sectorValues);
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
            'bonds' => $totalBondsValue,
            'bondsPercent' => $totalPortfolioValue > 0 ? ($totalBondsValue / $totalPortfolioValue) * 100 : 0.0,
            'cash' => $cashBalance,
            'cashPercent' => $totalPortfolioValue > 0 ? ($cashBalance / $totalPortfolioValue) * 100 : 0.0,
            'escrow' => $escrowedTotal,
            'escrowPercent' => $totalPortfolioValue > 0 ? ($escrowedTotal / $totalPortfolioValue) * 100 : 0.0,
        ];

        return $this->render('dashboard/index.html.twig', [
            'user' => $user,
            'holdings' => $holdingsData,
            'portfolioValue' => $totalPortfolioValue,
            'totalInvested' => $totalStocksValue + $totalEtfsValue + $totalBondsValue,
            'totalInvestedCost' => $totalInvestedCost,
            'totalUnrealizedPnL' => $totalUnrealizedPnL,
            'totalUnrealizedPnLPercent' => $totalUnrealizedPnLPercent,
            'cashBalance' => $cashBalance,
            'escrowedCash' => $escrowedCash,
            'escrowedShareValue' => $escrowedShareValue,
            'marginDebit' => $marginDebit,
            // Marked against live prices rather than the figures assembled above, so the risk panel and the
            // sweep that acts on it are reading the same numbers.
            'margin' => $marginEngine->status($user),
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
