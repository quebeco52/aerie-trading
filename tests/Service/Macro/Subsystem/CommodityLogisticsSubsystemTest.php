<?php

namespace App\Tests\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

class CommodityLogisticsSubsystemTest extends TestCase
{
    private MathUtility $mathUtility;
    private CommodityLogisticsSubsystem $subsystem;

    protected function setUp(): void
    {
        $this->mathUtility = new MathUtility();
        $this->subsystem = new CommodityLogisticsSubsystem($this->mathUtility);
    }

    public function testCommodityAndFreightIndicesStayWithinSensibleBounds(): void
    {
        $state = new MacroState();
        $state->energyPriceIndex = 100.0;
        $state->industrialMetalsIndex = 100.0;
        $state->agriculturalCommodityIndex = 100.0;
        $state->freightRateIndex = 100.0;
        $state->freightSupplyEma = 100.0;

        $this->subsystem->calculateEnergyShock($state, 0.25);
        $this->subsystem->calculateIndustrialMetalsIndex($state, 0.25);
        $this->subsystem->calculateAgriculturalCommodityIndex($state, 0.25);
        $this->subsystem->calculateFreightRateIndex($state, 0.25);

        $this->assertGreaterThan(10.0, $state->energyPriceIndex);
        $this->assertGreaterThan(20.0, $state->industrialMetalsIndex);
        $this->assertGreaterThan(20.0, $state->agriculturalCommodityIndex);
        $this->assertGreaterThan(20.0, $state->freightRateIndex);
    }

    public function testEnergyEquilibriumClearsGlobalDemandOverLaggedCapacity(): void
    {
        // Balanced: demand at baseline against baseline capacity clears at the baseline price.
        $this->assertEqualsWithDelta(
            MacroEngine::ENERGY_BASELINE,
            CommodityLogisticsSubsystem::resolveEnergyEquilibriumPrice(0.0, MacroEngine::ENERGY_BASELINE),
            1e-9
        );

        // A 2% global boom against unchanged capacity clears at the combined inelasticity.
        $boomDemand = 1.0 + (0.02 * CommodityLogisticsSubsystem::ENERGY_DEMAND_GAP_SENSITIVITY);
        $this->assertEqualsWithDelta(
            MacroEngine::ENERGY_BASELINE * ($boomDemand ** CommodityLogisticsSubsystem::ENERGY_CAPACITY_INELASTICITY),
            CommodityLogisticsSubsystem::resolveEnergyEquilibriumPrice(0.02, MacroEngine::ENERGY_BASELINE),
            1e-9
        );

        // Capacity built out far beyond demand cannot drag the equilibrium below the floor any market has cleared at.
        $this->assertSame(
            CommodityLogisticsSubsystem::MIN_ENERGY_EQUILIBRIUM,
            CommodityLogisticsSubsystem::resolveEnergyEquilibriumPrice(-0.05, 400.0)
        );
    }

    // --- Gold ---

    /** Barsky, Epstein, Lafont-Mueller & Yoo (2021): real rates, inflation expectations and bad times. */
    public function testGoldEquilibriumCarriesTheMeasuredSensitivities(): void
    {
        $realRate = CommodityLogisticsSubsystem::GOLD_REAL_RATE_ANCHOR;
        $inflation = CommodityLogisticsSubsystem::GOLD_INFLATION_EXPECTATION_ANCHOR;
        $sentiment = MacroEngine::SENTIMENT_TREND_LEVEL;
        $resting = CommodityLogisticsSubsystem::resolveGoldEquilibriumPrice($realRate, $inflation, $sentiment);

        $this->assertEqualsWithDelta(MacroEngine::GOLD_BASELINE, $resting, 1e-9, 'every driver at rest: gold at its baseline');
        $this->assertEqualsWithDelta(exp(-0.131), CommodityLogisticsSubsystem::resolveGoldEquilibriumPrice($realRate + 0.01, $inflation, $sentiment) / $resting, 1e-9, 'a point of real yield takes 13.1 log points off');
        $this->assertEqualsWithDelta(exp(0.365), CommodityLogisticsSubsystem::resolveGoldEquilibriumPrice($realRate, $inflation + 0.01, $sentiment) / $resting, 1e-9, 'a point of expected inflation adds 36.5');
        $this->assertGreaterThan($resting, CommodityLogisticsSubsystem::resolveGoldEquilibriumPrice($realRate, $inflation, $sentiment - 15.0), 'bad times raise the price of the refuge');
    }

