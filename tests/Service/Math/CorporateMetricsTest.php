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

        // 1. Below optimal threshold (TAM = 2T, InvestedCapital = 500B -> 25% share <= 50% optimal threshold)
        $zeroPenalty = $this->metrics->calculateMarketSaturationPenalty($stock, 500_000_000_000.0, $macro);
        $this->assertSame(0.0, $zeroPenalty);

        // 2. Above optimal threshold (InvestedCapital = 1.5T -> 75% share > 50% optimal threshold)
        $penalty75 = $this->metrics->calculateMarketSaturationPenalty($stock, 1_500_000_000_000.0, $macro);
        $this->assertGreaterThan(0.0, $penalty75);

        // 3. Monopolistic scale (InvestedCapital = 1.8T -> 90% share) -> penalty grows quadratically
        $penalty90 = $this->metrics->calculateMarketSaturationPenalty($stock, 1_800_000_000_000.0, $macro);
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

        // 1. Below optimal scale (InvestedCapital = 500B -> 25% share <= 50% optimal threshold)
        $returnNormal = $this->metrics->calculateMarginalReturn($stock, $trueReturn, $saturationPenalty, 500_000_000_000.0, $macro);
        $this->assertEqualsWithDelta(0.18, $returnNormal, 0.0001);

        // 2. Above optimal scale (InvestedCapital = 1.6T -> 80% share)
        $returnSaturated = $this->metrics->calculateMarginalReturn($stock, $trueReturn, $saturationPenalty, 1_600_000_000_000.0, $macro);
        $this->assertLessThan(0.18, $returnSaturated);
        $this->assertGreaterThan(0.0, $returnSaturated);
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
}
