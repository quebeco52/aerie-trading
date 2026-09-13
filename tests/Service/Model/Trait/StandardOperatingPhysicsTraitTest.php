<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Trait;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\DTO\ModelParameters;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Tests\Support\Model\BareStandardModel;
use App\Tests\Support\Model\ConfiguredStandardModel;
use PHPUnit\Framework\TestCase;

/**
 * The shared operating physics: the largest single body of behaviour in the model layer and, until now,
 * reachable only through whichever of the 48 sector models happened to leave a given default in place.
 *
 * Twenty of its constants sit behind `defined()` gates. Each test below addresses the fallback through
 * {@see BareStandardModel} and, where the gate changes an outcome rather than just a number, checks that
 * {@see ConfiguredStandardModel} actually reaches the calculation — a gate that silently stopped being
 * read would otherwise look identical to one that works.
 */
final class StandardOperatingPhysicsTraitTest extends TestCase
{
    private BareStandardModel $model;
    private MathUtility $math;

    protected function setUp(): void
    {
        $this->model = new BareStandardModel();
        $this->math = new MathUtility();
    }

    // --- Template method: the price/volume cost split ---

    /**
     * Price is not produced. A rent escalator or a spot-rate spike on a fixed fleet adds revenue without
     * adding a unit of cost, so the variable cost ratio may only be applied to the volume half of revenue.
     */
    public function testVariableCostsAreChargedOnVolumeRevenueOnly(): void
    {
        $stock = (new Stock())->setTicker('SPLIT');
        $macro = new MacroStateDTO();

        $allVolume = $this->model->computeActualFinancials($stock, 1_000_000.0, 0.60, 200_000.0, 0.10, $macro, $this->math);
        $this->assertEqualsWithDelta(600_000.0, $allVolume->actualVariableCosts, 0.0001, 'With no price component the ratio applies to all revenue.');
        $this->assertEqualsWithDelta(200_000.0, $allVolume->ebit, 0.0001, 'EBIT is revenue less fixed and variable costs.');

        // A quarter of the same revenue arriving as pure price carries no cost with it.
        $this->model->stubPriceRevenue = 250_000.0;
        $withPrice = $this->model->computeActualFinancials($stock, 1_000_000.0, 0.60, 200_000.0, 0.10, $macro, $this->math);
        $this->assertEqualsWithDelta(750_000.0 * 0.60, $withPrice->actualVariableCosts, 0.0001, 'Only the volume half of revenue attracts variable cost.');
        $this->assertGreaterThan($allVolume->ebit, $withPrice->ebit, 'Costless price revenue must drop straight to EBIT.');
        $this->assertEqualsWithDelta(250_000.0, $withPrice->priceRevenue, 0.0001);

        // The identity has to hold whatever the split.
        $this->assertEqualsWithDelta(
            $withPrice->actualRevenue - 200_000.0 - $withPrice->actualVariableCosts,
            $withPrice->ebit,
            0.0001,
            'The EBIT identity must survive the split.'
        );
    }

    /**
     * A sector cannot report more price than it reported revenue, nor negative price: either would make the
     * volume base negative and hand the firm a variable cost credit.
     */
    public function testPriceRevenueIsClampedIntoTheRevenueItCameFrom(): void
    {
        $stock = (new Stock())->setTicker('CLAMP');
        $macro = new MacroStateDTO();

        $this->model->stubPriceRevenue = 5_000_000.0;
        $overstated = $this->model->computeActualFinancials($stock, 1_000_000.0, 0.60, 0.0, 0.10, $macro, $this->math);
        $this->assertSame(1_000_000.0, $overstated->priceRevenue, 'Price revenue cannot exceed the revenue reported.');
        $this->assertSame(0.0, $overstated->actualVariableCosts, 'An all-price quarter carries no variable cost.');

        $this->model->stubPriceRevenue = -400_000.0;
        $negative = $this->model->computeActualFinancials($stock, 1_000_000.0, 0.60, 0.0, 0.10, $macro, $this->math);
        $this->assertSame(0.0, $negative->priceRevenue, 'Negative price revenue floors at zero.');
        $this->assertEqualsWithDelta(600_000.0, $negative->actualVariableCosts, 0.0001, 'It must not inflate the volume base beyond revenue.');
    }

