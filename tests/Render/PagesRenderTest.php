<?php

declare(strict_types=1);

namespace App\Tests\Render;

use App\Data\InitialMarket;
use App\Data\StockInfo;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Renders the main player pages from stub data, with no database or network, for a screenshot. Not in any suite:
 * bin/render-pages runs it. Strict variables are off because the stubs carry only what each page shows.
 */
final class PagesRenderTest extends KernelTestCase
{
    public function testRender(): void
    {
        mt_srand(11);

        $stocks = [];
        foreach (array_slice(InitialMarket::STOCKS, 0, 24) as $s) {
            $price = mt_rand(800, 42000) / 100;
            $shares = mt_rand(50, 900) * 1_000_000;
            $stocks[] = [
                'ticker' => $s['ticker'], 'name' => $s['name'], 'sector' => $s['sector'],
                'price' => $price, 'changePercent' => (mt_rand(-900, 900) / 10000),
                'marketCap' => $price * $shares, 'shares' => $shares, 'isBankrupt' => false,
                'peRatio' => mt_rand(800, 3200) / 100, 'eps' => mt_rand(50, 900) / 100,
                'currentRoic' => mt_rand(-30, 220) / 1000, 'equity' => $price * $shares * 0.4,
                'debtToEquity' => mt_rand(10, 300) / 100, 'netDebtToEbitda' => mt_rand(-50, 550) / 100,
                'fcfConversion' => mt_rand(40, 130) / 100,
            ];
        }
        $stocks[3]['changePercent'] = 0.0;
        usort($stocks, static fn ($a, $b) => $b['marketCap'] <=> $a['marketCap']);
        $macro = ['output_gap' => 0.0132, 'inflation' => 0.0241, 'policy_rate' => 0.0425, 'yield_10y' => 0.0461];

        $pages = [
            'landing' => ['/', 'home/landing.html.twig', [
                'etf' => ['name' => 'Skein Lakebird 30', 'ticker' => 'LBI', 'price' => 412.37],
                'breadth' => ['advancing' => 31, 'declining' => 12, 'unchanged' => 2, 'topMover' => ['ticker' => 'HUMM', 'changePercent' => 0.1342]],
                'macro' => $macro,
                'top_stocks' => array_slice($stocks, 0, 6),
            ]],
            'home' => ['/', 'home/index.html.twig', [
                'macro' => $macro,
                'indices' => [
                    ['ticker' => 'LBI', 'name' => 'Skein Lakebird 30', 'label' => 'Headline index', 'isBenchmark' => true, 'price' => 412.37],
                    ['ticker' => 'LBC', 'name' => 'Skein Total Market', 'label' => 'Whole board', 'isBenchmark' => false, 'price' => 188.02],
                ],
                'stocks' => $stocks,
            ]],
            'screener' => ['/screener', 'screener/index.html.twig', ['stocks' => $stocks]],
            'leaderboard' => ['/leaderboard', 'leaderboard/index.html.twig', ['leaders' => [
                ['username' => 'kestrel', 'total_value' => 1834221.12, 'cash_balance' => 120331.0, 'stock_value' => 1500000.0, 'etf_value' => 213890.12],
                ['username' => 'marlowe', 'total_value' => 1402113.50, 'cash_balance' => 30211.0, 'stock_value' => 1300000.0, 'etf_value' => 71902.5],
                ['username' => 'ptarmigan', 'total_value' => 1210990.00, 'cash_balance' => 410990.0, 'stock_value' => 800000.0, 'etf_value' => 0.0],
            ]]],
            'login' => ['/login', 'security/login.html.twig', ['error' => null, 'last_username' => '']],
            'economy' => ['/economy', 'economy/index.html.twig', ['economic_cycle' => 'Expansion', 'macro' => ['inflation' => 0.0241, 'outputGap' => 0.0132, 'policyRate' => 0.0425, 'yield10y' => 0.0461, 'qeActive' => false, 'qeIntensity' => 0.0]]],
            'stock' => ['/stock/LAKE', 'stock/index.html.twig', [
                'asset' => ['ticker' => 'LAKE', 'name' => 'Lakebird Bank', 'sector' => 'Financials', 'industry' => 'Commercial banking', 'price' => 84.21, 'isBankrupt' => false, 'bankrupt' => false, 'sharesOutstanding' => 2400000000, 'earningsPerShare' => 6.12, 'currentRoe' => 0.124, 'baselineRoe' => 0.12, 'currentRoic' => 0.09, 'baselineRoic' => 0.09, 'publicFloatPercentage' => 0.8, 'volatility' => 0.22, 'totalEquity' => 1.6e11, 'debtToEquityRatio' => 1.4, 'creditRating' => 'A', 'creditSpread' => 0.011, 'systemicImportance' => 'Systemic'],
                'isEtf' => false, 'isFinancial' => true, 'changePercent' => 0.0342, 'businessModel' => 'commercial_bank', 'generalInfo' => StockInfo::DESCRIPTIONS['LAKE'],
                'events' => [
                    ['type' => 'MANAGEMENT CHANGE', 'description' => 'Lakebird Bank chief executive was removed by the board after 5.2 years.', 'change_percent' => 0.0, 'recorded_at' => '2026-09-30 12:00'],
                    ['type' => 'CREDIT DOWNGRADE', 'description' => 'Shrike Standard Ratings cut Lakebird Bank to A-.', 'change_percent' => -1.4, 'recorded_at' => '2026-09-12 12:00'],
                ],
                'userQuantity' => 0, 'userDividendIncome' => 0, 'marketCap' => 2.02e11, 'peRatio' => 13.76, 'dividendYield' => 0.031, 'strategicStake' => 0, 'macro' => ['sovereignFundOwnershipShare' => 0.23, 'stampDutyRate' => 0.001],
                'analystTargets' => ['rating' => 'Outperform', 'upside_pct' => 8.4, 'consensus' => 91.3, 'growth_analyst' => 95.1, 'value_analyst' => 88.2, 'income_analyst' => 90.4],
                'openOrders' => [], 'tradeHistory' => [], 'optionsListed' => false, 'optionExpiries' => [], 'lifecycleStage' => null, 'lifecycleStages' => [], 'financialSummary' => [], 'peers' => [], 'industry' => null, 'management' => null, 'anchorPortfolio' => null, 'netAssetValue' => null, 'capital' => null, 'credit' => null, 'ticksPerYear' => 14400, 'halfSpread' => 0.0004, 'advShares' => 3400000, 'borrowFee' => 0.01, 'availableToBorrow' => 1000000, 'shortUtilization' => 0.2,
            ]],
            'dashboard' => ['/dashboard', 'dashboard/index.html.twig', [
                'portfolioValue' => 1234567.89, 'totalUnrealizedPnL' => -23456.7, 'totalUnrealizedPnLPercent' => -1.9, 'cashBalance' => 200000.0, 'totalInvested' => 1034567.89, 'escrowedCash' => 1200.0, 'totalDividendIncome' => 4321.0,
                'holdings' => [
                    ['ticker' => 'LAKE', 'name' => 'Lakebird Bank', 'type' => 'STOCK', 'sector' => 'Financials', 'quantity' => 1200, 'avgCost' => 70.1, 'price' => 84.21, 'marketValue' => 101052.0, 'unrealizedPnL' => 16932.0, 'unrealizedPnLPercent' => 20.1, 'weight' => 8.2, 'dividendsReceived' => 120.0, 'isBankrupt' => false],
                    ['ticker' => 'PEAC', 'name' => 'Peacock Heritage Group', 'type' => 'STOCK', 'sector' => 'Consumer Discretionary', 'quantity' => 300, 'avgCost' => 210.0, 'price' => 210.0, 'marketValue' => 63000.0, 'unrealizedPnL' => 0.0, 'unrealizedPnLPercent' => 0.0, 'weight' => 5.1, 'dividendsReceived' => 0.0, 'isBankrupt' => false],
                ],
                'openOrders' => [['id' => 41, 'ticker' => 'LAKE', 'action' => 'BUY', 'orderType' => 'STOP_LIMIT', 'quantity' => 100, 'limitPrice' => 80.0, 'stopPrice' => 82.0, 'createdAt' => new \DateTimeImmutable('2026-10-01')]],
                'tradeHistory' => [], 'dividendPayments' => [], 'allocation' => ['stocks' => 900000, 'stocksPercent' => 72.9, 'etfs' => 134567, 'etfsPercent' => 10.9, 'cash' => 200000, 'cashPercent' => 16.2],
                'sectorBreakdown' => [['name' => 'Financials', 'value' => 500000, 'percent' => 48.3], ['name' => 'Industrials', 'value' => 300000, 'percent' => 29.0]],
                'margin' => ['buyingPower' => 200000, 'shortMarketValue' => 0, 'optionShortValue' => 0, 'equity' => 1234567, 'maintenanceRequirement' => 0, 'isCalled' => false, 'equityRatio' => 1.0, 'callAmount' => 0], 'marginDebit' => 0, 'livePrices' => [], 'totalInvestedCost' => 1000000,
            ]],
        ];

        self::bootKernel();
        $container = static::getContainer();
        $stack = $container->get('request_stack');
        $twig = $container->get('twig');
        $twig->disableStrictVariables();
        $tokens = $container->get('security.token_storage');

        foreach ($pages as $name => [$path, $template, $data]) {
            $request = Request::create($path);
            $request->setSession(new Session(new MockArraySessionStorage()));
            $stack->push($request);
            $tokens->setToken($name === 'dashboard'
                ? new UsernamePasswordToken((new User())->setEmail('kestrel@example.com')->setUsername('kestrel'), 'main', ['ROLE_USER'])
                : null);
            $html = $twig->render($template, $data);
            $stack->pop();
            $this->assertFileExists(OfflinePage::write($name, $html));
        }
    }
}
