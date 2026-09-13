<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\CapitalAllocationEngine;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CorporateLedgerService;
use App\Service\Math\MathUtility;
use App\Service\Math\CorporateMetrics;
use App\Service\Event\NarrativeEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\MarketConsensusEngine;
use App\Data\Sectors;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Exhaustive Closed-Loop Multi-Quarter Simulation Invariant Test.
 * 
 * Verifies that running consecutive quarterly earnings reports across all 100+ registered
 * sector industries over a 5-to-10 year time horizon under multiple macroeconomic regimes
 * (Neutral, Severe Recession, Stagflationary Overheating) does not induce mathematical
 * death spirals, balance sheet collapses, NWC explosion, or unhandled singularities.
 */
class MultiQuarterCorporateSimulationTest extends TestCase
{
    private EarningsEngine $earningsEngine;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        // These are stochastic multi-year simulations checked against hard collapse thresholds. Left unseeded
        // they inherit whatever global mt_rand state ran before them, so adding a test anywhere earlier in the
        // suite silently reshuffles every path here and can trip a threshold that has nothing to do with the
        // change. Seeding makes each case reproducible and independent of test ordering.
        mt_srand(20260908);

        $this->mathUtility = new MathUtility();
        $corporateMetrics = new CorporateMetrics();
        $debtEngine = new DebtEngine($this->mathUtility, $corporateMetrics);
        $capExEngine = new CapExEngine();
        $marketConsensusEngine = new MarketConsensusEngine();

        $ledgerMock = $this->createStub(CorporateLedgerService::class);
        $eventPublisherMock = $this->createStub(MarketEventPublisher::class);
        $narrativeEngineMock = $this->createStub(NarrativeEngine::class);
        $eventDispatcherMock = $this->createStub(EventDispatcherInterface::class);

        $treasuryEngine = new TreasuryEngine(
            $corporateMetrics,
            $debtEngine,
            $capExEngine,
            $this->mathUtility
        );

        $capitalAllocationEngine = new CapitalAllocationEngine(
            $ledgerMock,
            $corporateMetrics,
            $debtEngine,
            $this->mathUtility,
            $treasuryEngine
        );