    public function testMarginClampBoundsTheCostRatioOnBothSides(): void
    {
        $this->assertSame(0.60, $this->model->clampMargin(0.60));
        $this->assertSame(0.01, $this->model->clampMargin(-2.0), 'A negative cost ratio is not physical.');
        $this->assertSame(1.50, $this->model->clampMargin(9.0), 'The ratio caps so a cost blowout cannot become unbounded.');
        $this->assertSame(0.25, $this->model->clampMargin(0.10, 0.25, 0.90), 'Explicit bounds override the defaults.');
    }

    // --- Analyst coverage ---

    /**
     * Systemic importance buys coverage: a titan is better understood than an anonymous small cap, and both
     * the base visibility and the floor move with it. Everything stays a probability.
     */
    public function testCoverageProfileLiftsWithSystemicImportanceAndStaysBounded(): void
    {
        $plain = (new Stock())->setTicker('SMALL');
        $base = $this->model->getCoverageProfile($plain);
        $this->assertSame(0.20, $base->baseVisibility, 'The fallback visibility applies when no constant is declared.');
        $this->assertSame(0.06, $base->errorStdDev);
        $this->assertSame(0.0, $base->minVisibility, 'With no declared floor a sector can go dark.');

        $systemic = (new Stock())->setTicker('SYS');
        $systemic->setSystemicImportance('systemic');
        $titan = (new Stock())->setTicker('TITAN');
        $titan->setSystemicImportance('titan');

        $this->assertGreaterThan($base->baseVisibility, $this->model->getCoverageProfile($systemic)->baseVisibility);
        $this->assertGreaterThan(
            $this->model->getCoverageProfile($systemic)->baseVisibility,
            $this->model->getCoverageProfile($titan)->baseVisibility,
            'A titan must be better covered than a merely systemic firm.'
        );
        $this->assertGreaterThan($base->minVisibility, $this->model->getCoverageProfile($titan)->minVisibility, 'The floor rises with importance too.');

        // A declared sector profile reaches the calculation, and the uplift cannot push past certainty.
        $configured = new ConfiguredStandardModel();
        $this->assertSame(0.55, $configured->getCoverageProfile($plain)->baseVisibility, 'A declared visibility must be used.');
        $this->assertSame(0.02, $configured->getCoverageProfile($plain)->errorStdDev);
        $this->assertLessThanOrEqual(1.0, $configured->getCoverageProfile($titan)->baseVisibility, 'Visibility is a probability and must clamp at one.');
        $this->assertLessThanOrEqual(1.0, $configured->getCoverageProfile($titan)->minVisibility);
        $this->assertLessThanOrEqual(
            $configured->getCoverageProfile($titan)->baseVisibility,
            $configured->getCoverageProfile($titan)->minVisibility,
            'The floor can never exceed the base visibility.'
        );
    }

    // --- Demand transmission ---

    /**
     * A spot business feels the cycle the quarter it turns; a long-lag business is still working through an
     * older book. At zero lag the macro series must pass through untouched and nothing is persisted.
     */
    public function testLaggedOutputGapPassesThroughAtZeroLagAndPartiallyAdjustsOtherwise(): void
    {
        $macro = new MacroStateDTO(outputGapEma: -0.04);

        $spot = (new Stock())->setTicker('SPOT');
        $this->assertSame(-0.04, $this->model->resolveLaggedOutputGap($spot, $macro), 'A spot business sees the macro gap directly.');
        $this->assertNull($spot->getLaggedDemandGap(), 'With no lag there is no state to carry.');

        // A firm entering the downturn from trend only partially adjusts in one quarter.
        $lagged = new ConfiguredStandardModel();
        $builder = (new Stock())->setTicker('BUILD');
        $builder->setLaggedDemandGap(0.0);
        $firstQuarter = $lagged->resolveLaggedOutputGap($builder, $macro);

        $this->assertGreaterThan(-0.04, $firstQuarter, 'A lagged firm cannot arrive at the macro gap in one quarter.');
        $this->assertLessThan(0.0, $firstQuarter, 'But it must move toward it.');
        $this->assertEqualsWithDelta($firstQuarter, $builder->getLaggedDemandGap(), 0.0000001, 'The lag state persists on the stock.');

        // Held at the same gap, the firm converges rather than oscillating or overshooting.
        $previous = $firstQuarter;
        for ($quarter = 0; $quarter < 20; $quarter++) {
            $next = $lagged->resolveLaggedOutputGap($builder, $macro);
            $this->assertLessThan($previous, $next, 'Convergence must be monotone.');
            $this->assertGreaterThanOrEqual(-0.04, $next, 'It must never overshoot the macro gap.');
            $previous = $next;
        }
        $this->assertEqualsWithDelta(-0.04, $previous, 0.005, 'After five years the firm has substantially arrived.');

        // A firm with no history starts at the macro gap rather than at zero, so it is not born mid-cycle.
        $fresh = (new Stock())->setTicker('FRESH');
        $this->assertEqualsWithDelta(-0.04, $lagged->resolveLaggedOutputGap($fresh, $macro), 0.0000001, 'A firm with no cost history starts at the prevailing gap.');
    }

