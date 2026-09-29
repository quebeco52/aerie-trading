<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\MiningBusinessModel;
use PHPUnit\Framework\TestCase;

final class MiningBusinessModelTest extends TestCase
{
    private MiningBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new MiningBusinessModel();
    }

    /** Every stream, realization and event draw at a fixed Z, so the benchmark price is the only thing moving. */
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
    private function macro(float $metals, array $macro = []): MacroStateDTO
    {
        return new MacroStateDTO(...($macro + ['industrialMetalsIndexEma' => $metals]));
    }

    private function report(MacroStateDTO $macro, float $z = 0.0, string $ticker = 'GEN_MINER'): ActualFinancialsDTO
    {
        $stock = new Stock();
        $stock->setTicker($ticker);

        return $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100.0,
            realizedVariableMargin: 0.15,
            fixedCosts: 0.0,
            baselineVol: 0.40,
            macroState: $macro,
            mathUtility: $this->fixedDraws($z)
        );
    }

    public function testRevenueIsTonnesTimesTheMetalsBenchmark(): void
    {
        // Unhedged: a 30% metals slump is a 30% revenue slump, and a rally is booked in full.
        $this->assertEqualsWithDelta(70.0, $this->report($this->macro(70.0))->actualRevenue, 1e-9);
        $this->assertEqualsWithDelta(140.0, $this->report($this->macro(140.0))->actualRevenue, 1e-9);
        $this->assertSame(['base_metals'], array_keys($this->report($this->macro(140.0))->streamRevenue), 'the default miner is a base-metals pure-play');
        $this->assertEqualsWithDelta(1.4, $this->report($this->macro(140.0))->kpis['realized_price_index'], 1e-9);
    }

    public function testOilAndRefiningPricesDoNotSetAMinesRevenue(): void
    {
        $baseline = $this->report($this->macro(100.0));
        $oilShock = $this->report($this->macro(100.0, ['energyPriceIndexEma' => 180.0, 'refiningCrackSpreadEma' => 40.0, 'naturalGasPriceIndexEma' => 200.0]));

        $this->assertEqualsWithDelta($baseline->actualRevenue, $oilShock->actualRevenue, 1e-9);
    }

    public function testCostsArePaidPerTonneSoPriceLandsOnMargin(): void
    {
        $baseline = $this->report($this->macro(100.0));
        $boom = $this->report($this->macro(150.0));
        $slump = $this->report($this->macro(60.0));

        // The same tonnes cost the same dollars, except the grinding-media leg of the input basket, which is itself
        // priced off the metals complex. Re-expressed per dollar of revenue, the ratio moves inversely with price.
        $metalsLeg = MiningBusinessModel::INPUT_COST_EXPOSURES['metals'];
        $boomCost = 0.15 * (1.0 + ($metalsLeg * 0.5));
        $slumpCost = 0.15 * (1.0 - ($metalsLeg * 0.4));
        $this->assertEqualsWithDelta($boomCost / 1.5, $boom->clampedMargin, 1e-9);
        $this->assertEqualsWithDelta($slumpCost / 0.6, $slump->clampedMargin, 1e-9);
        $this->assertEqualsWithDelta(100.0 * $boomCost, $boom->actualVariableCosts, 1e-9);
        $this->assertEqualsWithDelta(100.0 * $slumpCost, $slump->actualVariableCosts, 1e-9);
        $this->assertEqualsWithDelta(15.0, $baseline->actualVariableCosts, 1e-9);

        $this->assertGreaterThan(1.5, $boom->ebit / $baseline->ebit);
        $this->assertLessThan(0.6, $slump->ebit / $baseline->ebit);
    }

    public function testTheDomesticCycleDoesNotMoveAMinesOutput(): void
    {
        $stock = new Stock();
        $stock->setTicker('GEN_MINER');
        $boom = $this->model->getMacroPhysics($stock, $this->macro(100.0, ['outputGapEma' => 0.03]));
        $bust = $this->model->getMacroPhysics($stock, $this->macro(100.0, ['outputGapEma' => -0.03]));

        $this->assertSame($boom['macro_demand_shift'], $bust['macro_demand_shift']);
        $this->assertEqualsWithDelta(0.0, $boom['macro_demand_shift'], 1e-12);
        $this->assertSame(1.0, $boom['pricing_power_multiplier']);
        $this->assertSame(1.0, $boom['input_cost_multiplier']);

        $this->assertSame([1.0, 1.0, 1.0, 1.0], $this->model->getSeasonalityFactors());
    }

    public function testTailEventsCutOutputButNeverSetTheFirmsOwnPrice(): void
    {
        // Far in the right tail: the old commodity model priced a one-firm export ban here. Price is the market's.
        $this->assertNull($this->report($this->macro(100.0), z: 3.0)->eventType);

        $disaster = $this->report($this->macro(100.0), z: -3.0);
        $this->assertSame(ShockEvent::ENVIRONMENTAL_DISASTER, $disaster->eventType);
        $this->assertTrue($disaster->isPublicEvent);
        $this->assertLessThan(100.0 * MiningBusinessModel::DISASTER_OUTPUT_MULT, $disaster->actualRevenue);
    }

    public function testOnlyThePublicPriceLegIsScaledUpForAnalysts(): void
    {
        $rally = $this->report($this->macro(150.0));

        $this->assertEqualsWithDelta(0.5 / MiningBusinessModel::BASE_COVERAGE_VISIBILITY, $rally->observableShockZ, 1e-9);
    }

    public function testCondorSellsADiversifiedBasketAtItsTunedMix(): void
    {
        $baseline = $this->report($this->macro(100.0), ticker: 'CNDR');

        $this->assertEqualsWithDelta(100.0, $baseline->actualRevenue, 1e-9);
        foreach (['base_metals' => 70.0, 'precious_metals' => 10.0, 'energy_minerals' => 12.0, 'fertilizer_minerals' => 8.0] as $stream => $revenue) {
            $this->assertEqualsWithDelta($revenue, $baseline->streamRevenue[$stream], 1e-9, "{$stream} at baseline prices");
        }
    }

    public function testEachStreamSellsAgainstItsOwnBenchmark(): void
    {
        $baseline = $this->report($this->macro(100.0), ticker: 'CNDR')->streamRevenue;
        $moves = [
            'precious_metals' => ['goldPriceIndexEma' => 150.0],
            'energy_minerals' => ['naturalGasPriceIndexEma' => 150.0],
            'fertilizer_minerals' => ['agriculturalCommodityIndexEma' => 150.0],
        ];

        foreach ($moves as $moved => $macro) {
            $streams = $this->report($this->macro(100.0, $macro), ticker: 'CNDR')->streamRevenue;
            foreach ($baseline as $stream => $revenue) {
                $expected = $stream === $moved ? $revenue * 1.5 : $revenue;
                $this->assertEqualsWithDelta($expected, $streams[$stream], 1e-9, "{$moved} moved: {$stream}");
            }
        }
    }

    public function testAGoldLegCushionsABaseMetalsBust(): void
    {
        // A downturn: metals slump while gold, bought against bad times and falling real rates, rallies.
        $downturn = $this->macro(70.0, ['goldPriceIndexEma' => 115.0]);

        $pureRevenue = $this->report($downturn)->actualRevenue;
        $majorRevenue = $this->report($downturn, ticker: 'CNDR')->actualRevenue;

        $this->assertEqualsWithDelta(70.0, $pureRevenue, 1e-9);
        $this->assertEqualsWithDelta((70.0 * 0.70) + (10.0 * 1.15) + 12.0 + 8.0, $majorRevenue, 1e-9);
        $this->assertGreaterThan($pureRevenue + 10.0, $majorRevenue);
    }

    public function testCostsFollowTheTonnesAcrossTheWholeMix(): void
    {
        // Every stream up 50%: the same tonnes cost the same dollars (less the grinding-media leg, priced off the metals complex).
        $boom = $this->report($this->macro(150.0, ['goldPriceIndexEma' => 150.0, 'naturalGasPriceIndexEma' => 150.0, 'agriculturalCommodityIndexEma' => 150.0]), ticker: 'CNDR');
        $cost = 0.15 * (1.0 + (MiningBusinessModel::INPUT_COST_EXPOSURES['metals'] * 0.5));

        $this->assertEqualsWithDelta(150.0, $boom->actualRevenue, 1e-9);
        $this->assertEqualsWithDelta($cost / 1.5, $boom->clampedMargin, 1e-9);
        $this->assertEqualsWithDelta(100.0 * $cost, $boom->actualVariableCosts, 1e-9);
    }

    private function miner(string $ticker = 'GEN_MINER'): Stock
    {
        return (new Stock())->setTicker($ticker);
    }

    /**
     * The capital budget answers to the price deck, not the domestic economy: a metals slump with the output gap
     * closed cuts it, and a deep recession at equilibrium prices leaves it alone.
     */
    public function testTheCapexCycleIsTheMetalsPriceNotTheOutputGap(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->model->getCapexCycleSignal($this->miner(), $this->macro(100.0, ['outputGapEma' => -0.06])), 1e-12);
        $this->assertEqualsWithDelta(log(0.7), $this->model->getCapexCycleSignal($this->miner(), $this->macro(70.0)), 1e-12);
    }

    /** A diversified major budgets against its whole basket: a gold rally offsets part of a base-metals slump. */
    public function testADiversifiedMajorBudgetsAgainstItsBasket(): void
    {
        $downturn = $this->macro(70.0, ['goldPriceIndexEma' => 115.0]);
        $deck = (0.70 * 0.70) + (0.10 * 1.15) + 0.12 + 0.08;

        $this->assertEqualsWithDelta(log($deck), $this->model->getCapexCycleSignal($this->miner('CNDR'), $downturn), 1e-12);
    }

    /** The fitted elasticity of US mine investment to the real metals cycle: a 30% slump trims the budget by about a fifth. */
    public function testAThirtyPercentMetalsSlumpTrimsTheCapitalBudgetByAboutAFifth(): void
    {
        $modifier = 1.0 + ($this->model->getCapexCycleSignal($this->miner(), $this->macro(70.0)) * $this->model->getCapexCyclicality());

        $this->assertSame(0.60, $this->model->getCapexCyclicality());
        $this->assertEqualsWithDelta(0.786, $modifier, 0.001);
    }
}