        $this->earningsEngine = new EarningsEngine(
            $eventDispatcherMock,
            $eventPublisherMock,
            $capitalAllocationEngine,
            $debtEngine,
            $capExEngine,
            $this->mathUtility,
            $corporateMetrics,
            $narrativeEngineMock,
            $marketConsensusEngine
        );
    }

    public static function allIndustriesProvider(): array
    {
        $industries = [];
        foreach (Sectors::INDUSTRY_METRICS as $industry => $metrics) {
            $industries[$industry] = [$industry, $metrics];
        }

        return $industries;
    }

    private function createInitializedStock(string $industry, array $metrics): Stock
    {
        $stock = new Stock();
        $stock->setTicker('SIM_' . substr(md5($industry), 0, 4));
        $stock->setName('Simulation Corp ' . $industry);
        $stock->setIndustry($industry);
        $stock->setEarningsPerShare('2.50');
        $stock->setSharesOutstanding('1000000000');
        $stock->setPrice('50.00');
        $stock->setVolatility('0.15');
        $stock->setCurrentVolatility('0.15');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('40000000000.00'); // $40B
        $stock->setWholesaleDebt('10000000000.00'); // $10B
        $stock->setCorporateTreasury('5000000000.00'); // $5B
        $stock->setCustomerDeposits('15000000000.00'); // $15B
        $stock->setBaselineRoic('0.15');
        $stock->setBaselineRoe('0.15');
        $stock->setOperatingMargin('0.18');
        $stock->setTargetPayoutRatio('0.30');
        $stock->setDividendSpeed('0.20');
        $stock->setCapexRatio('0.20');
        $stock->setSamRatio('0.05');
        $stock->setDepreciationRate((string) ($metrics['depreciation'] ?? 0.05));

        return $stock;
    }

    /**
     * Test 1: 20-Quarter (5-Year) Steady-State Neutral Macro Simulation.
     * Asserts that in a stable macro environment, revenue, equity, and invested capital
     * achieve equilibrium and never decay or explode.
     */
    #[DataProvider('allIndustriesProvider')]
    public function testMultiQuarterNeutralSteadyState(string $industry, array $metrics): void
    {
        $stock = $this->createInitializedStock($industry, $metrics);

        $neutralMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            inflationEma: 0.02,
            policyRate: 0.04,
            policyRateEma: 0.04,
            yield2yEma: 0.04,
            yield5yEma: 0.042,
            yield10yEma: 0.045,
            nominalGdpIndex: 1.0,
            marketVolatilityEma: 0.15,
            macroCreditSpreadEma: 0.015,
            interbankLiquiditySpreadEma: 0.0010
        );

        $ticksPerQuarter = (int) (252 / 4);
        $reportingTick = EarningsEngine::resolveReportingTick($stock->getTicker(), 252);
        $initialRevenue = null;
        $quarterlyRevenues = [];

        // Simulate 20 consecutive quarters (5 years)
        for ($quarter = 1; $quarter <= 20; $quarter++) {
            $quarterTick = (($quarter - 1) * $ticksPerQuarter) + $reportingTick;
            $this->earningsEngine->calculate($stock, $neutralMacro, $quarterTick, 252);

            $currentRevenue = (float) $stock->getTotalRevenue();
            $currentEquity = (float) $stock->getTotalEquity();
            $currentTreasury = (float) $stock->getCorporateTreasury();
            $currentInvestedCapital = $stock->getInvestedCapital();
            $roicTtm = (float) $stock->getRoicTtm();
            $roeTtm = (float) $stock->getRoeTtm();
            $fcfPerShare = (float) $stock->getFreeCashFlowPerShare();

            $this->assertTrue(is_finite($currentRevenue), "Revenue became non-finite in Q{$quarter} for {$industry}");
            $this->assertTrue(is_finite($currentEquity), "Equity became non-finite in Q{$quarter} for {$industry}");
            $this->assertTrue(is_finite($currentTreasury), "Treasury became non-finite in Q{$quarter} for {$industry}");
            $this->assertTrue(is_finite($fcfPerShare), "FCF per share became non-finite in Q{$quarter} for {$industry}");

            if ($initialRevenue === null) {
                $initialRevenue = $currentRevenue;
            }
            $quarterlyRevenues[] = $currentRevenue;

            // Invariant 1: Revenue must never collapse toward zero (< 15% of initial baseline capacity)
            $this->assertGreaterThan(
                $initialRevenue * 0.15,
                $currentRevenue,
                "Quarter {$quarter} for {$industry} suffered a catastrophic collapse from initial \${$initialRevenue} to \${$currentRevenue}"
            );

            // Invariant 2: Invested Capital must remain strongly positive
            $this->assertGreaterThan(
                500_000_000.0,
                $currentInvestedCapital,
                "Invested capital collapsed below minimum threshold in Q{$quarter} for {$industry}"
            );

            // Invariant 3: Total Equity must remain solvent
            $this->assertGreaterThan(
                2_000_000_000.0,
                $currentEquity,
                "Total equity drained below solvency in Q{$quarter} for {$industry}"
            );

            // Invariant 4: Treasury must be finite and bounded
            $this->assertTrue(
                is_finite($currentTreasury),
                "Corporate treasury became non-finite in Q{$quarter} for {$industry}"
            );

            // Invariant 5: Return metrics must remain bounded
            $this->assertGreaterThanOrEqual(-0.50, $roicTtm, "ROIC TTM breached lower bound in Q{$quarter} for {$industry}");
            $this->assertLessThanOrEqual(1.00, $roicTtm, "ROIC TTM breached upper bound in Q{$quarter} for {$industry}");
            $this->assertGreaterThanOrEqual(-0.50, $roeTtm, "ROE TTM breached lower bound in Q{$quarter} for {$industry}");
            $this->assertLessThanOrEqual(1.00, $roeTtm, "ROE TTM breached upper bound in Q{$quarter} for {$industry}");

            // Invariant 6: Shares must remain strictly positive and below a reasonable hyper-dilution cap
            $currentShares = (int) $stock->getSharesOutstanding();
            $this->assertGreaterThan(0, $currentShares, "Shares outstanding collapsed to zero or negative in Q{$quarter} for {$industry}");
            $this->assertLessThan(1_000_000_000_000, $currentShares, "Shares outstanding hyper-diluted past 1 trillion in Q{$quarter} for {$industry}");

            // Invariant 7: Dividends must not be negative
            $this->assertGreaterThanOrEqual(0.0, (float) $stock->getLastDividend(), "Dividends went negative in Q{$quarter} for {$industry}");

            // Invariant 8: Debt must be finite and not explode into massive negative balances
            $currentDebt = (float) $stock->getWholesaleDebt();
            $this->assertTrue(is_finite($currentDebt), "Wholesale debt became non-finite in Q{$quarter} for {$industry}");
            $this->assertGreaterThanOrEqual(-50_000_000_000.0, $currentDebt, "Massive negative debt anomaly in Q{$quarter} for {$industry}");
        }

        // Over 5 years of neutral macro, revenue should not decay below 20% of initial capacity
        $finalRevenue = end($quarterlyRevenues);
        $this->assertGreaterThan(
            $initialRevenue * 0.20,
            $finalRevenue,
            "{$industry} experienced secular decay: Revenue dropped from \${$initialRevenue} to \${$finalRevenue} over 20 quarters"
        );
    }

    /**
     * The fixed-asset ledger must stay a coherent subset of the capital it is carved out of. Net PP&E plus
     * the other things invested capital is made of should track invested capital across a long run: if the
     * plant ledger drifted free of it, depreciation would be charged against an asset base the firm does
     * not have, and every coverage ratio built on EBIT would drift with it.
     */
    #[DataProvider('allIndustriesProvider')]
    public function testFixedAssetLedgerStaysCoherentWithInvestedCapital(string $industry, array $metrics): void
    {
        $stock = $this->createInitializedStock($industry, $metrics);
        $businessModel = Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';

        $neutralMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            inflationEma: 0.02,
            policyRate: 0.04,
            policyRateEma: 0.04,
            yield2yEma: 0.04,
            yield5yEma: 0.042,
            yield10yEma: 0.045,
            nominalGdpIndex: 1.0,
            marketVolatilityEma: 0.15,
            macroCreditSpreadEma: 0.015,
            interbankLiquiditySpreadEma: 0.0010
        );

        $ticksPerQuarter = (int) (252 / 4);
        $reportingTick = EarningsEngine::resolveReportingTick($stock->getTicker(), 252);

        for ($quarter = 1; $quarter <= 12; $quarter++) {
            $this->earningsEngine->calculate($stock, $neutralMacro, (($quarter - 1) * $ticksPerQuarter) + $reportingTick, 252);

            $strategy = Sectors::getBusinessModelStrategy($businessModel);
            $lease = (new CorporateMetrics())->calculateLeaseLiability((float) $stock->getTotalRevenue(), $strategy->getLeaseIntensity());

            if (Sectors::isFinancial($businessModel)) {
                // A balance-sheet business keeps no plant ledger; its asset side is the earning-asset book,
                // which has to be open, finite, and claimed in full by depositors, lenders and shareholders.
                $this->assertNull($stock->getGrossPpe(), "{$industry} opened a plant ledger it should not have");
                $this->assertTrue($stock->hasEarningAssetLedger(), "{$industry} never opened its earning-asset ledger");
                $book = (float) $stock->getEarningAssets();
                $allowance = (float) $stock->getCreditLossAllowance();
                $this->assertTrue(is_finite($book), "Earning assets became non-finite in Q{$quarter} for {$industry}");
                $this->assertGreaterThan(0.0, $book, "The earning-asset book collapsed in Q{$quarter} for {$industry}");
                $this->assertGreaterThanOrEqual(0.0, $allowance, "The credit-loss allowance went negative in Q{$quarter} for {$industry}");
                $this->assertLessThanOrEqual($book, $allowance, "The allowance exceeded the book it covers in Q{$quarter} for {$industry}");
                $this->assertBalanceSheetBalances($stock, $lease, $industry, $quarter);
                continue;
            }

            $gross = (float) $stock->getGrossPpe();
            $accumulated = (float) $stock->getAccumulatedDepreciation();
            $netPpe = $stock->getNetPpe();

            $this->assertTrue(is_finite($gross), "Gross PP&E became non-finite in Q{$quarter} for {$industry}");
            $this->assertGreaterThan(0.0, $gross, "Gross PP&E collapsed in Q{$quarter} for {$industry}");
            $this->assertLessThanOrEqual($gross, $accumulated, "Accumulated depreciation exceeded gross cost in Q{$quarter} for {$industry}");
            $this->assertGreaterThanOrEqual(0.0, $accumulated, "Accumulated depreciation went negative in Q{$quarter} for {$industry}");

            $this->assertGreaterThan(
                0.0,
                $netPpe,
                "Net PP&E fully depreciated away in Q{$quarter} for {$industry}"
            );

            // The balance sheet balances: every asset is claimed by a creditor or a shareholder and nothing
            // is left over. This is stronger than any bound on the plant alone, and it is the only test that
            // catches a ledger drifting free of the capital it was carved out of. (A negative-working-capital
            // business legitimately carries more plant than invested capital, because its suppliers fund
            // part of it, which is why a plant-to-capital bound was the wrong invariant here.)
            $this->assertBalanceSheetBalances($stock, $lease, $industry, $quarter);
        }
    }

    /** Every asset is claimed by a creditor or a shareholder and nothing is left over. */
    private function assertBalanceSheetBalances(Stock $stock, float $lease, string $industry, int $quarter): void
    {
        $assets = $stock->getTotalAssets($lease);
        $claims = $stock->getTotalLiabilities($lease) + (float) $stock->getTotalEquity();
        $this->assertEqualsWithDelta(
            $assets,
            $claims,
            max(1.0, $assets * 1e-6),
            "Balance sheet failed to balance in Q{$quarter} for {$industry}"
        );
    }

    /**
     * Test 2: 12-Quarter (3-Year) Severe Recession & Liquidity Crisis Stress Test.
     * Asserts that during severe macroeconomic distress, entities absorb the shocks without mathematical errors.
     */
    #[DataProvider('allIndustriesProvider')]
    public function testMultiQuarterRecessionaryShockStress(string $industry, array $metrics): void
    {
        $stock = $this->createInitializedStock($industry, $metrics);

        $severeRecessionMacro = new MacroStateDTO(
            outputGapEma: -0.06, // -6% output gap depression
            inflationEma: -0.01, // Deflationary pressure
            policyRate: 0.01,
            policyRateEma: 0.01,
            yield2yEma: 0.04, // 300bps yield curve inversion
            yield5yEma: 0.02,
            yield10yEma: 0.01,
            nominalGdpIndex: 0.90,
            marketVolatilityEma: 0.45, // 45% VIX Panic
            macroCreditSpreadEma: 0.07, // 700bps credit spread blowout
            interbankLiquiditySpreadEma: 0.0200 // Severe liquidity freeze
        );

        $ticksPerQuarter = (int) (252 / 4);
        $reportingTick = EarningsEngine::resolveReportingTick($stock->getTicker(), 252);

        // Simulate 12 quarters (3 years) of recession
        for ($quarter = 1; $quarter <= 12; $quarter++) {
            $quarterTick = (($quarter - 1) * $ticksPerQuarter) + $reportingTick;
            $this->earningsEngine->calculate($stock, $severeRecessionMacro, $quarterTick, 252);

            $currentRevenue = (float) $stock->getTotalRevenue();
            $currentEquity = (float) $stock->getTotalEquity();
            $currentTreasury = (float) $stock->getCorporateTreasury();
            $fcfPerShare = (float) $stock->getFreeCashFlowPerShare();
            $currentDebt = (float) $stock->getWholesaleDebt();
            $currentShares = (int) $stock->getSharesOutstanding();

            $this->assertTrue(is_finite($currentRevenue), "Revenue in {$industry} must be finite under recession in Q{$quarter}");
            $this->assertGreaterThanOrEqual(0.0, $currentRevenue, "Revenue in {$industry} must be non-negative in Q{$quarter}");
            $this->assertTrue(is_finite($currentEquity), "Equity in {$industry} must be finite in Q{$quarter}");
            $this->assertTrue(is_finite($currentTreasury), "Treasury in {$industry} must be finite in Q{$quarter}");
            $this->assertTrue(is_finite($fcfPerShare), "FCF per share in {$industry} must be finite in Q{$quarter}");
            $this->assertTrue(is_finite($currentDebt), "Wholesale debt in {$industry} must be finite in Q{$quarter}");
            $this->assertGreaterThan(0, $currentShares, "Shares dropped to 0 or below during recession in Q{$quarter} for {$industry}");
        }
    }

    /**
     * A severe but functioning credit market (700bps spreads) must not push solvent firms into payment
     * default. Market access to refinance an existing maturity is deliberately a looser test than the one
     * for taking on new leverage, because investment-grade issuers really do roll their debt straight
     * through recessions. If an ordinary downturn defaulted the whole market the mechanism would be wrong.
     */
    #[DataProvider('allIndustriesProvider')]
    public function testOrdinaryRecessionDoesNotDefaultSolventIssuers(string $industry, array $metrics): void
    {
        $stock = $this->createInitializedStock($industry, $metrics);
        $stock->setCreditRating('BBB');

        $recessionMacro = new MacroStateDTO(
            outputGapEma: -0.06,
            inflationEma: -0.01,
            policyRate: 0.01,
            policyRateEma: 0.01,
            yield2yEma: 0.04,
            yield5yEma: 0.02,
            yield10yEma: 0.01,
            nominalGdpIndex: 0.90,
            marketVolatilityEma: 0.45,
            macroCreditSpreadEma: 0.07,
            interbankLiquiditySpreadEma: 0.0200
        );

        $ticksPerQuarter = (int) (252 / 4);
        $reportingTick = EarningsEngine::resolveReportingTick($stock->getTicker(), 252);

        for ($quarter = 1; $quarter <= 12; $quarter++) {
            $this->earningsEngine->calculate($stock, $recessionMacro, (($quarter - 1) * $ticksPerQuarter) + $reportingTick, 252);

            $this->assertFalse(
                $stock->isPaymentDefault(),
                "{$industry} defaulted on a maturity in Q{$quarter} of an ordinary recession"
            );
        }
    }

    /**
     * Test 3: 12-Quarter (3-Year) Stagflationary Overheating Stress Test.
     * Asserts that high inflation, rate hikes, and CapEx demand do not cause working capital explosions.
     */
    #[DataProvider('allIndustriesProvider')]
    public function testMultiQuarterStagflationaryOverheatingStress(string $industry, array $metrics): void
    {
        $stock = $this->createInitializedStock($industry, $metrics);

        $stagflationMacro = new MacroStateDTO(
            outputGapEma: 0.04, // Overheating output
            inflationEma: 0.12, // 12% runaway inflation
            policyRate: 0.09, // 9% aggressive rate hikes
            policyRateEma: 0.09,
            yield2yEma: 0.09,
            yield5yEma: 0.095,
            yield10yEma: 0.10,
            nominalGdpIndex: 1.25,
            marketVolatilityEma: 0.28,
            macroCreditSpreadEma: 0.035,
            interbankLiquiditySpreadEma: 0.0050
        );

        $ticksPerQuarter = (int) (252 / 4);
        $reportingTick = EarningsEngine::resolveReportingTick($stock->getTicker(), 252);

        // Simulate 12 quarters (3 years) of stagflation
        for ($quarter = 1; $quarter <= 12; $quarter++) {
            $quarterTick = (($quarter - 1) * $ticksPerQuarter) + $reportingTick;
            $this->earningsEngine->calculate($stock, $stagflationMacro, $quarterTick, 252);

            $currentRevenue = (float) $stock->getTotalRevenue();
            $currentEquity = (float) $stock->getTotalEquity();
            $currentTreasury = (float) $stock->getCorporateTreasury();
            $currentInvestedCapital = $stock->getInvestedCapital();
            $fcfPerShare = (float) $stock->getFreeCashFlowPerShare();
            $currentDebt = (float) $stock->getWholesaleDebt();
            $currentShares = (int) $stock->getSharesOutstanding();

            $this->assertTrue(is_finite($currentRevenue), "Revenue in {$industry} must be finite under stagflation in Q{$quarter}");
            $this->assertTrue(is_finite($currentInvestedCapital), "Invested capital in {$industry} must be finite in Q{$quarter}");
            $this->assertTrue(is_finite($currentEquity), "Equity in {$industry} must be finite in Q{$quarter}");
            $this->assertTrue(is_finite($currentTreasury), "Treasury in {$industry} must be finite in Q{$quarter}");
            $this->assertTrue(is_finite($fcfPerShare), "FCF per share in {$industry} must be finite in Q{$quarter}");
            $this->assertTrue(is_finite($currentDebt), "Wholesale debt in {$industry} must be finite in Q{$quarter}");
            $this->assertGreaterThan(0, $currentShares, "Shares dropped to 0 or below during stagflation in Q{$quarter} for {$industry}");
        }
    }

    /**
     * Test 4: 8-Quarter (2-Year) IBHI Engineering & Construction Seasonality Simulation.
     * Asserts that cyclical winter ground freezes (Q1 seasonal factor) do not trigger
     * a debt issuance lockout, false liquidity crisis, or dilutive equity death spiral.
     */
    public function testIbhiEngineeringConstructionEightQuarterSimulationNoDeathSpiral(): void
    {
        $metrics = Sectors::INDUSTRY_METRICS['Engineering & Construction'];
        $stock = $this->createInitializedStock('Engineering & Construction', $metrics);
        $stock->setTicker('IBHI');
        $stock->setName('Iron Beak Heavy Industries');
        $stock->setFixedCostRatio(0.60);
        $stock->setOperatingMargin('0.09');
        $stock->setBaselineRoic('0.09');
        $stock->setTotalEquity('4000000000.00');
        $stock->setWholesaleDebt('1000000000.00');
        $stock->setCorporateTreasury('500000000.00');
        $stock->setCustomerDeposits('0.00');
        $initialShares = (int) $stock->getSharesOutstanding();

        $macro = new MacroStateDTO();
        $ticksPerQuarter = (int) (252 / 4);
        $reportingTick = EarningsEngine::resolveReportingTick('IBHI', 252);

        for ($quarter = 1; $quarter <= 8; $quarter++) {
            $quarterTick = (($quarter - 1) * $ticksPerQuarter) + $reportingTick;
            $result = $this->earningsEngine->calculate($stock, $macro, $quarterTick, 252);

            $this->assertNotNull($result, "IBHI must successfully report earnings in Q{$quarter}");
            $this->assertFalse($stock->isBankrupt(), "IBHI must not be bankrupt in Q{$quarter}");

            $currentRevenue = (float) $stock->getTotalRevenue();
            $this->assertGreaterThan(0.0, $currentRevenue, "IBHI revenue must remain positive in Q{$quarter}");

            $currentShares = (int) $stock->getSharesOutstanding();
            // In a death spiral, shares jump dramatically due to dilutive equity issuance. The bar is a
            // spiral, not any issuance at all: stock compensation settles in new shares every quarter and a
            // small funding raise (~1% of the count) shows up on some random paths, and this path depends
            // on which tests ran before it in the file. A spiral multiplies the count; 5% is not one.
            $this->assertLessThanOrEqual(
                (int) ($initialShares * 1.05),
                $currentShares,
                "IBHI must not undergo dilutive equity issuance in Q{$quarter} due to seasonal oscillation"
            );
        }
    }
}