    /**
     * A stronger domestic currency prices exports out and cheapens the importer's shelf, so the shift is
     * negative on the way up, positive on the way down, and scaled by how much revenue is actually exposed.
     */
    public function testFxDemandShiftIsSignedAgainstTheCurrencyAndScaledByExposure(): void
    {
        $atBase = new MacroStateDTO(exchangeRateIndexEma: FinancialConstants::FX_INDEX_BASE);
        $this->assertSame(-0.0, $this->model->resolveFxDemandShift($atBase), 'At the base index there is no FX effect.');

        $strong = new MacroStateDTO(exchangeRateIndexEma: 110.0);
        $weak = new MacroStateDTO(exchangeRateIndexEma: 90.0);

        $this->assertLessThan(0.0, $this->model->resolveFxDemandShift($strong), 'A stronger currency must hurt demand.');
        $this->assertGreaterThan(0.0, $this->model->resolveFxDemandShift($weak), 'A weaker currency must help it.');
        $this->assertEqualsWithDelta(
            -$this->model->resolveFxDemandShift($strong),
            $this->model->resolveFxDemandShift($weak),
            0.0000001,
            'Equal and opposite moves must produce equal and opposite shifts.'
        );

        // Exposure is the multiplier: a heavily exposed exporter feels the same move far more.
        $exposed = new ConfiguredStandardModel();
        $this->assertLessThan($this->model->resolveFxDemandShift($strong), $exposed->resolveFxDemandShift($strong), 'More exposed revenue means a larger hit.');
        $this->assertSame(0.0, $this->model->resolveFxDemandShift($strong, 0.0), 'A purely domestic firm is unaffected.');
        $this->assertEqualsWithDelta(-0.10, $this->model->resolveFxDemandShift($strong, 1.0), 0.0000001, 'At full exposure the shift is the currency move itself.');
    }

    // --- Input cost basket ---

    /**
     * The basket is an exposure-weighted average of the tracked markets, so an unexposed channel cannot
     * move the cost base and a negative share cannot become a subsidy.
     */
    public function testInputCostDeviationWeightsOnlyDeclaredExposures(): void
    {
        $calm = new MacroStateDTO(
            producerPriceInflationEma: MacroEngine::TARGET_INFLATION,
            wageGrowthEma: MacroEngine::TFP_DRIFT + MacroEngine::TARGET_INFLATION
        );
        $this->assertEqualsWithDelta(0.0, $this->model->resolveInputCostDeviation($calm), 0.0000001, 'At baseline every channel sits at zero.');

        // The configured model buys only energy and metals, so an agricultural spike must not touch it.
        $configured = new ConfiguredStandardModel();
        $agriSpike = new MacroStateDTO(
            agriculturalCommodityIndexEma: 200.0,
            producerPriceInflationEma: MacroEngine::TARGET_INFLATION,
            wageGrowthEma: MacroEngine::TFP_DRIFT + MacroEngine::TARGET_INFLATION
        );
        $this->assertEqualsWithDelta(0.0, $configured->resolveInputCostDeviation($agriSpike), 0.0000001, 'A market the firm does not buy in cannot move its costs.');
        $this->assertGreaterThan(0.0, $this->model->resolveInputCostDeviation($agriSpike), 'The default basket does buy agricultural inputs.');

        // A metals spike reaches it in proportion to the declared share.
        $metalsSpike = new MacroStateDTO(
            industrialMetalsIndexEma: 150.0,
            producerPriceInflationEma: MacroEngine::TARGET_INFLATION,
            wageGrowthEma: MacroEngine::TFP_DRIFT + MacroEngine::TARGET_INFLATION
        );
        $this->assertEqualsWithDelta(0.20 * 0.50, $configured->resolveInputCostDeviation($metalsSpike), 0.0000001, 'The deviation is the share times the relative move.');
    }

