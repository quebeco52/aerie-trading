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
        \App\Service\Market\MarginEngine $marginEngine,
        \App\Service\Macro\MacroStateProvider $macroStateProvider,
        \App\Service\View\PlayerPanelBuilder $playerPanels,
        Request $request,
    ): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // The holdings queries fetch-join their assets, so the loops below do not issue a query per row.
        $stockHoldings = $holdings->findStockHoldings($user);
        $etfHoldings = $holdings->findEtfHoldings($user);
        $bondHoldings = $holdings->findBondHoldings($user);
        $optionHoldings = $holdings->findOptionHoldings($user);

        $filledOrders = $orders->findFilledForUser($user);

        $costBasisMap = $costBasis->calculate($filledOrders);
        $totalRealised = array_sum($costBasis->realisedByTicker($filledOrders));

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

            $debtSector = $bond->getIssuer() !== null ? 'Corporate debt' : 'Sovereign debt';
            $sectorValues[$debtSector] = ($sectorValues[$debtSector] ?? 0.0) + $marketValue;

            $holdingsData[] = [
                'type' => 'BOND',
                'ticker' => $ticker,
                'name' => $bond->getName(),
                'sector' => $debtSector,
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

        // The option book. Signed throughout: a written contract is a negative position whose market value
        // is a liability and whose gain is the premium DECAYING, so the same two subtractions that price a
        // long position price a short one without a branch. Premiums are carried per share, which is the
        // convention the desk quotes in, so the multiplier appears wherever currency does.
        $totalOptionsValue = 0.0;

        foreach ($optionHoldings as $holding) {
            $contracts = (int) $holding->getQuantity();

            if ($contracts === 0) {
                continue;
            }

            $contract = $holding->getContract();
            $shares = $contracts * FinancialConstants::OPTION_CONTRACT_MULTIPLIER;

            $mark = (float) $contract->getPrice();
            $basis = (float) $holding->getAveragePremium();

            $marketValue = $mark * $shares;
            $positionCost = $basis * $shares;

            $totalOptionsValue += $marketValue;
            $totalInvestedCost += abs($positionCost);

            $unrealizedPnL = $marketValue - $positionCost;

            // Measured against what the position tied up, not against a signed cost: a written contract has
            // a negative basis, and dividing by it would report a profitable short as a loss.
            $unrealizedPnLPercent = abs($positionCost) > 0.0 ? ($unrealizedPnL / abs($positionCost)) * 100 : 0.0;

            // Gross, like the equity loop above and for the same reason: a written contract is exposure to
            // the underlying, not an offset against it. A signed sum here would let a book that had written
            // more premium than it holds report a NEGATIVE slice, which shrinks the denominator every other
            // sector's share is struck against and pushes them all past 100%.
            $sectorValues['Options'] = ($sectorValues['Options'] ?? 0.0) + abs($marketValue);

            $holdingsData[] = [
                'type' => 'OPTION',
                'ticker' => $contract->getTicker(),
                'name' => sprintf(
                    '%s %s $%s',
                    $contract->getStock()->getTicker(),
                    $contract->isCall() ? 'Call' : 'Put',
                    number_format((float) $contract->getStrike(), 2)
                ),
                'sector' => 'Options',
                'quantity' => $contracts,
                'price' => $mark,
                'avgCost' => $basis,
                'totalCost' => $positionCost,
                'marketValue' => $marketValue,
                'unrealizedPnL' => $unrealizedPnL,
                'unrealizedPnLPercent' => $unrealizedPnLPercent,
                'dividendsReceived' => 0.0,
                'isBankrupt' => false,
                'weight' => 0.0,
                'underlying' => $contract->getStock()->getTicker(),
                'expiresAtTime' => $contract->getExpiresAtTime(),
                'impliedVolatility' => (float) $contract->getImpliedVolatility(),
                'delta' => (float) $contract->getDelta(),
            ];
        }

        $macro = $macroStateProvider->liveState();

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

        // Borrowed cash is spent but still owed, so it comes back out of the headline figure.
        $marginDebit = (float) $user->getMarginDebit();
        $totalPortfolioValue = $cashBalance - $marginDebit + $totalStocksValue + $totalEtfsValue + $totalBondsValue + $totalOptionsValue + $escrowedTotal;
        $totalUnrealizedPnL = ($totalStocksValue + $totalEtfsValue + $totalBondsValue + $totalOptionsValue) - $totalInvestedCost;
        $totalUnrealizedPnLPercent = $totalInvestedCost > 0 ? ($totalUnrealizedPnL / $totalInvestedCost) * 100 : 0.0;

        // Calculate weights for holdings
        foreach ($holdingsData as &$h) {
            $h['weight'] = $totalPortfolioValue > 0 ? ($h['marketValue'] / $totalPortfolioValue) * 100 : 0.0;
        }
        unset($h);

        // Sort holdings by market value descending
        usort($holdingsData, fn($a, $b) => $b['marketValue'] <=> $a['marketValue']);

        $openOrders = $orders->findOpenForUser($user);
        $tradePages = max(1, (int) ceil($orders->countSettledForUser($user) / self::TRADE_HISTORY_ROWS));
        $tradePage = min($tradePages, max(1, $request->query->getInt('trades', 1)));
        $tradeHistory = $orders->findSettledForUser($user, self::TRADE_HISTORY_ROWS, ($tradePage - 1) * self::TRADE_HISTORY_ROWS);

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

        // Asset allocation: the same terms the headline value is summed from, the margin loan included as a
        // negative slice, so the shares add up to the portfolio. Stocks and cash always show; the rest only
        // when held. Shares are fractions of the portfolio value.
        $slices = [
            ['label' => 'Stocks', 'value' => $totalStocksValue, 'always' => true],
            ['label' => 'Index funds', 'value' => $totalEtfsValue, 'always' => false],
            ['label' => 'Bonds', 'value' => $totalBondsValue, 'always' => false],
            ['label' => 'Options', 'value' => $totalOptionsValue, 'always' => false],
            ['label' => 'In open orders', 'value' => $escrowedTotal, 'always' => false],
            ['label' => 'Cash', 'value' => $cashBalance, 'always' => true],
            ['label' => 'Margin loan', 'value' => -$marginDebit, 'always' => false],
        ];
        $seriesColours = ['bg-series-blue', 'bg-series-orange', 'bg-series-aqua', 'bg-series-yellow', 'bg-series-magenta'];
        $allocation = [];
        foreach ($slices as $slice) {
            if (!$slice['always'] && round($slice['value'], 2) === 0.0) {
                continue;
            }
            $colour = match ($slice['label']) {
                'Cash' => 'bg-on-surface-faint',
                'Margin loan' => 'bg-tertiary',
                default => array_shift($seriesColours),
            };
            $allocation[] = [
                'label' => $slice['label'],
                'value' => $slice['value'],
                'share' => $totalPortfolioValue > 0 ? $slice['value'] / $totalPortfolioValue : null,
                'colour' => $colour,
            ];
        }

        // Open orders show the price each one is waiting against.
        $livePrices = [];
        foreach ($conn->fetchAllAssociative(
            "SELECT DISTINCT o.ticker, COALESCE(s.price, e.price, b.price) AS price
             FROM trade_orders o
             LEFT JOIN stocks s ON s.ticker = o.ticker AND o.asset_type = 'STOCK'
             LEFT JOIN etfs   e ON e.ticker = o.ticker AND o.asset_type = 'ETF'
             LEFT JOIN bonds  b ON b.ticker = o.ticker AND o.asset_type = 'BOND'
             WHERE o.user_id = :user_id AND o.status = 'OPEN'",
            ['user_id' => $user->getId()]
        ) as $row) {
            if ($row['price'] !== null) {
                $livePrices[(string) $row['ticker']] = (float) $row['price'];
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'user' => $user,
            'holdings' => $holdingsData,
            'portfolioValue' => $totalPortfolioValue,
            'totalInvested' => $totalStocksValue + $totalEtfsValue + $totalBondsValue + $totalOptionsValue,
            'totalInvestedCost' => $totalInvestedCost,
            'totalUnrealizedPnL' => $totalUnrealizedPnL,
            'totalUnrealizedPnLPercent' => $totalUnrealizedPnLPercent,
            'totalRealised' => $totalRealised,
            'cashBalance' => $cashBalance,
            'escrowedCash' => $escrowedCash,
            'escrowedShareValue' => $escrowedShareValue,
            'marginDebit' => $marginDebit,
            // Marked against live prices rather than the figures assembled above, so the risk panel and the
            // sweep that acts on it are reading the same numbers.
            'margin' => $marginEngine->status($user),
            'openOrders' => $openOrders,
            'livePrices' => $livePrices,
            'tradeHistory' => $tradeHistory,
            'optionMultiplier' => FinancialConstants::OPTION_CONTRACT_MULTIPLIER,
            'sectorBreakdown' => $sectorBreakdown,
            'allocation' => $allocation,
            'cashShare' => $totalPortfolioValue > 0 ? $cashBalance / $totalPortfolioValue : null,
            // The rates the ticker accrues at, from the same two functions it calls.
            'cashRate' => \App\Service\User\Portfolio::cashSweepRate($macro->policyRateEma),
            'marginRate' => \App\Service\Market\ForcedLiquidationService::marginLoanRate($macro->policyRate),
            'totalDividendIncome' => $totalDividendIncome,
            'dividendPayments' => $dividendIncome->recentPayments($user),
            'couponPayments' => $couponIncome->recentPayments($user),
            'tradePage' => $tradePage,
            'tradePages' => $tradePages,
        ] + $playerPanels->build($user, $macro->totalTime, $filledOrders !== []));
    }

    /** Closes the account's book and reopens it with starting capital; see App\Service\User\FreshStart. */
    #[Route('/account/fresh-start', name: 'app_fresh_start', methods: ['POST'])]
    public function freshStart(Request $request, \App\Service\User\FreshStart $freshStart): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('fresh_start', (string) $request->request->get('_token')) || $request->request->get('confirm') !== '1') {
            $this->addFlash('error', 'Tick the box to confirm before starting afresh.');

            return $this->redirectToRoute('app_dashboard', [], Response::HTTP_SEE_OTHER);
        }

        try {
            $freshStart->restart($user);
            $this->addFlash('success', sprintf('Your account starts afresh with %s in cash.', '$' . number_format((float) $user->getCashBalance(), 2)));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_dashboard', [], Response::HTTP_SEE_OTHER);
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

        // An account with no snapshots yet gets an empty series; the page plots the portfolio value it
        // rendered with, which counts holdings and open orders rather than cash alone.
        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'price' => (float) $row['total_value'],
                'recorded_at' => $row['recorded_at'],
            ];
        }
        $results = array_reverse($results);

        return $this->json([
            'portfolio' => $results,
            'index' => $results === [] ? [] : $this->indexOver($conn, $results[0]['recorded_at'], $results[count($results) - 1]['recorded_at']),
            'indexName' => 'Lakebird 30',
        ]);
    }

    /** Points the index line draws at most; the fund's history is thinned evenly to this. */
    private const INDEX_LINE_POINTS = 600;

    /**
     * The benchmark fund's price over the same wall-clock window as the portfolio series, for the comparison line.
     * The page rebases it to the portfolio's first value, so the two lines start together.
     *
     * @return list<array{price: float, recorded_at: string}>
     */
    private function indexOver(\Doctrine\DBAL\Connection $conn, string $from, string $to): array
    {
        $rows = $conn->fetchAllAssociative(
            'SELECT h.price, h.recorded_at FROM etf_history h JOIN etfs e ON e.id = h.etf_id
             WHERE e.ticker = :ticker AND h.recorded_at BETWEEN :from AND :to
             ORDER BY h.recorded_at',
            ['ticker' => \App\Entity\Season::BENCHMARK_TICKER, 'from' => $from, 'to' => $to]
        );

        $step = max(1, (int) ceil(count($rows) / self::INDEX_LINE_POINTS));
        $points = [];
        foreach ($rows as $i => $row) {
            if ($i % $step === 0 || $i === count($rows) - 1) {
                $points[] = ['price' => (float) $row['price'], 'recorded_at' => (string) $row['recorded_at']];
            }
        }

        return $points;
    }
}
