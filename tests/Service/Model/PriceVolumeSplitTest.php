<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\ReitBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Price is not produced: revenue that is pure repricing of the same output must reach EBIT without a
 * matching unit of variable cost, and a firm's real pricing must be visible to the engine as the gap
 * between its selling price and its input cost level.
 */
#[AllowMockObjectsWithoutExpectations]
class PriceVolumeSplitTest extends TestCase
{
    public function testTemplateMethodAppliesVariableCostToVolumeRevenueOnly(): void
    {
        $model = new class extends StandardCorporateBusinessModel {
            protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
            {
                return new SectorPhysicsResult(
                    actualRevenue: 110.0,
                    rawVariableMargin: 0.50,
                    primaryShockZ: 0.0,
                    observableShockZ: 0.0,
                    priceRevenue: 10.0,
                );
            }
        };

        $result = $model->computeActualFinancials(new Stock(), 100.0, 0.50, 20.0, 0.1, new MacroStateDTO(), new MathUtility());

        // 100 of volume at a 50% cost ratio; the 10 of price is free of variable cost.
        $this->assertEqualsWithDelta(50.0, $result->actualVariableCosts, 1e-9);
        $this->assertEqualsWithDelta(40.0, $result->ebit, 1e-9);
        $this->assertEqualsWithDelta(10.0, $result->priceRevenue, 1e-9);
    }

    public function testPriceRevenueIsClampedToActualRevenue(): void
    {
        $model = new class extends StandardCorporateBusinessModel {
            protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
            {
                return new SectorPhysicsResult(actualRevenue: 50.0, rawVariableMargin: 0.40, primaryShockZ: 0.0, observableShockZ: 0.0, priceRevenue: 500.0);
            }
        };

        $result = $model->computeActualFinancials(new Stock(), 100.0, 0.40, 0.0, 0.1, new MacroStateDTO(), new MathUtility());

        $this->assertEqualsWithDelta(0.0, $result->actualVariableCosts, 1e-9);
        $this->assertEqualsWithDelta(50.0, $result->priceRevenue, 1e-9);
    }

    public function testReitRentEscalatorCarriesNoOperatingCost(): void
    {
        $model = new ReitBusinessModel();
        $stock = new Stock();
        $stock->setTicker('REIT');
        $stock->setBeta('0.5');

        $math = $this->createStub(MathUtility::class);
        $math->method('generatePersistentZ')->willReturn(0.0);

        // 4% CPI: the 2% excess is captured at 80% by escalators on the 85% lease stream.
        $macro = new MacroStateDTO(inflationEma: 0.04);
        $result = $model->computeActualFinancials($stock, 100_000_000.0, 0.40, 10_000_000.0, 0.0, $macro, $math);

        $escalatorRevenue = 100_000_000.0 * 0.85 * 0.02 * ReitBusinessModel::RENT_ESCALATOR_CAPTURE;
        $this->assertEqualsWithDelta($escalatorRevenue, $result->priceRevenue, 1.0);
        $this->assertEqualsWithDelta(($result->actualRevenue - $escalatorRevenue) * $result->clampedMargin, $result->actualVariableCosts, 1.0);
    }

    public function testStandardCorporateReportsInputCostLevelAtUnitElasticity(): void
    {
        $model = new StandardCorporateBusinessModel();
        $macro = new MacroStateDTO(tipsBreakevenEma: 0.03);

        // Median firm (pricing power 0.5) reprices at unit elasticity: price and input cost move together.
        $median = new Stock();
        $median->setTicker('MED');
        $median->setBeta('1.0');
        $physics = $model->getMacroPhysics($median, $macro);
        $this->assertEqualsWithDelta($physics['pricing_power_multiplier'], $physics['input_cost_multiplier'], 1e-9);

        // A price setter out-prices its inputs by half the inflation rate (elasticity 1.5 versus 1.0).
        $setter = new Stock();
        $setter->setTicker('SETTER');
        $setter->setBeta('1.0');
        $setterPhysics = (new class extends StandardCorporateBusinessModel {
            protected function resolveModelParameters(Stock $stock, array $defaults = []): \App\DTO\ModelParameters
            {
                return \App\Data\StockModelTuning::resolve($stock->getTicker(), [\App\Data\ModelParam::PricingPowerIndex->value => 1.0] + $defaults);
            }
        })->getMacroPhysics($setter, $macro);
        $realPricing = $setterPhysics['pricing_power_multiplier'] - $setterPhysics['input_cost_multiplier'];
        $this->assertGreaterThan(0.0, $realPricing);
        $this->assertEqualsWithDelta(($setterPhysics['pricing_power_multiplier'] - 1.0) / 3.0, $realPricing, 1e-9);
    }

    public function testElasticityDefaultsAndFinancialImmunity(): void
    {
        $this->assertSame(FinancialConstants::DEFAULT_PRICE_ELASTICITY_OF_DEMAND, (new StandardCorporateBusinessModel())->getPriceElasticityOfDemand());
        $this->assertSame(0.0, (new CommercialBankBusinessModel())->getPriceElasticityOfDemand());
    }
}