    public function testInputPriceDeviationsAreZeroAtEachChannelBaseline(): void
    {
        $baseline = new MacroStateDTO(
            energyCostPushLag: 0.0,
            industrialMetalsIndexEma: 100.0,
            agriculturalCommodityIndexEma: 100.0,
            freightRateIndexEma: 100.0,
            producerPriceInflationEma: MacroEngine::TARGET_INFLATION,
            wageGrowthEma: MacroEngine::TFP_DRIFT + MacroEngine::TARGET_INFLATION
        );

        foreach ($this->model->resolveInputPriceDeviations($baseline) as $channel => $deviation) {
            $this->assertEqualsWithDelta(0.0, $deviation, 0.0000001, "The {$channel} channel must read zero at its own baseline.");
        }

        // Wages are measured against productivity plus target inflation, not against zero: real unit labour
        // costs only rise when wage growth outruns what productivity pays for.
        $productiveWages = new MacroStateDTO(wageGrowthEma: MacroEngine::TFP_DRIFT + MacroEngine::TARGET_INFLATION + 0.01);
        $this->assertEqualsWithDelta(0.01, $this->model->resolveInputPriceDeviations($productiveWages)['labor'], 0.0000001, 'Only wage growth above productivity is a cost push.');
    }

    /**
     * On the way up costs lead prices, which is the squeeze; a price setter recovers more of it than a price
     * taker, and neither recovers all of it.
     */
    public function testInputCostDragSqueezesMarginAndIsSoftenedByPricingPower(): void
    {
        $stock = (new Stock())->setTicker('DRAG');
        $spike = new MacroStateDTO(
            industrialMetalsIndexEma: 200.0,
            producerPriceInflationEma: MacroEngine::TARGET_INFLATION,
            wageGrowthEma: MacroEngine::TFP_DRIFT + MacroEngine::TARGET_INFLATION
        );

        $taker = $this->model->exposeInputCostDrag($stock, $spike, $this->model->exposeStreamContext([], $this->math), 0.0, 0.60);
        $setter = $this->model->exposeInputCostDrag($stock, $spike, $this->model->exposeStreamContext([], $this->math), 1.0, 0.60);

        $this->assertGreaterThan(0.0, $taker, 'A cost spike must raise the variable cost ratio.');
        $this->assertLessThan($taker, $setter, 'A price setter recovers more of the spike than a price taker.');
        $this->assertGreaterThan(0.0, $setter, 'Pass-through is incomplete: even a price setter takes some of it.');

        // A collapse in input prices is a windfall of the same shape, not an asymmetric no-op.
        $collapse = new MacroStateDTO(
            industrialMetalsIndexEma: 50.0,
            producerPriceInflationEma: MacroEngine::TARGET_INFLATION,
            wageGrowthEma: MacroEngine::TFP_DRIFT + MacroEngine::TARGET_INFLATION
        );
        $this->assertLessThan(0.0, $this->model->exposeInputCostDrag($stock, $collapse, $this->model->exposeStreamContext([], $this->math), 0.0, 0.60), 'Falling input prices must widen margin.');

        // However violent the move, one quarter's drag is bounded.
        $crisis = new MacroStateDTO(
            industrialMetalsIndexEma: 100_000.0,
            producerPriceInflationEma: MacroEngine::TARGET_INFLATION,
            wageGrowthEma: MacroEngine::TFP_DRIFT + MacroEngine::TARGET_INFLATION
        );
        $this->assertLessThanOrEqual(0.50, $this->model->exposeInputCostDrag($stock, $crisis, $this->model->exposeStreamContext([], $this->math), 0.0, 0.60), 'The drag is clamped so one quarter cannot destroy the cost base.');
    }

    /**
     * Selling prices track the inflation measure the sector actually prices off: a services business follows
     * core services, not goods breakevens.
     */
    public function testExpectedInflationBasisSelectsTheDeclaredMeasure(): void
    {
        $macro = new MacroStateDTO(inflationEma: 0.031, supercoreInflationEma: 0.042, tipsBreakevenEma: 0.023);

        $this->assertSame(0.023, $this->model->resolveExpectedInflationBasis($macro), 'The fallback is goods breakevens.');
        $this->assertSame(0.042, (new ConfiguredStandardModel())->resolveExpectedInflationBasis($macro), 'A services model must price off supercore.');
    }

