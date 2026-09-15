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

    /**
     * Cobb-Douglas diminishing marginal productivity above the optimal operating scale. It is one of the
     * three components of a marginal return, and the only one a firm growing WITH its market never pays:
     * the decay is a function of share, so at constant share the next unit of capital is as productive as
     * the last.
     */
    public function testScaleDiseconomiesAreANoOpBelowTheOptimalScaleAndADecayAboveIt(): void
    {
        $stock = $this->scaledFirm('SCAL');
        $threshold = FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD;

        // A firm that still has market left to grow into pays nothing.
        $this->assertSame(0.18, $this->metrics->applyScaleDiseconomies($stock, 0.18, $threshold * 0.5));
        $this->assertSame(0.18, $this->metrics->applyScaleDiseconomies($stock, 0.18, $threshold));

        // Past it the return decays as a power law in how far past it the capital has run.
        $mild = $this->metrics->applyScaleDiseconomies($stock, 0.18, $threshold * 1.5);
        $severe = $this->metrics->applyScaleDiseconomies($stock, 0.18, $threshold * 3.0);

        $this->assertLessThan(0.18, $mild);
        $this->assertLessThan($mild, $severe);
        $this->assertEqualsWithDelta(
            0.18 * pow(3.0, -FinancialConstants::CAPITAL_MARGINAL_ELASTICITY),
            $severe,
            1e-12,
            'ROIC_marginal = ROIC_base x (K / K_optimal)^(-alpha) at the default moat factor.'
        );

        // A firm whose position defends the extra scale pays a softer elasticity for the same capital.
        $titan = $this->scaledFirm('TITN');
        $titan->setSystemicImportance('titan');
        $this->assertGreaterThan(
            $this->metrics->applyScaleDiseconomies($stock, 0.18, $threshold * 3.0),
            $this->metrics->applyScaleDiseconomies($titan, 0.18, $threshold * 3.0)
        );

        // A return already at or below zero has nothing left to decay.
        $this->assertSame(0.0, $this->metrics->applyScaleDiseconomies($stock, -0.05, $threshold * 3.0));
    }

    /**
     * The invariant behind the fix: the earnings engine's structural growth gate and the treasury's
     * deployment gates must price the next share-taking dollar identically.
     *
     * The earnings gate composes it from the pieces (its structural return already carries the saturation
     * penalty from getTargetMetrics, so it nets only the Cournot haircut and then the scale decay); the
     * treasury calls calculateMarginalReturn. Those two have to be the same number, or a firm can clear one
     * gate on a return the very next gate in the same quarter refuses.
     */
    public function testBothGrowthGatesPriceTheNextShareTakingDollarIdentically(): void
    {
        $macroState = new MacroStateDTO(nominalGdpIndex: 1.0);
        $structuralReturn = 0.16;

        foreach ([0.20, 0.50, 0.90, 1.40] as $targetShare) {
            $stock = $this->scaledFirm('GATE');
            $investedCapital = $targetShare * FinancialConstants::BASELINE_SECTOR_TAM * (float) $stock->getSamRatio();
            $share = $this->metrics->calculateScaleRatio($investedCapital, $macroState->nominalGdpIndex, (float) $stock->getSamRatio());

            // How EarningsEngine::calculateGrowthCapEx builds its share-taking return.
            $earningsGate = $this->metrics->applyScaleDiseconomies(
                $stock,
                $structuralReturn - $this->metrics->calculateCournotPriceHaircut($stock, $share, $investedCapital, $macroState),
                $share
            );

            // How TreasuryEngine builds the same figure. The penalty is zero because the structural return
            // the earnings engine starts from already carries it.
            $treasuryGate = $this->metrics->calculateMarginalReturn($stock, $structuralReturn, 0.0, $investedCapital, $macroState);

            $this->assertEqualsWithDelta(
                $treasuryGate,
                $earningsGate,
                1e-12,
                sprintf('The two gates disagree at a scale ratio of %.2f.', $share)
            );
        }
    }

    /**
     * A statutory monopoly sells nothing substitutable, so adding capacity cuts no price and the Cournot
     * haircut is exactly zero. Before the decay was shared, that left such a firm with NO scale brake at all
     * on the earnings side — its share-taking return was its average return however far its plant had
     * outrun the territory it serves.
     */
    public function testANonSubstitutableFirmStillPaysForOutgrowingItsMarket(): void
    {
        $utility = $this->scaledFirm('WATT');
        $utility->setIndustry('Utilities - Regulated Electric');

        $macroState = new MacroStateDTO(nominalGdpIndex: 1.0);
        $investedCapital = 1.4 * FinancialConstants::BASELINE_SECTOR_TAM * (float) $utility->getSamRatio();
        $share = $this->metrics->calculateScaleRatio($investedCapital, $macroState->nominalGdpIndex, (float) $utility->getSamRatio());

        $this->assertSame(
            0.0,
            $this->metrics->calculateCournotPriceHaircut($utility, $share, $investedCapital, $macroState),
            'Nothing substitutable is being sold, so no price is cut by building.'
        );
        $this->assertLessThan(
            0.16,
            $this->metrics->applyScaleDiseconomies($utility, 0.16, $share),
            'The plant has still outrun the territory it serves, and the next unit of it earns less.'
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
