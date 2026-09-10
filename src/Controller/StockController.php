<?php

namespace App\Controller;

use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Entity\Etf;
use App\Entity\User;
use App\Entity\UserStock;
use App\Entity\StockEvent;
use App\Entity\EtfEvent;
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
    public function view(
        string $ticker,
        EntityManagerInterface $entityManager,
        \Redis $redis,
        \App\Service\Math\CorporateMetrics $corporateMetrics,
        \App\Service\Market\MarketEngine $marketEngine,
        \App\Service\Corporate\DebtEngine $debtEngine,
        \App\Service\Market\PriceChangeFeed $priceChangeFeed,
        \App\Service\User\CostBasisCalculator $costBasis,
        \App\Service\User\DividendIncomeCalculator $dividendIncome,
        \App\Service\Market\LiquidityEngine $liquidityEngine
    ): Response
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
        $rawMacroState = $macroStateJson ? json_decode($macroStateJson, true) : [
            'inflation' => 0.02,
            'output_gap' => 0.00,
            'policy_rate' => 0.04,
            'yield_10y' => 0.045,
            'nominal_gdp_index' => 1.0
        ];

        // Hydrate the raw array into your strongly-typed DTO!
        $macroState = MacroStateDTO::fromArray($rawMacroState);

        $userQuantity = 0;
        if ($currentUser) {
            if ($isEtf) {
                $userAsset = $entityManager->getRepository(\App\Entity\UserEtf::class)->findOneBy(['user' => $currentUser, 'etf' => $asset]);
            } else {
                $userAsset = $entityManager->getRepository(UserStock::class)->findOneBy(['user' => $currentUser, 'stock' => $asset]);
            }
            $userQuantity = $userAsset ? $userAsset->getQuantity() : 0;
        }

        $marketCap = 0;
        $peRatio = null;
        $targetPE = 20.00;
        $marketShare = 0;
        $isFinancial = false;
        $businessModel = 'none';
        $investedCapital = 0.0;
        $analystTargets = null;
        $lifecycleStage = null;
        $dividendYield = 0.0;

        if (!$isEtf) {
            $isBankrupt = $asset->isBankrupt();
            $marketCap = $isBankrupt ? 0.0 : ((float) $asset->getPrice() * (float) $asset->getSharesOutstanding());
            $eps = $isBankrupt ? 0.0 : (float) $asset->getEarningsPerShare();
            $peRatio = (!$isBankrupt && $eps > 0) ? ((float) $asset->getPrice() / $eps) : null;

            $nominalGdpIndex = $macroState->nominalGdpIndex;
            $samRatio = (float) $asset->getSamRatio();

            $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$asset->getIndustry() ?? 'General']['business_model'] ?? 'none';
            $isFinancial = \App\Data\Sectors::isFinancial($businessModel);
            $evaluationCapital = $isFinancial ? (float) $asset->getTotalEquity() : $asset->getInvestedCapital();
            $investedCapital = $isBankrupt ? 0.0 : (float) $asset->getInvestedCapital();

            $marketShare = $isBankrupt ? 0.0 : min(0.9999, $corporateMetrics->calculateMarketShare($evaluationCapital, $nominalGdpIndex, $samRatio));

            // Dickinson (2011) stage stored by the last quarterly report; null until the first report lands.
            $lifecycleStage = $asset->getLifecycleStage();

            // lastDividend is the quarterly per-share payment (Lintner step each report), so the yield annualises it.
            $lastDividend = (float) $asset->getLastDividend();
            $priceForYield = (float) $asset->getPrice();
            $dividendYield = (!$isBankrupt && $priceForYield > 0.0 && $lastDividend > 0.0)
                ? ($lastDividend * 4.0) / $priceForYield
                : 0.0;

            if (!$isBankrupt) {
                $currentPrice = (float) $asset->getPrice();
                $health = $debtEngine->analyzeDebtHealth($asset, $macroState);
                $industry = $asset->getIndustry() ?: 'General';
                $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
                $shares = max(1.0, (float) $asset->getSharesOutstanding());
                $revenue = (float) $asset->getTotalRevenue();
                $debt = (float) $asset->getTotalDebt();
                $treasury = (float) $asset->getCorporateTreasury();
                $netDebt = max(0.0, $strategy->getNetDebtCapital($debt, (float) $asset->getWholesaleDebt(), $treasury));
                $baselinePE = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['pe_ratio'] ?? 20.0;
                $secularGrowth = $strategy->getSecularGrowthRate($asset);

                $pricingCtx = new \App\DTO\MarketPricingContext(
                    currentPrice: $currentPrice,
                    currentVolatility: (float) ($asset->getCurrentVolatility() ?? $asset->getVolatility()),
                    longTermVolatility: (float) $asset->getVolatility(),
                    earningsPerShare: (float) $asset->getEarningsPerShare(),
                    dt: 0.0,
                    beta: (float) $asset->getBeta(),
                    macroState: $macroState,
                    fcfPerShare: $asset->getFreeCashFlowPerShare() !== null ? (float) $asset->getFreeCashFlowPerShare() : null,
                    bookValuePerShare: (float) $asset->getBookValuePerShare(),
                    currentRoic: (float) ($asset->getCurrentRoic() ?: $asset->getBaselineRoic()),
                    roicTtm: (float) $asset->getRoicTtm(),
                    dividendPerShare: (float) $asset->getLastDividend(),
                    liveWacc: $health->wacc ?? 0.08,
                    baselineIndustryPE: $baselinePE,
                    revenuePerShare: $revenue / $shares,
                    businessModel: $businessModel,
                    liveCostOfEquity: $health->costOfEquity ?? 0.10,
                    netDebtPerShare: $netDebt / $shares,
                    secularGrowth: $secularGrowth,
                    baselineRoic: (float) ($asset->getBaselineRoic() ?? 0.10),
                    baselineMargin: (float) ($asset->getOperatingMargin() ?? 0.20),
                    investedCapitalPerShare: $asset->getInvestedCapital() / max(1.0, (float) $asset->getSharesOutstanding())
                );

                $pricingResult = $marketEngine->calculateNextPrice($pricingCtx);
                $analystTargets = $pricingResult['analyst_targets'];
                $fairValue = (float) ($pricingResult['perceived_fair_value'] ?? 0.0);
                $analystTargets['consensus'] = $fairValue > 0 ? $fairValue : max($analystTargets['growth_analyst'], $analystTargets['income_analyst'], $analystTargets['value_analyst']);

                $isOutperform = $analystTargets['consensus'] > ($currentPrice * 1.05);
                $isUnderperform = $analystTargets['consensus'] < ($currentPrice * 0.95);
                $analystTargets['rating'] = $isOutperform ? 'Outperform' : ($isUnderperform ? 'Underperform' : 'Neutral');
                $analystTargets['upside_pct'] = $currentPrice > 0 ? (($analystTargets['consensus'] - $currentPrice) / $currentPrice) * 100 : 0.0;
            }
        }

        $generalInfo = $asset->getDescription();
        $quote = \App\Data\StockInfo::getQuote($ticker);

        $allAssets = [];
        $pieLabels = [];
        $pieData = [];
        $sharesMap = [];
        $components = [];

        if ($isEtf) {
            $allAssets = $entityManager->getRepository(Stock::class)->findAll();
            $totalMcap = 0.0;
            foreach ($allAssets as $stock) {
                if ($stock->isBankrupt()) continue;
                $sPrice = (float) $stock->getPrice();
                $sShares = (float) $stock->getSharesOutstanding();
                $sMcap = $sPrice * $sShares;
                $totalMcap += $sMcap;
            }

            foreach ($allAssets as $stock) {
                if ($stock->isBankrupt()) continue;
                $sPrice = (float) $stock->getPrice();
                $sShares = (float) $stock->getSharesOutstanding();
                $sMcap = $sPrice * $sShares;
                $weight = $totalMcap > 0 ? ($sMcap / $totalMcap) * 100 : 0;

                $pieLabels[] = $stock->getTicker();
                $pieData[] = $sMcap;
                $sharesMap[$stock->getTicker()] = $sShares;

                $components[] = [
                    'ticker' => $stock->getTicker(),
                    'name' => $stock->getName(),
                    'sector' => $stock->getSector(),
                    'price' => $sPrice,
                    'marketCap' => $sMcap,
                    'weight' => $weight,
                ];
            }

            usort($components, fn($a, $b) => $b['weight'] <=> $a['weight']);
        }

        $events = [];

        if (!$isEtf) {
            $events = $entityManager->getRepository(StockEvent::class)->findBy(
                ['stock' => $asset],
                ['recordedAt' => 'DESC'], // Newest first
                15 // Limit to 15
            );
        } else {
            $events = $entityManager->getRepository(EtfEvent::class)->findBy(
                ['etf' => $asset],
                ['recordedAt' => 'DESC'],
                15 // Limit to 15
            );
        }

        $economicCycle = $macroState->economicCycleLabel();

        $openOrders = [];
        $userTrades = [];
        $userAvgCost = (float) $asset->getPrice();
        $userUnrealizedPnL = 0.0;
        $userUnrealizedPnLPercent = 0.0;
        $userDividendIncome = 0.0;

        if ($currentUser) {
            // Lifetime dividend cash this ticker has paid the viewer. Read outside the userQuantity > 0
            // branch below: income already received survives selling out of the position, and zeroing it
            // for a closed position would hide cash the user actually holds.
            $userDividendIncome = $dividendIncome->totalsByTicker($currentUser)[$ticker] ?? 0.0;

            $openOrders = $entityManager->getRepository(\App\Entity\TradeOrder::class)->findBy([
                'user' => $currentUser,
                'ticker' => $ticker,
                'status' => 'OPEN'
            ], ['createdAt' => 'DESC']);

            $userTrades = $entityManager->getRepository(\App\Entity\TradeOrder::class)->findBy([
                'user' => $currentUser,
                'ticker' => $ticker,
                'status' => ['FILLED', 'CANCELLED']
            ], ['createdAt' => 'DESC'], 20);

            if ($userQuantity > 0) {
                // Same weighted-average basis the dashboard reports. Averaging BUYs alone and ignoring SELLs
                // gave this page a different cost, and a different P&L, for the very same position.
                $filledOrders = $entityManager->createQuery(
                    'SELECT o FROM App\Entity\TradeOrder o WHERE o.user = :user AND o.ticker = :ticker AND o.status = :status ORDER BY o.createdAt ASC'
                )->setParameter('user', $currentUser)->setParameter('ticker', $ticker)->setParameter('status', 'FILLED')->getResult();

                $userAvgCost = $costBasis->calculateForTicker($filledOrders, $ticker) ?? $userAvgCost;
                $userPositionCost = $userAvgCost * $userQuantity;
                $currentVal = (float)$asset->getPrice() * $userQuantity;
                $userUnrealizedPnL = $currentVal - $userPositionCost;
                $userUnrealizedPnLPercent = $userPositionCost > 0 ? ($userUnrealizedPnL / $userPositionCost) * 100 : 0.0;
            }
        }

        // Fetch Sector Peers for comparative analysis
        $peers = [];
        if (!$isEtf && $asset->getSector()) {
            $peerEntities = $entityManager->getRepository(Stock::class)->findBy(['sector' => $asset->getSector()]);
            foreach ($peerEntities as $p) {
                if ($p->getId() === $asset->getId()) continue;
                $pPrice = (float) $p->getPrice();
                $pShares = (float) $p->getSharesOutstanding();
                $pEps = (float) $p->getEarningsPerShare();
                $pMcap = $p->isBankrupt() ? 0.0 : ($pPrice * $pShares);
                $pPe = (!$p->isBankrupt() && $pEps > 0) ? ($pPrice / $pEps) : null;
                $pModel = \App\Data\Sectors::INDUSTRY_METRICS[$p->getIndustry() ?? 'General']['business_model'] ?? 'none';
                $pIsFin = \App\Data\Sectors::isFinancial($pModel);
                $pRoic = $pIsFin ? ((float)$p->getCurrentRoe() ?: (float)$p->getBaselineRoe()) : ((float)$p->getCurrentRoic() ?: (float)$p->getBaselineRoic());

                $peers[] = [
                    'ticker' => $p->getTicker(),
                    'name' => $p->getName(),
                    'industry' => $p->getIndustry(),
                    'price' => $pPrice,
                    'marketCap' => $pMcap,
                    'peRatio' => $pPe,
                    'roic' => $pRoic,
                    'totalEquity' => (float)$p->getTotalEquity(),
                    'isBankrupt' => $p->isBankrupt(),
                ];
            }
            usort($peers, fn($a, $b) => $b['marketCap'] <=> $a['marketCap']);
        }

        // Null when the ticker has no usable buffered history; the header prints that as
        // unknown rather than as a flat 0.00%.
        // Etf carries no bankruptcy flag, so the delisted check only applies to a Stock.
        $changePercent = (!$isEtf && $asset->isBankrupt())
            ? null
            : $priceChangeFeed->changeForTicker($ticker, (float) $asset->getPrice());

        return $this->render('stock/index.html.twig', [
            'asset' => $asset,
            'changePercent' => $changePercent,
            'isEtf' => $isEtf,
            'isFinancial' => $isFinancial,
            'businessModel' => $businessModel,
            'investedCapital' => $investedCapital,
            'userQuantity' => $userQuantity,
            'userAvgCost' => $userAvgCost,
            'userUnrealizedPnL' => $userUnrealizedPnL,
            'userUnrealizedPnLPercent' => $userUnrealizedPnLPercent,
            'userDividendIncome' => $userDividendIncome,
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
            'quote' => $quote,
            'openOrders' => $openOrders,
            'userTrades' => $userTrades,
            'peers' => $peers,
            'pieLabels' => $pieLabels,
            'pieData' => $pieData,
            'sharesMap' => $sharesMap,
            'components' => $components,
            'analystTargets' => $analystTargets,
            'lifecycleStage' => $lifecycleStage,
            'lifecycleStages' => \App\Data\LifecycleStage::cases(),
            'dividendYield' => $dividendYield,
            // Depth and the cost of crossing it. Shown because a page that quotes a price without saying
            // what size costs is only telling half of what a trade is going to do.
            'advShares' => $isEtf ? 0.0 : $liquidityEngine->averageDailyVolume($asset),
            'halfSpread' => $isEtf
                ? \App\Service\Math\FinancialConstants::ETF_HALF_SPREAD
                : $liquidityEngine->halfSpreadFraction($asset),
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
        $maxChartPoints = 5000;
        $conn = $entityManager->getConnection();

        // Fetch the target asset ID
        $stock = $entityManager->getRepository(Stock::class)->findOneBy(['ticker' => $ticker]);
        if ($stock) {
            $targetId = $stock->getId();
            $tableName = 'stock_history';
            $foreignKey = 'stock_id';
            $priceColumn = 'price';
        } else {
            $etf = $entityManager->getRepository(Etf::class)->findOneBy(['ticker' => $ticker]);
            if ($etf) {
                $targetId = $etf->getId();
                $tableName = 'etf_history';
                $foreignKey = 'etf_id';
                $priceColumn = 'price';
            } else {
                $bond = $entityManager->getRepository(\App\Entity\Bond::class)->findOneBy(['ticker' => $ticker]);
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

        // Stocks carry a full bar; ETFs and bonds are a single series and select the close alone. Asking
        // for open_price on etf_history would be a SQL error rather than a null.
        $barColumns = $tableName === 'stock_history'
            ? ', open_price, high_price, low_price, volume'
            : '';

        $sql = sprintf(
            'SELECT id, %s AS price%s, recorded_at FROM %s WHERE %s = :id ORDER BY recorded_at DESC LIMIT %d',
            $priceColumn,
            $barColumns,
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

        $stock = $entityManager->getRepository(Stock::class)->findOneBy(['ticker' => $ticker]);
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
        $addLink('Pre-Tax Income', 'Net Income', $flow->netIncome);

        // Sources of cash, then what it was spent on. Depreciation appears on both sides on purpose: it is
        // struck against EBITDA and added straight back, which is exactly how a cash flow statement reads.
        $addLink('Net Income', 'Cash Generated', max(0.0, $flow->netIncome));
        $addLink('Depreciation', 'Cash Generated', $flow->depreciation);
        $addLink('External Funding', 'Cash Generated', $flow->externalFunding);

        $addLink('Cash Generated', 'Capital Expenditures', $flow->capitalExpenditures);
        $addLink('Cash Generated', 'Dividends', $flow->dividends);
        $addLink('Cash Generated', 'Stock Buybacks', $flow->buybacks);
        $addLink('Cash Generated', 'Retained Cash', $flow->retainedCash);

        return $this->json(['nodes' => $nodes, 'links' => $links]);
    }
}
