<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\RestaurantBusinessModel;
use App\DTO\StreamContext;
use App\Service\Event\ShockEvent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class RestaurantBusinessModelTest extends TestCase
{
    private RestaurantBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new RestaurantBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testRestaurantTriStreamStructure(): void
    {
        $stock = new Stock();
        $stock->setTicker('MCD');
        $stock->setBeta('0.9');

        $macro = new MacroStateDTO(
            consumerSentimentIndexEma: 100.0,
            energyPriceIndexEma: 100.0
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 10_000_000.0,
            realizedVariableMargin: 0.25,
            fixedCosts: 2_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('company_operated_stores', $result->streamRevenue);
        $this->assertArrayHasKey('franchise_royalties', $result->streamRevenue);
        $this->assertArrayHasKey('franchise_real_estate_leases', $result->streamRevenue);

        $this->assertGreaterThan(0.0, $result->streamRevenue['company_operated_stores']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['franchise_royalties']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['franchise_real_estate_leases']);
    }

    public function testKitchenEnergyUtilityDragIncreasesVariableCost(): void
    {
        $stock = new Stock();
        $stock->setTicker('MCD');
        $stock->setBeta('1.0');

        $mathUtilityMock = $this->createStub(MathUtility::class);
        $mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $macroBaseline = new MacroStateDTO(
            consumerSentimentIndexEma: 100.0,
            energyPriceIndexEma: 100.0,
            inflationEma: 0.02
        );

        $resultBaseline = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 1_000.0,
            realizedVariableMargin: 0.25,
            fixedCosts: 100.0,
            baselineVol: 0.10,
            macroState: $macroBaseline,
            mathUtility: $mathUtilityMock
        );

        $macroSpike = new MacroStateDTO(
            consumerSentimentIndexEma: 100.0,
            energyPriceIndexEma: 125.0,
            energyCostPushLag: 0.0025,
            inflationEma: 0.02
        );

        $resultSpike = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 1_000.0,
            realizedVariableMargin: 0.25,
            fixedCosts: 100.0,
            baselineVol: 0.10,
            macroState: $macroSpike,
            mathUtility: $mathUtilityMock
        );

        $this->assertGreaterThan($resultBaseline->clampedMargin, $resultSpike->clampedMargin);
        $this->assertLessThan($resultBaseline->ebit, $resultSpike->ebit);
    }

    public function testLaborMarketTightnessIncreasesKitchenWageCost(): void
    {
        $stock = new Stock();
        $stock->setTicker('MCD');
        $stock->setBeta('1.0');

        $mathUtilityMock = $this->createStub(MathUtility::class);
        $mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $macroNormalLabor = new MacroStateDTO(
            consumerSentimentIndexEma: 100.0,
            energyPriceIndexEma: 100.0,
            inflationEma: 0.02,
            unemploymentRateEma: 0.050
        );

        // A tight labor market shows up in kitchen crews' pay: wage growth well above the productivity-plus-target trend.
        $macroTightLabor = new MacroStateDTO(
            consumerSentimentIndexEma: 100.0,
            energyPriceIndexEma: 100.0,
            inflationEma: 0.02,
            unemploymentRateEma: 0.030,
            wageGrowthEma: 0.065
        );

        $resultNormal = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 10_000_000.0,
            realizedVariableMargin: 0.25,
            fixedCosts: 1_000_000.0,
            baselineVol: 0.0,
            macroState: $macroNormalLabor,
            mathUtility: $mathUtilityMock
        );

        $resultTight = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 10_000_000.0,
            realizedVariableMargin: 0.25,
            fixedCosts: 1_000_000.0,
            baselineVol: 0.0,
            macroState: $macroTightLabor,
            mathUtility: $mathUtilityMock
        );

        $this->assertGreaterThan(
            $resultNormal->clampedMargin,
            $resultTight->clampedMargin,
            'Tight labor markets (wage growth above trend) must increase restaurant kitchen wage costs and raise variable margin.'
        );
        $this->assertLessThan($resultNormal->ebit, $resultTight->ebit);
    }
    public function testTailEventPersistsAsAMarkovRegimeWithRandomExit(): void
    {
        $model = new RestaurantBusinessModel();
        $regimeKey = StreamContext::REGIME_STATE_PREFIX . RestaurantBusinessModel::REGIME_FOOD_SAFETY_RECOVERY;
        $macro = new MacroStateDTO(consumerSentimentIndexEma: 100.0, energyPriceIndexEma: 100.0);

        $run = function (array $momentum, bool $exits, float $onsetZ = 0.0) use ($model, $macro) {
            $stock = new Stock();
            $stock->setTicker('MCD');
            $stock->setBeta('1.0');
            $stock->setEarningsMomentumZ($momentum);
            // Partial mock: stream draws and regime dice are scripted, the mix-drift weight math stays real.
            $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generatePersistentZ', 'checkProbability'])->getMock();
            // The onset stream is recognised by its sentinel previous value; every other draw is flat.
            $math->method('generatePersistentZ')->willReturnCallback(fn (float $prev): float => $prev === -9.9 ? $onsetZ : 0.0);
            $math->method('checkProbability')->willReturn($exits);
            return $model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, $macro, $math);
        };

        $clean = $run([], false);
        $this->assertNull($clean->eventType);

        // Quarter 1: the trigger fires and the regime starts.
        $onset = $run(['event' => -9.9], false, -3.0);
        $this->assertSame(ShockEvent::PRODUCT_RECALL, $onset->eventType);
        $this->assertSame(1.0, $onset->streamZ[$regimeKey]);

        // Quarter 2: no new trigger, no exit -> the regime is still active and its cost persists silently.
        $ongoing = $run($onset->streamZ, false);
        $this->assertNull($ongoing->eventType, 'a continuing regime is not re-announced');
        $this->assertSame(2.0, $ongoing->streamZ[$regimeKey]);
        $this->assertLessThan($clean->actualRevenue, $ongoing->actualRevenue, 'lost traffic must still be recovering after the onset quarter');
        $this->assertGreaterThan($onset->actualRevenue, $ongoing->actualRevenue, 'traffic recovers geometrically quarter over quarter');

        // Quarter 3: the exit hazard fires -> back to baseline.
        $after = $run($ongoing->streamZ, true);
        $this->assertSame(0.0, $after->streamZ[$regimeKey]);
        $this->assertEqualsWithDelta($clean->actualRevenue, $after->actualRevenue, $clean->actualRevenue * 0.01, 'traffic is back to baseline once the regime exits');
    }

}
