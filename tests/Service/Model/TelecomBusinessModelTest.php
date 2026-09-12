<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use App\Service\Model\Sector\TelecomBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use App\DTO\StreamContext;
use App\Service\Macro\MacroEngine;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TelecomBusinessModelTest extends TestCase
{
    private TelecomBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new TelecomBusinessModel();
        $this->mathUtility = new MathUtility();
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
            mathUtility: $mathStub
        );

        $this->assertEqualsWithDelta(1.0, $result->kpis['subscriber_index'], 1e-9);

        $this->assertArrayHasKey('wireless_subscriptions', $result->streamRevenue);
        $this->assertArrayHasKey('equipment_sales', $result->streamRevenue);

        // Baseline: 85M subscriptions, 15M equipment
        $this->assertEqualsWithDelta(85_000_000.0, $result->streamRevenue['wireless_subscriptions'], 1.0);
        $this->assertEqualsWithDelta(15_000_000.0, $result->streamRevenue['equipment_sales'], 1.0);
        $this->assertEqualsWithDelta(100_000_000.0, $result->actualRevenue, 1.0);
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
            mathUtility: $mathStub
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
            mathUtility: $mathStub
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
            mathUtility: $mathStub
        );

        $resultNormal = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.40,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $normalYieldMacro,
            mathUtility: $mathStub
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
            $math->method('generatePersistentZ')->willReturnCallback(fn (float $prev): float => $prev === -9.9 ? $eventZ : 0.0);
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
}
