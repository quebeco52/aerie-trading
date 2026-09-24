<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\InitialMarket;
use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\RefiningBusinessModel;
use PHPUnit\Framework\TestCase;

final class RefiningBusinessModelTest extends TestCase
{
    /** The engine's variable cost ratio on the baseline barrel: feedstock plus about three points of fuel and chemicals. */
    private const VARIABLE_COST_RATIO = 0.835;

    private RefiningBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new RefiningBusinessModel();
    }

    private function fixedDraws(float $z = 0.0): MathUtility
    {
        return new class ($z) extends MathUtility {
            public function __construct(private readonly float $z)
            {
            }

            public function generatePersistentZ(float $previousZ, float $phi, ?float $commonInnovation = null, float $commonLoading = 0.0): float
            {
                return $this->z;
            }

            public function generateStandardNormal(): float
            {
                return 0.0;
            }
        };
    }

    /**
     * @param array<string, float> $macro
     */
    private function macro(float $crack = MacroEngine::CRACK_SPREAD_BASELINE, float $crudeIndex = MacroEngine::ENERGY_BASELINE, array $macro = []): MacroStateDTO
    {
        return new MacroStateDTO(...($macro + [
            'refiningCrackSpreadEma' => $crack,
            'energyPriceIndexEma' => $crudeIndex,
        ]));
    }

    private function report(MacroStateDTO $macro, float $z = 0.0): ActualFinancialsDTO
    {
        $stock = new Stock();
        $stock->setTicker('GEN_REFINER');

        return $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100.0,
            realizedVariableMargin: self::VARIABLE_COST_RATIO,
            fixedCosts: 0.0,
            baselineVol: 0.15,
            macroState: $macro,
            mathUtility: $this->fixedDraws($z)
        );
    }

    private function baselineRealization(): float
    {
        return $this->model->calculateBarrelRealization(RefiningBusinessModel::REFERENCE_CRUDE_PRICE, MacroEngine::CRACK_SPREAD_BASELINE);
    }

    public function testTheBaselineBarrelCapturesWhatRefinersReportAgainstTheBenchmarkCrack(): void
    {
        $capture = ($this->baselineRealization() - RefiningBusinessModel::REFERENCE_CRUDE_PRICE) / MacroEngine::CRACK_SPREAD_BASELINE;

        $this->assertGreaterThan(0.70, $capture);
        $this->assertLessThan(0.90, $capture);
        $this->assertEqualsWithDelta($capture, $this->report($this->macro())->kpis['capture_rate'], 1e-9);
    }

    public function testRevenueRisesWithCrudeWhileTheMarginBarelyMoves(): void
    {
        $baseline = $this->report($this->macro());
        $crudeSpike = $this->report($this->macro(crudeIndex: 150.0));

        // Products are priced over crude, so a 50% crude move carries the revenue line with it.
        $spikeCrude = RefiningBusinessModel::REFERENCE_CRUDE_PRICE * 1.5;
        $this->assertEqualsWithDelta(
            100.0 * $this->model->calculateBarrelRealization($spikeCrude, MacroEngine::CRACK_SPREAD_BASELINE) / $this->baselineRealization(),
            $crudeSpike->actualRevenue,
            1e-9
        );
        $this->assertGreaterThan(1.35, $crudeSpike->actualRevenue / $baseline->actualRevenue);

        // The margin falls only by the secondary barrel's discount to the dearer crude, and rises again when crude is cheap.
        $secondaryDiscount = 1.0 - RefiningBusinessModel::LIGHT_PRODUCT_YIELD
            - (RefiningBusinessModel::SECONDARY_PRODUCT_YIELD * RefiningBusinessModel::SECONDARY_PRODUCT_REALIZATION);
        $marginDrop = $secondaryDiscount * ($spikeCrude - RefiningBusinessModel::REFERENCE_CRUDE_PRICE) * 100.0 / $this->baselineRealization();
        $this->assertEqualsWithDelta($baseline->ebit - $marginDrop, $crudeSpike->ebit, 1e-9);
        $this->assertLessThan(0.10, ($baseline->ebit - $crudeSpike->ebit) / $baseline->ebit);

        $crudeSlump = $this->report($this->macro(crudeIndex: 60.0));
        $this->assertGreaterThan($baseline->ebit, $crudeSlump->ebit);
    }

    public function testTheCrackIsMarginOnAStandingCostBase(): void
    {
        $baseline = $this->report($this->macro());
        $wide = $this->report($this->macro(crack: MacroEngine::CRACK_SPREAD_BASELINE * 1.10));
        $collapsed = $this->report($this->macro(crack: 12.0));

        // Throughput and feedstock are unchanged, so the variable cost dollars are identical.
        $this->assertEqualsWithDelta($baseline->actualVariableCosts, $wide->actualVariableCosts, 1e-9);

        // Every extra dollar of crack on the light barrel is profit.
        $extraMargin = RefiningBusinessModel::LIGHT_PRODUCT_YIELD * (MacroEngine::CRACK_SPREAD_BASELINE * 0.10) * 100.0 / $this->baselineRealization();
        $this->assertEqualsWithDelta($baseline->ebit + $extraMargin, $wide->ebit, 1e-9);

        $revenueGrowth = ($wide->actualRevenue / $baseline->actualRevenue) - 1.0;
        $profitGrowth = ($wide->ebit / $baseline->ebit) - 1.0;
        $this->assertGreaterThan(5.0 * $revenueGrowth, $profitGrowth, 'A wider crack moves profit far more than revenue.');

        $this->assertLessThan(0.5 * $baseline->ebit, $collapsed->ebit, 'A collapsed crack takes most of the margin with it.');
    }

    public function testDemandReachesARefinerThroughThroughputOnly(): void
    {
        // Inside the physics the output gap is invisible: the crack index already carries what demand does to margins.
        $this->assertEqualsWithDelta(
            $this->report($this->macro())->ebit,
            $this->report($this->macro(macro: ['outputGapEma' => 0.03]))->ebit,
            1e-9
        );

        // Throughput answers the cycle at the income elasticity of oil demand.
        $stock = new Stock();
        $stock->setTicker('GEN_REFINER');
        $physics = $this->model->getMacroPhysics($stock, $this->macro(macro: ['outputGapEma' => 0.02]));
        $this->assertEqualsWithDelta(0.02 * CommodityLogisticsSubsystem::ENERGY_DEMAND_GAP_SENSITIVITY, $physics['macro_demand_shift'], 1e-12);
        $this->assertSame(1.0, $physics['pricing_power_multiplier']);
        $this->assertSame(1.0, $physics['input_cost_multiplier']);
    }

    public function testTheEnginesStickyCostAdjustmentNeverLandsOnFeedstock(): void
    {
        $run = function (float $realizedVariableMargin): ActualFinancialsDTO {
            $stock = new Stock();
            $stock->setTicker('GEN_REFINER');
            $stock->setStructuralVariableMargin(self::VARIABLE_COST_RATIO);

            return $this->model->computeActualFinancials(
                $stock,
                expectedRevenue: 100.0,
                realizedVariableMargin: $realizedVariableMargin,
                fixedCosts: 0.0,
                baselineVol: 0.15,
                macroState: $this->macro(),
                mathUtility: $this->fixedDraws()
            );
        };

        // The engine lifts the whole ratio 11.6% after a revenue contraction (exp(0.55 x 0.2)). Crude is bought
        // barrel for barrel, so only the fuel, catalysts and chemicals on top of it may carry that.
        $stickyMultiplier = exp(0.55 * 0.2);
        $opexShare = self::VARIABLE_COST_RATIO - (RefiningBusinessModel::REFERENCE_CRUDE_PRICE / $this->baselineRealization());
        $cleanEbit = $run(self::VARIABLE_COST_RATIO)->ebit;
        $stickyEbit = $run(self::VARIABLE_COST_RATIO * $stickyMultiplier)->ebit;

        $this->assertEqualsWithDelta($opexShare * ($stickyMultiplier - 1.0) * 100.0, $cleanEbit - $stickyEbit, 1e-9);
        $this->assertLessThan(0.1 * $cleanEbit, $cleanEbit - $stickyEbit, 'a sticky quarter must not wipe out a refining margin');
    }

    public function testSeasonalityFollowsTheTurnaroundCalendar(): void
    {
        $factors = $this->model->getSeasonalityFactors();

        $this->assertEqualsWithDelta(4.0, array_sum($factors), 1e-9);
        $this->assertSame(min($factors), $factors[0], 'Spring turnarounds make Q1 the lightest quarter.');
        $this->assertSame(max($factors), $factors[2], 'The summer driving season makes Q3 the heaviest.');
    }

    public function testARefineryFireCutsRunsAndLoadsRepairsOnEveryBarrel(): void
    {
        $fire = $this->report($this->macro(), z: -3.0);

        $this->assertSame(ShockEvent::ENVIRONMENTAL_DISASTER, $fire->eventType);
        $this->assertLessThan(0.95, $fire->kpis['throughput_index']);
        $this->assertLessThan($this->report($this->macro())->ebit, $fire->ebit);
        $this->assertNull($this->report($this->macro(), z: 3.0)->eventType);
    }

    /**
     * CASC's seeded margins must leave room for refinery fuel and chemicals above the crude it buys, or the
     * opex floor would be carrying the firm. Bounded conservatively: the whole invested capital depreciates.
     */
    public function testCascadesSeedLeavesRoomForRefineryOpexAboveFeedstock(): void
    {
        $casc = null;
        foreach (InitialMarket::STOCKS as $row) {
            if ($row['ticker'] === 'CASC') {
                $casc = $row;
            }
        }
        $this->assertNotNull($casc);

        $margin = (float) $casc['operating_margin'];
        $turnover = (float) $casc['baseline_roic'] / ($margin * (1.0 - MacroEngine::BASE_CORPORATE_TAX_RATE));
        $depreciationShare = (float) $casc['depreciation_rate'] / $turnover;
        $variableCostRatio = (1.0 - $margin - $depreciationShare) * (1.0 - (float) $casc['fixed_cost_ratio']);
        $feedstockShare = RefiningBusinessModel::REFERENCE_CRUDE_PRICE / $this->baselineRealization();

        $this->assertGreaterThan(0.01, $variableCostRatio - $feedstockShare, 'at least a point of revenue for fuel, catalysts and chemicals');
        $this->assertLessThan(0.06, $variableCostRatio - $feedstockShare, 'and not a variable cost base only a far less complex plant would run');
    }
}
