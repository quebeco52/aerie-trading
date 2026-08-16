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
    public function view(string $ticker, EntityManagerInterface $entityManager, \Redis $redis, \App\Service\Math\CorporateMetrics $corporateMetrics): Response
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
        }

        $generalInfo = $asset->getDescription();
        $quote = \App\Data\StockInfo::getQuote($ticker);

        $allAssets = [];
        if ($isEtf) {
            $allAssets = $entityManager->getRepository(Stock::class)->findAll();
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

        $economicCycle = $redis->get('economy_state') ?: 'Expansion';

        $openOrders = [];
        if ($currentUser) {
            $openOrders = $entityManager->getRepository(\App\Entity\TradeOrder::class)->findBy([
                'user' => $currentUser,
                'ticker' => $ticker,
                'status' => 'OPEN'
            ], ['createdAt' => 'DESC']);
        }

        return $this->render('stock/index.html.twig', [
            'asset' => $asset,
            'isEtf' => $isEtf,
            'isFinancial' => $isFinancial,
            'businessModel' => $businessModel,
            'investedCapital' => $investedCapital,
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
            'marketShare' => $marketShare,
            'quote' => $quote,
            'openOrders' => $openOrders
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

        // Ensure everything is numeric
        $revenue = (float)$latestReport['revenue'];
        $interestIncome = (float)$latestReport['interest_income'];
        $totalRevenue = $revenue + $interestIncome;

        if ($totalRevenue <= 0) {
            // Can't draw a meaningful Sankey if there's no revenue
            return $this->json(['nodes' => [], 'links' => []]);
        }

        // Fetch granular fields (fallback to 0 if migration hasn't run yet)
        $operatingCostsRaw = max(0, (float)($latestReport['operating_costs'] ?? 0));
        $capexRaw = max(0, (float)$latestReport['capital_expenditures']);
        $interestExpenseRaw = max(0, (float)$latestReport['interest_expense']);
        $taxPaidRaw = max(0, (float)($latestReport['tax_paid'] ?? 0));
        $divPaidRaw = max(0, (float)$latestReport['dividend_paid']);
        $buybacksRaw = max(0, (float)$latestReport['stock_buybacks']);

        // Balance the flows so the Sankey diagram is perfectly aligned. 
        // Sankey diagrams require flow in = flow out.
        $actualOperatingCosts = min($totalRevenue, $operatingCostsRaw);
        $opProfit = $totalRevenue - $actualOperatingCosts;
        
        $actualInterest = min($opProfit, $interestExpenseRaw);
        $preTax = $opProfit - $actualInterest;
        
        $actualTax = min($preTax, $taxPaidRaw);
        $netIncomeFlow = $preTax - $actualTax;
        
        $actualCapex = min($netIncomeFlow, $capexRaw);
        $actualDiv = min($netIncomeFlow - $actualCapex, $divPaidRaw);
        $actualBuybacks = min($netIncomeFlow - $actualCapex - $actualDiv, $buybacksRaw);
        $retained = $netIncomeFlow - $actualCapex - $actualDiv - $actualBuybacks;

        $nodes = [
            ['name' => 'Total Revenue', 'itemStyle' => ['color' => '#3b82f6']], // blue
            ['name' => 'Operating Costs', 'itemStyle' => ['color' => '#ef4444']], // red
            ['name' => 'Operating Profit', 'itemStyle' => ['color' => '#8b5cf6']], // purple
            ['name' => 'Capital Expenditures', 'itemStyle' => ['color' => '#eab308']], // yellow
            ['name' => 'Interest Expense', 'itemStyle' => ['color' => '#f97316']], // orange
            ['name' => 'Pre-Tax Income', 'itemStyle' => ['color' => '#14b8a6']], // teal
            ['name' => 'Taxes', 'itemStyle' => ['color' => '#f43f5e']], // rose
            ['name' => 'Net Income', 'itemStyle' => ['color' => '#22c55e']], // green
            ['name' => 'Dividends', 'itemStyle' => ['color' => '#0ea5e9']], // light blue
            ['name' => 'Stock Buybacks', 'itemStyle' => ['color' => '#d946ef']], // fuchsia
            ['name' => 'Retained Earnings', 'itemStyle' => ['color' => '#10b981']], // emerald
        ];

        $links = [];
        $addLink = function(string $source, string $target, float $value) use (&$links) {
            if ($value > 0.0001) {
                $links[] = ['source' => $source, 'target' => $target, 'value' => round($value, 4)];
            }
        };

        // Dynamically add revenue streams if present
        $revenueStreams = isset($latestReport['revenue_streams']) ? json_decode($latestReport['revenue_streams'], true) : null;
        if (is_array($revenueStreams) && count($revenueStreams) > 0) {
            foreach ($revenueStreams as $streamName => $streamValue) {
                $value = (float) $streamValue;
                if ($value > 0) {
                    $formattedName = ucwords(str_replace('_', ' ', $streamName)) . ' Revenue';
                    $nodes[] = ['name' => $formattedName, 'itemStyle' => ['color' => '#0284c7']]; // sky blue
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


        $addLink('Total Revenue', 'Operating Costs', $actualOperatingCosts);
        $addLink('Total Revenue', 'Operating Profit', $opProfit);
        
        $addLink('Operating Profit', 'Interest Expense', $actualInterest);
        $addLink('Operating Profit', 'Pre-Tax Income', $preTax);
        
        $addLink('Pre-Tax Income', 'Taxes', $actualTax);
        $addLink('Pre-Tax Income', 'Net Income', $netIncomeFlow);
        
        $addLink('Net Income', 'Capital Expenditures', $actualCapex);
        $addLink('Net Income', 'Dividends', $actualDiv);
        $addLink('Net Income', 'Stock Buybacks', $actualBuybacks);
        $addLink('Net Income', 'Retained Earnings', $retained);

        return $this->json(['nodes' => $nodes, 'links' => $links]);
    }
}