    public function testPricingPowerFallsBackToTheMedianAndStaysAProbability(): void
    {
        $stock = (new Stock())->setTicker('PP_NO_OVERRIDE');

        $this->assertSame(0.5, $this->model->exposePricingPower($stock), 'An undeclared sector is the median firm.');
        $this->assertSame(0.90, (new ConfiguredStandardModel())->exposePricingPower($stock), 'A declared index must reach the calculation.');
    }

    // --- Working capital ---

    /**
     * The day counts must reconstruct the cash conversion cycle they were derived from, or the balance sheet
     * and the intensity a sector declares stop agreeing.
     */
    public function testWorkingCapitalDaysReconstructTheDeclaredCycle(): void
    {
        $stock = (new Stock())->setTicker('WC');
        $days = $this->model->getWorkingCapitalDays($stock);
        $expectedCycle = $this->model->getWorkingCapitalIntensity($stock) * FinancialConstants::DAYS_PER_YEAR;

        $this->assertEqualsWithDelta(
            $expectedCycle,
            $days['dso'] + $days['dio'] - $days['dpo'],
            0.0000001,
            'DSO + DIO - DPO must return the declared cycle.'
        );
        foreach (['dso', 'dio', 'dpo'] as $leg) {
            $this->assertGreaterThan(0.0, $days[$leg], "A positive cycle ties up cash in {$leg}.");
        }
        $this->assertGreaterThan($days['dio'], $days['dso'], 'The receivable share exceeds the inventory share by construction.');
    }

    /**
     * A subscription or marketplace business collects before it pays: there is nothing tied up on the asset
     * side, and the whole cycle is a payables float.
     */
    public function testNegativeWorkingCapitalBecomesAPurePayablesFloat(): void
    {
        $float = new class extends BareStandardModel {
            public function getWorkingCapitalIntensity(Stock $stock): float
            {
                return -0.08;
            }
        };

        $days = $float->getWorkingCapitalDays((new Stock())->setTicker('SUB'));
        $this->assertSame(0.0, $days['dso'], 'A prepaid business has no receivables cycle.');
        $this->assertSame(0.0, $days['dio'], 'Nor inventory.');
        $this->assertEqualsWithDelta(0.08 * FinancialConstants::DAYS_PER_YEAR, $days['dpo'], 0.0000001, 'The whole cycle is supplier and customer float.');
        $this->assertEqualsWithDelta(
            -0.08 * FinancialConstants::DAYS_PER_YEAR,
            $days['dso'] + $days['dio'] - $days['dpo'],
            0.0000001,
            'The identity holds through the sign change.'
        );
    }

    // --- Asset decay ---

    /**
     * The decay physics is opt-in. A model declaring neither rate must not touch the margin at all, which is
     * what lets a financial balance sheet compose this trait without acquiring plant depreciation.
     */
    public function testAssetDecayIsANoOpForAModelDeclaringNoRates(): void
    {
        $stock = (new Stock())->setTicker('NODECAY');
        $stock->setOperatingMargin('0.20');

        $this->model->applyAssetDepreciationDecay($stock, 0.10, EarningsEngine::QUARTERLY_TIME_STEP);
        $this->assertSame('0.20', $stock->getOperatingMargin(), 'Starving a model with no decay physics must change nothing.');

        $this->model->applyAssetDepreciationDecay($stock, 5.00, EarningsEngine::QUARTERLY_TIME_STEP);
        $this->assertSame('0.20', $stock->getOperatingMargin(), 'Nor must over-investing in it.');
    }

