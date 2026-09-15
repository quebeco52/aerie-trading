<?php

declare(strict_types=1);

namespace App\Tests\Service\Math;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use PHPUnit\Framework\TestCase;

class CorporateMetricsTest extends TestCase
{
    private CorporateMetrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = CorporateMetrics::getInstance();
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

    public function testCobbDouglasDiminishingMarginalReturn(): void
    {
        $stock = new Stock();
        $stock->setSamRatio('1.0');
        $stock->setSystemicImportance('default');

        $macro = new MacroStateDTO(nominalGdpIndex: 1.0);

        $trueReturn = 0.20; // 20% ROIC
        $saturationPenalty = 0.02;

        // 1. Below optimal scale (InvestedCapital = 250B -> 25% share <= 50% optimal threshold)
        $returnNormal = $this->metrics->calculateMarginalReturn($stock, $trueReturn, $saturationPenalty, 250_000_000_000.0, $macro);
        $this->assertEqualsWithDelta(0.18, $returnNormal, 0.0001);

        // 2. Above optimal scale (InvestedCapital = 800B -> 80% share)
        $returnSaturated = $this->metrics->calculateMarginalReturn($stock, $trueReturn, $saturationPenalty, 800_000_000_000.0, $macro);
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

        $substitutability = \App\Data\Sectors::getBusinessModelStrategy(\App\Data\Sectors::INDUSTRY_METRICS['Steel']['business_model'])->getIndustrySubstitutability();
        $lerner = 0.30 * $substitutability / FinancialConstants::COURNOT_DEMAND_ELASTICITY;
        $expectedHaircut = 1.5 * (1.0 - 0.21) * $lerner;

        $haircut = $this->metrics->calculateCournotPriceHaircut($stock, 0.30, $capital, $macro);
        $this->assertEqualsWithDelta($expectedHaircut, $haircut, 1e-12);
        $this->assertEqualsWithDelta(0.20 - $expectedHaircut, $this->metrics->calculateMarginalReturn($stock, 0.20, 0.0, $capital, $macro), 1e-12);

        // The haircut grows with share: the same plant is a smaller cut for a smaller firm.
        $this->assertLessThan($haircut, $this->metrics->calculateCournotPriceHaircut($stock, 0.10, $capital, $macro));
        // It never takes the marginal return below zero.
        $this->assertSame(0.0, $this->metrics->calculateMarginalReturn($stock, 0.01, 0.0, $capital, $macro));

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
        $this->assertSame(FinancialConstants::LIFE_CYCLE_MAX_PAYOUT_RATIO, $expandedPayout);
    }
    public function testLeaseLiabilityScalesWithRevenueAndSectorIntensity(): void
    {
        $metrics = new CorporateMetrics();
        $this->assertEqualsWithDelta(600_000_000.0, $metrics->calculateLeaseLiability(1_000_000_000.0, 0.60), 1e-6);
        $this->assertSame(0.0, $metrics->calculateLeaseLiability(1_000_000_000.0, 0.0));
        $this->assertSame(0.0, $metrics->calculateLeaseLiability(-5.0, 0.60), 'negative revenue carries no lease book');
    }

}
