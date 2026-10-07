<?php

namespace App\Controller;

use App\Entity\Stock;
use App\Repository\EtfRepository;
use App\Repository\StockRepository;
use App\Service\View\StockPageBuilder;
use App\Entity\Etf;
use App\Entity\User;
use App\Service\Market\ChartRange;
use App\Service\Market\PriceBarAggregator;
use App\Service\Market\HistoryPruner;
use App\Service\Market\TickCadence;
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
     * The instrument page for a listed company or for the index fund.
     *
     * Composition lives in App\Service\View\StockPageBuilder; this resolves the ticker and renders.
     */
    #[Route('/stock/{ticker}', name: 'app_stock_view')]
    public function view(
        string $ticker,
        StockRepository $stocks,
        EtfRepository $etfs,
        EntityManagerInterface $entityManager,
        StockPageBuilder $pageBuilder,
        \App\Service\Notification\PriceAlertService $priceAlerts,
    ): Response {
        $asset = $stocks->findOneByTicker($ticker) ?? $etfs->findOneByTicker($ticker);
        if ($asset === null) {
            throw $this->createNotFoundException('Ticker not found');
        }

        /** @var User|null $currentUser */
        $currentUser = $this->getUser();

        $page = $pageBuilder->build($asset, $ticker, $currentUser);
        $page['isWatched'] = $currentUser !== null
            && $entityManager->getRepository(\App\Entity\WatchlistItem::class)->count(['user' => $currentUser, 'ticker' => $asset->getTicker()]) > 0;
        $page['priceAlerts'] = $currentUser !== null ? $priceAlerts->waiting($currentUser, $asset->getTicker()) : [];

        return $this->render('stock/index.html.twig', $page);
    }

    /**
     * API endpoint to retrieve historical price data for charting.
     *
     * @param Request                $request       The HTTP request carrying 'ticker', 'range' and the 'style' being drawn.
     * @param EntityManagerInterface $entityManager The entity manager.
     * @param \Redis                 $redis         The Redis instance for caching short-term data.
     *
     * @return JsonResponse Returns a JSON array of historical data points.
     */
    #[Route('/api/history', name: 'api_history')]
    public function history(
        Request $request,
        EntityManagerInterface $entityManager,
        \Redis $redis,
        PriceBarAggregator $barAggregator,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%app.ticks_per_year%')] int $ticksPerYear,
    ): JsonResponse
    {
        $ticker = $request->query->get('ticker');
        $range = (string) $request->query->get('range', ChartRange::DEFAULT_RANGE);

        if (!$ticker) return $this->json([]);

        // The line and the candle rendering of a range do not share a bar grid: a candle has a legible
        // minimum width, a line does not, and the finer grid is what keeps the live tail moving rather
        // than lurching a whole bar at a time. The chart asks for the one it is about to draw.
        $isLine = $request->query->get('style') === 'line';
        $targetBars = $isLine ? PriceBarAggregator::LINE_TARGET_BARS : PriceBarAggregator::TARGET_BARS;
        $minRowsPerBar = $isLine ? PriceBarAggregator::LINE_MIN_ROWS_PER_BAR : PriceBarAggregator::MIN_ROWS_PER_BAR;

        // A bond can be charted by its yield instead of its clean price; the series is otherwise the same.
        $chartsYield = $request->query->get('field') === 'yield';

        // Redis cache for short timeframes, counted in the buffer's own entries: a bond pushes its day's mark
        // and nothing between, everything else every tick (or every few at a fine tick grid).
        if (ChartRange::isBuffered($range)) {
            $isBond = $entityManager->getRepository(\App\Entity\Bond::class)->count(['ticker' => $ticker]) > 0;
            $entriesPerYear = $isBond
                ? TickCadence::bondMarksPerYear($ticksPerYear)
                : TickCadence::equityBufferEntriesPerYear($ticksPerYear);

            $cacheKey = "chart_buffer:{$ticker}";
            $redisData = $redis->lRange($cacheKey, 0, ChartRange::entries($range, $entriesPerYear) - 1);
            $results = [];
            foreach ($redisData as $age => $jsonStr) {
                $point = json_decode($jsonStr, true);
                if (!is_array($point)) {
                    continue;
                }
                if ($chartsYield) {
                    // Entries buffered before yields rode along have none and are left out, not drawn at zero.
                    if (!isset($point['yield'])) {
                        continue;
                    }
                    $point['price'] = $point['yield'];
                }
                // A buffer entry carries no simulated time, but the buffer takes one a tick (a bond, one a mark),
                // so an entry's age is its place in the list.
                $point['sim_time'] = -$age / $entriesPerYear;
                $results[] = $point;
            }

            // Buffered points are single ticks, so the bar has to be built here or every candle is a doji.
            return $this->json($barAggregator->aggregate($results, count($results) / $entriesPerYear, $entriesPerYear, $targetBars, $minRowsPerBar));
        }

        $conn = $entityManager->getConnection();

        // Fetch the target asset ID
        $stock = $entityManager->getRepository(Stock::class)->findOneByTicker($ticker);
        if ($stock) {
            $targetId = $stock->getId();
            $tableName = 'stock_history';
            $foreignKey = 'stock_id';
            $priceColumn = 'price';
        } else {
            $etf = $entityManager->getRepository(Etf::class)->findOneByTicker($ticker);
            if ($etf) {
                $targetId = $etf->getId();
                $tableName = 'etf_history';
                $foreignKey = 'etf_id';
                $priceColumn = 'price';
            } else {
                $bond = $entityManager->getRepository(\App\Entity\Bond::class)->findOneByTicker($ticker);
                if (!$bond) return $this->json([]);

                $targetId = $bond->getId();
                $tableName = 'bond_history';
                $foreignKey = 'bond_id';

                // Chart clean price to align with buffered short-range Redis quotes and exclude coupon sawtooth.
                $priceColumn = $chartsYield ? 'yield_to_maturity' : 'clean_price';
            }
        }

        // The range is a span of the series' own simulated time, not a row count: stock and fund history is
        // written once a bar and bond history once a mark, so no single rows-per-year is right for all three.
        $newestSimTime = $conn->fetchOne(
            sprintf('SELECT MAX(sim_time) FROM %s WHERE %s = :id', $tableName, $foreignKey),
            ['id' => $targetId]
        );
        $simTimeFloor = ChartRange::simTimeFloor(
            $range,
            $newestSimTime === null || $newestSimTime === false ? null : (float) $newestSimTime
        );

        $where = sprintf('%s = :id', $foreignKey);
        $params = ['id' => $targetId];
        if ($simTimeFloor !== null) {
            $where .= ' AND sim_time >= :floor';
            $params['floor'] = $simTimeFloor;
        }

        // The span the rows actually cover sets the slice width the aggregator cuts them into.
        $oldestSimTime = $conn->fetchOne(
            sprintf(
                'SELECT MIN(sim_time) FROM (SELECT sim_time FROM %s WHERE %s ORDER BY sim_time DESC, id DESC LIMIT %d) as sub',
                $tableName,
                $where,
                ChartRange::MAX_ROWS
            ),
            $params
        );

        if ($oldestSimTime === null || $oldestSimTime === false || $newestSimTime === null || $newestSimTime === false) return $this->json([]);

        // Stocks carry a full bar; ETFs and bonds are a single series and select the close alone. Asking
        // for open_price on etf_history would be a SQL error rather than a null.
        $barColumns = $tableName === 'stock_history'
            ? ', open_price, high_price, low_price, volume'
            : '';

        $sql = sprintf(
            // Order by sim_time and id descending using the sim_time index to preserve intra-tick candle order.
            'SELECT id, %s AS price%s, sim_time FROM %s WHERE %s ORDER BY sim_time DESC, id DESC LIMIT %d',
            $priceColumn,
            $barColumns,
            $tableName,
            $where,
            ChartRange::MAX_ROWS
        );

        $stmt = $conn->executeQuery($sql, $params);

        // A bond is written once a mark, everything else once a history bar. A range reaching past the
        // full-resolution window reaches history thinned to a row a week, and bars finer than that would leave
        // empty slices the chart closes up, drawing the thinned years narrower than the time they cover.
        $rowsPerYear = $tableName === 'bond_history'
            ? TickCadence::bondMarksPerYear($ticksPerYear)
            : TickCadence::historyPointsPerYear($ticksPerYear);
        if ((float) $oldestSimTime < HistoryPruner::thinnedBefore((float) $newestSimTime)) {
            $rowsPerYear = min($rowsPerYear, HistoryPruner::THINNED_ROWS_PER_YEAR);
        }

        // Aggregate rows into bars of equal simulated time, preserving price extremes.
        return $this->json($barAggregator->aggregate(
            $stmt->iterateAssociative(),
            (float) $newestSimTime - (float) $oldestSimTime,
            $rowsPerYear,
            $targetBars,
            $minRowsPerBar
        ));
    }

    /**
     * API endpoint to retrieve sparse, quarterly fundamental data for overlays.
     */
    #[Route('/api/fundamentals', name: 'api_fundamentals')]
    public function fundamentals(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $ticker = $request->query->get('ticker');
        if (!$ticker) return $this->json([]);

        $stock = $entityManager->getRepository(Stock::class)->findOneByTicker($ticker);
        if (!$stock) return $this->json([]); // ETFs don't have corporate reports

        $conn = $entityManager->getConnection();

        // Fetch all fundamental reports for this stock, oldest to newest (for charting)
        $sql = 'SELECT cr.* FROM corporate_report cr WHERE cr.stock_id = :id ORDER BY cr.recorded_at ASC';
        $stmt = $conn->executeQuery($sql, ['id' => $stock->getId()]);
        $results = $stmt->fetchAllAssociative();

        $currentPrice = (string) $stock->getPrice();
        $priceStmt = $conn->prepare('SELECT price FROM stock_history WHERE stock_id = :id AND recorded_at <= :date ORDER BY recorded_at DESC LIMIT 1');
        $priceStmt->bindValue('id', $stock->getId());

        foreach ($results as &$row) {
            $row['current_price'] = $currentPrice;
            $priceStmt->bindValue('date', $row['recorded_at']);
            $priceResult = $priceStmt->executeQuery()->fetchOne();
            $row['historical_price'] = $priceResult !== false ? (string) $priceResult : $currentPrice;
        }
        unset($row);

        return $this->json($results);
    }

    /**
     * API endpoint to retrieve data for the Sankey earnings flow diagram.
     */
    #[Route('/api/earnings-flow', name: 'api_earnings_flow')]
    public function earningsFlow(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $ticker = $request->query->get('ticker');
        if (!$ticker) return $this->json([]);

        $stock = $entityManager->getRepository(Stock::class)->findOneByTicker($ticker);
        if (!$stock) return $this->json([]);

        $conn = $entityManager->getConnection();

        // Fetch latest corporate report
        $sql = 'SELECT cr.* FROM corporate_report cr WHERE cr.stock_id = :id ORDER BY cr.recorded_at DESC LIMIT 1';
        $stmt = $conn->executeQuery($sql, ['id' => $stock->getId()]);
        $latestReport = $stmt->fetchAssociative();

        if (!$latestReport) return $this->json([]);

        $flow = \App\Service\Corporate\EarningsFlowStatement::fromReport($latestReport);

        if ($flow->totalRevenue <= 0) {
            // Can't draw a meaningful Sankey if there's no revenue
            return $this->json(['nodes' => [], 'links' => []]);
        }

        $totalRevenue = $flow->totalRevenue;

        // Each node carries its role, and sankey_controller.js colours it from the theme: money in, profit and
        // cash kept, costs, non-cash charges, and cash paid out to shareholders.
        $nodes = [
            ['name' => 'Total revenue', 'role' => 'income'],
            ['name' => 'Operating costs', 'role' => 'cost'],
            ['name' => 'EBITDA', 'role' => 'profit'],
            ['name' => 'Depreciation', 'role' => 'noncash'],
            ['name' => 'Operating profit', 'role' => 'profit'],
            ['name' => 'Capital expenditure', 'role' => 'cost'],
            ['name' => 'Interest expense', 'role' => 'cost'],
            ['name' => 'Pre-tax income', 'role' => 'profit'],
            ['name' => 'Taxes', 'role' => 'cost'],
            ['name' => 'Goodwill impairment', 'role' => 'noncash'],
            ['name' => 'Bank levy', 'role' => 'cost'],
            ['name' => 'Net income', 'role' => 'profit'],
            ['name' => 'Cash generated', 'role' => 'profit'],
            ['name' => 'External funding', 'role' => 'income'],
            ['name' => 'Dividends', 'role' => 'payout'],
            ['name' => 'Buybacks', 'role' => 'payout'],
            ['name' => 'Retained cash', 'role' => 'profit'],
        ];

        $links = [];
        $addLink = function(string $source, string $target, float $value) use (&$links) {
            if ($value > 0.0001) {
                $links[] = ['source' => $source, 'target' => $target, 'value' => round($value, 4)];
            }
        };

        // Dynamically add revenue streams if present
        $revenueStreams = isset($latestReport['revenue_streams']) ? json_decode($latestReport['revenue_streams'], true) : null;
        $streamDetails = isset($latestReport['stream_details']) ? json_decode($latestReport['stream_details'], true) : null;
        if (is_array($revenueStreams) && count($revenueStreams) > 0) {
            foreach ($revenueStreams as $streamName => $streamValue) {
                $value = (float) $streamValue;
                if ($value > 0) {
                    $formattedName = ucfirst(str_replace('_', ' ', $streamName)) . ' revenue';
                    $nodeData = ['name' => $formattedName, 'role' => 'income'];
                    if (is_array($streamDetails) && isset($streamDetails[$streamName])) {
                        $nodeData['streamKey'] = $streamName;
                        // Null is "not meaningful" — a stream with no prior quarter has no growth rate to quote.
                        $nodeData['qoq_delta'] = $streamDetails[$streamName]['qoq_delta'] ?? null;
                        $nodeData['drivers'] = $streamDetails[$streamName]['drivers'] ?? [];
                        if (!empty($streamDetails[$streamName]['event'])) {
                            $nodeData['event'] = $streamDetails[$streamName]['event'];
                        }
                    }
                    $nodes[] = $nodeData;
                    $addLink($formattedName, 'Total revenue', $value);
                }
            }
            
            // If the sum of streams doesn't perfectly match total revenue (due to interest income or rounding),
            // add an 'Other Revenue' or 'Interest Income' node to balance the Sankey
            $streamSum = array_sum($revenueStreams);
            $difference = $totalRevenue - $streamSum;
            if ($difference > 0.01) {
                $nodes[] = ['name' => 'Other and interest income', 'role' => 'income'];
                $addLink('Other and interest income', 'Total revenue', $difference);
            }
        }


        $addLink('Total revenue', 'Operating costs', $flow->operatingCosts);
        $addLink('Total revenue', 'EBITDA', $flow->ebitda);
        $addLink('EBITDA', 'Depreciation', $flow->depreciation);
        $addLink('EBITDA', 'Operating profit', $flow->operatingProfit);

        $addLink('Operating profit', 'Interest expense', $flow->interestExpense);
        $addLink('Operating profit', 'Pre-tax income', $flow->preTaxIncome);

        $addLink('Pre-tax income', 'Taxes', $flow->taxes);
        $addLink('Pre-tax income', 'Goodwill impairment', $flow->goodwillImpairment);
        $addLink('Pre-tax income', 'Bank levy', $flow->bankLevy);
        $addLink('Pre-tax income', 'Net income', $flow->netIncome);

        // Cash sources and uses: add back non-cash depreciation and goodwill impairment.
        $addLink('Net income', 'Cash generated', max(0.0, $flow->netIncome));
        $addLink('Depreciation', 'Cash generated', $flow->depreciation);
        $addLink('Goodwill impairment', 'Cash generated', $flow->goodwillImpairment);
        $addLink('External funding', 'Cash generated', $flow->externalFunding);

        $addLink('Cash generated', 'Capital expenditure', $flow->capitalExpenditures);
        $addLink('Cash generated', 'Dividends', $flow->dividends);
        $addLink('Cash generated', 'Buybacks', $flow->buybacks);
        $addLink('Cash generated', 'Retained cash', $flow->retainedCash);

        // A node nothing flows through this quarter (no impairment, no outside funding) would sit as a bare label.
        $linked = array_flip(array_merge(array_column($links, 'source'), array_column($links, 'target')));
        $nodes = array_values(array_filter($nodes, static fn (array $node): bool => isset($linked[$node['name']])));

        return $this->json(['nodes' => $nodes, 'links' => $links]);
    }
}
