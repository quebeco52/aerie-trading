<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\IndependentPowerBusinessModel;
use PHPUnit\Framework\TestCase;

final class IndependentPowerBusinessModelTest extends TestCase
{
    private const VOL = 0.30;
    private const VARIABLE_MARGIN = 0.50;

    private IndependentPowerBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new IndependentPowerBusinessModel();
    }

    /**
     * Stream draws in the order the physics makes them (capacity, water and tide, the unit event), cycling; regime
     * exits fixed.
     *
     * @param list<float> $zs
     */
    private function draws(array $zs = [0.0, 0.0, 0.0], bool $exits = false): MathUtility
    {
        return new class ($zs, $exits) extends MathUtility {
            private int $call = 0;

            /** @param list<float> $zs */
            public function __construct(private readonly array $zs, private readonly bool $exits) {}

            public function generatePersistentZ(float $previousZ, float $phi, ?float $commonInnovation = null, float $commonLoading = 0.0): float
            {
                return $this->zs[$this->call++ % count($this->zs)];
            }

            public function generateStandardNormal(): float
            {
                return 0.0;
            }

            public function checkProbability(float $probability): bool
            {
                return $this->exits;
            }
        };
    }

    /** The gas season the smoothed gas index carries at a time. */
    private static function gasSeason(float $time = 0.0): float
    {
        return CommodityLogisticsSubsystem::smoothedGasSeason($time);
    }

    /** The power season the smoothed power index carries at a time. */
    private static function powerSeason(float $time = 0.0): float
    {
        return CommodityLogisticsSubsystem::smoothedPowerSeason($time);
    }

    /**
     * Gas over its baseline out of season, with power where the market would clear it at the mean heat rate unless
     * given; oil flat. The smoothed indices carry the season of the time they are read at, as the macro's do.
     *
     * @param array<string, float> $extra
     */
    private function macro(float $gas = 1.0, ?float $power = null, array $extra = [], float $time = 0.0): MacroStateDTO
    {
        return new MacroStateDTO(...($extra + [
            'totalTime' => $time,
            'energyPriceIndexEma' => 100.0,
            'energyBasePrice' => 100.0,
            'gasOilRatioLog' => log($gas),
            'naturalGasPriceIndexEma' => 100.0 * $gas * self::gasSeason($time),
            'wholesalePowerPriceIndexEma' => 100.0 * ($power ?? ($gas ** CommodityLogisticsSubsystem::POWER_GAS_ELASTICITY)) * self::powerSeason($time),
        ]));
    }

    private function generator(string $ticker = 'DIPR'): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);

        return $stock;
    }

    private function report(Stock $stock, MacroStateDTO $macro, ?MathUtility $draws = null): ActualFinancialsDTO
    {
        $report = $this->model->computeActualFinancials($stock, 100.0, self::VARIABLE_MARGIN, 20.0, self::VOL, $macro, $draws ?? $this->draws());
        $stock->setEarningsMomentumZ($report->streamZ);

        return $report;
    }

    /** The year before time zero at baseline prices, so every tranche on the ladder is struck at the baseline curve. */
    private function seasoned(string $ticker = 'DIPR'): Stock
    {
        $stock = $this->generator($ticker);
        for ($q = 0; $q < IndependentPowerBusinessModel::HEDGE_LADDER_TRANCHES; $q++) {
            $this->report($stock, $this->macro(time: ($q - IndependentPowerBusinessModel::HEDGE_LADDER_TRANCHES) / 4.0));
        }

        return $stock;
    }

    public function testDipperReadsItsMixFleetAndHedgeFromTuning(): void
    {
        $this->assertSame(
            ['wholesale' => 0.80, 'capacity' => 0.20, 'gas_fleet' => 0.50, 'hedge_ratio' => 0.60],
            $this->model->resolveRevenueMix($this->generator('DIPR')),
        );

        $default = $this->model->resolveRevenueMix($this->generator('GEN_IPP'));
        $this->assertSame(IndependentPowerBusinessModel::GAS_FLEET_SHARE, $default['gas_fleet']);
        $this->assertSame(IndependentPowerBusinessModel::POWER_HEDGE_RATIO, $default['hedge_ratio']);
        $this->assertEqualsWithDelta(1.0, $default['wholesale'] + $default['capacity'], 1e-12);
        $this->assertSame(0.80, $this->model->resolveWholesalePowerRevenueShare($this->generator('DIPR')));
    }

    /**
     * Gas sets the clearing price, so a gas rise lifts power. The zero-fuel half keeps the whole rise; the gas half
     * pays for its fuel out of it and keeps the rest. A new ladder is struck on today's curve, so the quarter's
     * realized price is the spot.
     */
    public function testAGasRiseThatLiftsPowerAddsToTheZeroFuelFleetsMarginInFull(): void
    {
        $baseline = $this->report($this->generator(), $this->macro());
        $rise = $this->report($this->generator(), $this->macro(gas: 1.5));

        $power = (1.5 ** CommodityLogisticsSubsystem::POWER_GAS_ELASTICITY) - 1.0;
        $zeroFuelGain = 80.0 * 0.5 * $power * self::powerSeason();
        $gasFleetGain = 80.0 * 0.5 * (($power * self::powerSeason()) - (FirmEconomics::gasFuelShareOfPowerPrice() * 0.5 * self::gasSeason()));

        $this->assertEqualsWithDelta($zeroFuelGain + $gasFleetGain, $rise->ebit - $baseline->ebit, 1e-6);
        $this->assertGreaterThan($gasFleetGain, $zeroFuelGain);
        $this->assertGreaterThan(0.0, $gasFleetGain, 'power moves on the whole MWh, fuel on half its price');
    }

    /**
     * The spark spread's sign: gas up with the power price held (the market heat rate collapsing) squeezes the gas
     * stations, and the gas bought forward with last year's sales covers part of the bill.
     */
    public function testGasRisingWithoutPowerSqueezesTheGasStationsThroughTheHedge(): void
    {
        // The squeeze a year after the baseline quarter, so both are read in the same season on a ladder struck at
        // baseline.
        $stock = $this->seasoned();
        $baseline = $this->report($stock, $this->macro());
        for ($q = 1; $q < 4; $q++) {
            $this->report($stock, $this->macro(time: $q / 4.0));
        }
        $squeeze = $this->report($stock, $this->macro(gas: 1.5, power: 1.0, time: 1.0));

        $this->assertLessThan($baseline->kpis['clean_spark_spread'], $squeeze->kpis['clean_spark_spread']);
        $this->assertEqualsWithDelta(self::powerSeason(1.0) - (FirmEconomics::gasFuelShareOfPowerPrice() * 1.5 * self::gasSeason(1.0)), $squeeze->kpis['clean_spark_spread'], 1e-9);
        $this->assertLessThan($baseline->ebit, $squeeze->ebit);

        // Unhedged, the gas half would pay the whole half again on its fuel; the ladder covered the hedged 60% at
        // baseline.
        $unhedgedFuelRise = 80.0 * 0.5 * FirmEconomics::gasFuelShareOfPowerPrice() * 0.5 * self::gasSeason(1.0);
        $this->assertLessThan($unhedgedFuelRise, $baseline->ebit - $squeeze->ebit);
        $this->assertEqualsWithDelta(0.4 * $unhedgedFuelRise, $baseline->ebit - $squeeze->ebit, 0.05);
    }

    /**
     * The ladder sells forward: a spike reaches the realized price one tranche a quarter and holds once the ladder has
     * rolled.
     */
    public function testTheHedgeBookMovesTheRealizedPriceLessThanSpot(): void
    {
        $stock = $this->seasoned();
        $spot = 1.8 ** CommodityLogisticsSubsystem::POWER_GAS_ELASTICITY;

        // Prices read out of season: the delivery season divided out of each quarter's realized price.
        $realized = [];
        $gains = [];
        for ($q = 0; $q < 6; $q++) {
            $report = $this->report($stock, $this->macro(gas: 1.8, time: $q / 4.0));
            $realized[] = $report->kpis['power_price_index'] / self::powerSeason($q / 4.0);
            $gains[] = $report->kpis['hedge_gain'];
        }

        $this->assertEqualsWithDelta((0.6 * 1.0) + (0.4 * $spot), $realized[0], 0.01, 'the first quarter delivers tranches struck before the spike');
        $this->assertLessThan(0.0, $gains[0]);
        for ($q = 1; $q < 4; $q++) {
            $this->assertGreaterThan($realized[$q - 1], $realized[$q]);
        }
        $this->assertEqualsWithDelta($realized[4], $realized[5], 1e-9, 'four quarters on, the whole ladder is struck at the spiked curve');
        // Gas above its mean backwardates the curve, so even a rolled ladder sells below spot.
        $this->assertLessThan($spot, $realized[5]);
    }

    /**
     * A season everyone knows is in the forward: with only the deterministic gas and power seasons moving, the hedge
     * gain is the same in every quarter of the year. What is left is the oil curve's slope at baseline, not the season,
     * which unpriced swung the gain by about seven points of revenue between winter and summer.
     */
    public function testAKnownSeasonBooksNoSeasonalHedgeGain(): void
    {
        $stock = $this->seasoned();
        $gains = [];
        for ($q = 0; $q < 8; $q++) {
            $gains[] = $this->report($stock, $this->macro(time: $q / 4.0))->kpis['hedge_gain'];
        }

        $this->assertLessThan(5e-4, max($gains) - min($gains));
        $this->assertLessThan(0.01, abs($gains[0]));
    }

    /**
     * Capacity payments are contracted: a two-sigma draw moves them about a percent, the same draw on water a tenth.
     */
    public function testCapacityPaymentsBarelyMoveWhileWaterMovesOutput(): void
    {
        $base = $this->report($this->generator(), $this->macro());
        $capacityShock = $this->report($this->generator(), $this->macro(), $this->draws([2.0, 0.0, 0.0]));
        $waterShock = $this->report($this->generator(), $this->macro(), $this->draws([0.0, 2.0, 0.0]));
        $powerSpike = $this->report($this->generator(), $this->macro(gas: 1.8));

        $capacityMove = $capacityShock->streamRevenue['capacity_payments'] / $base->streamRevenue['capacity_payments'] - 1.0;
        $this->assertEqualsWithDelta(2.0 * self::VOL * IndependentPowerBusinessModel::CAPACITY_VARIANCE_SCALAR, $capacityMove, 1e-9);
        $this->assertLessThan(0.02, $capacityMove);

        $this->assertGreaterThan(1.05, $waterShock->kpis['generation_index']);
        $this->assertEqualsWithDelta($base->streamRevenue['capacity_payments'], $waterShock->streamRevenue['capacity_payments'], 1e-9);
        $this->assertEqualsWithDelta($base->streamRevenue['capacity_payments'], $powerSpike->streamRevenue['capacity_payments'], 1e-9, 'the pool price does not reach the contracts');
    }

    /**
     * A tripped unit takes its output and its capacity payment with it until it returns; the output already sold
     * forward is bought back at spot.
     */
    public function testAUnitOutageLosesOutputUntilTheUnitReturns(): void
    {
        $stock = $this->seasoned();
        $spot = (1.5 ** CommodityLogisticsSubsystem::POWER_GAS_ELASTICITY) * self::powerSeason();
        $available = 1.0 - IndependentPowerBusinessModel::OUTAGE_OUTPUT_SHARE;

        $trip = $this->report($stock, $this->macro(gas: 1.5), $this->draws([0.0, 0.0, -2.5]));
        $this->assertSame(ShockEvent::GENERATION_OUTAGE, $trip->eventType);
        $this->assertEqualsWithDelta($available, $trip->kpis['generation_index'], 1e-12);
        $this->assertEqualsWithDelta($available, $trip->kpis['availability'], 1e-12);
        $this->assertEqualsWithDelta(20.0 * $available, $trip->streamRevenue['capacity_payments'], 1e-9);
        $strike = ($trip->streamRevenue['wholesale_generation'] / 80.0 - (($available - 0.6) * $spot)) / 0.6;
        $this->assertEqualsWithDelta(self::powerSeason(), $strike, 0.02, 'the hedged volume is still delivered at its strike, the shortfall bought at spot');

        $stillOut = $this->report($stock, $this->macro(gas: 1.5, time: 0.25), $this->draws([0.0, 0.0, 0.0], exits: false));
        $this->assertNull($stillOut->eventType);
        $this->assertEqualsWithDelta($available, $stillOut->kpis['availability'], 1e-12);

        $back = $this->report($stock, $this->macro(gas: 1.5, time: 0.5), $this->draws([0.0, 0.0, 0.0], exits: true));
        $this->assertEqualsWithDelta(1.0, $back->kpis['availability'], 1e-12);
    }

    /** The zero-fuel plant runs whatever the load, so the cycle's swing lands on the gas stations alone. */
    public function testLoadFollowsTheCycleThroughTheGasStations(): void
    {
        $gap = -0.03;
        $recession = $this->macro(extra: ['outputGapEma' => $gap]);
        $gasSwing = $gap * IndependentPowerBusinessModel::OPERATING_CYCLICALITY / IndependentPowerBusinessModel::SYSTEM_GAS_GENERATION_SHARE;

        $report = $this->report($this->generator(), $recession);
        $this->assertEqualsWithDelta(1.0 + (0.5 * $gasSwing), $report->kpis['generation_index'], 1e-12);
        $this->assertEqualsWithDelta(0.8 * 0.5 * $gasSwing, $this->model->resolveSectorActivityShift($this->generator(), $recession), 1e-12);
        $this->assertLessThan(0.0, $this->model->resolveSectorActivityShift($this->generator(), $recession));
    }

    /**
     * The pool price carries no inflation of its own: the engine's escalator reaches the capacity contracts alone, so
     * the wholesale stream is not inflated twice.
     */
    public function testInflationEscalatesTheCapacityContractsAndNotThePoolPrice(): void
    {
        $stock = $this->generator();
        $macro = $this->macro(extra: ['inflationEma' => 0.04]);

        $physics = $this->model->getMacroPhysics($stock, $macro);
        $passThrough = (float) $stock->getInflationPassThrough();
        $this->assertEqualsWithDelta(0.04, $passThrough, 1e-12);
        $this->assertEqualsWithDelta(1.0 + (0.2 * $passThrough), $physics['pricing_power_multiplier'], 1e-12);
        $this->assertEqualsWithDelta($physics['pricing_power_multiplier'], $physics['input_cost_multiplier'], 1e-12);

        $report = $this->report($stock, $macro);
        $base = 100.0 / $physics['pricing_power_multiplier'];
        $this->assertEqualsWithDelta($base * 0.8 * self::powerSeason(), $report->streamRevenue['wholesale_generation'], 1e-9);
        $this->assertEqualsWithDelta($base * 0.2 * (1.0 + $passThrough), $report->streamRevenue['capacity_payments'], 1e-9);
    }

    /**
     * What a carbon price's power uplift is worth to the firm: the uplift on every MWh less the carbon the gas half
     * pays.
     */
    public function testTheCarbonUpliftEarningsShareIsRegistered(): void
    {
        $report = $this->report($this->generator(), $this->macro());

        $this->assertEqualsWithDelta(0.8 * (1.0 - (0.5 / 0.80)) / ((0.8 * self::powerSeason()) + 0.2), $report->streamZ[FinancialConstants::STATE_CARBON_POWER_EARNINGS_SHARE], 1e-9);
    }
}
