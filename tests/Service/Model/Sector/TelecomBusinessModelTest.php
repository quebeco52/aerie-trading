<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\Data\Company\StockModelTuning;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\Industry\IndustryRegimeView;
use App\Service\Event\ShockEvent;
use App\Service\Macro\MacroEngine;
use App\Service\Math\Distributions;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use App\Service\Model\Sector\TelecomBusinessModel;
use App\Service\Model\StreamContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TelecomBusinessModelTest extends TestCase
{
    private TelecomBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new TelecomBusinessModel();
    }

    public function testDualStreamRevenueDecomposition(): void
    {
        $stock = new Stock();
        $stock->setTicker('TEL');
        $stock->setBeta('0.8');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02);

        // Flat draws: no event, gross adds exactly replace churn, so the subscriber index holds at 1.0.
        $mathStub = $this->createStub(MathUtility::class);
        $mathStub->method('generatePersistentZ')->willReturn(0.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.45,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $macro,
            mathUtility: $mathStub,
        );

        $this->assertEqualsWithDelta(1.0, $result->kpis['subscriber_index'], 1e-9);

        $this->assertArrayHasKey('wireless_subscriptions', $result->streamRevenue);
        $this->assertArrayHasKey('equipment_sales', $result->streamRevenue);

        $this->assertEqualsWithDelta(100_000_000.0 * TelecomBusinessModel::SUBSCRIPTION_WEIGHT, $result->streamRevenue['wireless_subscriptions'], 1.0);
        $this->assertEqualsWithDelta(100_000_000.0 * TelecomBusinessModel::EQUIPMENT_WEIGHT, $result->streamRevenue['equipment_sales'], 1.0);
        $this->assertEqualsWithDelta(100_000_000.0, $result->actualRevenue, 1.0);
        // At the target mix the stream cost split reproduces the engine's cost ratio exactly.
        $this->assertEqualsWithDelta(0.45, $result->clampedMargin, 1e-9);
    }

    /**
     * Plan revenue does not follow the calendar; the holiday upgrade season is device sales. The engine
     * applies the blended factor to expected revenue, and the physics must hand the whole swing to equipment.
     */
    public function testTheUpgradeSeasonMovesDeviceSalesAndLeavesPlanRevenueFlat(): void
    {
        $stock = (new Stock())->setTicker('TEL')->setBeta('0.8');
        $factors = $this->model->resolveSeasonalityFactors($stock);
        $this->assertEqualsWithDelta(4.0, array_sum($factors), 1e-9);
        $this->assertEqualsWithDelta(4.0, array_sum(TelecomBusinessModel::EQUIPMENT_SEASONALITY), 1e-9);

        $math = $this->createStub(MathUtility::class);
        $math->method('generatePersistentZ')->willReturn(0.0);
        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02);

        $plans = [];
        foreach ([0, 1, 2, 3] as $quarter) {
            $result = $this->model->computeActualFinancials((new Stock())->setTicker('TEL')->setBeta('0.8'), 100_000_000.0 * $factors[$quarter], 0.45, 20_000_000.0, 0.0, $macro, $math, $quarter);
            $plans[] = $result->streamRevenue['wireless_subscriptions'];
            $this->assertEqualsWithDelta(100_000_000.0 * $factors[$quarter], $result->actualRevenue, 1e-3, 'The physics keeps the engine\'s seasonal revenue level.');
            $this->assertEqualsWithDelta(100_000_000.0 * TelecomBusinessModel::EQUIPMENT_WEIGHT * TelecomBusinessModel::EQUIPMENT_SEASONALITY[$quarter], $result->streamRevenue['equipment_sales'], 1e-3);
        }
        $this->assertEqualsWithDelta(0.0, max($plans) - min($plans), 1e-3);
    }

    /**
     * Devices are sold below cost (Verizon: cost of equipment 1.10-1.12x equipment revenue), so the Q4 device
     * peak raises the variable cost ratio, and analysts are told the seasonal shift rather than surprised by it.
     */
    public function testDevicesAreSoldAtALossSoTheHolidayQuarterRunsAHigherCostRatioThatAnalystsAnticipate(): void
    {
        $stock = (new Stock())->setTicker('TEL')->setBeta('0.8');
        $factors = $this->model->resolveSeasonalityFactors($stock);
        $math = $this->createStub(MathUtility::class);
        $math->method('generatePersistentZ')->willReturn(0.0);
        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02);

        $run = fn(int $quarter) => $this->model->computeActualFinancials((new Stock())->setTicker('TEL')->setBeta('0.8'), 100_000_000.0 * $factors[$quarter], 0.30, 20_000_000.0, 0.0, $macro, $math, $quarter);
        $q3 = $run(2);
        $q4 = $run(3);
        $q1 = $run(0);

        $this->assertGreaterThan($q3->clampedMargin, $q4->clampedMargin);
        $this->assertLessThan($q4->clampedMargin, $q1->clampedMargin);
        $this->assertEqualsWithDelta($q4->clampedMargin - $q3->clampedMargin, $q4->seasonalCostRatioShift, 1e-9);
        $this->assertEqualsWithDelta($q1->clampedMargin - $q4->clampedMargin, $q1->seasonalCostRatioShift, 1e-9);

        // An extra dollar of device sales costs more than a dollar: the device stream loses money at the margin.
        $deviceCostDelta = ($q4->actualVariableCosts - $q3->actualVariableCosts);
        $deviceRevenueDelta = ($q4->streamRevenue['equipment_sales'] - $q3->streamRevenue['equipment_sales']);
        $this->assertGreaterThan($deviceRevenueDelta, $deviceCostDelta);
    }

    public function testPriceWarFatTailEventTriggersMarginPenalty(): void
    {
        $stock = new Stock();
        $stock->setTicker('TEL');
        $stock->setBeta('0.8');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02);

        $mathStub = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ'])
            ->getMock();

        // stream sequence: wireless_subscriptions Z = 0.0, equipment_sales Z = 0.0, event Z = -2.5 (price war)
        $mathStub->method('generatePersistentZ')
            ->willReturnOnConsecutiveCalls(0.0, 0.0, -2.5);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.45,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $mathStub,
        );

        $this->assertSame(ShockEvent::TELECOM_PRICE_WAR, $result->eventType);
        $this->assertTrue($result->isPublicEvent);
    }

    public function testSpectrumAuctionShockEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('TEL');
        $stock->setBeta('0.8');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02);

        $mathStub = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ'])
            ->getMock();

        // stream sequence: wireless_subscriptions Z = 0.0, equipment_sales Z = 0.0, event Z = 2.5 (spectrum auction)
        $mathStub->method('generatePersistentZ')
            ->willReturnOnConsecutiveCalls(0.0, 0.0, 2.5);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.45,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $mathStub,
        );

        $this->assertSame(ShockEvent::SPECTRUM_AUCTION, $result->eventType);
        $this->assertTrue($result->isPublicEvent);
        // The licence outlay is a real CapEx commitment (a quarter of annual revenue), not just a headline.
        $this->assertEqualsWithDelta(100_000_000.0 * 4.0 * TelecomBusinessModel::SPECTRUM_AUCTION_CAPEX_RATIO, $result->scheduledCapex, 1.0);
    }

    public function testTenYearYieldDoesNotTouchOperatingMargin(): void
    {
        $stock = new Stock();
        $stock->setTicker('TEL');
        $stock->setBeta('1.0');

        // Interest is a financing cost handled by DebtEngine's maturity wall; an 800bps 10Y move
        // must leave the operating cost ratio untouched (no double counting above EBIT).
        $highYieldMacro = new MacroStateDTO(yield10yEma: 0.08, inflationEma: 0.02);
        $normalYieldMacro = new MacroStateDTO(yield10yEma: 0.04, inflationEma: 0.02);

        $mathStub = $this->createStub(MathUtility::class);
        $mathStub->method('generatePersistentZ')->willReturn(0.0);

        $resultHigh = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.40,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $highYieldMacro,
            mathUtility: $mathStub,
        );

        $resultNormal = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.40,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $normalYieldMacro,
            mathUtility: $mathStub,
        );

        $this->assertEqualsWithDelta($resultNormal->clampedMargin, $resultHigh->clampedMargin, 1e-9);
        $this->assertEqualsWithDelta($resultNormal->ebit, $resultHigh->ebit, 1e-6);
        $this->assertNotContains('yield_10y_ema', $this->model->getOperatingMacroFields());
    }

    public function testBondProxyRollsFixedRateDebtSlowerThanStandardCorporate(): void
    {
        $standard = new StandardCorporateBusinessModel();

        $this->assertSame(TelecomBusinessModel::DEBT_MATURITY_ROLLOVER_RATE, $this->model->getDebtMaturityRolloverRate());
        $this->assertLessThan($standard->getDebtMaturityRolloverRate(), $this->model->getDebtMaturityRolloverRate());
        $this->assertGreaterThan(0.0, $this->model->getDebtMaturityRolloverRate());
    }

    public function testPriceWarErodesTheSubscriberBaseAcrossQuartersAndDoesNotSnapBack(): void
    {
        $regimeKey = StreamContext::REGIME_STATE_PREFIX . TelecomBusinessModel::REGIME_PRICE_WAR;
        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02);

        $run = function (array $momentum, bool $warEnds, float $eventZ = 0.0) use ($macro) {
            $stock = new Stock();
            $stock->setTicker('TEL');
            $stock->setBeta('0.8');
            $stock->setEarningsMomentumZ($momentum);
            $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generatePersistentZ', 'checkProbability'])->getMock();
            $math->method('generatePersistentZ')->willReturnCallback(fn(float $prev): float => $prev === -9.9 ? $eventZ : 0.0);
            $math->method('checkProbability')->willReturn($warEnds);
            return $this->model->computeActualFinancials($stock, 100_000_000.0, 0.45, 20_000_000.0, 0.10, $macro, $math);
        };

        $clean = $run([], false);
        $this->assertEqualsWithDelta(1.0, $clean->kpis['subscriber_index'], 1e-9);

        // Onset: churn jumps, promotional gross adds only partly defend the base, ARPU is discounted.
        $onset = $run(['event' => -9.9], false, -2.5);
        $this->assertSame(ShockEvent::TELECOM_PRICE_WAR, $onset->eventType);
        $this->assertSame(1.0, $onset->streamZ[$regimeKey]);
        $this->assertLessThan(1.0, $onset->kpis['subscriber_index']);
        $this->assertLessThan(1.0, $onset->kpis['arpu_index']);
        $this->assertLessThan($clean->streamRevenue['wireless_subscriptions'], $onset->streamRevenue['wireless_subscriptions']);

        // Quarter 2: the war continues silently, the base keeps eroding and SAC keeps margins compressed.
        $second = $run($onset->streamZ, false);
        $this->assertNull($second->eventType);
        $this->assertLessThan($onset->kpis['subscriber_index'], $second->kpis['subscriber_index']);
        $this->assertGreaterThan($clean->clampedMargin, $second->clampedMargin);

        // Quarter 3: the war ends. ARPU normalizes at once, but lost subscribers must be re-won at the
        // replacement rate, so the base recovers gradually rather than snapping back.
        $after = $run($second->streamZ, true);
        $this->assertSame(0.0, $after->streamZ[$regimeKey]);
        $this->assertEqualsWithDelta(1.0, $after->kpis['arpu_index'], 1e-9);
        $this->assertGreaterThan($second->kpis['subscriber_index'], $after->kpis['subscriber_index']);
        $this->assertLessThan(1.0, $after->kpis['subscriber_index']);
    }

    /**
     * Involuntary disconnects rise with job losses, so unemployment above the natural rate must lift churn
     * above the gross adds that replace it and erode the subscriber base even with no price war running.
     */
    public function testUnemploymentAboveTheNaturalRateLiftsChurnAndErodesTheSubscriberBase(): void
    {
        $math = $this->createStub(MathUtility::class);
        $math->method('generatePersistentZ')->willReturn(0.0);

        $run = function (float $unemployment) use ($math): float {
            $stock = (new Stock())->setTicker('LOON_JOBS')->setBeta('0.8');

            return $this->model->computeActualFinancials($stock, 100_000_000.0, 0.45, 20_000_000.0, 0.0, new MacroStateDTO(outputGapEma: 0.0, unemploymentRateEma: $unemployment), $math)->kpis['subscriber_index'];
        };

        $this->assertEqualsWithDelta(1.0, $run(MacroEngine::NATURAL_UNEMPLOYMENT), 1e-9, 'At the natural rate gross adds exactly replace churn.');

        $excess = 0.04;
        $expectedIndex = 1.0 - ($excess * TelecomBusinessModel::UNEMPLOYMENT_CHURN_SENSITIVITY);
        $this->assertEqualsWithDelta($expectedIndex, $run(MacroEngine::NATURAL_UNEMPLOYMENT + $excess), 1e-9);
        $this->assertEqualsWithDelta(1.0, $run(MacroEngine::NATURAL_UNEMPLOYMENT - 0.01), 1e-9, 'A tight labor market does not create subscribers who already have a phone.');
    }

    public function testTheCycleReachesTheCostBaseThroughEquipmentVolumeOnly(): void
    {
        $stock = (new Stock())->setTicker('LOON_ACTIVITY')->setBeta('1.0');
        $recession = new MacroStateDTO(outputGapEma: -0.03, exchangeRateIndexEma: 100.0);

        $this->assertEqualsWithDelta(0.0, $this->model->getMacroPhysics($stock, $recession)['macro_demand_shift'], 1e-12);

        // Equipment share x (device sensitivity x cyclicality x gap); the ARPU downgrade on subscriptions is price and stays out.
        $expected = TelecomBusinessModel::EQUIPMENT_WEIGHT * (TelecomBusinessModel::EQUIPMENT_CYCLE_SENSITIVITY * TelecomBusinessModel::OPERATING_CYCLICALITY * -0.03);
        $this->assertEqualsWithDelta($expected, $this->model->resolveSectorActivityShift($stock, $recession), 1e-9);
    }

    /**
     * A war a rival started is this carrier's war too: from its next report it loses subscribers to switching offers
     * and matches the rival's plan prices, but it publishes nothing, since it did not start it.
     */
    public function testAPriceWarARivalStartedReachesThisCarrierWithoutAnEvent(): void
    {
        $regimeKey = StreamContext::REGIME_STATE_PREFIX . TelecomBusinessModel::REGIME_PRICE_WAR;
        $math = $this->createStub(MathUtility::class);
        $math->method('generatePersistentZ')->willReturn(0.0);
        $run = function (?IndustryRegimeView $industry) use ($math) {
            $this->model->useIndustryRegimes($industry);
            $stock = (new Stock())->setTicker('TEL_RIVAL')->setBeta('0.8');
            $result = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.45, 20_000_000.0, 0.10, new MacroStateDTO(outputGapEma: 0.0), $math);
            $this->model->useIndustryRegimes(null);

            return $result;
        };

        $peace = $run(new IndustryRegimeView([], 2));
        $war = $run(new IndustryRegimeView([TelecomBusinessModel::REGIME_PRICE_WAR => 2], 2));

        $this->assertNull($war->eventType);
        $this->assertSame(2.0, $war->streamZ[$regimeKey], 'the carrier\'s own clock reads the industry\'s');
        $this->assertSame(0.0, $peace->streamZ[$regimeKey]);
        $this->assertEqualsWithDelta(TelecomBusinessModel::BASE_QUARTERLY_CHURN + TelecomBusinessModel::PRICE_WAR_CHURN_UPLIFT, $war->kpis['quarterly_churn'], 1e-12);
        $this->assertEqualsWithDelta(1.0 - TelecomBusinessModel::PRICE_WAR_ARPU_DISCOUNT, $war->kpis['arpu_index'], 1e-12);
        $this->assertLessThan($peace->kpis['subscriber_index'], $war->kpis['subscriber_index']);
        // The war's cost comes only from the extra promotional adds and from plan costs spread over discounted ARPU.
        $acquisition = TelecomBusinessModel::SUBSCRIBER_ACQUISITION_COST_ELASTICITY * TelecomBusinessModel::PRICE_WAR_GROSS_ADDS_RESPONSE * TelecomBusinessModel::SUBSCRIPTION_WEIGHT;
        $discount = TelecomBusinessModel::PRICE_WAR_ARPU_DISCOUNT;
        $warCost = $war->clampedMargin - $peace->clampedMargin;
        $this->assertGreaterThan($acquisition, $warCost, 'promotional adds and the ARPU cut both raise the cost ratio');
        $this->assertLessThan($acquisition + ($peace->clampedMargin * $discount / (1.0 - $discount)), $warCost, 'no flat penalty on top of the mechanism');
    }

    /** The carrier that draws the onset starts the war for its industry, its length drawn once from the exit hazard. */
    public function testTheCarrierThatDrawsTheOnsetStartsTheWarForItsIndustry(): void
    {
        $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generatePersistentZ', 'generateGeometricCount'])->getMock();
        // subscriptions, equipment, event: the event Z is below the onset threshold.
        $math->method('generatePersistentZ')->willReturnOnConsecutiveCalls(0.0, 0.0, -2.5);
        $math->expects($this->once())->method('generateGeometricCount')->with(TelecomBusinessModel::PRICE_WAR_EXIT_HAZARD)->willReturn(3);

        $industry = new IndustryRegimeView([], 1);
        $this->model->useIndustryRegimes($industry);
        $result = $this->model->computeActualFinancials((new Stock())->setTicker('TEL_START')->setBeta('0.8'), 100_000_000.0, 0.45, 20_000_000.0, 0.10, new MacroStateDTO(outputGapEma: 0.0), $math);
        $this->model->useIndustryRegimes(null);

        $this->assertSame(ShockEvent::TELECOM_PRICE_WAR, $result->eventType);
        $this->assertSame([TelecomBusinessModel::REGIME_PRICE_WAR => 3], $industry->startedRegimes());
        $this->assertSame(1.0, $result->streamZ[StreamContext::REGIME_STATE_PREFIX . TelecomBusinessModel::REGIME_PRICE_WAR]);
    }

    /**
     * The onset threshold was set when one listed carrier stood for the whole market. With two listed, each carries
     * half the market's onset rate, so a second carrier on the board does not double how often the market goes to war.
     */
    public function testTheOnsetRateIsTheMarketsSharedAmongItsCarriers(): void
    {
        $this->assertSame(TelecomBusinessModel::PRICE_WAR_Z_SCORE, TelecomBusinessModel::priceWarOnsetThreshold(1));
        $marketRate = Distributions::standardNormalCdf(TelecomBusinessModel::PRICE_WAR_Z_SCORE);
        $this->assertEqualsWithDelta($marketRate / 2.0, Distributions::standardNormalCdf(TelecomBusinessModel::priceWarOnsetThreshold(2)), 1e-6);
        $this->assertEqualsWithDelta($marketRate / 3.0, Distributions::standardNormalCdf(TelecomBusinessModel::priceWarOnsetThreshold(3)), 1e-6);

        $startsAWar = function (int $carriers): bool {
            $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generatePersistentZ'])->getMock();
            $math->method('generatePersistentZ')->willReturnOnConsecutiveCalls(0.0, 0.0, -2.1);
            $industry = new IndustryRegimeView([], $carriers);
            $this->model->useIndustryRegimes($industry);
            $this->model->computeActualFinancials((new Stock())->setTicker('TEL_ONSET')->setBeta('0.8'), 100_000_000.0, 0.45, 20_000_000.0, 0.10, new MacroStateDTO(outputGapEma: 0.0), $math);
            $this->model->useIndustryRegimes(null);

            return $industry->startedRegimes() !== [];
        };

        $this->assertTrue($startsAWar(1));
        $this->assertFalse($startsAWar(2), 'a draw of -2.1 is inside half the market\'s tail, -2.28');
    }

    /**
     * The macro attribution re-runs the quarter on its own draws with the industry's opening state. A war this
     * quarter started must replay identically, its length draw included, or every stream reads a phantom macro effect.
     */
    public function testReplayingAQuarterThatStartedASharedWarReproducesItsStreams(): void
    {
        $macro = new MacroStateDTO(outputGapEma: 0.01, inflationEma: 0.02);
        $started = null;
        for ($seed = 1; $seed <= 3_000 && $started === null; $seed++) {
            mt_srand($seed);
            $math = new MathUtility();
            $industry = new IndustryRegimeView([], 1);
            $stock = (new Stock())->setTicker('TEL_REPLAY')->setBeta('0.8');
            $basis = clone $stock;
            $this->model->useIndustryRegimes($industry);
            $math->beginDrawLog();
            $result = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.45, 20_000_000.0, 0.10, $macro, $math);
            $draws = $math->endDrawLog();
            if ($industry->startedRegimes() !== []) {
                $started = [$basis, $draws, $result];
            } else {
                $this->model->useIndustryRegimes(null);
            }
        }
        $this->assertNotNull($started, 'some seed in 3,000 draws a war (2.3% a quarter)');
        [$basis, $draws, $result] = $started;

        $effects = $this->model->measureStreamMacroEffects($basis, $draws, ['output_gap_ema' => [$macro, 100_000_000.0]], 0.45, 20_000_000.0, 0.10, $result->streamRevenue);
        $this->model->useIndustryRegimes(null);

        $this->assertSame(ShockEvent::TELECOM_PRICE_WAR, $result->eventType);
        foreach (['wireless_subscriptions', 'equipment_sales'] as $stream) {
            $this->assertEqualsWithDelta(0.0, $effects[$stream]['output_gap_ema'], 1e-12, "{$stream} replays as it ran");
        }
    }

    /**
     * A carrier's own churn sets the flow its gross adds replace: at rest a high-churn base holds steady, and in a war
     * its promotional adds scale with that larger replacement flow.
     */
    public function testACarriersOwnBaseChurnSetsTheRateItsGrossAddsReplace(): void
    {
        $churn = StockModelTuning::resolve('SKUA', [ModelParam::BaseQuarterlyChurn->value => TelecomBusinessModel::BASE_QUARTERLY_CHURN])[ModelParam::BaseQuarterlyChurn];
        $this->assertGreaterThan(TelecomBusinessModel::BASE_QUARTERLY_CHURN, $churn, 'the challenger\'s prepaid-heavy base turns over faster');

        $math = $this->createStub(MathUtility::class);
        $math->method('generatePersistentZ')->willReturn(0.0);
        $run = function (string $ticker, int $warQuarter) use ($math) {
            $this->model->useIndustryRegimes(new IndustryRegimeView($warQuarter > 0 ? [TelecomBusinessModel::REGIME_PRICE_WAR => $warQuarter] : [], 2));
            $result = $this->model->computeActualFinancials((new Stock())->setTicker($ticker)->setBeta('0.8'), 100_000_000.0, 0.45, 20_000_000.0, 0.10, new MacroStateDTO(outputGapEma: 0.0), $math);
            $this->model->useIndustryRegimes(null);

            return $result->kpis;
        };

        $rest = $run('SKUA', 0);
        $this->assertEqualsWithDelta($churn, $rest['quarterly_churn'], 1e-12);
        $this->assertEqualsWithDelta(1.0, $rest['subscriber_index'], 1e-12, 'gross adds replace its own churn, not the default');

        foreach (['SKUA' => $churn, 'TEL_DEFAULT' => TelecomBusinessModel::BASE_QUARTERLY_CHURN] as $ticker => $base) {
            $expected = 1.0 - ($base + TelecomBusinessModel::PRICE_WAR_CHURN_UPLIFT) + ($base * (1.0 + TelecomBusinessModel::PRICE_WAR_GROSS_ADDS_RESPONSE));
            $this->assertEqualsWithDelta($expected, $run($ticker, 1)['subscriber_index'], 1e-12, $ticker);
        }
    }
}
