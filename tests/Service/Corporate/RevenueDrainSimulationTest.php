<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CapitalAllocationEngine;
use App\Service\Corporate\CorporateLedgerService;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\News\NarrativeEngine;
use App\Service\Market\Pricing\MarketConsensusEngine;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class RevenueDrainSimulationTest extends TestCase
{
    /**
     * A rate-hiking cycle squeezes a fixed-price contractor's margins (credit drag on commercial orders,
     * material inflation, floating-rate interest) and must show up in ROIC. It must not shrink the firm's
     * physical revenue capacity: before the structural turnover anchor, the depressed trailing ROIC fed back
     * into expected revenue and IBHI lost more than half its revenue within eight quarters.
     */
    public function testConstructionRevenueCapacityHoldsThroughRateHikingCycle(): void
    {
        $ticksPerYear = 252;
        $ticksPerQuarter = (int) ($ticksPerYear / 4);
        $reportingTick = EarningsEngine::resolveReportingTick('IBHI', $ticksPerYear);

        // The same firm under two rate paths. Trailing ROIC over sixteen quarters of a single seed lands
        // anywhere within a few points of the ten-percent line, and the two paths consume the random stream
        // differently, so the squeeze is asserted as the mean over several seeds against a control left at
        // the starting rate rather than against a fixed level on one draw path.
        $simulate = function (bool $hiking, int $seed) use ($ticksPerYear, $ticksPerQuarter, $reportingTick): array {
            mt_srand($seed);
            $earningsEngine = $this->createEngine();
            $stock = $this->createStockFromInitialMarket('IBHI', 'Engineering & Construction');

            $revenues = [];
            $macro = null;
            for ($q = 1; $q <= 16; $q++) {
                // Policy rate ramps from 2.5% to 5.0% over eight quarters while bank lending standards tighten.
                $ramp = $hiking ? min(1.0, max(0.0, ($q - 1) / 8.0)) : 0.0;
                $policy = 0.025 + 0.025 * $ramp;
                $macro = new MacroStateDTO(
                    outputGapEma: -0.015 * $ramp,
                    policyRate: $policy,
                    policyRateEma: $policy,
                    yield2yEma: $policy + 0.005,
                    yield5yEma: $policy + 0.008,
                    yield10yEma: $policy + 0.010,
                    sloosTighteningIndexEma: 0.4 * $ramp,
                    macroCreditSpreadEma: 0.02 + 0.01 * $ramp,
                    inflationEma: 0.03,
                    producerPriceInflation: 0.03,
                    producerPriceInflationEma: 0.03,
                );

                $earningsEngine->calculate($stock, $macro, (($q - 1) * $ticksPerQuarter) + $reportingTick, $ticksPerYear);
                $revenues[$q] = (float) $stock->getTotalRevenue();
            }

            return [
                'revenues' => $revenues,
                'roic' => (float) $stock->getRoicTtm(),
                'turnover' => (float) $stock->getAssetTurnover(),
                'tax_rate' => $macro->corporateTaxRate,
            ];
        };

        $hiked = $simulate(true, 42);

        $seededTurnover = 0.13 / (0.09 * (1.0 - $hiked['tax_rate']));
        $this->assertEqualsWithDelta($seededTurnover, $hiked['turnover'], 0.001, 'turnover is seeded once by the DuPont identity and held');

        $peak = max(array_slice($hiked['revenues'], 0, 4, true));
        $troughAfterHikes = min(array_slice($hiked['revenues'], 4, null, true));
        $this->assertGreaterThan($peak * 0.60, $troughAfterHikes, 'a margin squeeze must not collapse physical revenue capacity');

        $seeds = [1, 2, 3, 4, 5, 6, 7, 8];
        $hikedRoic = array_sum(array_map(fn (int $seed): float => $simulate(true, $seed)['roic'], $seeds)) / count($seeds);
        $controlRoic = array_sum(array_map(fn (int $seed): float => $simulate(false, $seed)['roic'], $seeds)) / count($seeds);
        $this->assertLessThan($controlRoic, $hikedRoic, 'the squeeze itself still shows up in trailing ROIC, below the same firm left at the starting rate');
    }

    /**
     * Removing the inflation revaluation of equity must not leave firms shrinking in nominal terms.
     *
     * The revaluation used to be the only thing keeping invested capital growing with prices, so deleting
     * it without a replacement channel would have quietly reintroduced a capacity death spiral: nominal
     * revenue capacity is anchored to invested capital, so capital that stood still while prices rose
     * would mean real capacity falling every quarter. Replacement-cost maintenance CapEx is that channel —
     * the firm spends more cash to replace the same plant, and the plant ledger grows by what it spent.
     */
    public function testInvestedCapitalKeepsPaceWithInflationWithoutTheEquityRevaluation(): void
    {
        $ticksPerYear = 252;
        $ticksPerQuarter = (int) ($ticksPerYear / 4);
        $reportingTick = EarningsEngine::resolveReportingTick('IBHI', $ticksPerYear);
        $quarters = 24;

        // One path's closing quarter lands anywhere within fifteen points of its opening one, and real revenue
        // only just holds its value, so the claim is asserted on the mean over enough draw paths to resolve it.
        $simulate = function (int $seed) use ($ticksPerYear, $ticksPerQuarter, $reportingTick, $quarters): array {
            mt_srand($seed);
            $earningsEngine = $this->createEngine();
            $stock = $this->createStockFromInitialMarket('IBHI', 'Engineering & Construction');

            $openingCapital = null;
            $openingRevenue = null;
            $deflator = 1.0;

            for ($q = 1; $q <= $quarters; $q++) {
                // Sustained 3% inflation: the price level compounds, quarter by quarter.
                $deflator *= (1.0 + 0.03 / 4.0);
                $macro = new MacroStateDTO(
                    outputGapEma: 0.0,
                    policyRate: 0.04,
                    policyRateEma: 0.04,
                    yield2yEma: 0.045,
                    yield5yEma: 0.048,
                    yield10yEma: 0.050,
                    inflationEma: 0.03,
                    gdpDeflator: $deflator,
                );

                $earningsEngine->calculate($stock, $macro, (($q - 1) * $ticksPerQuarter) + $reportingTick, $ticksPerYear);

                $openingCapital ??= $stock->getInvestedCapital();
                $openingRevenue ??= (float) $stock->getTotalRevenue();
            }

            return [
                'capital' => $stock->getInvestedCapital() / $openingCapital,
                'revenue' => (float) $stock->getTotalRevenue() / $openingRevenue,
                'price' => $deflator,
                'vintage' => (float) $stock->getPpeVintageDeflator(),
            ];
        };

        $runs = array_map($simulate, range(1, 30));
        $mean = static fn (string $key): float => array_sum(array_column($runs, $key)) / count($runs);
        $priceGrowth = $runs[0]['price'];

        // Nominal capital and revenue must at least hold their real value against the price level.
        $this->assertGreaterThanOrEqual(
            $priceGrowth,
            $mean('capital'),
            'invested capital fell behind the price level, so real capacity is shrinking'
        );
        $this->assertGreaterThanOrEqual(
            $priceGrowth,
            $mean('revenue'),
            'nominal revenue capacity fell behind the price level'
        );

        // The plant is being replaced at current prices, so its vintage tracks the price level up on every path.
        foreach ($runs as $run) {
            $this->assertGreaterThan(1.0, $run['vintage']);
        }
    }

    private function createEngine(): EarningsEngine
    {
        $mathUtility = new MathUtility();
        $corporateMetrics = new CorporateMetrics();
        $debtEngine = new DebtEngine($mathUtility, $corporateMetrics);
        $capExEngine = new CapExEngine();
        $treasuryEngine = new TreasuryEngine($corporateMetrics, $debtEngine, $capExEngine, $mathUtility);
        $capitalAllocationEngine = new CapitalAllocationEngine(
            $this->createStub(CorporateLedgerService::class),
            $corporateMetrics,
            $debtEngine,
            $mathUtility,
            $treasuryEngine
        );

        return new EarningsEngine(
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(MarketEventPublisher::class),
            $capitalAllocationEngine,
            $debtEngine,
            $capExEngine,
            $mathUtility,
            $corporateMetrics,
            $this->createStub(NarrativeEngine::class),
            new MarketConsensusEngine()
        );
    }

    private function createStockFromInitialMarket(string $ticker, string $industry): Stock
    {
        $data = [];
        foreach (\App\Data\Company\InitialMarket::STOCKS as $s) {
            if ($s['ticker'] === $ticker) {
                $data = $s;
            }
        }

        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setName($ticker);
        $stock->setIndustry($industry);
        $stock->setSystemicImportance($data['systemic_importance'] ?? 'base');
        $stock->setEarningsPerShare('2.50');
        $stock->setSharesOutstanding((string) ($data['shares_outstanding'] ?? 1000000000));
        $stock->setPrice('50.00');
        $stock->setVolatility((string) ($data['volatility'] ?? 0.15));
        $stock->setCurrentVolatility((string) ($data['volatility'] ?? 0.15));
        $stock->setBeta((string) ($data['beta'] ?? 1.0));
        $stock->setJumpIntensity((string) ($data['jump_intensity'] ?? 1.0));
        $stock->setJumpVol((string) ($data['jump_vol'] ?? 0.10));
        $stock->setTotalEquity((string) ($data['total_equity'] ?? 40000000000.00));
        $stock->setWholesaleDebt((string) ($data['wholesale_debt'] ?? 10000000000.00));
        $stock->setCorporateTreasury((string) ($data['corporate_treasury'] ?? 5000000000.00));
        $stock->setCustomerDeposits((string) ($data['customer_deposits'] ?? 0.00));
        $stock->setRetainedEarnings((string) ($data['retained_earnings'] ?? 0.00));
        $stock->setBaselineRoic((string) ($data['baseline_roic'] ?? 0.15));
        $stock->setBaselineRoe((string) ($data['baseline_roe'] ?? 0.15));
        $stock->setOperatingMargin((string) ($data['operating_margin'] ?? 0.18));
        $stock->setTargetPayoutRatio((string) ($data['target_payout_ratio'] ?? 0.30));
        $stock->setDividendSpeed((string) ($data['dividendSpeed'] ?? 0.20));
        $stock->setCapexRatio((string) ($data['capex_ratio'] ?? 0.20));
        $stock->setSamRatio((string) ($data['sam_ratio'] ?? 0.05));
        $stock->setFixedCostRatio((float) ($data['fixed_cost_ratio'] ?? 0.35));
        $stock->setDepreciationRate((string) ($data['depreciation_rate'] ?? 0.05));
        $stock->setFloatingDebtRatio((string) ($data['floating_debt_ratio'] ?? 0.30));
        $stock->setHistoricalFixedRate((string) ($data['historical_fixed_rate'] ?? 0.05));
        $stock->setCreditSpread((string) ($data['credit_spread'] ?? 0.01));

        return $stock;
    }

    public function testInvestedCapitalEnforcesFiftyPercentEquityFloorEvenWithMassiveCash(): void
    {
        $stock = new Stock();
        $stock->setTicker('CASH_COW');
        $stock->setTotalEquity('50000000000.00'); // 50B
        $stock->setWholesaleDebt('10000000000.00'); // 10B
        $stock->setCustomerDeposits('0.00');
        $stock->setCorporateTreasury('120000000000.00'); // 120B (Massive cash hoard > Equity + Debt)

        // Without floor: 50B + 10B - 120B = -60B.
        // The 50% core business floor guarantees at least 50B * 0.50 = 25B
        $investedCapital = $stock->getInvestedCapital();
        $this->assertEquals(25000000000.00, $investedCapital);
    }
}
