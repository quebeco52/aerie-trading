<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\Distributions;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Model\ModelParam;
use App\Service\Model\ModelParameters;
use App\Service\Model\Sector\UtilityBusinessModel;
use App\Service\Model\StreamContext;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\TestCase;

class UtilityBusinessModelTest extends TestCase
{
    public function testAStrongMerchantGenerationQuarterRaisesRevenue(): void
    {
        $model = new UtilityBusinessModel();
        $stock = new Stock();
        $stock->setTicker('UTIL');
        $stock->setBeta('0.5');

        // Sequence of generateStandardNormal calls:
        // 1. firm-wide demand innovation = 0.0
        // 2. regulatedZ = 0.0
        // 3. unregulatedZ idiosyncratic = 2.5 (composite 2.0: a strong wind and solar quarter)
        // 4. eventZ = 0.0
        $mathUtilityMock = new ScriptedDraws(generateStandardNormal: [0.0, 0.0, 2.5, 0.0]);

        $macroState = MacroStateDTO::fromArray(['inflation_ema' => 0.02]);
        $result = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.40,
            100.0,
            0.20,
            $macroState,
            $mathUtilityMock,
        );

        $this->assertSame(4, $mathUtilityMock->callCount('generateStandardNormal'));
        // More merchant generation at the same wholesale price is more revenue
        $this->assertGreaterThan(1000.0, $result->actualRevenue);
    }

    /**
     * Rate-base build-out spends cash on plant the allowed return pays for, so it discounts nothing: the multiple is
     * the value.
     */
    public function testRateBaseBuildOutDoesNotDiscountTheEarningsValue(): void
    {
        $this->assertSame(100.0, (new UtilityBusinessModel())->calculateEarningsValue(80.0, 100.0));
    }

    /**
     * US retail electricity revenue (EIA-861M, 2015-2019) has one summer peak, not twin winter and summer
     * peaks: Q1 sits below the mean and the autumn shoulder is the trough.
     */
    public function testElectricSeasonalityIsOneSummerPeak(): void
    {
        $factors = $this->seasonalityOf('Utilities - Regulated Electric');

        $this->assertSame([0.953, 0.938, 1.183, 0.926], $factors);
        $this->assertEqualsWithDelta(4.0, array_sum($factors), 1e-9);
        $this->assertSame(2, array_search(max($factors), $factors, true), 'Q3 cooling load is the peak');
        $this->assertSame(3, array_search(min($factors), $factors, true), 'the autumn shoulder is the trough');
        $this->assertLessThan(1.0, $factors[0], 'winter heating does not lift Q1 above the mean');
    }

    /** American Water Works revenue (2015-2019): irrigation lifts summer, and winter has no heating load to sell. */
    public function testWaterSeasonalityPeaksInSummerAndBottomsInWinter(): void
    {
        $factors = $this->seasonalityOf('Utilities - Regulated Water');

        $this->assertSame([0.908, 0.998, 1.121, 0.973], $factors);
        $this->assertEqualsWithDelta(4.0, array_sum($factors), 1e-9);
        $this->assertSame(2, array_search(max($factors), $factors, true), 'Q3 outdoor watering is the peak');
        $this->assertSame(0, array_search(min($factors), $factors, true), 'Q1 is the trough');
    }

    /**
     * Every seeded utility gets its own industry's calendar; any other industry on the model reads the electric one.
     */
    public function testEachSeededUtilityResolvesItsIndustrysCalendar(): void
    {
        $seen = [];
        foreach (\App\Data\Company\InitialMarket::STOCKS as $row) {
            if (\App\Data\Company\Sectors::businessModelFor($row['industry']) !== 'utility') {
                continue;
            }
            $seen[$row['ticker']] = $this->seasonalityOf($row['industry']);
            $this->assertEqualsWithDelta(4.0, array_sum($seen[$row['ticker']]), 1e-9, $row['ticker']);
        }

        $this->assertSame(UtilityBusinessModel::SEASONALITY_FACTORS, $seen['BIRD'] ?? null);
        $this->assertSame(UtilityBusinessModel::WATER_SEASONALITY, $seen['WADE'] ?? null);
        $this->assertSame(UtilityBusinessModel::SEASONALITY_FACTORS, $this->seasonalityOf('Utilities - Diversified'));
    }

    /** @return array<int, float> */
    private function seasonalityOf(string $industry): array
    {
        return (new UtilityBusinessModel())->resolveSeasonalityFactors((new Stock())->setIndustry($industry));
    }

    public function testUtilityIsUnderLeveraged(): void
    {
        $model = new UtilityBusinessModel();

        // ICR >= 2.5, debtRatio < tolerance * 0.70 -> True
        $this->assertTrue(
            $model->isUnderLeveraged(
                0.30,
                0.60,
                3.0,
                2.0,
            ),
        );

        // ICR < 2.5 -> False
        $this->assertFalse(
            $model->isUnderLeveraged(
                0.30,
                0.60,
                2.0,
                2.0,
            ),
        );
    }
    public function testTailEventPersistsAsAMarkovRegimeWithRandomExit(): void
    {
        $model = $this->unhedgedModel();
        $regimeKey = StreamContext::REGIME_STATE_PREFIX . UtilityBusinessModel::REGIME_INFRASTRUCTURE_LIABILITY;
        $macro = new MacroStateDTO();

        $run = function (array $momentum, bool $exits, float $onsetZ = 0.0) use ($model, $macro) {
            $stock = new Stock();
            $stock->setTicker('UTIL');
            $stock->setBeta('1.0');
            // The rate case is held level, so the tariff lag does not move between the quarters compared.
            $stock->setEarningsMomentumZ([...$momentum, UtilityBusinessModel::STATE_TARIFF_SHORTFALL => 0.0]);
            // Stream draws and regime dice are scripted; the mix-drift weight math stays real.
            // The onset stream is recognised by its sentinel previous value; every other draw is flat.
            $math = new ScriptedDraws(
                generatePersistentZ: fn(float $prev): float => $prev === -9.9 ? $onsetZ : 0.0,
                checkProbability: $exits,
            );
            return $model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, $macro, $math);
        };

        $clean = $run([], false);
        $this->assertNull($clean->eventType);

        // Quarter 1: the trigger fires and the regime starts.
        $onset = $run(['event' => -9.9], false, -3.0);
        $this->assertSame(ShockEvent::INFRASTRUCTURE_FAILURE, $onset->eventType);
        $this->assertSame(1.0, $onset->streamZ[$regimeKey]);

        // Quarter 2: no new trigger, no exit -> the regime is still active and its cost persists silently.
        $ongoing = $run($onset->streamZ, false);
        $this->assertNull($ongoing->eventType, 'a continuing regime is not re-announced');
        $this->assertSame(2.0, $ongoing->streamZ[$regimeKey]);
        $this->assertGreaterThan($clean->clampedMargin, $ongoing->clampedMargin, 'ongoing regime cost must persist after the onset quarter');

        // Quarter 3: the exit hazard fires -> back to baseline.
        $after = $run($ongoing->streamZ, true);
        $this->assertSame(0.0, $after->streamZ[$regimeKey]);
        $this->assertEqualsWithDelta($clean->clampedMargin, $after->clampedMargin, 1e-9, 'costs return to baseline once the regime exits');
    }

    /**
     * Commissions set the allowed return off long Treasuries (Bulkley, SD PUC EL23-020): when the 30-year yield rises,
     * the next orders authorise a higher return, which the tariff recovers on the equity rate base.
     */
    public function testRisingTreasuryYieldsRaiseTheAuthorisedReturnAtTheNextOrders(): void
    {
        $model = new UtilityBusinessModel();
        $roeAt = static fn(float $yield): float => UtilityBusinessModel::ALLOWED_ROE_INTERCEPT + (UtilityBusinessModel::ALLOWED_ROE_YIELD_SLOPE * $yield);
        $run = function (float $yield) use ($model, $roeAt): ActualFinancialsDTO {
            $stock = new Stock();
            $stock->setTicker('UTIL');
            $stock->setBeta('1.0');
            $stock->setTotalEquity('400000000');
            $momentum = [UtilityBusinessModel::STATE_OPENING_ROE => $roeAt(0.04), UtilityBusinessModel::STATE_AUTHORISED_ROE => $roeAt(0.04)];
            // Every pending case is decided the quarter after it is filed.
            $math = new ScriptedDraws(generatePersistentZ: 0.0, checkProbability: true);
            $result = null;
            for ($quarter = 0; $quarter < 40; $quarter++) {
                $stock->setEarningsMomentumZ($momentum);
                $result = $model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, new MacroStateDTO(yield30yEma: $yield), $math);
                $momentum = $result->streamZ;
            }

            return $result;
        };

        $flat = $run(0.04);
        $risen = $run(0.06);

        $this->assertEqualsWithDelta($roeAt(0.04), $flat->streamZ[UtilityBusinessModel::STATE_AUTHORISED_ROE], 1e-12, 'No move in yields, no move in the allowed return.');
        $this->assertEqualsWithDelta($roeAt(0.06), $risen->streamZ[UtilityBusinessModel::STATE_AUTHORISED_ROE], 0.001, 'Orders carry the allowed return to the yield-implied one.');
        // Equity equals a year's revenue here, so 0.88pp more allowed return is ~0.9pp of revenue on the regulated
        // share.
        $this->assertLessThan($flat->clampedMargin - 0.005, $risen->clampedMargin, 'The higher authorised return is recovered in the tariff.');
    }

    public function testLiabilityRegimeCommitsGridHardeningCapex(): void
    {
        $model = new UtilityBusinessModel();
        $macro = new MacroStateDTO();

        $run = function (array $momentum) use ($model, $macro) {
            $stock = new Stock();
            $stock->setTicker('UTIL');
            $stock->setBeta('0.5');
            $stock->setEarningsMomentumZ($momentum);
            $math = new ScriptedDraws(generatePersistentZ: 0.0);
            return $model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, $macro, $math);
        };

        $clean = $run([]);
        $this->assertSame(0.0, $clean->scheduledCapex);

        $rebuilding = $run([StreamContext::REGIME_STATE_PREFIX . UtilityBusinessModel::REGIME_INFRASTRUCTURE_LIABILITY => 2.0]);
        $this->assertEqualsWithDelta(100_000_000.0 * 4.0 * UtilityBusinessModel::GRID_HARDENING_CAPEX_RATIO, $rebuilding->scheduledCapex, 1.0);
    }

    /** A merchant quarter on an unhedged book, so the spot arithmetic is all that moves. */
    private function merchantQuarter(string $ticker, float $gasIndexEma, float $powerIndexEma): ActualFinancialsDTO
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setBeta('0.5');
        $math = new ScriptedDraws();
        $macro = new MacroStateDTO(inflationEma: 0.02, naturalGasPriceIndexEma: $gasIndexEma, wholesalePowerPriceIndexEma: $powerIndexEma);

        return $this->unhedgedModel()->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, $macro, $math);
    }

    /** The utility model with its merchant book sold at spot: no forward ladder. */
    private function unhedgedModel(): UtilityBusinessModel
    {
        return new class extends UtilityBusinessModel {
            protected function resolveModelParameters(Stock $stock, array $defaults = []): ModelParameters
            {
                return parent::resolveModelParameters($stock, [ModelParam::PowerHedgeRatio->value => 0.0] + $defaults);
            }
        };
    }

    /**
     * The merchant book is sold forward on the ladder: a spot move reaches only the open share of the quarter's output
     * at once, and the hedged share delivers the strike struck over the last three years, the same whatever spot does.
     */
    public function testTheMerchantBookIsSoldForwardOnTheLadder(): void
    {
        $quarter = function (float $powerIndexEma): ActualFinancialsDTO {
            $stock = (new Stock())->setTicker('BIRD')->setBeta('0.5');
            $math = new ScriptedDraws();
            $macro = new MacroStateDTO(inflationEma: 0.02, wholesalePowerPriceIndexEma: $powerIndexEma);

            return (new UtilityBusinessModel())->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, $macro, $math);
        };

        $base = $quarter(100.0);
        $spike = $quarter(150.0);
        $open = 1.0 - UtilityBusinessModel::POWER_HEDGE_RATIO;
        $this->assertEqualsWithDelta(100_000_000.0 * 0.20 * $open * 0.50, $spike->actualRevenue - $base->actualRevenue, 1.0);
        $this->assertEqualsWithDelta($open * 0.50, $spike->kpis['power_price_index'] - $base->kpis['power_price_index'], 1e-9);
        $this->assertArrayHasKey(FinancialConstants::STATE_POWER_STRIKE_SUM_PREFIX . (UtilityBusinessModel::POWER_HEDGE_LADDER_TRANCHES - 1), $base->streamZ);
    }

    /**
     * A water utility's market-based arm sells no power, so it rolls no ladder; the tariff lag lands on its tariffed
     * book alone, and its market-based work sells at the market.
     */
    public function testTheTariffLagHaircutsTheRegulatedBookAlone(): void
    {
        $shortfall = 0.05;
        $stock = (new Stock())->setTicker('BIRD')->setBeta('0.5');
        $stock->setEarningsMomentumZ([UtilityBusinessModel::STATE_TARIFF_SHORTFALL => $shortfall]);
        $math = new ScriptedDraws();
        $macro = new MacroStateDTO(inflationEma: 0.02, wholesalePowerPriceIndexEma: MacroConstants::WHOLESALE_POWER_BASELINE);
        $model = $this->unhedgedModel();

        // The engine passes expected revenue at the firm-wide level the lag leaves: 80% of the book lags by 5%.
        $bookLevel = 1.0 - (0.80 * $shortfall);
        $this->assertEqualsWithDelta($bookLevel, $model->getMacroPhysics($stock, $macro)['pricing_power_multiplier'] / $model->getMacroPhysics($stock, $macro)['input_cost_multiplier'], 1e-12);
        $result = $model->computeActualFinancials($stock, 100_000_000.0 * $bookLevel, 0.40, 20_000_000.0, 0.10, $macro, $math);

        $this->assertEqualsWithDelta(100_000_000.0 * 0.20, $result->streamRevenue['unregulated_merchant'], 1e-6, 'The merchant book sells at the market.');
        $this->assertEqualsWithDelta(100_000_000.0 * 0.80 * (1.0 - $shortfall), $result->streamRevenue['regulated_weather_load'], 1e-6);
        $this->assertEqualsWithDelta(0.80 * (float) $stock->getTotalRevenue(), $model->annualUtilityTariffBase($stock), 1e-9);
    }

    /**
     * The shortfall a grant leaves on average follows the rate-case cycle the model runs: quarterly cost drift, a
     * filing at the threshold, decisions at the hazard, the granted share removed, the cap. The closed form misses the
     * cycle's spread of case lengths and the cap, so it sits within a tenth of it, and falls as commissions grant more.
     */
    public function testTheSteadyShortfallFollowsTheRateCaseCycle(): void
    {
        $simulated = static function (float $grant): float {
            mt_srand(7);
            [$shortfall, $pending, $sum, $quarters] = [0.0, false, 0.0, 200000];
            for ($q = 0; $q < $quarters; ++$q) {
                $shortfall += MacroConstants::TARGET_INFLATION * 0.25;
                $decided = $pending && (mt_rand() / mt_getrandmax()) < FirmEconomics::RATE_CASE_EXIT_HAZARD;
                if ($decided) {
                    $pending = false;
                    $shortfall -= $shortfall * $grant;
                } elseif (!$pending && $shortfall >= FirmEconomics::RATE_CASE_FILING_THRESHOLD) {
                    $pending = true;
                }
                $shortfall = min(FirmEconomics::MAX_TARIFF_SHORTFALL, $shortfall);
                $sum += $shortfall;
            }

            return $sum / $quarters;
        };

        $previous = INF;
        foreach ([0.24, 0.40, 0.55, FirmEconomics::APPOINTED_COMMISSION_GRANTED_SHARE, 0.85] as $grant) {
            $closedForm = FirmEconomics::steadyTariffShortfall($grant);
            $this->assertEqualsWithDelta($simulated($grant), $closedForm, 0.11 * $simulated($grant), "grant {$grant}");
            $this->assertLessThan($previous, $closedForm);
            $previous = $closedForm;
        }
        $this->assertEqualsWithDelta(-4.0 * log(1.0 - FirmEconomics::RATE_CASE_EXIT_HAZARD), FirmEconomics::RATE_CASE_DECISION_RATE, 1e-4);
    }

    /**
     * Gas sets the power price, so a gas spike reaches a zero-fuel merchant fleet as a windfall, not a squeeze (the
     * 2022 inframarginal rent).
     */
    public function testAGasSpikeIsAWindfallForAZeroFuelMerchantFleet(): void
    {
        $calm = $this->merchantQuarter('BIRD', 100.0, 100.0);
        $spike = $this->merchantQuarter('BIRD', 150.0, 100.0 * (1.5 ** CommodityLogisticsSubsystem::POWER_GAS_ELASTICITY));

        $this->assertGreaterThan($calm->ebit, $spike->ebit, 'the merchant windfall outweighs the regulated fuel clause lag');
    }

    public function testMerchantPowerIsSoldPerMwhAtTheWholesalePrice(): void
    {
        // Gas held fixed, so the regulated fuel basket is identical and only the power price moves.
        $base = $this->merchantQuarter('BIRD', 120.0, 100.0);
        $dear = $this->merchantQuarter('BIRD', 120.0, 140.0);

        // BIRD sells 20% of its revenue as merchant power: the price lifts that slice, and the MWh cost what they cost.
        $this->assertEqualsWithDelta(100_000_000.0 * 0.20 * 0.40, $dear->actualRevenue - $base->actualRevenue, 1.0);
        $this->assertEqualsWithDelta($base->actualVariableCosts, $dear->actualVariableCosts, 1.0);
        $this->assertEqualsWithDelta($dear->actualRevenue - $base->actualRevenue, $dear->ebit - $base->ebit, 1.0);
        $this->assertEqualsWithDelta(1.40, $dear->kpis['power_price_index'], 1e-9);
    }

    public function testTheGasFiredFleetEarnsTheSparkSpreadAndTheFuelIsNotCountedTwice(): void
    {
        // WADE sells no power, so its cost change on a gas move is the regulated fuel clause lag alone: the drag per
        // unit of regulated cost base, which any utility on this model shares.
        $waterCalm = $this->merchantQuarter('WADE', 100.0, 100.0);
        $waterGas = $this->merchantQuarter('WADE', 150.0, 100.0);
        $clauseLagPerRegulatedCost = ($waterGas->actualVariableCosts - $waterCalm->actualVariableCosts) / (100_000_000.0 * 0.40 * 0.95);

        // A default utility (15% merchant, 43.1% of it gas-fired): the regulated slice carries the clause lag and the
        // gas-fired MWh burn 7.74 MMBtu each at spot, recovered only through the power price.
        $calm = $this->merchantQuarter('UTIL', 100.0, 100.0);
        $gas = $this->merchantQuarter('UTIL', 150.0, 100.0);
        $baselineFuelShare = MacroConstants::GAS_FLEET_HEAT_RATE * MacroConstants::REFERENCE_GAS_PRICE / MacroConstants::REFERENCE_POWER_PRICE;
        $merchantFuel = 100_000_000.0 * 0.15 * UtilityBusinessModel::MERCHANT_GAS_FLEET_SHARE * $baselineFuelShare * 0.50;
        $regulatedClauseLag = 100_000_000.0 * 0.40 * 0.85 * $clauseLagPerRegulatedCost;

        $this->assertEqualsWithDelta($merchantFuel + $regulatedClauseLag, $gas->actualVariableCosts - $calm->actualVariableCosts, 1.0);
    }

    public function testAWaterUtilitysMarketBasedArmIgnoresThePowerPrice(): void
    {
        $base = $this->merchantQuarter('WADE', 100.0, 100.0);
        $powerSpike = $this->merchantQuarter('WADE', 100.0, 250.0);

        $this->assertEqualsWithDelta($base->actualRevenue, $powerSpike->actualRevenue, 1e-6);
        $this->assertEqualsWithDelta($base->ebit, $powerSpike->ebit, 1e-6);
    }

    /**
     * A district storm opens the liability regime once, in the report whose window its losses arrive in, with the
     * utility's own event draw flat: not on a first report, which has no earlier reading, and not again when the regime
     * ends early while the storm's claims are still open.
     */
    public function testADistrictStormOpensTheGridLiabilityRegimeOncePerStorm(): void
    {
        $model = $this->unhedgedModel();
        $regimeKey = StreamContext::REGIME_STATE_PREFIX . UtilityBusinessModel::REGIME_INFRASTRUCTURE_LIABILITY;
        $quarter = function (array $momentum, float $burden, float $totalTime, bool $exits) use ($model): ActualFinancialsDTO {
            $stock = (new Stock())->setTicker('UTIL')->setBeta('0.5');
            $stock->setEarningsMomentumZ($momentum);
            $math = new ScriptedDraws(generatePersistentZ: 0.0, checkProbability: $exits);
            // The smoothed stock of open claims sits high throughout: it is not what the trigger reads.
            $macro = new MacroStateDTO(inflationEma: 0.02, catastropheLossIndex: $burden, catastropheLossIndexEma: 9.0, totalTime: $totalTime);

            return $model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, $macro, $math);
        };

        $first = $quarter([], 1.0, 10.0, false);
        $this->assertSame(0.0, $first->streamZ[$regimeKey] ?? 0.0, 'A first report has no window to read.');

        $burden = exp(-CommodityLogisticsSubsystem::CATASTROPHE_LOSS_DECAY * 0.25) + 6.0;
        $storm = $quarter($first->streamZ, $burden, 10.25, false);
        $this->assertSame(1.0, $storm->streamZ[$regimeKey]);
        $this->assertSame(ShockEvent::INFRASTRUCTURE_FAILURE, $storm->eventType);
        $this->assertGreaterThan(0.0, $storm->specialCharge);

        // The regime ends early; the storm's claims only decay, so nothing new arrives and it does not re-open.
        $exited = $quarter($storm->streamZ, $burden * exp(-CommodityLogisticsSubsystem::CATASTROPHE_LOSS_DECAY * 0.25), 10.5, true);
        $this->assertSame(0.0, $exited->streamZ[$regimeKey]);
        $again = $quarter($exited->streamZ, $burden * exp(-CommodityLogisticsSubsystem::CATASTROPHE_LOSS_DECAY * 0.5), 10.75, false);
        $this->assertSame(0.0, $again->streamZ[$regimeKey]);
        $this->assertNull($again->eventType);
    }

    /**
     * A water network takes a storm at its exposure, a quarter of an overhead grid's, through the onset charge, the
     * ongoing charges and the hardening capex alike. A failure of its own is a main collapse, not a wildfire its lines
     * start, and is charged at its own network exposure, where a power grid's own failure is charged in full.
     */
    public function testAWaterNetworkTakesTheStormAndItsOwnFailureAtItsExposure(): void
    {
        $model = $this->unhedgedModel();
        $onset = function (string $ticker, float $burden, float $eventZ) use ($model): ActualFinancialsDTO {
            $stock = (new Stock())->setTicker($ticker)->setBeta('0.5');
            $stock->setEarningsMomentumZ([
                'event' => -9.9,
                FinancialConstants::STATE_CATASTROPHE_BURDEN_AT_REPORT => 1.0,
                FinancialConstants::STATE_CATASTROPHE_BURDEN_READ_AT => 10.0,
            ]);
            $math = new ScriptedDraws(
                generatePersistentZ: static fn(float $prev): float => $prev === -9.9 ? $eventZ : 0.0,
            );
            $macro = new MacroStateDTO(inflationEma: 0.02, catastropheLossIndex: $burden, totalTime: 10.25);

            return $model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, $macro, $math);
        };
        $storm = exp(-CommodityLogisticsSubsystem::CATASTROPHE_LOSS_DECAY * 0.25) + 6.0;

        $grid = $onset('BIRD', $storm, 0.0);
        $water = $onset('WADE', $storm, 0.0);
        $this->assertGreaterThan(0.0, $grid->specialCharge);
        $this->assertEqualsWithDelta(0.25, ($water->specialCharge / $water->actualRevenue) / ($grid->specialCharge / $grid->actualRevenue), 0.02);
        $this->assertEqualsWithDelta(0.25 * $grid->scheduledCapex, $water->scheduledCapex, 1.0);

        $gridFailure = $onset('BIRD', 1.0, -3.0);
        $waterFailure = $onset('WADE', 1.0, -3.0);
        $this->assertEqualsWithDelta($grid->specialCharge / $grid->actualRevenue, $gridFailure->specialCharge / $gridFailure->actualRevenue, 0.01, 'A power grid\'s own failure is charged in full.');
        $this->assertEqualsWithDelta(0.25, ($waterFailure->specialCharge / $waterFailure->actualRevenue) / ($gridFailure->specialCharge / $gridFailure->actualRevenue), 0.02);
        $this->assertEqualsWithDelta(0.25 * $gridFailure->scheduledCapex, $waterFailure->scheduledCapex, 1.0);
    }

    /**
     * A utility's own catastrophic failure, a wildfire its lines start or an explosion, is about a one-in-a-century
     * event: near the US record of roughly a dozen among some fifty large investor-owned utilities in twenty years.
     */
    public function testAnOwnCatastrophicFailureIsAboutOnePercentAYear(): void
    {
        $annual = 1.0 - ((1.0 - Distributions::calculateNormalCDF(UtilityBusinessModel::GRID_FAILURE_Z_SCORE)) ** 4);

        $this->assertEqualsWithDelta(0.010, $annual, 0.002);
    }

    /**
     * The share of revenue the utility reports the carbon price's power uplift adding to its earnings is what the
     * uplift adds: a renewable merchant fleet keeps all of it, a fleet with gas at the US share keeps what the gas does
     * not pay in carbon beyond the price's pass-through.
     */
    public function testTheReportedCarbonShareIsWhatTheUpliftAddsToEarnings(): void
    {
        $model = $this->unhedgedModel();
        $run = function (string $ticker, float $carbon) use ($model): ActualFinancialsDTO {
            $stock = new Stock();
            $stock->setTicker($ticker);
            $stock->setBeta('0.5');
            $math = new ScriptedDraws(generatePersistentZ: 0.0);
            $uplift = CommodityLogisticsSubsystem::carbonPowerPriceUplift($carbon);
            $macro = new MacroStateDTO(inflationEma: 0.02, carbonPrice: $carbon, wholesalePowerPriceIndexEma: MacroConstants::WHOLESALE_POWER_BASELINE * (1.0 + $uplift));

            return $model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, $macro, $math);
        };

        $uplift = CommodityLogisticsSubsystem::carbonPowerPriceUplift(40.0);
        foreach (['BIRD' => 0.0, 'UTIL' => UtilityBusinessModel::MERCHANT_GAS_FLEET_SHARE] as $ticker => $gasShare) {
            $without = $run($ticker, 0.0);
            $with = $run($ticker, 40.0);
            $share = $with->streamZ[FinancialConstants::STATE_CARBON_POWER_EARNINGS_SHARE];

            $this->assertEqualsWithDelta($without->streamRevenue['unregulated_merchant'] / $without->actualRevenue * (1.0 - ($gasShare / MacroConstants::CARBON_POWER_PASS_THROUGH)), $without->streamZ[FinancialConstants::STATE_CARBON_POWER_EARNINGS_SHARE], 1e-12, $ticker);
            $this->assertGreaterThan(0.0, $share, $ticker);
            $this->assertEqualsWithDelta($share * $with->actualRevenue * $uplift, $with->ebit - $without->ebit, 1e-6 * $with->actualRevenue, $ticker);
        }
    }
}
