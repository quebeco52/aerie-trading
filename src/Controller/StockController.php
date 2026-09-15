<?php

namespace App\Controller;

use App\Entity\Stock;
use App\Repository\EtfRepository;
use App\Repository\StockRepository;
use App\Service\View\StockPageBuilder;
use App\Entity\Etf;
use App\Entity\User;
use App\Service\Market\PriceBarAggregator;
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
     * @param Request                $request       The HTTP request containing 'ticker' and 'range' parameters.
     * @param EntityManagerInterface $entityManager The entity manager.
     * @param \Redis                 $redis         The Redis instance for caching short-term data.
     *
     * @return JsonResponse Returns a JSON array of historical data points.
     */
    #[Route('/api/history', name: 'api_history')]
    public function history(Request $request, EntityManagerInterface $entityManager, \Redis $redis, PriceBarAggregator $barAggregator): JsonResponse
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

            // Buffered points are single ticks, so the bar has to be built here or every candle is a doji.
            return $this->json($barAggregator->aggregate($results, count($results)));
        }

        // A range button names a span of SIMULATED TIME, so the row limit behind it has to be derived from
        // the configured tick rate and the rate history is actually sampled at. The counts here were
        // hardcoded to a 4,800-row year, a figure the sampler never produces: it caps at 2,400 rows per
        // year, so every span was off by whatever ratio the configured rate happened to differ by.
        $pointsPerYear = \App\Command\MarketTickerCommand::historyPointsPerYear($ticksPerYear);
        $rangeYears = ['3m' => 0.25, '6m' => 0.5, '1y' => 1.0, '3y' => 3.0, '5y' => 5.0, '10y' => 10.0];

        $limit = $range === 'max'
            ? 999999
            : (int) ceil(($rangeYears[$range] ?? 1.0) * $pointsPerYear);

        $dbLimit = min($limit, 500000);
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

                // Bonds chart CLEAN, matching what the ticker buffers into Redis for the short ranges.
                // Charting the dirty price would draw the coupon accrual sawtooth as if it were price
                // movement, and the series would jump at the join between the buffer and the table.
                $priceColumn = 'clean_price';
            }
        }

        // Row count sets the bucket width the aggregator folds into bars.
        $countSql = sprintf(
            'SELECT COUNT(id) FROM (SELECT id FROM %s WHERE %s = :id ORDER BY sim_time DESC, id DESC LIMIT %d) as sub',
            $tableName,
            $foreignKey,
            (int)$dbLimit
        );
        $actualCount = (int) $conn->fetchOne($countSql, ['id' => $targetId]);

        if ($actualCount === 0) return $this->json([]);

        // Stocks carry a full bar; ETFs and bonds are a single series and select the close alone. Asking
        // for open_price on etf_history would be a SQL error rather than a null.
        $barColumns = $tableName === 'stock_history'
            ? ', open_price, high_price, low_price, volume'
            : '';

        $sql = sprintf(
            // Ordered by SIMULATION time, which is the clock the market is keyed on. `recorded_at` is the
            // wall clock of whichever container wrote the row, and the two only track each other while the
            // ticker runs uninterrupted — a restart leaves a gap in one and none in the other. The id
            // tie-break is not decoration either: many rows share a simulation instant at a fast tick rate
            // and their order within it is undefined, which scrambles the open and close inside every bar.
            // Leading with sim_time keeps the idx_*_sim_time backward scan; ordering by id alone cannot use
            // an index and filesorts the name's whole history on every chart load.
            'SELECT id, %s AS price%s, recorded_at FROM %s WHERE %s = :id ORDER BY sim_time DESC, id DESC LIMIT %d',
            $priceColumn,
            $barColumns,
            $tableName,
            $foreignKey,
            (int)$dbLimit
        );

        $stmt = $conn->executeQuery($sql, ['id' => $targetId]);

        // Bucketed rather than decimated. Keeping every Nth row and discarding the rest is right for a line
        // — it is a subsample of closes — but it throws away the extremes of every dropped bar and leaves
        // each surviving candle opening nowhere near the previous close. The stream is consumed once.
        return $this->json($barAggregator->aggregate($stmt->iterateAssociative(), $actualCount));
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

        // Sources of cash, then what it was spent on. Depreciation appears on both sides on purpose: it is
        // struck against EBITDA and added straight back, which is exactly how a cash flow statement reads.
        // The goodwill write-off comes back for the same reason — no money left the company.
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
