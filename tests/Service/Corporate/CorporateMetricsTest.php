<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Math\FinancialConstants;
use App\Service\Math\TimeSeries;
use PHPUnit\Framework\TestCase;

class CorporateMetricsTest extends TestCase
{
    private CorporateMetrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = CorporateMetrics::getInstance();
    }

    /**
     * The next unit of capital earns the return the whole base earns less what it adds to the saturation penalty on
     * that base: the derivative of E x (r - p(E)), nothing below the optimal share, and a softer drag for a firm whose
     * position defends the extra scale.
     */
    public function testTheMarginalReturnIsTheDerivativeOfWhatTheCapitalEarns(): void
    {
        $macroState = new MacroStateDTO(nominalGdpIndex: 1.0);
        $bank = $this->scaledFirm('MARG');
        $bank->setIndustry('Banks - Regional'); // a financial: no Cournot haircut, so the drag is all there is
        $baseReturn = 0.16;
        $earned = fn(Stock $stock, float $capital): float => $capital * ($baseReturn - $this->metrics->calculateMarketSaturationPenalty($stock, $capital, $macroState));

        foreach ([0.30, 0.55, 0.60] as $targetShare) {
            $capital = $targetShare * CorporateMetrics::BASELINE_SECTOR_TAM * (float) $bank->getSamRatio();
            $trueReturn = $baseReturn - $this->metrics->calculateMarketSaturationPenalty($bank, $capital, $macroState);
            $step = $capital * 1e-6;
            $derivative = ($earned($bank, $capital + $step) - $earned($bank, $capital - $step)) / (2.0 * $step);

            $this->assertEqualsWithDelta($derivative, $this->metrics->calculateMarginalReturn($bank, $trueReturn, $capital, $macroState), 1e-6, sprintf('share %.2f', $targetShare));
        }

        $belowOptimal = 0.30 * CorporateMetrics::BASELINE_SECTOR_TAM * (float) $bank->getSamRatio();
        $this->assertSame($baseReturn, $this->metrics->calculateMarginalReturn($bank, $baseReturn, $belowOptimal, $macroState));
        // Far past it the next unit would lower what the base earns, and is worth nothing.
        $farPast = 0.90 * CorporateMetrics::BASELINE_SECTOR_TAM * (float) $bank->getSamRatio();
        $this->assertSame(0.0, $this->metrics->calculateMarginalReturn($bank, $baseReturn, $farPast, $macroState));

        $titan = $this->scaledFirm('TITN');
        $titan->setSystemicImportance('titan');
        $this->assertLessThan(
            $this->metrics->calculateMarginalSaturationDrag($bank, 0.90),
            $this->metrics->calculateMarginalSaturationDrag($titan, 0.90),
        );
    }

    /**
     * A statutory monopoly sells nothing substitutable, so adding capacity cuts no price and the Cournot haircut is
     * exactly zero; past its optimal share the next unit of plant still earns less, through the saturation drag.
     */
    public function testANonSubstitutableFirmStillPaysForOutgrowingItsMarket(): void
    {
        $utility = $this->scaledFirm('WATT');
        $utility->setIndustry('Utilities - Regulated Electric');

        $macroState = new MacroStateDTO(nominalGdpIndex: 1.0);
        $investedCapital = 1.4 * CorporateMetrics::BASELINE_SECTOR_TAM * (float) $utility->getSamRatio();
        $share = $this->metrics->calculateScaleRatio($investedCapital, $macroState->nominalGdpIndex, (float) $utility->getSamRatio());

        $this->assertSame(
            0.0,
            $this->metrics->calculateCournotPriceHaircut($utility, $share, $investedCapital, $macroState),
            'Nothing substitutable is being sold, so no price is cut by building.',
        );
        $this->assertLessThan(
            0.16,
            $this->metrics->calculateMarginalReturn($utility, 0.16, $investedCapital, $macroState),
            'The plant has still outrun the territory it serves, and the next unit of it earns less.',
        );
    }

    private function scaledFirm(string $ticker): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setIndustry('Auto Manufacturers');
        $stock->setSamRatio('1.00');
        $stock->setTotalRevenue('80000000000');
        $stock->setAssetTurnover('1.0000');

        return $stock;
    }

    public function testGetIndustryDepreciationRate(): void
    {
        $this->assertSame(0.02, $this->metrics->getIndustryDepreciationRate('Banks - Diversified'));
        $this->assertSame(0.08, $this->metrics->getIndustryDepreciationRate('Auto Manufacturers'));
        $this->assertSame(0.05, $this->metrics->getIndustryDepreciationRate('NonExistentIndustryFallback'));
    }

    public function testCalculateOperatingBaseFloor(): void
    {
        // When both revenue and equity are tiny, floor at 10M
        $this->assertSame(10_000_000.0, $this->metrics->calculateOperatingBase(100.0, 500.0));
        // When revenue is larger
        $this->assertSame(50_000_000.0, $this->metrics->calculateOperatingBase(50_000_000.0, 10_000_000.0));
        // When equity is larger
        $this->assertSame(80_000_000.0, $this->metrics->calculateOperatingBase(20_000_000.0, 80_000_000.0));
    }

    public function testCalculateLiveInvestedCapitalFloors(): void
    {
        // Equity 100, Debt 50, Treasury 20 -> 100 + 50 - 20 = 130
        $this->assertSame(130.0, $this->metrics->calculateLiveInvestedCapital(100.0, 50.0, 20.0));

        // When Treasury is massive (e.g., Equity 100, Debt 0, Treasury 90 -> 10), floor at 50% Equity (50.0)
        $this->assertSame(50.0, $this->metrics->calculateLiveInvestedCapital(100.0, 0.0, 90.0));

        // Edge case: all zero -> floor at 1.0
        $this->assertSame(1.0, $this->metrics->calculateLiveInvestedCapital(0.0, 0.0, 0.0));
    }

    public function testRevenueGeneratingCapitalExcludesGoodwillAndCappedConstruction(): void
    {
        // 100 of capital, 10 under construction, 15 of goodwill: 75 runs plant.
        $this->assertSame(75.0, CorporateMetrics::revenueGeneratingCapital(100.0, 10.0, 15.0));

        // Construction beyond the cap still leaves the plant already running: at most 25% is deducted.
        $this->assertSame(75.0, CorporateMetrics::revenueGeneratingCapital(100.0, 60.0, 0.0));

        // Writing goodwill off moves capital and goodwill together, so the productive base does not move.
        $this->assertSame(
            CorporateMetrics::revenueGeneratingCapital(100.0, 0.0, 15.0),
            CorporateMetrics::revenueGeneratingCapital(85.0, 0.0, 0.0),
        );
    }

    public function testMarketSaturationPenaltyPenroseQuadraticFriction(): void
    {
        $stock = new Stock();
        $stock->setSamRatio('1.0');
        $stock->setSystemicImportance('default');

        $macro = new MacroStateDTO(nominalGdpIndex: 1.0);

        // 1. Below optimal threshold (TAM = 1T, InvestedCapital = 250B -> 25% share <= 50% optimal threshold)
        $zeroPenalty = $this->metrics->calculateMarketSaturationPenalty($stock, 250_000_000_000.0, $macro);
        $this->assertSame(0.0, $zeroPenalty);

        // 2. Above optimal threshold (InvestedCapital = 750B -> 75% share > 50% optimal threshold)
        $penalty75 = $this->metrics->calculateMarketSaturationPenalty($stock, 750_000_000_000.0, $macro);
        $this->assertGreaterThan(0.0, $penalty75);

        // 3. Monopolistic scale (InvestedCapital = 900B -> 90% share) -> penalty grows quadratically
        $penalty90 = $this->metrics->calculateMarketSaturationPenalty($stock, 900_000_000_000.0, $macro);
        $this->assertGreaterThan($penalty75 * 2.0, $penalty90);
    }

    /**
     * Saturation reads the market the industry ledger sells into: the seeded SAM grown with the sector's faded
     * secular drift. On nominal GDP alone a marketplace that kept pace with e-commerce read as 1.6 times its whole
     * market at Year 63 and could clear no growth hurdle while the ledger had it short of capacity.
     */
    public function testTheAddressableMarketGrowsWithTheSectorsSecularDrift(): void
    {
        $stock = new Stock();
        $stock->setIndustry('Internet Retail');
        $stock->setSamRatio('0.375');
        $stock->setSystemicImportance('base');

        $excess = IndustryShareLedger::secularExcessGrowth(Sectors::strategyFor('Internet Retail'), $stock);
        $this->assertGreaterThan(0.03, $excess, 'online retail outgrows the economy at the open');

        $this->assertSame(1.0, CorporateMetrics::secularMarketGrowth($stock, 0.0), 'the open is the seeded SAM');
        $this->assertEqualsWithDelta(
            exp(TimeSeries::fadedExcessIntegral($excess, 0.0, 63.0, FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS)),
            CorporateMetrics::secularMarketGrowth($stock, 63.0),
            1e-12,
            'the same faded drift the ledger grows trend demand by',
        );

        // Capital that kept pace with its market from a 29% opening share is still at 29% of it.
        $year63 = new MacroStateDTO(nominalGdpIndex: 10.6, totalTime: 63.0);
        $capital = 0.29 * CorporateMetrics::BASELINE_SECTOR_TAM * 10.6 * 0.375 * CorporateMetrics::secularMarketGrowth($stock, 63.0);

        $this->assertEqualsWithDelta(0.29, $this->metrics->calculateAddressableShare($stock, $capital, $year63), 1e-9);
        $this->assertSame(0.0, $this->metrics->calculateMarketSaturationPenalty($stock, $capital, $year63));
    }

    public function testTheMarginalReturnFallsOnlyPastTheOptimalShare(): void
    {
        $stock = new Stock();
        $stock->setSamRatio('1.0');
        $stock->setSystemicImportance('default');

        $macro = new MacroStateDTO(nominalGdpIndex: 1.0);

        // Below the optimal share (25% of the market) the next unit earns what the base earns.
        $this->assertEqualsWithDelta(0.18, $this->metrics->calculateMarginalReturn($stock, 0.18, 250_000_000_000.0, $macro), 1e-12);

        // Just past it (55%) it earns less, and still something.
        $returnSaturated = $this->metrics->calculateMarginalReturn($stock, 0.18, 550_000_000_000.0, $macro);
        $this->assertLessThan(0.18, $returnSaturated);
        $this->assertGreaterThan(0.0, $returnSaturated);
    }

    public function testMarginalReturnInternalisesTheFirmsOwnPriceEffectOnItsIndustry(): void
    {
        // A steel maker (substitutability 0.6) at a 30% addressable share, turning its capital 1.5x a year.
        $stock = new Stock();
        $stock->setIndustry('Steel');
        $stock->setSamRatio('1.0');
        $stock->setSystemicImportance('default');
        $stock->setTotalRevenue((string) (450_000_000_000.0));
        $macro = new MacroStateDTO(nominalGdpIndex: 1.0, corporateTaxRate: 0.21);
        $capital = 300_000_000_000.0;

        $substitutability = Sectors::getBusinessModelStrategy(Sectors::INDUSTRY_METRICS['Steel']['business_model'])->getIndustrySubstitutability();
        $lerner = 0.30 * $substitutability / FinancialConstants::COURNOT_DEMAND_ELASTICITY;
        $expectedHaircut = 1.5 * (1.0 - 0.21) * $lerner;

        $haircut = $this->metrics->calculateCournotPriceHaircut($stock, 0.30, $capital, $macro);
        $this->assertEqualsWithDelta($expectedHaircut, $haircut, 1e-12);
        $this->assertEqualsWithDelta(0.20 - $expectedHaircut, $this->metrics->calculateMarginalReturn($stock, 0.20, $capital, $macro), 1e-12);

        // The haircut grows with share: the same plant is a smaller cut for a smaller firm.
        $this->assertLessThan($haircut, $this->metrics->calculateCournotPriceHaircut($stock, 0.10, $capital, $macro));
        // It never takes the marginal return below zero.
        $this->assertSame(0.0, $this->metrics->calculateMarginalReturn($stock, 0.01, $capital, $macro));

        // No revenue, no share, a financial, or a non-substitutable model: price-taker, no haircut.
        $this->assertSame(0.0, $this->metrics->calculateCournotPriceHaircut($stock, 0.0, $capital, $macro));
        $bank = new Stock();
        $bank->setIndustry('Banks - Diversified');
        $bank->setTotalRevenue('450000000000');
        $this->assertSame(0.0, $this->metrics->calculateCournotPriceHaircut($bank, 0.30, $capital, $macro));
        $utility = new Stock();
        $utility->setIndustry('Utilities - Regulated Electric');
        $utility->setTotalRevenue('450000000000');
        $this->assertSame(0.0, $this->metrics->calculateCournotPriceHaircut($utility, 0.30, $capital, $macro));
    }

    public function testInterestCoverageRatioZeroHandling(): void
    {
        // Zero interest expense should return 999.0 safe maximum
        $this->assertSame(999.0, $this->metrics->calculateInterestCoverageRatio(5000.0, 0.0));
        $this->assertSame(999.0, $this->metrics->calculateInterestCoverageRatio(5000.0, -10.0));

        // Normal ratio
        $this->assertSame(5.0, $this->metrics->calculateInterestCoverageRatio(500.0, 100.0));
    }

    public function testSaturationSeverityAndLifeCyclePayoutRatio(): void
    {
        // Zero penalty -> 0 severity
        $severityZero = $this->metrics->calculateSaturationSeverity(0.0, 0.20);
        $this->assertSame(0.0, $severityZero);
        $this->assertSame(0.30, $this->metrics->calculateLifeCyclePayoutRatio(0.30, $severityZero));

        // Severe penalty (penalty 0.20 on 0.05 net return -> severity near 1.0)
        $severityMax = $this->metrics->calculateSaturationSeverity(0.20, 0.05);
        $this->assertGreaterThan(0.70, $severityMax);

        // Payout expands towards 85% ceiling
        $expandedPayout = $this->metrics->calculateLifeCyclePayoutRatio(0.30, 1.0);
        $this->assertSame(CorporateMetrics::LIFE_CYCLE_MAX_PAYOUT_RATIO, $expandedPayout);
    }
    public function testLeaseLiabilityScalesWithRevenueAndSectorIntensity(): void
    {
        $metrics = new CorporateMetrics();
        $this->assertEqualsWithDelta(600_000_000.0, $metrics->calculateLeaseLiability(1_000_000_000.0, 0.60), 1e-6);
        $this->assertSame(0.0, $metrics->calculateLeaseLiability(1_000_000_000.0, 0.0));
        $this->assertSame(0.0, $metrics->calculateLeaseLiability(-5.0, 0.60), 'negative revenue carries no lease book');
    }

    /**
     * The lease book is contracted (ASC 842-20-30-1): a firm that adds capacity signs its leases at once, and one that
     * needs less capacity keeps paying until the leases expire, running off at dt / term. A revenue multiple deleted
     * a restaurant chain's lease debt the quarter its sales fell, while the rent behind it stayed in fixed costs.
     */
    public function testTheLeaseBookIsSignedForCapacityAndRunsOffAtTheTerm(): void
    {
        $opened = CorporateMetrics::rollLeaseLiability(null, 1_000_000_000.0, 0.60, 0.25);
        $this->assertEqualsWithDelta(600_000_000.0, $opened, 1e-6, 'the first report opens the book at the capacity it runs');

        $grown = CorporateMetrics::rollLeaseLiability($opened, 1_200_000_000.0, 0.60, 0.25);
        $this->assertEqualsWithDelta(720_000_000.0, $grown, 1e-6, 'new capacity is leased at once');

        $slumped = CorporateMetrics::rollLeaseLiability($grown, 800_000_000.0, 0.60, 0.25);
        $this->assertEqualsWithDelta(720_000_000.0 * (1.0 - (0.25 / FinancialConstants::LEASE_TERM_YEARS)), $slumped, 1e-6, 'a quarter of the term runs off, not the fall in need');
        $this->assertEqualsWithDelta(480_000_000.0, CorporateMetrics::rollLeaseLiability(490_000_000.0, 800_000_000.0, 0.60, 0.25), 1e-6, 'and no further than the capacity still in use');
    }

}