    public function testGoldRevertsTowardItsEquilibriumAtTheMeasuredSpeed(): void
    {
        $quiet = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
        };
        $subsystem = new CommodityLogisticsSubsystem($quiet);

        $state = new MacroState();
        $state->yield10yEma = CommodityLogisticsSubsystem::GOLD_REAL_RATE_ANCHOR + CommodityLogisticsSubsystem::GOLD_INFLATION_EXPECTATION_ANCHOR;
        $state->tipsBreakevenEma = CommodityLogisticsSubsystem::GOLD_INFLATION_EXPECTATION_ANCHOR;
        $state->consumerSentimentIndexEma = MacroEngine::SENTIMENT_TREND_LEVEL;
        $state->goldPriceIndex = 150.0;

        for ($quarter = 0; $quarter < 4; $quarter++) {
            $subsystem->calculateGoldPriceIndex($state, 0.25);
        }

        // One year of the exact log-OU transition with no noise.
        $kappa = CommodityLogisticsSubsystem::GOLD_MEAN_REVERSION;
        $sigma = CommodityLogisticsSubsystem::GOLD_VOLATILITY;
        // The log mean that leaves the level averaging the equilibrium: ln theta - sigma^2 / 4 kappa.
        $alpha = log(MacroEngine::GOLD_BASELINE) - ($sigma * $sigma) / (4.0 * $kappa);
        $expected = exp((exp(-$kappa) * log(150.0)) + ((1.0 - exp(-$kappa)) * $alpha));
        $this->assertEqualsWithDelta($expected, $state->goldPriceIndex, 1e-6);
        $this->assertEqualsWithDelta(0.51, exp(-$kappa), 0.01, 'deviations carry half their size into the next year');
    }

    public function testGoldIsAsVolatileAsTheMetalItModels(): void
    {
        mt_srand(20260924);
        $state = new MacroState();
        $state->yield10yEma = CommodityLogisticsSubsystem::GOLD_REAL_RATE_ANCHOR + CommodityLogisticsSubsystem::GOLD_INFLATION_EXPECTATION_ANCHOR;
        $state->tipsBreakevenEma = CommodityLogisticsSubsystem::GOLD_INFLATION_EXPECTATION_ANCHOR;
        $state->consumerSentimentIndexEma = MacroEngine::SENTIMENT_TREND_LEVEL;

        $annual = [];
        $previous = $state->goldPriceIndex;
        for ($week = 1; $week <= 200 * 52; $week++) {
            $this->subsystem->calculateGoldPriceIndex($state, 1.0 / 52.0);
            if ($week % 52 === 0) {
                $annual[] = log($state->goldPriceIndex / $previous);
                $previous = $state->goldPriceIndex;
            }
        }

        $mean = array_sum($annual) / count($annual);
        $sd = sqrt(array_sum(array_map(static fn (float $x): float => ($x - $mean) ** 2, $annual)) / count($annual));
        // Gold's annualised volatility has stayed within 10-18% on most days since 1971 (World Gold Council).
        $this->assertGreaterThan(0.10, $sd);
        $this->assertLessThan(0.18, $sd);
    }

    public function testConvenienceYieldSpikesWhenPhysicalInventoryDrawsDown(): void
    {
        $ampleYield = $this->mathUtility->calculateConvenienceYield(105.0, 50.0);
        $this->assertEquals(0.0, $ampleYield, 'Ample buffer stocks must have zero convenience yield (contango)');

        $tightYield = $this->mathUtility->calculateConvenienceYield(75.0, 50.0);
        $criticalYield = $this->mathUtility->calculateConvenienceYield(55.0, 50.0);

        $this->assertGreaterThan(0.0, $tightYield);
        $this->assertGreaterThan(3.0 * $tightYield, $criticalYield, 'Critical inventory depletion must spike convenience yield non-linearly');

        $state = new MacroState();
        $state->energyPriceIndex = 100.0;
        $state->energyInventoryIndex = 55.0; // Very tight inventory buffer

        $this->subsystem->calculateEnergyShock($state, 0.25);
        $this->assertGreaterThan(0.0, $state->energyPriceShock, 'Depleted buffer inventory must generate backwardation price shock');
    }

    public function testCalculateRefiningCrackSpread(): void
    {
        $state = new MacroState();
        $state->refiningCrackSpread = 22.0;
        $state->outputGapEma = 0.02;
        $state->energyInventoryIndexEma = 75.0; // Tight inventory

        $this->subsystem->calculateRefiningCrackSpread($state, 0.25);
        $this->assertGreaterThan(10.0, $state->refiningCrackSpread);
        $this->assertLessThan(80.0, $state->refiningCrackSpread);
    }

    public function testTheCrackPeaksInTheDrivingSeasonAndTroughsInAutumn(): void
    {
        $quiet = new class extends MathUtility {
            public function generateStandardNormal(): float
            {
                return 0.0;
            }
        };
        $subsystem = new CommodityLogisticsSubsystem($quiet);
        $state = new MacroState();
        $state->globalDemandGapEma = 0.0;
        $state->energyInventoryIndexEma = MacroEngine::COMMODITY_INVENTORY_BASELINE;

        // Settle the stochastic part, then read the season off one more year, a week at a time.
        $crackAt = [];
        for ($week = 1; $week <= 4 * 52; $week++) {
            $state->totalTime += 1.0 / 52.0;
            $subsystem->calculateRefiningCrackSpread($state, 1.0 / 52.0);
            if ($week > 3 * 52) {
                $crackAt[(int) round(fmod($state->totalTime, 1.0) * 52)] = $state->refiningCrackSpread;
            }
        }

        // Early June against early December: the seasonal factor's own peak-to-trough ratio.
        $amplitude = CommodityLogisticsSubsystem::CRACK_SEASONAL_AMPLITUDE;
        $june = $crackAt[(int) round(CommodityLogisticsSubsystem::CRACK_SEASONAL_PEAK * 52)];
        $december = $crackAt[(int) round((CommodityLogisticsSubsystem::CRACK_SEASONAL_PEAK + 0.5) * 52)];
        $this->assertEqualsWithDelta((1.0 + $amplitude) / (1.0 - $amplitude), $june / $december, 0.01);
    }

    /** EIA Gulf Coast 3-2-1 against WTI, 2010-2024: de-seasonalised log sd 0.44, a half-life of about six months, a ~$19-22 mean. */
    public function testTheCrackIsAsVolatileAndAsPersistentAsTheRealOne(): void
    {
        mt_srand(20260924);
        $state = new MacroState();
        $state->globalDemandGapEma = 0.0;
        $state->energyInventoryIndexEma = MacroEngine::COMMODITY_INVENTORY_BASELINE;

        $logBase = [];
        $levels = [];
        for ($step = 1; $step <= 200 * 48; $step++) {
            $state->totalTime += 1.0 / 48.0;
            $this->subsystem->calculateRefiningCrackSpread($state, 1.0 / 48.0);
            if ($step % 4 === 0) {
                $levels[] = $state->refiningCrackSpread;
                $logBase[] = log($state->refiningCrackSpread / CommodityLogisticsSubsystem::resolveCrackSeasonalFactor($state->totalTime));
            }
        }

        $n = count($logBase);
        $mean = array_sum($logBase) / $n;
        $variance = array_sum(array_map(static fn (float $x): float => ($x - $mean) ** 2, $logBase)) / $n;
        $lagged = 0.0;
        for ($i = 1; $i < $n; $i++) {
            $lagged += ($logBase[$i] - $mean) * ($logBase[$i - 1] - $mean);
        }
        $halfLifeYears = log(0.5) / log($lagged / ($variance * $n)) / 12.0;

        $this->assertGreaterThan(0.35, sqrt($variance), 'as dispersed as the real crack');
        $this->assertLessThan(0.55, sqrt($variance));
        $this->assertGreaterThan(0.30, $halfLifeYears, 'shocks fade over months, not weeks');
        $this->assertLessThan(0.80, $halfLifeYears);
        $this->assertEqualsWithDelta(MacroEngine::CRACK_SPREAD_BASELINE, array_sum($levels) / count($levels), 2.5, 'centred on the baseline, not ten percent below it');
    }

    public function testCalculateSupplyChainPressureIndex(): void
    {
        $stateNeutral = new MacroState();
        $stateNeutral->freightRateIndexEma = 100.0;
        $stateNeutral->inventoryStockGapEma = 0.0;
        $stateNeutral->industrialMetalsIndexEma = 100.0;

        $this->subsystem->calculateSupplyChainPressureIndex($stateNeutral);
        $this->assertEqualsWithDelta(0.0, $stateNeutral->supplyChainPressureIndex, 0.0001);

        $stateChoked = new MacroState();
        $stateChoked->freightRateIndexEma = 220.0; // Huge ocean freight spike
        $stateChoked->inventoryStockGapEma = -0.04; // Severe inventory depletion
        $stateChoked->industrialMetalsIndexEma = 140.0;

        $this->subsystem->calculateSupplyChainPressureIndex($stateChoked);
        $this->assertGreaterThan(1.5, $stateChoked->supplyChainPressureIndex, 'Logistics bottlenecks must generate positive GSCPI stress.');
    }


    // --- Natural Gas ---

    /** Ramberg & Parsons (2012): the gas-to-oil ratio is stationary around one; the level is not. */
    public function testTheGasToOilRatioIsStationaryAroundUnity(): void
    {
        mt_srand(20260919);
        $state = new MacroState();
        $state->energyPriceIndex = 100.0;

        $logRatios = [];
        $ratios = [];
        for ($i = 0; $i < 40 * 252; $i++) {
            $state->totalTime += 1.0 / 252.0;
            $this->subsystem->calculateNaturalGasIndex($state, 1.0 / 252.0);
            if ($i > 5 * 252 && $i % 63 === 0) {
                $logRatios[] = $state->gasOilRatioLog;
                $ratios[] = exp($state->gasOilRatioLog);
            }
        }

        $count = count($logRatios);
        $this->assertEqualsWithDelta(1.0, array_sum($ratios) / $count, 0.10, 'The ratio averages one: gas prices off oil.');
        $variance = 0.0;
        $mean = array_sum($logRatios) / $count;
        foreach ($logRatios as $x) {
            $variance += ($x - $mean) ** 2;
        }
        $this->assertGreaterThan(0.20, sqrt($variance / $count), 'but wanders enough to be a market of its own');
        $this->assertLessThan(0.45, sqrt($variance / $count), 'without leaving the recorded range.');
    }

    public function testGasCarriesAWinterPremiumOverItsSummerPrice(): void
    {
        $quiet = new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
            public function checkProbability(float $probability): bool { return false; }
        };
        $subsystem = new CommodityLogisticsSubsystem($quiet);

        $winter = new MacroState();
        $winter->totalTime = 3.0;
        $summer = new MacroState();
        $summer->totalTime = 3.5;

        $subsystem->calculateNaturalGasIndex($winter, 0.01);
        $subsystem->calculateNaturalGasIndex($summer, 0.01);

        $expectedRatio = (1.0 + CommodityLogisticsSubsystem::GAS_SEASONALITY_AMPLITUDE) / (1.0 - CommodityLogisticsSubsystem::GAS_SEASONALITY_AMPLITUDE);
        $this->assertEqualsWithDelta($expectedRatio, $winter->naturalGasPriceIndex / $summer->naturalGasPriceIndex, 0.001);
    }

    public function testGasFollowsOilThroughTheRatio(): void
    {
        $quiet = new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
            public function checkProbability(float $probability): bool { return false; }
        };
        $subsystem = new CommodityLogisticsSubsystem($quiet);

        $cheapOil = new MacroState();
        $cheapOil->totalTime = 0.25;
        $cheapOil->energyPriceIndex = 80.0;
        $dearOil = new MacroState();
        $dearOil->totalTime = 0.25;
        $dearOil->energyPriceIndex = 160.0;

        $subsystem->calculateNaturalGasIndex($cheapOil, 0.01);
        $subsystem->calculateNaturalGasIndex($dearOil, 0.01);

        $this->assertEqualsWithDelta(2.0, $dearOil->naturalGasPriceIndex / $cheapOil->naturalGasPriceIndex, 1e-9, 'With the ratio pinned, gas is oil.');
    }

    // --- Wholesale Power ---

    public function testPowerPassesGasThroughAtTheHubsElasticity(): void
    {
        $subsystem = $this->quietCommoditySubsystem();
        $cheapGas = new MacroState();
        $cheapGas->totalTime = 0.25;
        $cheapGas->naturalGasPriceIndex = 80.0;
        $dearGas = new MacroState();
        $dearGas->totalTime = 0.25;
        $dearGas->naturalGasPriceIndex = 160.0;

        $subsystem->calculateWholesalePowerIndex($cheapGas, 0.01);
        $subsystem->calculateWholesalePowerIndex($dearGas, 0.01);

        // Four gas-marginal hubs on Henry Hub, 2017-2025: a doubling of gas lifts power by 2^0.852, not one for one.
        $this->assertEqualsWithDelta(2.0 ** 0.852, $dearGas->wholesalePowerPriceIndex / $cheapGas->wholesalePowerPriceIndex, 1e-9);
    }

    public function testPowerPeaksInWinterAndSummerAndTroughsInSpring(): void
    {
        $factors = [];
        for ($day = 0; $day < 360; $day++) {
            $factors[] = CommodityLogisticsSubsystem::resolvePowerSeasonalFactor($day / 360.0);
        }
        $this->assertEqualsWithDelta(1.0, array_sum($factors) / count($factors), 1e-9, 'the season moves power within the year, not its level');

        $month = static fn (int $m): float => CommodityLogisticsSubsystem::resolvePowerSeasonalFactor(($m + 0.5) / 12.0);
        $this->assertGreaterThan($month(3), $month(0), 'January heating load over April');
        $this->assertGreaterThan($month(0), $month(6), 'the air-conditioning peak is the larger one');
        $this->assertLessThan($month(9), $month(3), 'spring, with hydro and mild weather, is the trough');
        $this->assertSame(3, array_search(min(array_map($month, range(0, 11))), array_map($month, range(0, 11)), true));
    }

    public function testTheHeatRateIsAsVolatileAndAsPersistentAsTheHubsAtTheQuarterFirmsBook(): void
    {
        mt_srand(20260925);
        $state = new MacroState();
        $dt = 1.0 / 360.0;

        $quarterly = [];
        $levels = [];
        $window = [];
        for ($step = 1; $step <= 120 * 360; $step++) {
            $state->totalTime += $dt;
            $state->naturalGasPriceIndex = MacroEngine::NATURAL_GAS_BASELINE;
            $this->subsystem->calculateWholesalePowerIndex($state, $dt);
            $levels[] = $state->wholesalePowerPriceIndex;
            $window[] = $state->wholesalePowerPriceIndex / CommodityLogisticsSubsystem::resolvePowerSeasonalFactor($state->totalTime);
            if ($step % 90 === 0) {
                $quarterly[] = log(array_sum($window) / count($window));
                $window = [];
            }
        }

        $n = count($quarterly);
        $mean = array_sum($quarterly) / $n;
        $variance = array_sum(array_map(static fn (float $x): float => ($x - $mean) ** 2, $quarterly)) / $n;
        $lagged = 0.0;
        for ($i = 1; $i < $n; $i++) {
            $lagged += ($quarterly[$i] - $mean) * ($quarterly[$i - 1] - $mean);
        }

        // Four-hub quarterly means of the de-seasonalised log heat rate, 2017-2025: sd 0.183, AR1 0.37.
        $this->assertEqualsWithDelta(0.183, sqrt($variance), 0.03);
        $this->assertEqualsWithDelta(0.37, $lagged / ($variance * $n), 0.12);
        $this->assertEqualsWithDelta(MacroEngine::WHOLESALE_POWER_BASELINE, array_sum($levels) / count($levels), 2.0, 'centred on the baseline, not its median');
    }


    // --- Physical Catastrophes ---

    public function testTheCatastropheBurdenAveragesAnAverageYearWithARealRightTail(): void
    {
        mt_srand(20260919);
        $state = new MacroState();
        $samples = [];
        for ($i = 0; $i < 60 * 252; $i++) {
            $state->totalTime += 1.0 / 252.0;
            $this->subsystem->calculateCatastropheLosses($state, 1.0 / 252.0);
            if ($i > 2 * 252 && $i % 21 === 0) {
                $samples[] = $state->catastropheLossIndex;
            }
        }

        sort($samples);
        $count = count($samples);
        $mean = array_sum($samples) / $count;
        $this->assertEqualsWithDelta(1.0, $mean, 0.25, 'The burden averages an average year: lambda x mean severity / decay = 1.');
        $this->assertGreaterThan(3.0 * $mean, $samples[(int) floor(0.99 * $count)], 'and the worst percent of months carry several average years of losses.');
        $this->assertLessThan(0.5 * $mean, $samples[(int) floor(0.25 * $count)], 'while most months are quiet.');
    }

    public function testAHeadlineStormIsRecordedOnTheTickItLands(): void
    {
        $math = new class extends MathUtility {
            public int $events = 0;
            public float $severity = 0.0;
            public function generatePoissonCount(float $mean): int { return $this->events; }
            public function generateParetoSeverity(float $scale, float $alpha, float $cap): float { return $this->severity; }
        };
        $subsystem = new CommodityLogisticsSubsystem($math);
        $state = new MacroState();
        $state->totalTime = 2.0;
        $state->catastropheLossIndex = 0.0; // a clean slate, so the two scripted events are all the burden there is

        $math->events = 1;
        $math->severity = CommodityLogisticsSubsystem::SYSTEMIC_CATASTROPHE_SEVERITY - 0.1;
        $subsystem->calculateCatastropheLosses($state, 0.01);
        $this->assertSame(-1.0, $state->lastCatastropheAt, 'A routine event is claims, not news.');

        $state->totalTime = 2.01;
        $math->severity = 4.0;
        $subsystem->calculateCatastropheLosses($state, 0.01);
        $this->assertSame(2.01, $state->lastCatastropheAt);
        $this->assertSame(4.0, $state->lastCatastropheSeverity);
        $this->assertGreaterThan(4.0, $state->catastropheLossIndex, 'The burden carries both events.');

        $math->events = 0;
        $state->totalTime = 2.51;
        $subsystem->calculateCatastropheLosses($state, 0.5);
        $this->assertEqualsWithDelta(exp(-CommodityLogisticsSubsystem::CATASTROPHE_LOSS_DECAY * 0.5), $state->catastropheLossIndex / (4.0 + (CommodityLogisticsSubsystem::SYSTEMIC_CATASTROPHE_SEVERITY - 0.1) * exp(-CommodityLogisticsSubsystem::CATASTROPHE_LOSS_DECAY * 0.01)), 0.01, 'and settles at the claims decay rate.');
    }


    // --- Energy Supply Cobweb ---

    private function quietCommoditySubsystem(): CommodityLogisticsSubsystem
    {
        $quiet = new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
            public function checkProbability(float $probability): bool { return false; }
        };

        return new CommodityLogisticsSubsystem($quiet);
    }

    /** Anderson, Kellogg & Salant (2018): a sustained high price summons capacity, but only over the investment lag. */
    public function testASustainedPriceLiftsCapacityOverTheInvestmentLag(): void
    {
        $subsystem = $this->quietCommoditySubsystem();
        $state = new MacroState();
        $state->energyPriceIndexEma = 120.0;
        $state->energyInventoryIndex = 100.0;

        $subsystem->calculateEnergyShock($state, 0.25);
        $this->assertLessThan(101.0, $state->energySupplyEma, 'A quarter of high prices adds almost no capacity.');

        for ($i = 0; $i < 4 * 9; $i++) {
            $state->energyPriceIndexEma = 120.0;
            $subsystem->calculateEnergyShock($state, 0.25);
        }
        $targetSupply = 100.0 * (1.2 ** CommodityLogisticsSubsystem::ENERGY_SUPPLY_ELASTICITY);
        $this->assertEqualsWithDelta($targetSupply, $state->energySupplyEma, 0.3, 'Nine years on, capacity has caught up with the price at the supply elasticity.');
    }

    public function testTheEquilibriumPriceClearsDemandAgainstCapacity(): void
    {
        $subsystem = $this->quietCommoditySubsystem();

        $settle = static function (float $gap, float $supply) use ($subsystem): float {
            $state = new MacroState();
            $state->globalDemandGapEma = $gap; // fuel clears on world demand
            $state->energySupplyEma = $supply;
            $state->energyPriceIndex = 100.0;
            $state->energyBasePrice = 100.0;
            $state->energyInventoryIndex = 100.0;
            for ($i = 0; $i < 400; $i++) {
                $state->energyPriceIndexEma = 100.0; // hold the price capacity sees, so only demand moves the clearing level
                $state->energySupplyEma = $supply;
                $subsystem->calculateEnergyShock($state, 0.01);
            }

            return $state->energyBasePrice;
        };

        $neutral = $settle(0.0, 100.0);
        $boom = $settle(0.04, 100.0);
        $glut = $settle(0.0, 104.0);

        $expectedBoom = $neutral * ((1.0 + 0.04 * CommodityLogisticsSubsystem::ENERGY_DEMAND_GAP_SENSITIVITY) ** CommodityLogisticsSubsystem::ENERGY_CAPACITY_INELASTICITY);
        $this->assertEqualsWithDelta($expectedBoom, $boom, 0.5, 'A 4% boom lifts demand 2% and the clearing price by the inelasticity of that.');
        $expectedGlut = $neutral * ((100.0 / 104.0) ** CommodityLogisticsSubsystem::ENERGY_CAPACITY_INELASTICITY);
        $this->assertEqualsWithDelta($expectedGlut, $glut, 0.5, 'and 4% of spare capacity does the same in reverse.');
    }

    public function testCapacityAdjustmentIsInvariantToTheTickRate(): void
    {
        $subsystem = $this->quietCommoditySubsystem();

        $run = static function (int $ticksPerYear) use ($subsystem): float {
            $state = new MacroState();
            $state->energyInventoryIndex = 100.0;
            for ($i = 0; $i < $ticksPerYear * 2; $i++) {
                $state->energyPriceIndexEma = 130.0;
                $subsystem->calculateEnergyShock($state, 1.0 / $ticksPerYear);
            }

            return $state->energySupplyEma;
        };

        $this->assertEqualsWithDelta($run(4), $run(252), 0.05, 'Two years of capacity build must not depend on how finely the years are ticked.');
    }


    /** A district this size does not set the copper price: metals clear on world demand, so a foreign boom moves them with the district flat. */
    public function testMetalsClearOnGlobalDemandNotTheDistrictsGapAlone(): void
    {
        $subsystem = $this->quietCommoditySubsystem();

        $settle = static function (float $globalGap) use ($subsystem): float {
            $state = new MacroState();
            $state->outputGapEma = 0.0;
            $state->globalDemandGapEma = $globalGap;
            for ($i = 0; $i < 4000; $i++) {
                $subsystem->calculateIndustrialMetalsIndex($state, 0.01);
            }

            return $state->industrialMetalsIndex;
        };

        $flat = $settle(0.0);
        $worldBoom = $settle(0.03);
        $this->assertEqualsWithDelta(exp(0.03 * CommodityLogisticsSubsystem::METALS_OUTPUT_GAP_SENSITIVITY), $worldBoom / $flat, 0.01, 'A 3% world boom lifts the long-run metals equilibrium by its elasticity.');
    }

    /**
     * One oil shock at the first tick, then quiet: a subsystem whose jump lands once, as a +50% move.
     */
    private function oneShockSubsystem(): CommodityLogisticsSubsystem
    {
        $math = new class extends MathUtility {
            private bool $landed = false;
            public function generateStandardNormal(): float { return 0.0; }
            public function checkProbability(float $probability): bool { return false; }
            public function calculateJumpDiffusion(float $lambda, float $jumpMean, float $jumpVol, float $dt): array
            {
                if ($this->landed) {
                    return ['multiplier' => 1.0, 'shock_pct' => null, 'exponent' => null];
                }
                $this->landed = true;

                return ['multiplier' => 1.5, 'shock_pct' => 50.0, 'exponent' => log(1.5)];
            }
        };

        return new CommodityLogisticsSubsystem($math);
    }

    /** Log deviation of the base price from where the same path sits without the shock, stepped for $years. */
    private function shockPath(float $dt, float $years): array
    {
        $run = function (CommodityLogisticsSubsystem $subsystem) use ($dt, $years): array {
            $state = new MacroState();
            $state->energyBasePrice = 100.0;
            $state->energyPriceIndex = 100.0;
            $state->energyInventoryIndex = 100.0;
            $path = [];
            for ($i = 0; $i < (int) round($years / $dt); $i++) {
                $state->energyPriceIndexEma = 100.0;
                $state->energySupplyEma = 100.0;
                $subsystem->calculateEnergyShock($state, $dt);
                $path[] = $state->energyBasePrice;
            }

            return $path;
        };
        $shocked = $run($this->oneShockSubsystem());
        $quiet = $run($this->quietCommoditySubsystem());

        return array_map(static fn (float $a, float $b): float => log($a / $b), $shocked, $quiet);
    }

    /** Merton (1976): an oil shock is a move in the level, which then reverts like any other; it does not vanish after a tick. */
    public function testAnOilShockLandsInTheBasePriceAndDecaysAtTheReversionSpeed(): void
    {
        $dt = 1.0 / 360.0;
        $path = $this->shockPath($dt, 1.0);

        $this->assertEqualsWithDelta(log(1.5), $path[0], 1e-9, 'The shock is in the base price on the tick it lands.');
        $this->assertEqualsWithDelta(log(1.5) * exp(-CommodityLogisticsSubsystem::ENERGY_MEAN_REVERSION * (count($path) - 1) * $dt), $path[count($path) - 1], 1e-9, 'A year on it has decayed at the reversion speed, not vanished.');
    }

    /** A shock's weight on the price over time is the same at every step size (a one-tick shock's weight shrinks with dt). */
    public function testAnOilShockCarriesTheSameWeightAtEveryStepSize(): void
    {
        $area = function (float $dt): float {
            return array_sum($this->shockPath($dt, 2.0)) * $dt;
        };

        $coarse = $area(1.0 / 360.0);
        $fine = $area(1.0 / 3600.0);

        $this->assertEqualsWithDelta($coarse, $fine, 0.01 * $coarse);
        $this->assertEqualsWithDelta(log(1.5) * (1.0 - exp(-2.0 * CommodityLogisticsSubsystem::ENERGY_MEAN_REVERSION)) / CommodityLogisticsSubsystem::ENERGY_MEAN_REVERSION, $fine, 0.01 * $fine);
    }

    /** A carbon price adds the gas fleet's carbon per MWh to wholesale power, as far as the market passes it through (Fabra & Reguant 2014). */
    public function testACarbonPriceAddsTheGasFleetsCarbonToPower(): void
    {
        $subsystem = $this->quietCommoditySubsystem();
        $free = new MacroState();
        $free->totalTime = 0.25;
        $priced = clone $free;
        $priced->carbonPrice = 70.37;

        $subsystem->calculateWholesalePowerIndex($free, 0.01);
        $subsystem->calculateWholesalePowerIndex($priced, 0.01);

        // 53.06 kg of CO2 per MMBtu, 7.74 MMBtu per MWh: 0.41 t a MWh, 80% of which reaches the price.
        $adder = 0.80 * 0.05306 * 7.74 * 70.37;
        $this->assertEqualsWithDelta($adder, CommodityLogisticsSubsystem::carbonPowerPriceAdder(70.37), 1e-12);
        $this->assertEqualsWithDelta(MacroEngine::WHOLESALE_POWER_BASELINE * $adder / CommodityLogisticsSubsystem::REFERENCE_POWER_PRICE, $priced->wholesalePowerPriceIndex - $free->wholesalePowerPriceIndex, 1e-9);
        $this->assertSame(0.0, CommodityLogisticsSubsystem::carbonPowerPriceAdder(0.0));
        $this->assertEqualsWithDelta(($priced->wholesalePowerPriceIndex - $free->wholesalePowerPriceIndex) / MacroEngine::WHOLESALE_POWER_BASELINE, CommodityLogisticsSubsystem::carbonPowerPriceUplift(70.37), 1e-9, 'The uplift is the carbon part of the power index\'s move off its baseline.');
    }

    /** A state from before the carbon lag has its earnings already carrying the carbon price in force. */
    public function testAStateFromBeforeTheLagCarriesTheCarbonPriceInForce(): void
    {
        $payload = (new MacroState())->toArray();
        $payload['carbon_price'] = 40.0;
        unset($payload['carbon_power_uplift_embodied']);

        $this->assertSame(CommodityLogisticsSubsystem::carbonPowerPriceUplift(40.0), MacroState::fromArray($payload)->carbonPowerUpliftEmbodied);
    }
}