    /**
     * Under-investment decays margin toward the sector floor and never through it; over-investment climbs
     * toward the ceiling with diminishing returns and never past it.
     */
    public function testUnderInvestmentDecaysToTheFloorAndOverInvestmentConvergesToTheCeiling(): void
    {
        $model = new ConfiguredStandardModel();

        $starved = (new Stock())->setTicker('STARVE');
        $starved->setOperatingMargin('0.30');
        $model->applyAssetDepreciationDecay($starved, 0.0, EarningsEngine::QUARTERLY_TIME_STEP);
        $this->assertLessThan(0.30, (float) $starved->getOperatingMargin(), 'Under-investment must cost margin.');

        for ($quarter = 0; $quarter < 200; $quarter++) {
            $model->applyAssetDepreciationDecay($starved, 0.0, EarningsEngine::QUARTERLY_TIME_STEP);
        }
        $this->assertEqualsWithDelta(
            ConfiguredStandardModel::MIN_OPERATING_MARGIN_FLOOR,
            (float) $starved->getOperatingMargin(),
            0.0001,
            'Sustained starvation converges on the structural floor.'
        );
        $this->assertGreaterThanOrEqual(ConfiguredStandardModel::MIN_OPERATING_MARGIN_FLOOR, (float) $starved->getOperatingMargin(), 'It must never fall through it.');

        $invested = (new Stock())->setTicker('MODERN');
        $invested->setOperatingMargin('0.10');
        for ($quarter = 0; $quarter < 200; $quarter++) {
            $model->applyAssetDepreciationDecay($invested, 3.0, EarningsEngine::QUARTERLY_TIME_STEP);
        }
        $this->assertEqualsWithDelta(
            ConfiguredStandardModel::MAX_OPERATING_MARGIN_CEILING,
            (float) $invested->getOperatingMargin(),
            0.0001,
            'Sustained modernization converges on the structural ceiling.'
        );
        $this->assertLessThanOrEqual(ConfiguredStandardModel::MAX_OPERATING_MARGIN_CEILING, (float) $invested->getOperatingMargin(), 'It must never exceed it.');

        // Replacement-rate investment is the neutral point: neither decay nor gain.
        $held = (new Stock())->setTicker('HOLD');
        $held->setOperatingMargin('0.22');
        $model->applyAssetDepreciationDecay($held, 1.0, EarningsEngine::QUARTERLY_TIME_STEP);
        $this->assertSame('0.22', $held->getOperatingMargin(), 'Investing exactly at replacement holds the margin.');

        // A firm already above its ceiling is left alone rather than dragged down to it.
        $exceptional = (new Stock())->setTicker('ABOVE');
        $exceptional->setOperatingMargin('0.55');
        $model->applyAssetDepreciationDecay($exceptional, 3.0, EarningsEngine::QUARTERLY_TIME_STEP);
        $this->assertSame('0.55', $exceptional->getOperatingMargin(), 'Modernization cannot add margin above the ceiling, but must not remove any either.');
    }

    /**
     * The decay is a rate per quarter, so a longer step must move the margin further: the physics has to be
     * expressed in time, not in calls.
     */
    public function testAssetDecayScalesWithTheTimeStep(): void
    {
        $model = new ConfiguredStandardModel();

        $quarter = (new Stock())->setTicker('Q');
        $quarter->setOperatingMargin('0.30');
        $model->applyAssetDepreciationDecay($quarter, 0.0, EarningsEngine::QUARTERLY_TIME_STEP);

        $year = (new Stock())->setTicker('Y');
        $year->setOperatingMargin('0.30');
        $model->applyAssetDepreciationDecay($year, 0.0, EarningsEngine::QUARTERLY_TIME_STEP * 4.0);

        $this->assertLessThan((float) $quarter->getOperatingMargin(), (float) $year->getOperatingMargin(), 'A year of starvation must cost more than a quarter of it.');
    }

    // --- Reporting calendar and inherited scalars ---

    /**
     * The fiscal offset is a quarter index, so the trait must normalise whatever the tuning table carries
     * into 0..3 — including a negative offset, where PHP's own modulo would otherwise return a negative
     * quarter and index off the front of the seasonality array.
     */
    public function testFiscalYearStartQuarterNormalisesIntoTheYear(): void
    {
        $this->assertSame(0, $this->model->getFiscalYearStartQuarter((new Stock())->setTicker('CAL_DEFAULT')), 'A sector with no declared offset reports on the calendar year.');

        // No ticker in the tuning table declares an offset today, so the raw value is injected here to reach
        // the trait's own normalisation rather than to restate it.
        $tuned = new class extends BareStandardModel {
            public float $rawOffset = 0.0;

            protected function resolveModelParameters(Stock $stock, array $defaults = []): ModelParameters
            {
                return new ModelParameters([ModelParam::FiscalYearStartQuarter->value => $this->rawOffset]);
            }
        };

        $stock = (new Stock())->setTicker('FISCAL');
        foreach ([[0.0, 0], [1.0, 1], [3.0, 3], [4.0, 0], [5.0, 1], [-1.0, 3], [-5.0, 3], [7.0, 3]] as [$raw, $expected]) {
            $tuned->rawOffset = $raw;
            $quarter = $tuned->getFiscalYearStartQuarter($stock);

            $this->assertSame($expected, $quarter, "A raw offset of {$raw} must normalise to quarter {$expected}.");
            $this->assertGreaterThanOrEqual(0, $quarter, 'A negative offset must never survive into a quarter index.');
            $this->assertLessThanOrEqual(3, $quarter, 'Nor may an offset run past the end of the year.');
        }
    }

