<?php

declare(strict_types=1);

namespace App\Tests\Render;

use App\Data\Company\InitialMarket;
use App\Data\Company\StockInfo;
use App\Entity\StockEvent;
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
                'creditRating' => 'BBB', 'creditRatingRank' => 4,
            ];
        }
        $this->assertArrayHasKey(3, $stocks);
        $stocks[3]['changePercent'] = 0.0;
        usort($stocks, static fn($a, $b) => $b['marketCap'] <=> $a['marketCap']);
        $macro = ['output_gap' => 0.0132, 'inflation' => 0.0241, 'inflation_ema' => 0.0238, 'policy_rate' => 0.0425, 'yield_10y' => 0.0461];

        $almanac = new \App\Service\Lore\Almanac(dirname(__DIR__, 2));
        $affair = $almanac->get('drainrock-affair');
        $mandate = static fn(string $department): string => array_column(\App\Data\Politics\AerieCouncil::DEPARTMENTS, 'mandate', 'name')[$department];

        $pages = [
            'almanac' => ['/almanac', 'almanac/index.html.twig', ['sections' => ['Institution' => [$almanac->get('security-bureau')], 'Incident' => [$affair]]]],
            'almanac_entry' => ['/almanac/drainrock-affair', 'almanac/entry.html.twig', [
                'entry' => $affair, 'linkedFrom' => [$almanac->get('security-bureau')], 'pages' => [['name' => 'Security Bureau', 'href' => '/security-bureau']],
            ]],
            'security_bureau' => ['/security-bureau', 'security_bureau/index.html.twig', [
                'bureau' => [
                    'mandate' => \App\Data\Politics\AerieCouncil::DEPARTMENTS[array_search(\App\Data\District\Institutions::SECURITY_BUREAU, array_column(\App\Data\Politics\AerieCouncil::DEPARTMENTS, 'name'), true)]['mandate'],
                    'director' => ['name' => 'Augustin Pell', 'age' => 58, 'sinceLabel' => 'Year 7 Q1', 'termEndsLabel' => 'Year 17 Q1', 'opening' => false,
                        'passedOver' => [['name' => 'Nell Rourke', 'age' => 51], ['name' => 'Wendell Price', 'age' => 55]]],
                    'rules' => ['termYears' => 10.0, 'shortlist' => 3],
                ],
                'entries' => $almanac->about('security-bureau'),
            ]],
            'coast_guard' => ['/coast-guard', 'guard/index.html.twig', [
                'force' => [
                    'name' => \App\Data\District\Institutions::COAST_GUARD,
                    'mandate' => $mandate(\App\Data\District\Institutions::COAST_GUARD),
                    'headTitle' => 'commandant',
                    'head' => ['name' => 'Imogen Hale', 'age' => 51, 'sinceLabel' => 'Year 3 Q3', 'termEndsLabel' => 'Year 8 Q3', 'opening' => false,
                        'passedOver' => [['name' => 'Dorian Shaw', 'age' => 48], ['name' => 'Petra Lowell', 'age' => 54]]],
                    'rules' => ['termYears' => 5.0, 'shortlist' => 3],
                ],
                'entries' => $almanac->about('coast-guard'),
            ]],
            'state_guard' => ['/state-guard', 'guard/index.html.twig', [
                'force' => [
                    'name' => \App\Data\District\Institutions::STATE_GUARD,
                    'mandate' => $mandate(\App\Data\District\Institutions::STATE_GUARD),
                    'headTitle' => 'commander',
                    'head' => ['name' => 'Marcus Holloway', 'age' => 54, 'sinceLabel' => 'Before Year 1', 'termEndsLabel' => 'Year 4 Q3', 'opening' => true, 'passedOver' => []],
                    'rules' => ['termYears' => 5.0, 'shortlist' => 3],
                ],
                'entries' => $almanac->about('state-guard'),
            ]],
            'landing' => ['/', 'home/landing.html.twig', [
                'etf' => ['name' => 'Skein Lakebird 30', 'ticker' => 'LBI', 'price' => 412.37],
                'breadth' => ['advancing' => 31, 'declining' => 12, 'unchanged' => 2, 'topMover' => ['ticker' => 'HUMM', 'changePercent' => 0.1342]],
                'macro' => $macro,
                'top_stocks' => array_slice($stocks, 0, 6),
                'cycleLabel' => 'Boom',
            ]],
            'home' => ['/', 'home/index.html.twig', [
                'macro' => $macro,
                'indices' => [
                    ['ticker' => 'LBI', 'name' => 'Skein Lakebird 30', 'label' => 'Headline index', 'isBenchmark' => true, 'price' => 412.37],
                    ['ticker' => 'LBC', 'name' => 'Skein Total Market', 'label' => 'Whole board', 'isBenchmark' => false, 'price' => 188.02],
                ],
                'stocks' => $stocks,
                'cycleLabel' => 'Boom',
                'inflationTarget' => \App\Service\Math\MacroConstants::TARGET_INFLATION,
            ]],
            'screener' => ['/screener', 'screener/index.html.twig', [
                'stocks' => array_map(static fn(array $s): array => $s + \App\Controller\ScreenerController::cautions($s['netDebtToEbitda'], $s['fcfConversion']), $stocks),
            ]],
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
                    'head' => ['name' => 'Leontine Ashby', 'age' => 54, 'sinceLabel' => 'Year 12 Q3', 'termEndsLabel' => 'Year 17 Q3', 'stance' => 'middle', 'stanceText' => 'A moderate regulator', 'requirement' => 0.1046, 'ltvCap' => 0.85, 'neutralBuffer' => 0.02,
                        'passedOver' => [['name' => 'Osric Vane', 'age' => 49, 'requirement' => 0.08, 'ltvCap' => null], ['name' => 'Maren Holt', 'age' => 58, 'requirement' => 0.1255, 'ltvCap' => null]]],
                    'inForce' => 0.1012, 'buffer' => 0.005, 'phasing' => ['target' => 0.1046, 'completeLabel' => 'Year 13 Q3'],
                    'rules' => ['termYears' => 5.0, 'shortlist' => 3, 'lightest' => 0.08, 'strictest' => 0.13, 'phaseInMonths' => 12],
                ],
                'required' => 0.1062,
                'banks' => [
                    ['ticker' => 'PLVR', 'name' => 'Plover Savings', 'cet1' => 0.1011, 'headroom' => -0.0051, 'status' => 'below'],
                    ['ticker' => 'LAKE', 'name' => 'Lakebird Bank', 'cet1' => 0.1128, 'headroom' => 0.0066, 'status' => 'near'],
                    ['ticker' => 'POOL', 'name' => 'Pool Street Trust', 'cet1' => 0.1342, 'headroom' => 0.0280, 'status' => 'clear'],
                ],
                'mortgage' => ['cap' => 0.85, 'heldBelow' => 0.031, 'debtToIncome' => 0.98, 'tightestCap' => 0.80, 'strictestBuffer' => 0.02],
            ]],
            'exchequer' => ['/exchequer', 'exchequer/index.html.twig', [
                'exchequer' => [
                    'head' => ['name' => 'Rosalind Tate', 'age' => 51, 'sinceLabel' => 'Year 13 Q1', 'termEndsLabel' => 'Year 18 Q1', 'opening' => false, 'stance' => 'short', 'stanceText' => 'Favours borrowing short', 'maturity' => 4.3,
                        'passedOver' => [['name' => 'Hal Brennan', 'age' => 47, 'maturity' => 5.7], ['name' => 'Ines Okafor', 'age' => 56, 'maturity' => 4.9]]],
                    'debt' => 0.912, 'netDebt' => 0.764, 'netLiabilities' => 0.342, 'remittanceToGdp' => 0.0034, 'authorityLossesToGdp' => 0.0, 'maturity' => 4.7, 'movingTo' => 4.3, 'repricingShare' => 0.295, 'rate' => 0.0341,
                    'interestToGdp' => 0.0311, 'tenYear' => 0.0438, 'maturityBp' => -6, 'riskSpreadBp' => 14, 'councilMaturity' => 4.6,
                    'rules' => ['termYears' => 5.0, 'shortlist' => 3, 'shortest' => 4.0, 'longest' => 5.92, 'founding' => 4.96],
                ],
            ]],
            'fair_trading' => ['/fair-trading', 'fair_trading/index.html.twig', [
                'board' => [
                    'head' => ['name' => 'Odile Farrant', 'age' => 55, 'sinceLabel' => 'Year 6 Q1', 'termEndsLabel' => 'Year 11 Q1', 'opening' => false, 'stance' => 'strict', 'stanceText' => 'A strict enforcer against concentration',
                        'passedOver' => [['name' => 'Hal Brennan', 'age' => 47, 'line' => 0.235], ['name' => 'Ines Okafor', 'age' => 56, 'line' => 0.205]]],
                    'screens' => ['delta' => 0.0115, 'concentrated' => 0.1905, 'shareCeiling' => 0.405],
                    'councilLine' => 0.2105,
                    'rules' => ['termYears' => 5.0, 'shortlist' => 3,
                        'lightest' => ['delta' => 0.02, 'concentrated' => 0.25, 'shareCeiling' => 1.0], 'strictest' => ['delta' => 0.01, 'concentrated' => 0.18, 'shareCeiling' => 0.30]],
                ],
            ]],
            'securities' => ['/securities', 'securities/index.html.twig', [
                'commission' => [
                    'head' => ['name' => 'Thea Lindqvist', 'age' => 54, 'sinceLabel' => 'Year 10 Q3', 'termEndsLabel' => 'Year 15 Q3', 'opening' => false, 'stance' => 'strict', 'stanceText' => 'A strict overseer of the markets',
                        'passedOver' => [['name' => 'Arlo Mercer', 'age' => 49, 'margin' => 0.45], ['name' => 'Ines Okafor', 'age' => 56, 'margin' => 0.6]]],
                    'inForce' => ['initial' => 0.5, 'long' => 0.25, 'short' => 0.30, 'enforcement' => 1.0],
                    'coming' => ['fromLabel' => 'Year 10 Q4', 'initial' => 0.68, 'enforcement' => 0.7],
                    'councilMargin' => 0.55,
                    'rules' => ['termYears' => 5.0, 'shortlist' => 3, 'noticeMonths' => 3, 'lightestMargin' => 0.40, 'strictestMargin' => 0.80, 'lightestEnforcement' => 1.5, 'strictestEnforcement' => 0.5],
                ],
            ]],
            'trade_office' => ['/trade-office', 'trade_office/index.html.twig', [
                'office' => [
                    'tariff' => ['inForce' => 0.04, 'next' => 0.06, 'expected' => 0.03, 'retaliation' => 0.025, 'revenueToGdp' => 0.0072, 'outputCost' => 0.0044, 'goodsImportsToGdp' => 0.19],
                    'migration' => ['inForce' => 0.0116, 'next' => 0.0116, 'expected' => 0.0102, 'structural' => 0.0104, 'natural' => 0.0054, 'cyclical' => 0.0009, 'jobsResponse' => 0.00155, 'structuralMigration' => 0.005, 'closed' => 0.0011, 'open' => 0.0079, 'populationShift' => 0.012],
                    'foreignBuyers' => ['inForce' => 0.15, 'next' => 0.15, 'expected' => null, 'priceEffect' => 0.06, 'revenueToGdp' => 0.00017, 'strictest' => 0.60],
                    'expectedFrom' => 'Year 17 Q2',
                ],
            ]],
            'reserve' => ['/reserve', 'reserve/index.html.twig', $this->reservePage()],
            'notifications' => ['/notifications', 'notifications/index.html.twig', [
                'page' => 1, 'pages' => 1,
                'notifications' => [
                    ['read' => false, 'tone' => 'badge-accent', 'label' => 'Order filled', 'ticker' => 'LAKE', 'dateline' => '3 Mar, Year 14', 'link' => '/stock/LAKE', 'title' => 'Bought 100 LAKE at $81.95', 'body' => 'Your limit order filled.'],
                    ['read' => false, 'tone' => 'badge-warn', 'label' => 'Margin call', 'ticker' => null, 'dateline' => '28 Feb, Year 14', 'link' => '/dashboard', 'title' => 'Margin call: your broker closed positions to meet the maintenance requirement', 'body' => 'Sold 120 GULL; bought back 2 GULL-C25 contracts.'],
                    ['read' => true, 'tone' => 'badge-neutral', 'label' => 'Watchlist', 'ticker' => 'HUMM', 'dateline' => '12 Feb, Year 14', 'link' => '/stock/HUMM', 'title' => 'HUMM: Earnings beat expectations by $0.11', 'body' => null],
                ],
            ]],
            'news' => ['/news', 'news/index.html.twig', self::newswire()],
            'login' => ['/login', 'security/login.html.twig', ['error' => null, 'last_username' => '']],
            'economy' => ['/economy', 'economy/index.html.twig', ['economic_cycle' => 'Boom', 'chartConstants' => \App\Controller\EconomyController::chartConstants(), 'macro' => ['inflation' => 0.0241, 'inflationEma' => 0.0238, 'outputGap' => 0.0132, 'policyRate' => 0.0425, 'yield10y' => 0.0461, 'qeActive' => false, 'balanceSheetIntensity' => 0.0], 'exposures' => [['key' => 'oil', 'title' => 'Oil and fuel', 'intro' => 'Producers sell crude at the world price; transport and heavy industry burn its products.', 'lists' => [['label' => 'Sell crude and liquids', 'unit' => 'of sales', 'format' => \App\Service\View\FigureFormat::Percent, 'decimals' => 0, 'detail_unit' => null, 'rows' => [['ticker' => 'SINK', 'name' => 'Sinkhole Petroleum', 'value' => 0.7, 'detail' => null], ['ticker' => 'DRLL', 'name' => 'Drillwell Energy', 'value' => 0.55, 'detail' => null]]], ['label' => 'Buy fuel', 'unit' => 'of costs', 'format' => \App\Service\View\FigureFormat::Percent, 'decimals' => 0, 'detail_unit' => null, 'rows' => [['ticker' => 'KEEL', 'name' => 'Keel Line Shipping', 'value' => 0.102, 'detail' => null], ['ticker' => 'RAIL', 'name' => 'Pamlico Rail', 'value' => 0.088, 'detail' => null], ['ticker' => 'HAUL', 'name' => 'Haulwright Logistics', 'value' => 0.069, 'detail' => null]]]]], ['key' => 'rates', 'title' => 'Interest rates', 'intro' => 'Floating-rate debt reprices with the policy rate.', 'lists' => [['label' => 'Most floating-rate debt', 'unit' => 'of market value', 'format' => \App\Service\View\FigureFormat::Percent, 'decimals' => 0, 'detail_unit' => 'of its debt floats', 'rows' => [['ticker' => 'GULL', 'name' => 'Gull Resorts', 'value' => 0.42, 'detail' => 0.6], ['ticker' => 'IBHI', 'name' => 'Ibis Heavy Industries', 'value' => 0.18, 'detail' => 0.35]]]]], ['key' => 'property', 'title' => 'Property prices', 'intro' => 'Landlords reset rents toward market as leases roll; lenders lose more on defaults when the collateral is worth less.', 'lists' => [['label' => 'Largest landlords', 'unit' => 'total assets', 'format' => \App\Service\View\FigureFormat::LargeMoney, 'decimals' => null, 'detail_unit' => null, 'rows' => [['ticker' => 'TOWR', 'name' => 'Tower Row Properties', 'value' => 18400000000, 'detail' => null]]], ['label' => 'Lenders with most property loans', 'unit' => 'of loans', 'format' => \App\Service\View\FigureFormat::Percent, 'decimals' => 0, 'detail_unit' => null, 'rows' => [['ticker' => 'PLVR', 'name' => 'Plover Bank', 'value' => 0.61, 'detail' => null], ['ticker' => 'LAKE', 'name' => 'Lakeside Bancorp', 'value' => 0.48, 'detail' => null]]]]], ['key' => 'cycle', 'title' => 'The business cycle', 'intro' => 'How far sales swing with the economy, where 1.0x is a typical company.', 'lists' => [['label' => 'Most cyclical sales', 'unit' => 'typical swing', 'format' => \App\Service\View\FigureFormat::Multiple, 'decimals' => 1, 'detail_unit' => null, 'rows' => [['ticker' => 'STRK', 'name' => 'Stark Motors', 'value' => 1.5, 'detail' => null], ['ticker' => 'GULL', 'name' => 'Gull Resorts', 'value' => 1.5, 'detail' => null]]]]]]]],
            'stock' => ['/stock/LAKE', 'stock/index.html.twig', [
                'asset' => ['ticker' => 'LAKE', 'name' => 'Lakebird Bank', 'sector' => 'Financials', 'industry' => 'Commercial banking', 'price' => 84.21, 'isBankrupt' => false, 'bankrupt' => false, 'sharesOutstanding' => 2400000000, 'earningsPerShare' => 6.12, 'currentRoe' => 0.124, 'baselineRoe' => 0.12, 'currentRoic' => 0.09, 'baselineRoic' => 0.09, 'publicFloatPercentage' => 0.8, 'volatility' => 0.22, 'totalEquity' => 1.6e11, 'debtToEquity' => 1.4, 'creditRating' => 'A', 'creditSpread' => 0.011, 'dynamicCreditSpread' => 0.0134, 'systemicImportance' => 'Systemic'],
                'isEtf' => false, 'isFinancial' => true, 'changePercent' => 0.0342, 'businessModel' => 'commercial_bank', 'generalInfo' => StockInfo::DESCRIPTIONS['LAKE'], 'hasProfile' => true,
                'events' => [
                    (new StockEvent())->setEventType('MANAGEMENT CHANGE')->setDescription('Lakebird Bank chief executive was removed by the board after 5.2 years.')->setFacts(['forced' => true])->setChangePercent('0.00')->setRecordedAt(new \DateTime('2026-09-30 12:00')),
                    (new StockEvent())->setEventType('CREDIT DOWNGRADE')->setDescription('Shrike Standard Ratings cut Lakebird Bank to A-.')->setChangePercent('-1.40')->setRecordedAt(new \DateTime('2026-09-12 12:00')),
                ],
                'userQuantity' => 0, 'userDividendIncome' => 0, 'marketCap' => 2.02e11, 'peRatio' => 13.76, 'dividendYield' => 0.031, 'strategicStake' => 0, 'macro' => ['sovereignFundOwnershipShare' => 0.23, 'stampDutyRate' => 0.001],
                'analystTargets' => ['rating' => 'Outperform', 'tone' => 'up', 'upside' => 0.084, 'consensus' => 91.3, 'growth_analyst' => 95.1, 'value_analyst' => 88.2, 'income_analyst' => 90.4],
                'isInsurer' => false, 'pieLabels' => [], 'pieData' => [], 'displayReturn' => 0.124, 'fundStake' => 0.184, 'industryCharts' => ['netInterestEngineChart', 'regulatoryRatiosChart'], 'industryDescription' => \App\Data\Company\Sectors::BUSINESS_MODEL_DESCRIPTIONS['commercial_bank'],
                'openOrders' => [['id' => 52, 'ticker' => 'LAKE', 'assetType' => 'STOCK', 'action' => 'SELL', 'orderType' => 'STOP', 'stop' => true, 'quantity' => 50, 'limitPrice' => null, 'stopPrice' => 79.5, 'createdAt' => new \DateTimeImmutable('2026-10-02 09:30')]], 'userTrades' => [['ticker' => 'LAKE', 'assetType' => 'STOCK', 'action' => 'BUY', 'orderType' => 'MARKET', 'origin' => null, 'quantity' => 100, 'filledQuantity' => 100, 'executionPrice' => 83.9, 'limitPrice' => null, 'status' => 'FILLED', 'spreadCost' => 3.4, 'impactCost' => 0.0, 'stampDuty' => 8.39, 'createdAt' => new \DateTimeImmutable('2026-09-20 10:00'), 'filledAt' => new \DateTimeImmutable('2026-09-20 10:00')]], 'tradeHistory' => [], 'optionsListed' => false, 'optionExpiries' => [], 'lifecycleStage' => \App\Data\Company\LifecycleStage::Mature, 'lifecycleStages' => \App\Data\Company\LifecycleStage::cases(), 'financialSummary' => [], 'peers' => [], 'industry' => null, 'management' => null, 'anchorPortfolio' => null, 'netAssetValue' => null, 'capital' => null, 'credit' => null, 'ticksPerYear' => 14400, 'halfSpread' => 0.0004, 'advShares' => 3400000, 'borrowFee' => 0.01, 'availableToBorrow' => 1000000, 'shortUtilization' => 0.2,
                'borrowWarnings' => ['expensiveFee' => 0.05, 'scarceUtilization' => 0.90, 'recallUtilization' => 0.97],
                'shortInterest' => ['shares' => 21600000, 'floatShare' => 0.01125, 'daysToCover' => 6.35],
                'nextReport' => ['dateline' => '14 Feb, Year 15', 'quarter' => 'Year 14 Q4'],
                'kpiSeries' => [],
            ]],
            'profile' => ['/stock/LAKE/profile', 'stock/profile.html.twig', [
                'asset' => ['ticker' => 'LAKE', 'name' => 'Lakebird Bank', 'sector' => 'Financials', 'industry' => 'Banks - Diversified', 'price' => 84.21, 'isBankrupt' => false],
                'article' => \App\Data\Company\CompanyResearch::ARTICLES['LAKE'], 'publisher' => 'Tickbird Research',
                'changePercent' => 0.0342, 'marketCap' => 2.02e11, 'freeFloat' => 0.5,
                'management' => ['label' => 'Fortress', 'mandate' => 'expected to rebuild the balance sheet and hold cash against the cycle', 'tenureYears' => 6.4],
                'holders' => [], 'holdings' => [],
                'related' => [
                    ['ticker' => 'SWAN', 'name' => 'Black Swan Capital', 'hasProfile' => true, 'isDelisted' => false],
                    ['ticker' => 'IBHI', 'name' => 'Iron Beak Heavy Ind', 'hasProfile' => false, 'isDelisted' => false],
                    ['ticker' => 'PLVR', 'name' => 'Plover Savings Bank', 'hasProfile' => false, 'isDelisted' => true],
                ],
                'peers' => [
                    ['ticker' => 'PLVR', 'name' => 'Plover Savings Bank', 'marketCap' => 0.0, 'isBankrupt' => true],
                    ['ticker' => 'RIVR', 'name' => 'Riverstone Financial', 'marketCap' => 1.84e10, 'isBankrupt' => false],
                    ['ticker' => 'KING', 'name' => 'Kingfisher Capital', 'marketCap' => 4.1e10, 'isBankrupt' => false],
                ],
                'headlines' => [
                    (new StockEvent())->setEventType('MANAGEMENT CHANGE')->setDescription('Lakebird Bank chief executive was removed by the board after 5.2 years.')->setFacts(['forced' => true])->setChangePercent('0.00')->setRecordedAt(new \DateTime('2026-09-30 12:00')),
                    (new StockEvent())->setEventType('CREDIT DOWNGRADE')->setDescription('Shrike Standard Ratings cut Lakebird Bank to A-.')->setChangePercent('-1.40')->setRecordedAt(new \DateTime('2026-09-12 12:00')),
                ],
            ]],
            'dashboard' => ['/dashboard', 'dashboard/index.html.twig', [
                'portfolioValue' => 1234567.89, 'totalUnrealizedPnL' => -23456.7, 'totalUnrealizedReturn' => -0.019, 'cashBalance' => 200000.0, 'totalInvested' => 1034567.89, 'escrowedCash' => 1200.0, 'totalDividendIncome' => 4321.0,
                'holdings' => [
                    ['ticker' => 'LAKE', 'name' => 'Lakebird Bank', 'type' => 'STOCK', 'sector' => 'Financials', 'quantity' => 1200, 'avgCost' => 70.1, 'price' => 84.21, 'marketValue' => 101052.0, 'unrealizedPnL' => 16932.0, 'unrealizedReturn' => 0.201, 'weight' => 0.082, 'dividendsReceived' => 120.0, 'isBankrupt' => false],
                    ['ticker' => 'PEAC', 'name' => 'Peacock Heritage Group', 'type' => 'STOCK', 'sector' => 'Consumer Discretionary', 'quantity' => 300, 'avgCost' => 210.0, 'price' => 210.0, 'marketValue' => 63000.0, 'unrealizedPnL' => 0.0, 'unrealizedReturn' => 0.0, 'weight' => 0.051, 'dividendsReceived' => 0.0, 'isBankrupt' => false],
                ],
                'openOrders' => [['id' => 41, 'ticker' => 'LAKE', 'action' => 'BUY', 'orderType' => 'STOP_LIMIT', 'quantity' => 100, 'limitPrice' => 80.0, 'stopPrice' => 82.0, 'createdAt' => new \DateTimeImmutable('2026-10-01')]],
                'tradeHistory' => [
                    ['ticker' => 'LAKE', 'assetType' => 'STOCK', 'action' => 'BUY', 'orderType' => 'LIMIT', 'origin' => null, 'quantity' => 1200, 'filledQuantity' => 1200, 'executionPrice' => 70.1, 'limitPrice' => 70.5, 'status' => 'FILLED', 'spreadCost' => 33.6, 'impactCost' => 12.0, 'stampDuty' => 84.12, 'createdAt' => new \DateTimeImmutable('2026-08-03 10:00'), 'filledAt' => new \DateTimeImmutable('2026-08-03 10:05')],
                    ['ticker' => 'LAKE-C-90-Y15Q2', 'assetType' => 'OPTION', 'action' => 'WRITE', 'orderType' => 'MARKET', 'origin' => null, 'quantity' => 2, 'filledQuantity' => 2, 'executionPrice' => 1.85, 'limitPrice' => null, 'status' => 'FILLED', 'spreadCost' => 4.0, 'impactCost' => null, 'stampDuty' => null, 'createdAt' => new \DateTimeImmutable('2026-08-10 11:00'), 'filledAt' => new \DateTimeImmutable('2026-08-10 11:00')],
                    ['ticker' => 'PEAC', 'assetType' => 'STOCK', 'action' => 'SELL', 'orderType' => 'MARKET', 'origin' => 'MARGIN_CALL', 'quantity' => 40, 'filledQuantity' => 0, 'executionPrice' => null, 'limitPrice' => null, 'status' => 'CANCELLED', 'spreadCost' => null, 'impactCost' => null, 'stampDuty' => null, 'createdAt' => new \DateTimeImmutable('2026-09-01 12:00'), 'filledAt' => null],
                ], 'dividendPayments' => [], 'allocation' => [
                    ['label' => 'Stocks', 'value' => 900000, 'share' => 0.729, 'colour' => 'bg-series-blue'],
                    ['label' => 'Index funds', 'value' => 134567, 'share' => 0.109, 'colour' => 'bg-series-orange'],
                    ['label' => 'Cash', 'value' => 200000, 'share' => 0.162, 'colour' => 'bg-on-surface-faint'],
                ],
                'cashShare' => 0.162, 'escrowedShareValue' => 0.0, 'optionMultiplier' => 100,
                'totalRealised' => 3150.25, 'cashRate' => 0.0425, 'marginRate' => 0.075,
                'sectorBreakdown' => [['name' => 'Financials', 'value' => 500000, 'share' => 0.483], ['name' => 'Industrials', 'value' => 300000, 'share' => 0.29]],
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
                    'number' => 4, 'endsOn' => 'Year 17 Q1', 'realSecondsLeft' => 9.5 * 86400, 'progress' => 0.66, 'ranked' => 31, 'qualifyingWeeks' => 13,
                    'row' => ['rank' => 7, 'forfeited' => false, 'weeks' => 137, 'return' => 0.214, 'benchmarkReturn' => 0.171, 'excess' => 0.043, 'sharpe' => 0.81, 'beta' => 1.12, 'alpha' => 0.018, 'maxDrawdown' => 0.137],
                ],
                'watchlist' => [['ticker' => 'HUMM', 'name' => 'Hummock Foods', 'assetType' => 'STOCK', 'price' => 41.07, 'change' => -0.0121]],
                'alertsByTicker' => ['HUMM' => [['ticker' => 'HUMM', 'direction' => 'BELOW', 'targetPrice' => 38.0]]],
                'freshStartBlocker' => null, 'freshStartCapital' => 23456.0,
            ]],
        ];

        // The dashboard's figures the builder derives from its rows.
        $book = $pages['dashboard'][2]['holdings'];
        $pages['dashboard'][2] += ['holdingsFeed' => \App\Service\View\DashboardPageBuilder::holdingsFeed($book), 'largestWeight' => \App\Service\View\DashboardPageBuilder::largestWeight($book)];

        // The company page for a telecom (seven summary tiles, its operating figures charted) and an insurer, from the
        // bank's stub.
        $pages['stock-telecom'] = ['/stock/WIRE', 'stock/index.html.twig', array_replace($pages['stock'][2], [
            'isFinancial' => false, 'businessModel' => 'telecom', 'shortUtilization' => 0.93, 'borrowFee' => 0.081,
            'industryCharts' => ['kpiHistoryChart'], 'industryDescription' => \App\Data\Company\Sectors::BUSINESS_MODEL_DESCRIPTIONS['telecom'], 'displayReturn' => 0.09,
            'creditHealth' => [
                'inDefault' => false, 'quartersInDefault' => 0, 'cureDueNextReport' => false,
                'revolverCommitment' => 500000000.0, 'revolverDrawn' => 100000000.0, 'revolverUtilization' => 0.2,
                'netDebtToEbitda' => 1.8, 'covenantLimit' => 3.5, 'hasCovenantHeadroom' => true,
                'interestCoverage' => 6.2, 'distanceToDefault' => 3.1, 'investmentGradeDistance' => \App\Service\Market\Bond\CreditRatingAgency::THRESHOLD_BBB, 'coverageState' => 'adequate',
            ],
            'financialSummary' => [
                ['label' => 'Operating margin', 'value' => 0.22, 'format' => \App\Service\View\FigureFormat::Percent], ['label' => 'ROIC − WACC', 'value' => 0.011, 'format' => \App\Service\View\FigureFormat::SignedPercent],
                ['label' => 'FCF / net income', 'value' => 0.78, 'format' => \App\Service\View\FigureFormat::Percent], ['label' => 'Subscriber index', 'value' => 1.041, 'format' => \App\Service\View\FigureFormat::Index],
                ['label' => 'Quarterly churn', 'value' => 0.011, 'format' => \App\Service\View\FigureFormat::Percent], ['label' => 'Net adds', 'value' => 0.003, 'format' => \App\Service\View\FigureFormat::SignedIndex],
                ['label' => 'ARPU index', 'value' => 1.019, 'format' => \App\Service\View\FigureFormat::Index],
            ],
            'kpiSeries' => ['subscriber_index' => ['label' => 'Subscriber index', 'format' => \App\Service\View\FigureFormat::Index], 'quarterly_churn' => ['label' => 'Quarterly churn', 'format' => \App\Service\View\FigureFormat::Percent], 'net_adds' => ['label' => 'Net adds', 'format' => \App\Service\View\FigureFormat::SignedIndex], 'arpu_index' => ['label' => 'ARPU index', 'format' => \App\Service\View\FigureFormat::Index]],
        ])];
        $pages['stock-insurer'] = ['/stock/GULL', 'stock/index.html.twig', array_replace($pages['stock'][2], [
            'isInsurer' => true, 'businessModel' => 'insurance',
            'industryCharts' => ['kpiHistoryChart', 'regulatoryRatiosChart', 'insuranceDualEngineChart'], 'industryDescription' => \App\Data\Company\Sectors::BUSINESS_MODEL_DESCRIPTIONS['insurance'],
            'financialSummary' => [['label' => 'Capital ratio', 'value' => 0.25, 'format' => \App\Service\View\FigureFormat::Percent], ['label' => 'ROE', 'value' => 0.09, 'format' => \App\Service\View\FigureFormat::Percent], ['label' => 'Combined ratio', 'value' => 0.94, 'format' => \App\Service\View\FigureFormat::Percent], ['label' => 'Loss ratio', 'value' => 0.63, 'format' => \App\Service\View\FigureFormat::Percent], ['label' => 'Expense ratio', 'value' => 0.31, 'format' => \App\Service\View\FigureFormat::Percent]],
            'kpiSeries' => ['loss_ratio' => ['label' => 'Loss ratio', 'format' => \App\Service\View\FigureFormat::Percent], 'expense_ratio' => ['label' => 'Expense ratio', 'format' => \App\Service\View\FigureFormat::Percent]],
        ])];

        // A drug company: the pipeline card on the company tab, its ladder grouped by the builder itself.
        $pages['stock-biotech'] = ['/stock/IBIS', 'stock/index.html.twig', array_replace($pages['stock'][2], [
            'asset' => array_replace($pages['stock'][2]['asset'], ['ticker' => 'IBIS', 'name' => 'Ibis Pharmaceuticals', 'sector' => 'Health Care', 'industry' => 'Drug Manufacturers - General']),
            'isFinancial' => false, 'businessModel' => 'biotech', 'generalInfo' => StockInfo::DESCRIPTIONS['IBIS'] ?? '',
            'industryCharts' => ['reinvestmentIntensityChart'], 'industryDescription' => \App\Data\Company\Sectors::BUSINESS_MODEL_DESCRIPTIONS['biotech'], 'displayReturn' => 0.09,
            'pipeline' => [
                'lateStageAssets' => 3.4, 'readoutsPerYear' => 1.34, 'protectedShare' => 0.71, 'franchiseChange' => 0.083, 'rndReplacement' => 0.92, 'ramping' => 2,
                'ladder' => \App\Service\View\BiotechPipelineBuilder::ladder([
                    ['share' => 0.27, 'adoption' => 1.0, 'exclusivityQuarters' => 2.0], ['share' => 0.18, 'adoption' => 1.0, 'exclusivityQuarters' => 10.0],
                    ['share' => 0.13, 'adoption' => 0.95, 'exclusivityQuarters' => 30.0], ['share' => 0.0, 'adoption' => 0.0, 'exclusivityQuarters' => 50.0],
                    ['share' => 0.03, 'adoption' => 0.4, 'exclusivityQuarters' => 46.0], ['share' => 0.10, 'adoption' => 1.0, 'exclusivityQuarters' => -3.0],
                ], 0.29),
                'runway' => ['years' => 6.8, 'quarterlyBurn' => 4.1e8],
            ],
        ])];

        // An index fund: the index and holdings tabs in place of the company's. Rates and weights are fractions.
        $members = [
            ['rank' => 1, 'ticker' => 'LAKE', 'name' => 'Lakebird Bank', 'sector' => 'Financials', 'price' => 84.21, 'marketCap' => 2.02e11, 'floatCap' => 1.01e11, 'adjustedCap' => 1.01e11, 'volatility' => 0.21, 'weight' => 0.4125, 'targetWeight' => 0.40, 'changePercent' => 0.0342, 'isNew' => false],
            ['rank' => 2, 'ticker' => 'GULL', 'name' => 'Gull Resorts', 'sector' => 'Consumer Cyclical', 'price' => 41.07, 'marketCap' => 1.1e11, 'floatCap' => 8.3e10, 'adjustedCap' => 8.3e10, 'volatility' => 0.32, 'weight' => 0.3375, 'targetWeight' => 0.3376, 'changePercent' => -0.012, 'isNew' => false],
            ['rank' => 3, 'ticker' => 'WIRE', 'name' => 'Wirefield Telecom', 'sector' => 'Communication Services', 'price' => 22.9, 'marketCap' => 7.4e10, 'floatCap' => 6.1e10, 'adjustedCap' => 6.1e10, 'volatility' => 0.18, 'weight' => 0.25, 'targetWeight' => null, 'changePercent' => null, 'isNew' => true],
        ];
        $pages['stock-etf'] = ['/stock/LBI', 'stock/index.html.twig', array_replace($pages['stock'][2], [
            'isEtf' => true, 'isFinancial' => false, 'businessModel' => 'none',
            'industryCharts' => [], 'industryDescription' => 'The thirty largest companies on the board by free float.', 'displayReturn' => null, 'fundStake' => 0.0,
            'asset' => array_replace($pages['stock'][2]['asset'], ['ticker' => 'LBI', 'name' => 'Skein Lakebird 30 ETF', 'price' => 412.37]),
            'components' => $members, 'pieLabels' => array_column($members, 'ticker'), 'pieData' => array_column($members, 'adjustedCap'),
            'sharesMap' => ['LAKE' => 1.2e9, 'GULL' => 2.02e9, 'WIRE' => 2.66e9],
            'indexFacts' => [
                'ticker' => 'LBI', 'label' => 'Headline index', 'indexName' => 'Skein Lakebird 30', 'indexProvider' => 'Skein Indices', 'indexProviderTicker' => 'SKN',
                'providerIsConstituent' => false, 'mandate' => 'The thirty largest companies on the board by free float.', 'isSelective' => true,
                'carriesPassiveBook' => true, 'passiveShare' => 0.62, 'constituentCount' => 30, 'memberCount' => 3, 'listedCount' => 60,
                'universe' => 'the whole listed board', 'rankingLabel' => 'Free-float capitalisation', 'weightingLabel' => 'Capped float weight',
                'weightingDescription' => 'Each member is held in proportion to its free float', 'isCapped' => true, 'maxConstituentWeight' => 0.10,
                'appliesConcentrationBudget' => false, 'screensEarnings' => true, 'totalFloatCap' => 2.45e11, 'topWeight' => 1.0, 'topN' => 3,
                'largest' => $members[0],
                'sectorWeights' => [['sector' => 'Financials', 'weight' => 0.4125, 'count' => 1], ['sector' => 'Consumer Cyclical', 'weight' => 0.3375, 'count' => 1], ['sector' => 'Communication Services', 'weight' => 0.25, 'count' => 1]],
                'divisor' => 8.1e8, 'reconstitutionsPerYear' => 4, 'added' => ['WIRE'], 'deleted' => ['KITE'], 'hasRoster' => true,
                'daysSinceReconstitution' => 40.0, 'daysUntilReconstitution' => 50.0, 'bands' => ['inner' => 24, 'outer' => 36],
                'atRisk' => [['rank' => 31, 'ticker' => 'POOL', 'name' => 'Pool Capital', 'sector' => 'Financials', 'exits' => false]],
                'contenders' => [['rank' => 23, 'ticker' => 'KITE', 'name' => 'Kite Industrial REIT', 'sector' => 'Real Estate', 'qualifies' => true]],
                'expenseRatio' => 0.0005, 'navPremium' => 0.00042, 'arbitrageBand' => 0.0015, 'sharesOutstanding' => 4.2e7,
                'distributionYield' => 0.0105, 'accruedIncome' => 0.85, 'feesPaidPerShare' => 1.94, 'tradingCostsPerShare' => 0.12,
                'trackingDifference' => 0.0012,
                'baseLevel' => \App\Service\Math\FinancialConstants::INDEX_BASE_LEVEL, 'membershipBuffer' => \App\Service\Market\Index\IndexCommittee::INDEX_MEMBERSHIP_BUFFER,
                'distributionsPerYear' => \App\Service\Math\FinancialConstants::FUND_DISTRIBUTIONS_PER_YEAR,
            ],
        ])];

        // A listed fund manager quoted against its net assets: the NAV premium chart beside the multiples.
        $pages['stock-fund'] = ['/stock/SWAN', 'stock/index.html.twig', array_replace($pages['stock'][2], ['netAssetValue' => ['premium' => -0.042]])];

        // A bank, quoted on tangible book and judged on its capital ratio (CompanySnapshotBuilder::capitalPosition).
        $pages['stock-bank'] = ['/stock/LAKE', 'stock/index.html.twig', array_replace($pages['stock'][2], [
            'isFinancial' => true, 'businessModel' => 'commercial_bank', 'displayReturn' => 0.124,
            'industryCharts' => ['netInterestEngineChart', 'regulatoryRatiosChart'], 'industryDescription' => \App\Data\Company\Sectors::BUSINESS_MODEL_DESCRIPTIONS['commercial_bank'],
            'capital' => ['tangibleBookPerShare' => 52.4, 'priceToTangibleBook' => 1.61, 'returnOnTangibleEquity' => 0.141, 'goodwillShare' => 0.08, 'ratio' => 0.094, 'zone' => 'Safe', 'cet1' => 0.118],
            'capitalThresholds' => ['warning' => 0.08, 'distress' => 0.05, 'bankrupt' => 0.03],
        ])];

        // A miner in default, seen signed in: the credit card under stress, the industry card with its merger review,
        // management, peers, holdings, a listed bond, an option chain and the business map.
        $pages['stock-industrial'] = ['/stock/FNCH', 'stock/index.html.twig', array_replace($pages['stock'][2], self::minerStock())];

        $pages += self::bondPages();

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
            $tokens->setToken(in_array($name, ['dashboard', 'bond-held', 'bond-redeemed', 'stock-industrial'], true)
                ? new UsernamePasswordToken((new User())->setEmail('kestrel@example.com')->setUsername('kestrel'), 'main', ['ROLE_USER'])
                : null);
            $html = $twig->render($template, $data);
            $stack->pop();
            $this->assertFileExists(OfflinePage::write($name, $html));
        }

        $request = Request::create('/stock/LAKE');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack->push($request);
        foreach (\App\Data\Company\LifecycleStage::cases() as $stage) {
            $stockData = $pages['stock'][2];
            $stockData['lifecycleStage'] = $stage;
            $html = $twig->render('stock/index.html.twig', $stockData);
            $this->assertStringContainsString($stage->label(), $html);
        }
        $stack->pop();
    }

    /**
     * The reserve page as SovereignReservePageBuilder hands it over: a stub state with the fund open, a head named and
     * a programme running, over the first eight listings and the District's clearinghouse.
     *
     * @return array<string, mixed>
     */
    private function reservePage(): array
    {
        $rows = [...array_slice(InitialMarket::STOCKS, 0, 8), ...array_values(array_filter(InitialMarket::STOCKS, static fn(array $row): bool => $row['ticker'] === 'ACC'))];
        $board = [];
        foreach ($rows as $i => $row) {
            $board[] = (new \App\Entity\Stock())->setTicker($row['ticker'])->setName($row['name'])->setSector($row['sector'])
                ->setPrice((string) (20 + (7 * $i)))->setSharesOutstanding((80 + (35 * $i)) * 1_000_000)->setPublicFloatPercentage('0.8')->setLastDividend('0.4');
        }
        $stocks = $this->createStub(\App\Repository\StockRepository::class);
        $stocks->method('findAll')->willReturn($board);
        $macro = new \App\DTO\MacroStateDTO(
            totalTime: 14.4,
            nominalGdpIndex: 131.0,
            lastSovereignRebalanceAt: 14.25,
            sovereignFundTargetWeight: 0.0314,
            sovereignFundPolicyEquityShare: 0.62,
            sovereignFundDollarsPerGdp: 1.0e10,
            sovereignFundAnnualDraw: 2.4e10,
            sovereignFundRebalanceMonthsLeft: 2.0,
            sovereignFundRebalanceShare: 0.0036,
            sovereignFundToGdp: 1.52,
            sovereignFundDomesticWeight: 0.0262,
            sovereignFundEquityShare: 0.6,
            sovereignFundOwnershipShare: 0.061,
            sovereignFundDrawToGdp: 0.0184,
            sovereignFundStampDutyYearToDate: 1.1e9,
            sovereignFundStampDutyToGdp: 0.0016,
            sovereignDebtToGdp: 0.84,
            sovereignNetDebtToGdp: 0.31,
            sovereignFundReturnIndex: 134.2,
            sovereignFundExpectedRealReturn: 0.0318,
            foreignBondYield: 0.0334,
        );
        $politics = new \App\DTO\PoliticsStateDTO(totalTime: 14.4, heads: [\App\Service\Politics\Department\Office::FundHead->value => [
            'name' => 'Ines Varga', 'birth' => -41.0, 'termStart' => 12.0, 'stance' => \App\Service\Politics\Department\SovereignReserveFund::stance(0.62),
        ] + \App\Service\Politics\Department\Office::VACANT]);

        return (new \App\Service\View\SovereignReservePageBuilder($stocks, new \App\Service\Macro\Subsystem\SovereignFundSubsystem(new \App\Service\Math\MathUtility())))->build($macro, $politics);
    }

    /**
     * The bond desk from entities built as the auction and the corporate desk build them: the ladder with an inverted
     * curve, an issue signed out, a corporate issue held with a resting order, and a redeemed issue. The view
     * variables mirror BondController; keep the two in step.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private static function bondPages(): array
    {
        $issuer = (new \App\Entity\Stock())->setTicker('LAKE')->setName('Lakebird Bank')->setCreditRating('A')->setDynamicCreditSpread('0.0134');
        $bond = static fn(string $ticker, string $name, float $tenor, float $coupon, float $clean, float $ytm, float $duration, float $convexity): \App\Entity\Bond => (new \App\Entity\Bond())
            ->setTicker($ticker)->setName($name)->setTenorYears((string) $tenor)->setCouponRate((string) $coupon)->setFaceValue('1000')
            ->setIssuedAtTime(13.5 - $tenor / 2)->setMaturesAtTime(13.5 + $tenor / 2)->setLastCouponTime(13.25)
            ->setCleanPrice((string) $clean)->setAccruedInterest('8.25')->setPrice((string) ($clean + 8.25))
            ->setYieldToMaturity((string) $ytm)->setModifiedDuration((string) $duration)->setConvexity((string) $convexity)->setOutstandingFace('42000000000');
        $sovereign2 = $bond('G02-007', '4.125% Sovereign 2Y due Y14.50', 2.0, 0.04125, 1001.2, 0.0402, 0.97, 1.4)->setIsOnTheRun(true);
        $sovereign10 = $bond('G10-003', '3.750% Sovereign 10Y due Y18.50', 10.0, 0.0375, 1004.15, 0.0368, 4.62, 25.3)->setIsOnTheRun(true);
        $older10 = $bond('G10-002', '3.250% Sovereign 10Y due Y16.00', 10.0, 0.0325, 981.4, 0.0371, 2.41, 6.9);
        $corporate = $bond('LAKE-05Y02', 'LAKE 5.100% Senior Notes 5Y due Y16.00', 5.0, 0.051, 1012.9, 0.0478, 2.38, 6.8)
            ->setIssuer($issuer)->setSeniority(\App\Entity\Bond::SENIORITY_SENIOR_UNSECURED)->setCreditSpread('0.0134')->setOutstandingFace('1500000000');
        $redeemed = $bond('G02-004', '2.875% Sovereign 2Y due Y13.00', 2.0, 0.02875, 1000.0, 0.0, 0.0, 0.0)->setStatus(\App\Entity\Bond::STATUS_MATURED)->setAccruedInterest('0')->setPrice('1000');

        $row = static fn(\App\Entity\Bond $b, ?float $change): array => ['bond' => $b, 'yearsToMaturity' => $b->yearsToMaturity(13.5), 'change' => $change];
        $flows = [];
        for ($k = 1; $k <= 10; $k++) {
            $flows[] = ['yearsAway' => $k * 0.5 - 0.25, 'amount' => $k === 10 ? 1025.5 : 25.5, 'isRedemption' => $k === 10];
        }
        $view = static fn(\App\Entity\Bond $b, array $overrides = []): array => array_replace([
            'bond' => $b, 'yearsToMaturity' => $b->yearsToMaturity(13.5), 'retired' => null, 'changePercent' => 0.0042,
            'cashFlows' => $flows, 'annualCouponIncome' => (float) $b->getCouponRate() * 1000.0, 'userQuantity' => 0, 'userAvgCost' => 0.0,
            'userCouponIncome' => 0.0, 'openOrders' => [], 'dealingPrice' => (float) $b->getPrice() + 0.6,
            'rateShocks' => \App\Controller\BondController::rateShocks($b), 'ticksPerYear' => 14400,
        ], $overrides);

        return [
            'bond-ladder' => ['/bonds', 'bond/ladder.html.twig', [
                'rowsByTenor' => ['2' => [$row($sovereign2, 0.0011)], '10' => [$row($sovereign10, -0.0063), $row($older10, null)]],
                'corporateByIssuer' => ['LAKE' => ['issuer' => $issuer, 'rating' => 'A', 'spread' => 0.0134, 'issues' => [$row($corporate, 0.0021)]]],
                'holdings' => ['G10-003' => 40],
                'curve' => [['tenor' => 0.25, 'yield' => 0.0431], ['tenor' => 2.0, 'yield' => 0.0402], ['tenor' => 10.0, 'yield' => 0.0368], ['tenor' => 30.0, 'yield' => 0.0381]],
                'macro' => new \App\DTO\MacroStateDTO(policyRate: 0.0425, inflation: 0.0241, yield2y: 0.0402, yield10y: 0.0368, termPremium10y: 0.0061),
                'benchmarks' => [
                    ['label' => '2Y', 'tenor' => 2.0, 'yield' => 0.0402], ['label' => '5Y', 'tenor' => 5.0, 'yield' => 0.0379],
                    ['label' => '10Y', 'tenor' => 10.0, 'yield' => 0.0368], ['label' => '30Y', 'tenor' => 30.0, 'yield' => 0.0381],
                ],
            ]],
            'bond' => ['/bond/G10-003', 'bond/view.html.twig', $view($sovereign10)],
            'bond-held' => ['/bond/LAKE-05Y02', 'bond/view.html.twig', $view($corporate, [
                'changePercent' => -0.011, 'userQuantity' => 25, 'userAvgCost' => 998.1, 'userCouponIncome' => 112.5,
                'openOrders' => [['id' => 77, 'ticker' => 'LAKE-05Y02', 'action' => 'SELL', 'orderType' => 'LIMIT', 'quantity' => 10, 'limitPrice' => 1025.0]],
            ])],
            'bond-redeemed' => ['/bond/G02-004', 'bond/view.html.twig', $view($redeemed, [
                'retired' => \App\Controller\BondController::RETIRED[\App\Entity\Bond::STATUS_MATURED], 'changePercent' => null, 'cashFlows' => [],
            ])],
        ];
    }

    /**
     * The keys a struggling miner's page carries on top of the bank's stub, as the stock page builders hand them over.
     */
    private static function minerStock(): array
    {
        $leg = static fn(string $ticker, float $mark, float $delta, float $theta, int $position, bool $itm): array => [
            'ticker' => $ticker, 'mark' => $mark, 'impliedVolatility' => 0.41, 'delta' => $delta, 'gamma' => 0.03, 'theta' => $theta,
            'dailyDecay' => abs($theta) / 365.0, 'openInterest' => 1200, 'position' => $position, 'inTheMoney' => $itm,
        ];

        return [
            'asset' => ['ticker' => 'FNCH', 'name' => 'Goldfinch Mining', 'sector' => 'Materials', 'industry' => 'Gold', 'price' => 84.21, 'isBankrupt' => false, 'bankrupt' => false, 'sharesOutstanding' => 310000000, 'earningsPerShare' => -1.12, 'currentRoe' => 0.0, 'baselineRoe' => 0.0, 'currentRoic' => 0.0, 'baselineRoic' => 0.064, 'publicFloatPercentage' => 0.7, 'volatility' => 0.41, 'totalEquity' => 6.4e9, 'debtToEquity' => 2.6, 'creditRating' => 'B', 'creditSpread' => 0.052, 'dynamicCreditSpread' => 0.061, 'systemicImportance' => 'Base', 'beta' => 1.4, 'accrualsRatio' => 0.031],
            'isFinancial' => false, 'businessModel' => 'mining', 'lifecycleStage' => \App\Data\Company\LifecycleStage::ShakeOut,
            'industryCharts' => ['cyclicalDynamicsChart'], 'industryDescription' => \App\Data\Company\Sectors::BUSINESS_MODEL_DESCRIPTIONS['mining'],
            'marketCap' => 2.61e10, 'peRatio' => null, 'dividendYield' => 0.0, 'strategicStake' => 0.0, 'marketShare' => 0.31, 'displayReturn' => 0.064, 'fundStake' => 0.161,
            'analystTargets' => ['rating' => 'Underperform', 'tone' => 'down', 'upside' => -0.12, 'consensus' => 74.1, 'growth_analyst' => 70.2, 'value_analyst' => 77.9, 'income_analyst' => 74.4],
            'creditHealth' => [
                'inDefault' => true, 'quartersInDefault' => 2, 'cureDueNextReport' => true,
                'revolverCommitment' => 0.0, 'revolverDrawn' => 0.0, 'revolverUtilization' => null,
                'netDebtToEbitda' => 5.6, 'covenantLimit' => 2.5, 'hasCovenantHeadroom' => false,
                'interestCoverage' => -0.4, 'distanceToDefault' => 0.8, 'investmentGradeDistance' => \App\Service\Market\Bond\CreditRatingAgency::THRESHOLD_BBB, 'coverageState' => 'negative',
            ],
            'industry' => [
                'tracked' => true, 'pricesCapacity' => true, 'addressableShare' => 0.31,
                'saturation' => ['threshold' => 0.25, 'penalty' => 0.0021, 'severity' => 0.12, 'trueReturn' => 0.11, 'trailingReturn' => 0.094, 'marginalReturn' => 0.072, 'moatFactor' => 1.0],
                'rosterShare' => 0.38, 'peerShares' => ['SINK' => 0.41, 'FNCH' => 0.38, 'DRLL' => 0.21], 'rosterSize' => 3,
                'capacityRatio' => 1.08, 'installedCapacity' => 4.2e10, 'trendCapacity' => 3.9e10, 'balance' => 'overbuilt', 'balanceTone' => 'warn',
                'priceMove' => 0.93 - 1.0, 'rivalShareDrain' => 0.0042, 'ownPriceVolumeShift' => -0.0011, 'idlePlant' => 0.064,
                'outlook' => ['level' => 0.912, 'doubt' => 0.088, 'revision' => -0.061],
                'mergerReview' => ['herfindahl' => 0.3266, 'highlyConcentrated' => true, 'acquirerShare' => 0.38, 'fringeShare' => 0.12, 'clearedShare' => 0.003, 'reviewBinds' => true, 'screens' => ['delta' => 0.02, 'concentrated' => 0.25, 'shareCeiling' => 0.35]],
            ],
            'segments' => [
                ['label' => 'Gold and copper mining', 'industry' => 'Gold', 'outlook' => null, 'revenue' => 5.8e9, 'share' => 0.86],
                ['label' => 'Ore terminals', 'industry' => 'Marine Ports & Services', 'outlook' => 1.04, 'revenue' => 9.4e8, 'share' => 0.14],
                ['label' => 'Royalty streams', 'industry' => 'Investment Companies', 'outlook' => null, 'revenue' => null, 'share' => null],
            ],
            'management' => ['style' => 'empire_builder', 'label' => 'Empire Builder', 'mandate' => 'mandated to grow, and will fund projects a stricter board would refuse', 'conviction' => 'pronounced', 'tone' => 'warn', 'tenureYears' => 3.2],
            'peers' => [
                ['ticker' => 'SINK', 'name' => 'Sinkhole Gold', 'industry' => 'Gold', 'price' => 31.2, 'marketCap' => 9.4e9, 'peRatio' => 11.2, 'roic' => 0.081, 'totalEquity' => 4.1e9, 'isBankrupt' => false, 'sameIndustry' => true],
                ['ticker' => 'DRLL', 'name' => 'Drillwell Copper', 'industry' => 'Copper', 'price' => 18.7, 'marketCap' => 5.2e9, 'peRatio' => null, 'roic' => -0.012, 'totalEquity' => 2.2e9, 'isBankrupt' => false, 'sameIndustry' => false],
            ],
            'anchorPortfolio' => ['totalValue' => 3.1e9, 'shareOfNav' => 0.42, 'holdings' => [
                ['ticker' => 'KEEL', 'name' => 'Keel Line Shipping', 'industry' => 'Marine Shipping', 'ownership' => 0.34, 'tier' => 'Associate', 'price' => 22.4, 'changePercent' => 0.018, 'value' => 2.6e9, 'shareOfPortfolio' => 0.84, 'shareOfNav' => 0.35, 'isBankrupt' => false],
                ['ticker' => 'KITE', 'name' => 'Kite Freight', 'industry' => 'Trucking', 'ownership' => 0.12, 'tier' => 'Minority', 'price' => 0.0, 'changePercent' => null, 'value' => 0.0, 'shareOfPortfolio' => 0.0, 'shareOfNav' => 0.0, 'isBankrupt' => true],
            ]],
            'corporateBonds' => [['ticker' => 'CB-FNCH-5Y', 'name' => 'Goldfinch 7.25% Year 19', 'couponRate' => 0.0725, 'cleanPrice' => 81.4, 'yieldToMaturity' => 0.124, 'modifiedDuration' => 3.6, 'maturesAtTime' => 18.2, 'yearsToMaturity' => 4.4]],
            'optionsListed' => true, 'optionsReason' => null, 'optionDealerGammaPerPercent' => 125000.0, 'optionOpenInterest' => 4200, 'optionMultiplier' => 100,
            'optionExpiries' => [['serial' => 1, 'expiresAtTime' => 14.25, 'daysToExpiry' => 41.0, 'atmVolatility' => 0.38, 'atmDistance' => 0.79, 'rows' => [
                ['strike' => 80.0, 'isNearestStrike' => false, 'call' => $leg('FNCH-C80', 7.12, 0.71, -9.1, 2, true), 'put' => null],
                ['strike' => 85.0, 'isNearestStrike' => true, 'call' => $leg('FNCH-C85', 4.05, 0.48, -11.4, 0, false), 'put' => $leg('FNCH-P85', 4.61, -0.52, -10.2, -1, true)],
            ]]],
            'businessMap' => [
                'edges' => [['d:metals', 'rev:gold', -1, 0.004]],
                'outside' => [['group' => 'Input markets'], ['id' => 'd:metals', 'label' => 'Precious metals', 'value' => -0.031, 'format' => \App\Service\View\FigureFormat::SignedPercent, 'unit' => null, 'note' => null, 'chips' => [['text' => 'on gold', 'figure' => -0.004, 'format' => \App\Service\View\FigureFormat::SignedPercent, 'tone' => 'down']], 'feeds' => ['Gold']]],
                'inside' => [['id' => 'ops:gold', 'label' => 'Gold', 'value' => null, 'format' => null, 'unit' => null, 'note' => null, 'chips' => [['text' => 'Operational headwinds', 'figure' => null, 'format' => null, 'tone' => 'down']]]],
                'produced' => [['id' => 'rev:gold', 'label' => 'Gold', 'value' => 2.1e9, 'format' => \App\Service\View\FigureFormat::LargeMoney, 'share' => 1.0, 'change' => -0.031, 'note' => null, 'chips' => [['text' => 'Soft market', 'figure' => null, 'format' => null, 'tone' => 'neutral']]]],
                'handoff' => ['revenue' => 2.1e9, 'change' => -0.031],
                'event' => ['text' => 'Strike at the coldwater pit', 'figure' => null, 'format' => null, 'tone' => 'warn'],
            ],
            'priceAlerts' => [],
        ];
    }

    /** A newswire page from the presenter, so each card is the one the page renders live. */
    private static function newswire(): array
    {
        $presenter = new \App\Service\News\EventPresenter();
        $stories = [
            ['GOVERNMENT', 'budget_enacted', null, null, 'The Diet passed the Civic-Vanguard budget: corporate tax at 21.0%, tariffs at 2.5%.', -0.8, true, []],
            ['EARNINGS', null, 'HUMM', 'Hummock Foods', 'Q-Earnings: $1.42 (Beat expectations by $0.11 | +$1.20B EVA).', 6.4, true, ['eps' => 1.42, 'surprise' => 0.11, 'economicProfit' => 1.2e9, 'actions' => []]],
            ['SHOCK', null, 'ORRA', 'Orra Biosciences', 'Orra Biosciences shares drop 13.2% in a sudden move.', -13.2, true, []],
            ['INDEX', null, 'LBI', 'Skein Lakebird 30', 'Admitted: HUMM. Dropped: KITE.', 0.0, false, []],
            ['CREDIT_DOWNGRADE', null, 'KITE', 'Kite Freight', 'Credit rating downgraded from BBB to BB due to deteriorating credit profile.', null, true, ['from' => 'BBB', 'to' => 'BB']],
            ['ECONOMY', 'banking_crisis', null, null, 'Interbank lending has frozen: the overnight spread stands at 310 basis points.', -7.1, true, []],
            ['MANAGEMENT CHANGE', null, 'LAKE', 'Lakebird Bank', 'Lakebird Bank named a new chief executive after 6.1 years.', 0.0, false, ['forced' => false]],
        ];

        $items = [];
        foreach ($stories as $i => [$type, $topic, $ticker, $name, $description, $change, $headline, $facts]) {
            $card = $presenter->present(new \App\Service\News\PresentableEvent($type, $description, $facts, $change, $topic, 13.71 - 0.013 * $i, new \DateTimeImmutable(sprintf('2026-10-06 14:%02d', 50 - 7 * $i))));
            $items[] = ['card' => $card, 'ticker' => $ticker, 'name' => $name, 'headline' => $headline];
        }

        return ['section' => 'all', 'sections' => \App\Service\News\NewsDesk::SECTIONS, 'items' => $items];
    }
}
