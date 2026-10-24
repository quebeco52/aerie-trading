<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Model\Sector\OilGasProducerBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class OilGasProducerBusinessModelTest extends TestCase
{
    private OilGasProducerBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new OilGasProducerBusinessModel();
    }

    /** Every stream, basis and event draw at a fixed Z, so the benchmark prices are the only thing moving. */
    private function fixedDraws(float $z = 0.0): ScriptedDraws
    {
        return new ScriptedDraws(generatePersistentZ: $z);
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

    /**
     * The fitted elasticity of US oil & gas drilling to the real oil price cycle: a halved price cuts the budget by
     * about a third.
     */
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
            mathUtility: $this->fixedDraws($z),
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

        // The same barrels cost the same dollars: re-expressed per dollar of revenue, the ratio moves inversely with
        // price.
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

    /**
     * A quarter at a boom price books what its barrels fetched above the long-run price, after tax, and its first
     * report seeds the whole trailing year with it; at the long-run price there is no windfall.
     */
    public function testABoomQuarterBooksItsPriceWindfall(): void
    {
        $longRun = CommodityLogisticsSubsystem::resolveEnergyEquilibriumPrice((new MacroStateDTO())->energySupplyEma);
        $gasAtLongRun = MacroConstants::NATURAL_GAS_BASELINE * $longRun / MacroConstants::ENERGY_BASELINE;

        $boomMacro = $this->macro(crude: 1.6 * $longRun, gas: $gasAtLongRun);
        $boom = $this->report($this->producer(), $boomMacro);
        $afterTax = 1.0 - $this->model->getEffectiveTaxRate($boomMacro->corporateTaxRate);
        $expected = (array_sum($boom->streamRevenue) - (100.0 * $longRun / MacroConstants::ENERGY_BASELINE)) * $afterTax;
        $this->assertGreaterThan(0.0, $expected);
        for ($q = 0; $q < 4; $q++) {
            $this->assertEqualsWithDelta($expected, $boom->streamZ[OilGasProducerBusinessModel::STATE_PRICE_WINDFALL_PREFIX . $q], 1e-9);
        }

        $calm = $this->report($this->producer(), $this->macro(crude: $longRun, gas: $gasAtLongRun));
        $this->assertEqualsWithDelta(0.0, $calm->streamZ[OilGasProducerBusinessModel::STATE_PRICE_WINDFALL_PREFIX . '0'], 1e-9);
    }

    /** The dividend is set on the trailing year's earnings less its average quarter's price windfall per share. */
    public function testTheDividendBaseLeavesOutThePriceWindfall(): void
    {
        $stock = $this->producer();
        $stock->setSharesOutstanding('10');
        $stock->setEarningsMomentumZ([
            OilGasProducerBusinessModel::STATE_PRICE_WINDFALL_PREFIX . '0' => 8.0,
            OilGasProducerBusinessModel::STATE_PRICE_WINDFALL_PREFIX . '1' => 4.0,
            OilGasProducerBusinessModel::STATE_PRICE_WINDFALL_PREFIX . '2' => 4.0,
            OilGasProducerBusinessModel::STATE_PRICE_WINDFALL_PREFIX . '3' => 0.0,
        ]);

        $this->assertEqualsWithDelta(1.50 - 0.40, $this->model->getSustainableDividendBase($stock, 1.50, 0.0, 0.0), 1e-12);
    }

    public function testTailEventsCutVolumeButNeverSetTheFirmsOwnPrice(): void
    {
        // A draw far in the right tail: the old model priced a one-firm export ban here. Price is the market's.
        $rightTail = $this->report($this->producer(), $this->macro(crude: 100.0), z: 3.0);
        $this->assertNull($rightTail->eventType);

        // A blowout shuts part of the field in, and its clean-up is a one-off special item on the barrels it cost,
        // not a per-barrel cost the run rate would carry forward.
        $disaster = $this->report($this->producer(), $this->macro(crude: 100.0), z: -3.0);
        $this->assertSame(ShockEvent::ENVIRONMENTAL_DISASTER, $disaster->eventType);
        $this->assertTrue($disaster->isPublicEvent);
        $this->assertEqualsWithDelta(0.13, $disaster->clampedMargin, 0.02);
        $this->assertGreaterThan(0.0, $disaster->specialCharge);
    }

    public function testThePublicPriceLegIsReadInFull(): void
    {
        $spike = $this->report($this->producer(), $this->macro(crude: 150.0, gas: 150.0));

        $this->assertEqualsWithDelta(1.5, $spike->kpis['realized_price_index'], 1e-9);
    }

    /**
     * The strictest rules on extraction cost Greenstone, List & Syverson's 4.8% of productivity: every barrel costs 1 /
     * 0.952 as much to lift.
     */
    public function testStrictExtractionRulesRaiseTheCostOfEveryBarrel(): void
    {
        $founding = $this->report($this->producer(), $this->macro(crude: 100.0));
        $strict = $this->report($this->producer(), $this->macro(crude: 100.0, macro: ['extractionStringency' => 1.0]));

        $this->assertEqualsWithDelta($founding->actualRevenue, $strict->actualRevenue, 1e-9);
        $this->assertEqualsWithDelta($founding->actualVariableCosts / (1.0 - 0.048), $strict->actualVariableCosts, 1e-9);
    }

    /**
     * What the market prices a change in the rules against is the cost they scale, unit costs and committed base alike,
     * at any crude price: the strictest rules add the base times the factor's rise.
     */
    public function testTheMarketsBaseIsTheCostTheRulesScale(): void
    {
        $factor = FirmEconomics::calculateExtractionCostFactor(1.0);
        $this->assertSame($factor, $this->model->getFixedCostFactor($this->macro(crude: 100.0, macro: ['extractionStringency' => 1.0])));

        foreach ([60.0, 100.0, 150.0] as $crude) {
            $founding = $this->report($this->producer(), $this->macro(crude: $crude), fixedCosts: 50.0);
            $strict = $this->report($this->producer(), $this->macro(crude: $crude, macro: ['extractionStringency' => 1.0]), fixedCosts: 50.0 * $factor);
            $stock = $this->producer()->setTotalRevenue((string) (4.0 * $founding->actualRevenue))->setEarningsMomentumZ($founding->streamZ);

            $this->assertEqualsWithDelta(
                ($strict->actualVariableCosts + (50.0 * $factor)) - ($founding->actualVariableCosts + 50.0),
                $this->model->annualExtractionCostBase($stock) / 4.0 * ($factor - 1.0),
                1e-6,
            );
        }
    }
}
