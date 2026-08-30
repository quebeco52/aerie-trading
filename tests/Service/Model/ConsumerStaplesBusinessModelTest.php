<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\ModelParam;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ConsumerStaplesBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ConsumerStaplesBusinessModelTest extends TestCase
{
    public function testDynamicWorkingCapitalIntensity(): void
    {
        $model = new ConsumerStaplesBusinessModel();

        // 1. Default stock mix (65% branded, 35% volume)
        $defaultStock = new Stock();
        $defaultStock->setTicker('DEFAULT_STAPLE');
        $expectedDefaultNwc = (0.65 * -0.05 + 0.35 * 0.05) / 1.0; // -0.015
        $this->assertEqualsWithDelta($expectedDefaultNwc, $model->getWorkingCapitalIntensity($defaultStock), 0.0001);

        // 2. Pure branded consumer staples (e.g. LARK: 80% branded, 20% volume)
        $lark = new Stock();
        $lark->setTicker('LARK');
        $expectedLarkNwc = (0.80 * -0.05 + 0.20 * 0.05) / 1.0; // -0.030
        $this->assertEqualsWithDelta($expectedLarkNwc, $model->getWorkingCapitalIntensity($lark), 0.0001);

        // 3. Agricultural volume heavy (e.g. CROP: 20% branded, 50% volume)
        $crop = new Stock();
        $crop->setTicker('CROP');
        $expectedCropNwc = (0.20 * -0.05 + 0.50 * 0.05) / 0.70; // +0.0214
        $this->assertEqualsWithDelta($expectedCropNwc, $model->getWorkingCapitalIntensity($crop), 0.0001);

        // 4. Threshold invariant
        $this->assertEquals(0.15, $model->getReversionSpeed());
        $this->assertEquals(0.015, $model->getMoatSpread());
    }

    public function testPricingPowerAndElasticityModulation(): void
    {
        $model = new ConsumerStaplesBusinessModel();
        $macro = new MacroStateDTO(outputGapEma: -0.04, inflationEma: 0.05);

        // Standard stock (pricing power = 0.50)
        $stockStandard = new Stock();
        $stockStandard->setTicker('STD_STAPLE');
        $stockStandard->setBeta('0.8');

        $physicsStandard = $model->getMacroPhysics($stockStandard, $macro);

        // High pricing power stock (e.g. SGRB: pricing power = 0.90)
        $stockHighPricing = new Stock();
        $stockHighPricing->setTicker('SGRB');
        $stockHighPricing->setBeta('0.8');

        $physicsHighPricing = $model->getMacroPhysics($stockHighPricing, $macro);

        // Higher pricing power lowers PED (less demand drop during recession)
        $this->assertGreaterThan(
            $physicsStandard['macro_demand_shift'],
            $physicsHighPricing['macro_demand_shift']
        );

        // Higher pricing power expands nominal revenue more during inflation
        $this->assertGreaterThan(
            $physicsStandard['pricing_power_multiplier'],
            $physicsHighPricing['pricing_power_multiplier']
        );
    }

    public function testCommodityInputElasticityAndAgriculturalCostSqueeze(): void
    {
        $model = new ConsumerStaplesBusinessModel();
        $stock = new Stock();
        $stock->setTicker('STAPLE');
        $stock->setBeta('0.6');

        $macroState = new MacroStateDTO(inflationEma: 0.04, energyPriceIndexEma: 100.0);

        // 1. Crop failure / agricultural supply shortage (volumeZ = -2.0)
        $shortageMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
        $shortageMock->method('generateStandardNormal')
            ->willReturnOnConsecutiveCalls(0.0, -2.0, 0.0, 0.0, 0.0);

        $shortageResult = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.60,
            50.0,
            0.15,
            $macroState,
            $shortageMock
        );

        // 2. Bumper crop / harvest abundance (volumeZ = +2.0)
        $bumperMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
        $bumperMock->method('generateStandardNormal')
            ->willReturnOnConsecutiveCalls(0.0, 2.0, 0.0, 0.0, 0.0);

        $bumperResult = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.60,
            50.0,
            0.15,
            $macroState,
            $bumperMock
        );

        // Crop failure increases variable cost ratio relative to bumper harvest
        $this->assertGreaterThan($bumperResult->clampedMargin, $shortageResult->clampedMargin);
    }

    public function testPackagingAndEnergyLogisticsPenaltyWithHedging(): void
    {
        $model = new ConsumerStaplesBusinessModel();
        $mathUtility = new MathUtility();

        // Stock without commodity hedging
        $unhedgedStock = new Stock();
        $unhedgedStock->setTicker('LARK');
        $unhedgedStock->setBeta('1.0');

        // Stock with internal Proof Desk commodity trading (20% weight)
        $hedgedStock = new Stock();
        $hedgedStock->setTicker('PINT');
        $hedgedStock->setBeta('1.0');

        $spikeMacro = new MacroStateDTO(inflationEma: 0.02, energyPriceIndexEma: 150.0); // +50% energy spike

        $unhedgedResult = $model->computeActualFinancials(
            $unhedgedStock,
            100_000_000.0,
            0.40,
            10_000_000.0,
            0.0,
            $spikeMacro,
            $mathUtility
        );

        $hedgedResult = $model->computeActualFinancials(
            $hedgedStock,
            100_000_000.0,
            0.40,
            10_000_000.0,
            0.0,
            $spikeMacro,
            $mathUtility
        );

        // Hedged stock experiences lower variable cost ratio due to inventory buffer
        $unhedgedCostRatio = $unhedgedResult->actualVariableCosts / $unhedgedResult->actualRevenue;
        $hedgedCostRatio = $hedgedResult->actualVariableCosts / $hedgedResult->actualRevenue;

        $this->assertLessThan($unhedgedCostRatio, $hedgedCostRatio);
    }

    public function testProductRecallAndRegulatoryFineTailRisk(): void
    {
        $model = new ConsumerStaplesBusinessModel();
        $stock = new Stock();
        $stock->setTicker('STAPLE');
        $stock->setBeta('0.8');

        $macro = new MacroStateDTO(inflationEma: 0.02, energyPriceIndexEma: 100.0);

        // Severe event shock (Z = -3.0 -> PRODUCT_RECALL)
        $mathRecall = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
        $mathRecall->method('generateStandardNormal')->willReturnOnConsecutiveCalls(0.0, 0.0, -3.0, 0.0, 0.0);

        $recallResult = $model->computeActualFinancials(
            $stock,
            100_000.0,
            0.40,
            10_000.0,
            0.0,
            $macro,
            $mathRecall
        );

        $this->assertSame(ShockEvent::PRODUCT_RECALL, $recallResult->eventType);
        $this->assertTrue($recallResult->isPublicEvent);

        // Moderate event shock (Z = -2.2 -> REGULATORY_FINE)
        $mathFine = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
        $mathFine->method('generateStandardNormal')->willReturnOnConsecutiveCalls(0.0, 0.0, -2.2, 0.0, 0.0);

        $fineResult = $model->computeActualFinancials(
            $stock,
            100_000.0,
            0.40,
            10_000.0,
            0.0,
            $macro,
            $mathFine
        );

        $this->assertSame(ShockEvent::REGULATORY_FINE, $fineResult->eventType);
        $this->assertTrue($fineResult->isPublicEvent);
    }

    public function testBrandEquityAmortizationAndMarketingSuperCycle(): void
    {
        $model = new ConsumerStaplesBusinessModel();

        // R = 0.5 -> Brand equity erosion
        $stock = new Stock();
        $stock->setOperatingMargin('0.22');
        $model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $decayed = (float) $stock->getOperatingMargin();
        $this->assertLessThan(0.22, $decayed);
        $this->assertGreaterThanOrEqual(ConsumerStaplesBusinessModel::MIN_OPERATING_MARGIN_FLOOR, $decayed);

        // R = 1.5 -> Marketing super-cycle expands pricing power
        $stock->setOperatingMargin('0.22');
        $model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $expanded = (float) $stock->getOperatingMargin();
        $this->assertGreaterThan(0.22, $expanded);
        $this->assertLessThanOrEqual(ConsumerStaplesBusinessModel::MAX_OPERATING_MARGIN_CEILING, $expanded);
    }

    public function testWeaponizedProofDeskCommodityArbitrage(): void
    {
        $model = new ConsumerStaplesBusinessModel();
        $mathUtility = new MathUtility();

        $stock = new Stock();
        $stock->setTicker('PINT');
        $stock->setBeta('0.8');

        $normalMacro = new MacroStateDTO(inflationEma: 0.02, energyPriceIndexEma: 100.0);
        $spikeMacro = new MacroStateDTO(inflationEma: 0.06, energyPriceIndexEma: 150.0);

        $normalResult = $model->computeActualFinancials(
            $stock,
            100_000_000.0,
            0.40,
            10_000_000.0,
            0.0,
            $normalMacro,
            $mathUtility
        );

        $spikeResult = $model->computeActualFinancials(
            $stock,
            100_000_000.0,
            0.40,
            10_000_000.0,
            0.0,
            $spikeMacro,
            $mathUtility
        );

        $this->assertArrayHasKey('commodity_trading', $normalResult->streamRevenue);
        $this->assertArrayHasKey('commodity_trading', $spikeResult->streamRevenue);

        // Under inflation/energy spike, the Proof Desk generates windfall commodity trading profits
        $this->assertGreaterThan(
            $normalResult->streamRevenue['commodity_trading'],
            $spikeResult->streamRevenue['commodity_trading']
        );
    }

    public function testExchangeRateDragOnMacroPhysics(): void
    {
        $model = new ConsumerStaplesBusinessModel();
        $stock = new Stock();
        $stock->setTicker('STD_STAPLE');
        $stock->setBeta('0.8');

        $baseMacro = new MacroStateDTO(exchangeRateIndexEma: 100.0);
        $strongDollarMacro = new MacroStateDTO(exchangeRateIndexEma: 120.0);

        $basePhysics = $model->getMacroPhysics($stock, $baseMacro);
        $strongDollarPhysics = $model->getMacroPhysics($stock, $strongDollarMacro);

        $this->assertLessThan($basePhysics['macro_demand_shift'], $strongDollarPhysics['macro_demand_shift']);
    }
}

