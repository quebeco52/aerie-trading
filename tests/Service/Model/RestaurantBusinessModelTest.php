<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\RestaurantBusinessModel;
use PHPUnit\Framework\TestCase;

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

        $macroTightLabor = new MacroStateDTO(
            consumerSentimentIndexEma: 100.0,
            energyPriceIndexEma: 100.0,
            inflationEma: 0.02,
            unemploymentRateEma: 0.030
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
            'Tight labor markets (unemployment < natural rate) must increase restaurant kitchen wage costs and raise variable margin.'
        );
        $this->assertLessThan($resultNormal->ebit, $resultTight->ebit);
    }
}
