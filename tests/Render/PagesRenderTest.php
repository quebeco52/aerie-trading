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
            'leaderboard' => ['/leaderboard', 'leaderboard/index.html.twig', [
                'view' => 'season',
                'views' => ['season' => 'This season', 'networth' => 'Net worth', 'past' => 'Past seasons'],
                'myId' => 2,
                'season' => [
                    'number' => 4, 'span' => 'Year 13 Q1 to Year 17 Q1', 'realSecondsLeft' => 9.5 * 86400, 'qualifyingWeeks' => 13,
                    'ranked' => [
                        ['rank' => 1, 'userId' => 1, 'username' => 'kestrel', 'value' => 18342.12, 'return' => 0.312, 'excess' => 0.141, 'sharpe' => 1.42, 'beta' => 1.31, 'maxDrawdown' => 0.183],
                        ['rank' => 2, 'userId' => 2, 'username' => 'marlowe', 'value' => 14021.50, 'return' => 0.198, 'excess' => 0.027, 'sharpe' => 0.94, 'beta' => 0.88, 'maxDrawdown' => 0.091],
                        ['rank' => 3, 'userId' => 3, 'username' => 'ptarmigan', 'value' => 12109.90, 'return' => -0.041, 'excess' => -0.212, 'sharpe' => -0.18, 'beta' => 2.05, 'maxDrawdown' => 0.402],
                    ],
                    'rankedCount' => 3,
                    'unranked' => [['rank' => null, 'userId' => 4, 'username' => 'wren', 'value' => 23011.0, 'return' => 0.052, 'excess' => 0.011, 'sharpe' => null, 'beta' => null, 'maxDrawdown' => 0.0]],
                    'mine' => null, 'mineShown' => true,
                ],
            ]],
            'guide' => ['/guide', 'guide/index.html.twig', [
                'startingCapital' => 23456.0, 'stampDutyRate' => 0.001, 'cashSpread' => 0.0025, 'marginSpread' => 0.03,
                'initialMargin' => 0.5, 'maintenanceLong' => 0.25, 'maintenanceShort' => 0.30, 'contractSize' => 100, 'bondFace' => 1000.0,
                'seasonYears' => 4.0, 'qualifyingWeeks' => 13, 'maxAlerts' => 25, 'maxWatchlist' => 40,
            ]],
            'regulator' => ['/regulator', 'regulator/index.html.twig', [
                'regulator' => [
                    'head' => ['name' => 'Leontine Ashby', 'age' => 54, 'sinceLabel' => 'Year 12 Q3', 'termEndsLabel' => 'Year 17 Q3', 'stance' => 'middle', 'requirement' => 0.1046, 'ltvCap' => 0.85,
                        'passedOver' => [['name' => 'Osric Vane', 'age' => 49, 'requirement' => 0.0843, 'ltvCap' => 1.0], ['name' => 'Maren Holt', 'age' => 58, 'requirement' => 0.1255, 'ltvCap' => null]]],
                    'inForce' => 0.1012, 'buffer' => 0.005, 'phasing' => ['target' => 0.1046, 'completeLabel' => 'Year 13 Q3'],
                    'rules' => ['termYears' => 5.0, 'shortlist' => 3, 'lightest' => 0.0843, 'strictest' => 0.1315, 'phaseInMonths' => 12],
                ],
                'required' => 0.1062,
                'banks' => [
                    ['ticker' => 'PLVR', 'name' => 'Plover Savings', 'cet1' => 0.1011, 'headroom' => -0.0051, 'status' => 'below'],
                    ['ticker' => 'LAKE', 'name' => 'Lakebird Bank', 'cet1' => 0.1128, 'headroom' => 0.0066, 'status' => 'near'],
                    ['ticker' => 'POOL', 'name' => 'Pool Street Trust', 'cet1' => 0.1342, 'headroom' => 0.0280, 'status' => 'clear'],
                ],
                'mortgage' => ['cap' => 0.85, 'heldBelow' => 0.031, 'debtToIncome' => 0.98, 'tightestCap' => 0.70, 'loosestCap' => 1.0, 'regimes' => 17, 'uncapped' => 7],
            ]],
            'notifications' => ['/notifications', 'notifications/index.html.twig', [
                'page' => 1, 'pages' => 1, 'total' => 3,
                'notifications' => [
                    ['read' => false, 'tone' => 'badge-accent', 'label' => 'Order filled', 'ticker' => 'LAKE', 'dateline' => '3 Mar, Year 14', 'link' => '/stock/LAKE', 'title' => 'Bought 100 LAKE at $81.95', 'body' => 'Your limit order filled.'],
                    ['read' => false, 'tone' => 'badge-warn', 'label' => 'Margin call', 'ticker' => null, 'dateline' => '28 Feb, Year 14', 'link' => '/dashboard', 'title' => 'Margin call: your broker closed positions to meet the maintenance requirement', 'body' => 'Sold 120 GULL; bought back 2 GULL-C25 contracts.'],
                    ['read' => true, 'tone' => 'badge-neutral', 'label' => 'Watchlist', 'ticker' => 'HUMM', 'dateline' => '12 Feb, Year 14', 'link' => '/stock/HUMM', 'title' => 'HUMM: Earnings beat expectations by $0.11', 'body' => null],
                ],
            ]],
            'news' => ['/news', 'news/index.html.twig', self::newswire()],
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
                'tradeHistory' => [], 'dividendPayments' => [], 'allocation' => [
                    ['label' => 'Stocks', 'value' => 900000, 'share' => 0.729, 'colour' => 'bg-series-blue'],
                    ['label' => 'Index funds', 'value' => 134567, 'share' => 0.109, 'colour' => 'bg-series-orange'],
                    ['label' => 'Cash', 'value' => 200000, 'share' => 0.162, 'colour' => 'bg-on-surface-faint'],
                ],
                'cashShare' => 0.162, 'escrowedShareValue' => 0.0, 'optionMultiplier' => 100,
                'totalRealised' => 3150.25, 'cashRate' => 0.0425, 'marginRate' => 0.075,
                'sectorBreakdown' => [['name' => 'Financials', 'value' => 500000, 'percent' => 48.3], ['name' => 'Industrials', 'value' => 300000, 'percent' => 29.0]],
                'margin' => ['buyingPower' => 200000, 'shortMarketValue' => 0, 'optionShortValue' => 0, 'equity' => 1234567, 'maintenanceRequirement' => 0, 'isCalled' => false, 'equityRatio' => 1.0, 'callAmount' => 0], 'marginDebit' => 0, 'livePrices' => [], 'totalInvestedCost' => 1000000,
                'couponPayments' => [], 'tradePage' => 1, 'tradePages' => 1,
                'checklist' => ['complete' => false, 'items' => [
                    ['label' => 'Read the investor guide', 'done' => false, 'href' => '/guide'],
                    ['label' => 'Buy your first shares', 'done' => true, 'href' => '/'],
                    ['label' => 'Place a limit or stop order', 'done' => true, 'href' => '/guide#orders'],
                    ['label' => 'Watch a company', 'done' => false, 'href' => '/screener'],
                    ['label' => 'Set a price alert', 'done' => false, 'href' => '/guide#alerts'],
                ]],
                'season' => [
                    'number' => 4, 'endsOn' => 'Year 17 Q1', 'realSecondsLeft' => 9.5 * 86400, 'progress' => 0.66, 'ranked' => 31, 'entrants' => 38, 'qualifyingWeeks' => 13,
                    'row' => ['rank' => 7, 'forfeited' => false, 'weeks' => 137, 'return' => 0.214, 'benchmarkReturn' => 0.171, 'excess' => 0.043, 'sharpe' => 0.81, 'beta' => 1.12, 'alpha' => 0.018, 'maxDrawdown' => 0.137],
                ],
                'watchlist' => [['ticker' => 'HUMM', 'name' => 'Hummock Foods', 'assetType' => 'STOCK', 'price' => 41.07, 'change' => -0.0121]],
                'priceAlerts' => [['ticker' => 'HUMM', 'direction' => 'BELOW', 'targetPrice' => 38.0]],
                'freshStartBlocker' => null, 'freshStartCapital' => 23456.0,
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

    /** A newswire page from the presenter, so each card is the one the page renders live. */
    private static function newswire(): array
    {
        $presenter = new \App\Service\Event\EventPresenter();
        $stories = [
            ['GOVERNMENT', 'budget_enacted', null, null, 'The Diet passed the Civic-Vanguard budget: corporate tax at 21.0%, tariffs at 2.5%.', -0.8, true],
            ['EARNINGS', null, 'HUMM', 'Hummock Foods', 'Q-Earnings: $1.42 (Beat expectations by $0.11 | +$1.20B EVA).', 6.4, true],
            ['SHOCK', null, 'ORRA', 'Orra Biosciences', 'Orra Biosciences shares drop 13.2% in a sudden move.', -13.2, true],
            ['INDEX', null, 'LBI', 'Skein Lakebird 30', 'Admitted: HUMM. Dropped: KITE.', 0.0, false],
            ['CREDIT_DOWNGRADE', null, 'KITE', 'Kite Freight', '[CREDIT DOWNGRADE] KITE: Credit rating downgraded from BBB to BB due to deteriorating credit profile.', -3.0, true],
            ['ECONOMY', 'banking_crisis', null, null, 'Interbank lending has frozen: the overnight spread stands at 310 basis points.', -7.1, true],
            ['MANAGEMENT CHANGE', null, 'LAKE', 'Lakebird Bank', 'Lakebird Bank named a new chief executive after 6.1 years.', 0.0, false],
        ];

        $items = [];
        foreach ($stories as $i => [$type, $topic, $ticker, $name, $description, $change, $headline]) {
            $card = $presenter->present(['type' => $type, 'topic' => $topic, 'description' => $description, 'change_percent' => $change, 'recorded_at' => sprintf('2026-10-06 14:%02d', 50 - 7 * $i), 'sim_time' => 13.71 - 0.013 * $i]);
            $items[] = ['card' => $card, 'ticker' => $ticker, 'name' => $name, 'headline' => $headline];
        }

        return ['section' => 'all', 'sections' => \App\Service\Event\NewsDesk::SECTIONS, 'items' => $items];
    }
}
