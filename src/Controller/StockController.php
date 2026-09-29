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
        StockPageBuilder $pageBuilder
    ): Response {
        $asset = $stocks->findOneByTicker($ticker) ?? $etfs->findOneByTicker($ticker);
        if ($asset === null) {
            throw $this->createNotFoundException('Ticker not found');
        }

        /** @var User|null $currentUser */
        $currentUser = $this->getUser();

        return $this->render('stock/index.html.twig', $pageBuilder->build($asset, $ticker, $currentUser));
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
    public function history(Request $request, EntityManagerInterface $entityManager, \Redis $redis, PriceBarAggregator $barAggregator): JsonResponse
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

        $ticksPerYear = (int) ($_ENV['SIM_TICKS_PER_YEAR'] ?? 14400);

        // Redis cache for short timeframes, counted in the buffer's own entries: a bond pushes its day's mark
        // and nothing between, everything else pushes every tick.
        if (ChartRange::isBuffered($range)) {
            $isBond = $entityManager->getRepository(\App\Entity\Bond::class)->count(['ticker' => $ticker]) > 0;
            $entriesPerYear = $isBond
                ? TickCadence::bondMarksPerYear($ticksPerYear)
                : $ticksPerYear;

            $cacheKey = "chart_buffer:{$ticker}";
            $redisData = $redis->lRange($cacheKey, 0, ChartRange::entries($range, $entriesPerYear) - 1);
            $results = [];
            foreach ($redisData as $jsonStr) {
                $results[] = json_decode($jsonStr, true);
            }

            // Buffered points are single ticks, so the bar has to be built here or every candle is a doji.
            return $this->json($barAggregator->aggregate($results, count($results), $targetBars, $minRowsPerBar));
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
                $priceColumn = 'clean_price';
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

        // Row count sets the bucket width the aggregator folds into bars.
        $countSql = sprintf(
            'SELECT COUNT(id) FROM (SELECT id FROM %s WHERE %s ORDER BY sim_time DESC, id DESC LIMIT %d) as sub',
            $tableName,
            $where,
            ChartRange::MAX_ROWS
        );
        $actualCount = (int) $conn->fetchOne($countSql, $params);

        if ($actualCount === 0) return $this->json([]);

        // Stocks carry a full bar; ETFs and bonds are a single series and select the close alone. Asking
        // for open_price on etf_history would be a SQL error rather than a null.
        $barColumns = $tableName === 'stock_history'
            ? ', open_price, high_price, low_price, volume'
            : '';

        $sql = sprintf(
            // Order by sim_time and id descending using the sim_time index to preserve intra-tick candle order.
            'SELECT id, %s AS price%s, recorded_at FROM %s WHERE %s ORDER BY sim_time DESC, id DESC LIMIT %d',
            $priceColumn,
            $barColumns,
            $tableName,
            $where,
            ChartRange::MAX_ROWS
        );

        $stmt = $conn->executeQuery($sql, $params);

        // Aggregate rows into candles via bucketed bar aggregation to preserve price extremes.
        return $this->json($barAggregator->aggregate($stmt->iterateAssociative(), $actualCount, $targetBars, $minRowsPerBar));
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

        $nodes = [
            ['name' => 'Total Revenue', 'itemStyle' => ['color' => '#3b82f6']], // blue
            ['name' => 'Operating Costs', 'itemStyle' => ['color' => '#ef4444']], // red
            ['name' => 'EBITDA', 'itemStyle' => ['color' => '#a78bfa']], // violet
            ['name' => 'Depreciation', 'itemStyle' => ['color' => '#94a3b8']], // slate (non-cash)
            ['name' => 'Operating Profit', 'itemStyle' => ['color' => '#8b5cf6']], // purple
            ['name' => 'Capital Expenditures', 'itemStyle' => ['color' => '#eab308']], // yellow
            ['name' => 'Interest Expense', 'itemStyle' => ['color' => '#f97316']], // orange
            ['name' => 'Pre-Tax Income', 'itemStyle' => ['color' => '#14b8a6']], // teal
            ['name' => 'Taxes', 'itemStyle' => ['color' => '#f43f5e']], // rose
            ['name' => 'Goodwill Impairment', 'itemStyle' => ['color' => '#94a3b8']], // slate (non-cash)
            ['name' => 'Net Income', 'itemStyle' => ['color' => '#22c55e']], // green
            ['name' => 'Cash Generated', 'itemStyle' => ['color' => '#34d399']], // mint
            ['name' => 'External Funding', 'itemStyle' => ['color' => '#fb923c']], // amber (debt raised or shares issued)
            ['name' => 'Dividends', 'itemStyle' => ['color' => '#0ea5e9']], // light blue
            ['name' => 'Stock Buybacks', 'itemStyle' => ['color' => '#d946ef']], // fuchsia
            ['name' => 'Retained Cash', 'itemStyle' => ['color' => '#10b981']], // emerald
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
                    $formattedName = ucwords(str_replace('_', ' ', $streamName)) . ' Revenue';
                    $nodeData = ['name' => $formattedName, 'itemStyle' => ['color' => '#0284c7']]; // sky blue
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
                    $addLink($formattedName, 'Total Revenue', $value);
                }
            }
            
            // If the sum of streams doesn't perfectly match total revenue (due to interest income or rounding),
            // add an 'Other Revenue' or 'Interest Income' node to balance the Sankey
            $streamSum = array_sum($revenueStreams);
            $difference = $totalRevenue - $streamSum;
            if ($difference > 0.01) {
                $nodes[] = ['name' => 'Other / Interest Income', 'itemStyle' => ['color' => '#64748b']]; // slate
                $addLink('Other / Interest Income', 'Total Revenue', $difference);
            }
        }


        $addLink('Total Revenue', 'Operating Costs', $flow->operatingCosts);
        $addLink('Total Revenue', 'EBITDA', $flow->ebitda);
        $addLink('EBITDA', 'Depreciation', $flow->depreciation);
        $addLink('EBITDA', 'Operating Profit', $flow->operatingProfit);

        $addLink('Operating Profit', 'Interest Expense', $flow->interestExpense);
        $addLink('Operating Profit', 'Pre-Tax Income', $flow->preTaxIncome);

        $addLink('Pre-Tax Income', 'Taxes', $flow->taxes);
        $addLink('Pre-Tax Income', 'Goodwill Impairment', $flow->goodwillImpairment);
        $addLink('Pre-Tax Income', 'Net Income', $flow->netIncome);

        // Cash sources and uses: add back non-cash depreciation and goodwill impairment.
        $addLink('Net Income', 'Cash Generated', max(0.0, $flow->netIncome));
        $addLink('Depreciation', 'Cash Generated', $flow->depreciation);
        $addLink('Goodwill Impairment', 'Cash Generated', $flow->goodwillImpairment);
        $addLink('External Funding', 'Cash Generated', $flow->externalFunding);

        $addLink('Cash Generated', 'Capital Expenditures', $flow->capitalExpenditures);
        $addLink('Cash Generated', 'Dividends', $flow->dividends);
        $addLink('Cash Generated', 'Stock Buybacks', $flow->buybacks);
        $addLink('Cash Generated', 'Retained Cash', $flow->retainedCash);

        return $this->json(['nodes' => $nodes, 'links' => $links]);
    }
}