    /**
     * Seasonality is a redistribution of the year, not a change to it: the four factors must sum to four or
     * a sector silently gains or loses annual revenue.
     */
    public function testDefaultSeasonalityIsFlatAndConservesTheYear(): void
    {
        $factors = $this->model->getSeasonalityFactors();

        $this->assertCount(4, $factors);
        $this->assertEqualsWithDelta(4.0, array_sum($factors), 0.0000001, 'Seasonality must conserve annual revenue.');
        foreach ($factors as $quarter => $factor) {
            $this->assertSame(1.0, $factor, "The default sector has no seasonality in Q{$quarter}.");
        }
    }

    /**
     * The surprise blend must be a partition, or the earnings surprise the market reacts to is scaled by
     * something other than one.
     */
    public function testSurpriseBlendWeightsPartitionTheSurprise(): void
    {
        $weights = $this->model->getSurpriseBlendWeights();

        $this->assertArrayHasKey('eps_weight', $weights);
        $this->assertArrayHasKey('revenue_weight', $weights);
        $this->assertEqualsWithDelta(1.0, $weights['eps_weight'] + $weights['revenue_weight'], 0.0000001, 'The blend must sum to one.');
    }

    /**
     * An operating company's assets are plant and a trade cycle, not credit: there is nothing to charge off,
     * and that zero is what keeps the CECL machinery off a non-financial balance sheet.
     */
    public function testNonFinancialsCarryNoCreditLossRate(): void
    {
        $this->assertSame(0.0, $this->model->getThroughTheCycleCreditLossRate(), 'A manufacturer has no loan book to provision against.');
        $this->assertSame(1.0, $this->model->getCreditLossHorizonYears(), 'The horizon still has to be a usable number.');
    }

    public function testInheritedOperatingScalarsAreTheDocumentedDefaults(): void
    {
        $stock = (new Stock())->setTicker('SCALARS');

        $this->assertSame(0.02, $this->model->getSecularGrowthRate($stock), 'Trend growth for a firm with no declared secular story.');
        $this->assertSame(1.5, $this->model->getCapexCyclicality(), 'Capex is more cyclical than output.');
        $this->assertGreaterThan(1.0, $this->model->getCapexCyclicality(), 'Investment must swing harder than the cycle it responds to.');
        $this->assertSame(0.10, $this->model->getWorkingCapitalIntensity($stock));
        $this->assertSame(0.33, $this->model->getCapExCompletionRate($stock), 'Construction in progress completes over about three quarters.');
        $this->assertSame(4.0, $this->model->getMarginReversionSpeed());

        // The tax hook is a pass-through unless a sector has a genuine structural rate advantage.
        $this->assertSame(0.21, $this->model->getEffectiveTaxRate(0.21), 'The macro rate applies unless a sector overrides it.');
        $this->assertSame(0.35, $this->model->getEffectiveTaxRate(0.35), 'It must track the macro rate rather than pin a constant.');
    }

    /**
     * Reporting incentives are a management property, so the propensity clamps into a probability whatever
     * the sector or the ticker tuning declares.
     */
    public function testEarningsManagementPropensityStaysAProbability(): void
    {
        $stock = (new Stock())->setTicker('EM_NO_OVERRIDE');

        $this->assertSame(FinancialConstants::DEFAULT_EARNINGS_MANAGEMENT_PROPENSITY, $this->model->getEarningsManagementPropensity($stock));
        $this->assertSame(0.90, (new ConfiguredStandardModel())->getEarningsManagementPropensity($stock), 'A declared propensity must reach the accrual channel.');

        $extreme = new class extends BareStandardModel {
            public const EARNINGS_MANAGEMENT_PROPENSITY = 9.0;
        };
        $this->assertSame(1.0, $extreme->getEarningsManagementPropensity($stock), 'The propensity clamps at certainty.');
    }
}
