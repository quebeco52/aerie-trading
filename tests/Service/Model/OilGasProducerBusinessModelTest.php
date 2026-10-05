<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\OilGasProducerBusinessModel;
use PHPUnit\Framework\TestCase;

final class OilGasProducerBusinessModelTest extends TestCase
{
    private OilGasProducerBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new OilGasProducerBusinessModel();
    }

    /** Every stream, basis and event draw at a fixed Z, so the benchmark prices are the only thing moving. */
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
    private function macro(float $crude, float $gas = 100.0, array $macro = []): MacroStateDTO
    {
        return new MacroStateDTO(...($macro + [
            'energyPriceIndexEma' => $crude,
            'energyBasePrice' => $crude,
            'naturalGasPriceIndexEma' => $gas,
        ]));
    }

    /**
     * The capital budget answers to the price deck, not the domestic economy: halving the oil price with the
     * output gap closed cuts the budget, and a deep recession at the equilibrium price leaves it alone.
     */
    public function testTheCapexCycleIsTheOilPriceNotTheOutputGap(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->model->getCapexCycleSignal(new Stock(), $this->macro(100.0, macro: ['outputGapEma' => -0.06])), 1e-12);
        $this->assertEqualsWithDelta(log(0.5), $this->model->getCapexCycleSignal(new Stock(), $this->macro(50.0)), 1e-12);
        $this->assertEqualsWithDelta(log(1.5), $this->model->getCapexCycleSignal(new Stock(), $this->macro(150.0)), 1e-12);
    }

    /** The fitted elasticity of US oil & gas drilling to the real oil price cycle: a halved price cuts the budget by about a third. */
    public function testAHalvedOilPriceCutsTheCapitalBudgetByAboutAThird(): void
    {
        $modifier = 1.0 + ($this->model->getCapexCycleSignal(new Stock(), $this->macro(50.0)) * $this->model->getCapexCyclicality());

        $this->assertSame(0.58, $this->model->getCapexCyclicality());
        $this->assertEqualsWithDelta(0.598, $modifier, 0.001);
    }

    private function report(Stock $stock, MacroStateDTO $macro, float $variableMargin = 0.13, float $z = 0.0, float $fixedCosts = 0.0): ActualFinancialsDTO
    {
        return $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100.0,
            realizedVariableMargin: $variableMargin,
            fixedCosts: $fixedCosts,
            baselineVol: 0.20,
            macroState: $macro,
            mathUtility: $this->fixedDraws($z)
        );
    }

    private function producer(string $ticker = 'GEN_PRODUCER'): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);

        return $stock;
    }

    public function testRevenueIsVolumeTimesTheBlendedBenchmarkPrice(): void
    {
        $liquids = OilGasProducerBusinessModel::LIQUIDS_REVENUE_SHARE;

        // A firm with no ladder yet strikes it on today's curve, so the realized price is the benchmark itself.
        $crudeSlump = $this->report($this->producer(), $this->macro(crude: 70.0));
        $this->assertEqualsWithDelta(100.0 * (($liquids * 0.70) + (1.0 - $liquids)), $crudeSlump->actualRevenue, 1e-6);
        $this->assertEqualsWithDelta(100.0 * $liquids * 0.70, $crudeSlump->streamRevenue['crude_oil'], 1e-6);

        // Gas is sold at the gas hub: a gas squeeze lifts the gas stream and leaves crude alone.
        $gasSqueeze = $this->report($this->producer(), $this->macro(crude: 100.0, gas: 150.0));
        $this->assertEqualsWithDelta(100.0 * (1.0 - $liquids) * 1.5, $gasSqueeze->streamRevenue['natural_gas'], 1e-6);
        $this->assertEqualsWithDelta(100.0 * $liquids, $gasSqueeze->streamRevenue['crude_oil'], 1e-6);
    }

    public function testSinkIsAnOilWeightedLightlyHedgedProducer(): void
    {
        $profile = $this->model->resolveProductionProfile($this->producer('SINK'));

        $this->assertSame(0.85, $profile['liquids_share']);
        $this->assertSame(0.15, $profile['hedge_ratio']);

        // Unhedged, the whole liquids share moves with crude: a halving costs it 42.5% of revenue.
        $slump = $this->report($this->producer('SINK'), $this->macro(crude: 50.0));
        $this->assertEqualsWithDelta(100.0 * (1.0 - (0.85 * 0.5)), $slump->actualRevenue, 1e-6);
    }

    public function testCostsArePaidPerBarrelSoPriceLandsOnMargin(): void
    {
        $baseline = $this->report($this->producer(), $this->macro(crude: 100.0));
        $boom = $this->report($this->producer(), $this->macro(crude: 150.0, gas: 150.0));
        $slump = $this->report($this->producer(), $this->macro(crude: 60.0, gas: 60.0));

        // The same barrels cost the same dollars: re-expressed per dollar of revenue, the ratio moves inversely with price.
        $this->assertEqualsWithDelta(0.13, $baseline->clampedMargin, 1e-9);
        $this->assertEqualsWithDelta(0.13 / 1.5, $boom->clampedMargin, 1e-9);
        $this->assertEqualsWithDelta(0.13 / 0.6, $slump->clampedMargin, 1e-9);
        $this->assertEqualsWithDelta($baseline->actualVariableCosts, $boom->actualVariableCosts, 1e-9);
        $this->assertEqualsWithDelta($baseline->actualVariableCosts, $slump->actualVariableCosts, 1e-9);

        // So operating profit moves by more than revenue does, in both directions.
        $this->assertGreaterThan(1.5, $boom->ebit / $baseline->ebit);
        $this->assertLessThan(0.6, $slump->ebit / $baseline->ebit);
    }

    public function testTheDomesticCycleDoesNotMoveAProducersVolume(): void
    {
        $stock = $this->producer();
        $boom = $this->model->getMacroPhysics($stock, $this->macro(crude: 100.0, macro: ['outputGapEma' => 0.03]));
        $bust = $this->model->getMacroPhysics($stock, $this->macro(crude: 100.0, macro: ['outputGapEma' => -0.03]));

        $this->assertSame($boom['macro_demand_shift'], $bust['macro_demand_shift']);
        $this->assertEqualsWithDelta(0.0, $boom['macro_demand_shift'], 1e-12);
        // Inflation lives in the benchmark prices, not in an engine-level selling-price multiplier.
        $this->assertSame(1.0, $boom['pricing_power_multiplier']);
        $this->assertSame(1.0, $boom['input_cost_multiplier']);

        $this->assertSame([1.0, 1.0, 1.0, 1.0], $this->model->getSeasonalityFactors());
    }

    public function testTheSwapLadderDeliversLastYearsForwardsAndRollsOverFourQuarters(): void
    {
        $stock = $this->producer();
        $liquids = OilGasProducerBusinessModel::LIQUIDS_REVENUE_SHARE;
        $hedge = OilGasProducerBusinessModel::OIL_HEDGE_RATIO;

        $first = $this->report($stock, $this->macro(crude: 100.0));
        $this->assertEqualsWithDelta(0.0, $first->kpis['hedge_gain'], 1e-12, 'A new ladder is struck at today\'s curve: no phantom gain.');
        for ($d = 0; $d < OilGasProducerBusinessModel::HEDGE_LADDER_TRANCHES; $d++) {
            $this->assertArrayHasKey(OilGasProducerBusinessModel::STATE_HEDGE_STRIKE_SUM_PREFIX . $d, $first->streamZ);
        }

        $stock->setEarningsMomentumZ($first->streamZ);
        foreach ([100.0, 100.0, 100.0] as $crude) {
            $stock->setEarningsMomentumZ($this->report($stock, $this->macro(crude: $crude))->streamZ);
        }

        // Crude spikes to 150 and stays there. The strike the ladder delivers each quarter follows it up one
        // tranche at a time, then holds once the whole ladder has been struck at the spiked curve.
        $strikes = [];
        $gains = [];
        for ($quarter = 0; $quarter < 6; $quarter++) {
            $report = $this->report($stock, $this->macro(crude: 150.0));
            $stock->setEarningsMomentumZ($report->streamZ);
            $realizedOil = $report->streamRevenue['crude_oil'] / (100.0 * $liquids);
            $strikes[] = ($realizedOil - ((1.0 - $hedge) * 1.5)) / $hedge;
            $gains[] = $report->kpis['hedge_gain'];
        }

        $this->assertLessThan(-0.10, $gains[0], 'A spike against a ladder struck at 100 is a hedge loss.');
        $this->assertEqualsWithDelta(1.0, $strikes[0], 0.01, 'The first quarter delivers tranches struck before the spike.');
        for ($q = 1; $q < 4; $q++) {
            $this->assertGreaterThan($strikes[$q - 1], $strikes[$q], 'Each quarter one more tranche at the new curve reaches delivery.');
            $this->assertGreaterThan($gains[$q - 1], $gains[$q]);
        }
        $this->assertEqualsWithDelta($strikes[4], $strikes[5], 1e-9, 'Four quarters on, the ladder is struck wholly at the spiked curve.');

        // A spike above the equilibrium backwardates the curve, so even a fully rolled ladder sells below spot.
        $this->assertLessThan(1.5, $strikes[5]);
        $this->assertGreaterThan(1.0, $strikes[5]);
    }

    public function testTailEventsCutVolumeButNeverSetTheFirmsOwnPrice(): void
    {
        // A draw far in the right tail: the old model priced a one-firm export ban here. Price is the market's.
        $rightTail = $this->report($this->producer(), $this->macro(crude: 100.0), z: 3.0);
        $this->assertNull($rightTail->eventType);

        // A blowout shuts part of the field in and loads remediation on every barrel still produced.
        $disaster = $this->report($this->producer(), $this->macro(crude: 100.0), z: -3.0);
        $this->assertSame(ShockEvent::ENVIRONMENTAL_DISASTER, $disaster->eventType);
        $this->assertTrue($disaster->isPublicEvent);
        $this->assertEqualsWithDelta(
            (0.13 + OilGasProducerBusinessModel::ENVIRONMENTAL_DISASTER_PENALTY),
            $disaster->clampedMargin,
            0.02
        );
    }

    public function testOnlyThePublicPriceLegIsScaledUpForAnalysts(): void
    {
        $spike = $this->report($this->producer(), $this->macro(crude: 150.0, gas: 150.0));

        // Revenue is 50% above plan, all of it price: analysts, who see the benchmarks, are handed it in full
        // once their visibility is netted out.
        $this->assertEqualsWithDelta(0.5 / OilGasProducerBusinessModel::BASE_COVERAGE_VISIBILITY, $spike->observableShockZ, 1e-9);
        $this->assertEqualsWithDelta(1.5, $spike->kpis['realized_price_index'], 1e-9);
    }

    /** The strictest rules on extraction cost Greenstone, List & Syverson's 4.8% of productivity: every barrel costs 1 / 0.952 as much to lift. */
    public function testStrictExtractionRulesRaiseTheCostOfEveryBarrel(): void
    {
        $founding = $this->report($this->producer(), $this->macro(crude: 100.0));
        $strict = $this->report($this->producer(), $this->macro(crude: 100.0, macro: ['extractionStringency' => 1.0]));

        $this->assertEqualsWithDelta($founding->actualRevenue, $strict->actualRevenue, 1e-9);
        $this->assertEqualsWithDelta($founding->actualVariableCosts / (1.0 - 0.048), $strict->actualVariableCosts, 1e-9);
    }

    /** What the market prices a change in the rules against is the cost they scale, unit costs and committed base alike, at any crude price: the strictest rules add the base times the factor's rise. */
    public function testTheMarketsBaseIsTheCostTheRulesScale(): void
    {
        $factor = MathUtility::calculateExtractionCostFactor(1.0);
        $this->assertSame($factor, $this->model->getFixedCostFactor($this->macro(crude: 100.0, macro: ['extractionStringency' => 1.0])));

        foreach ([60.0, 100.0, 150.0] as $crude) {
            $founding = $this->report($this->producer(), $this->macro(crude: $crude), fixedCosts: 50.0);
            $strict = $this->report($this->producer(), $this->macro(crude: $crude, macro: ['extractionStringency' => 1.0]), fixedCosts: 50.0 * $factor);
            $stock = $this->producer()->setTotalRevenue((string) (4.0 * $founding->actualRevenue))->setEarningsMomentumZ($founding->streamZ);

            $this->assertEqualsWithDelta(
                ($strict->actualVariableCosts + (50.0 * $factor)) - ($founding->actualVariableCosts + 50.0),
                $this->model->annualExtractionCostBase($stock) / 4.0 * ($factor - 1.0),
                1e-6
            );
        }
    }
}
