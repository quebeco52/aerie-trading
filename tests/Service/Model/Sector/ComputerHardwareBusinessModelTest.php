<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ComputerHardwareBusinessModel;
use PHPUnit\Framework\TestCase;

class ComputerHardwareBusinessModelTest extends TestCase
{
    private ComputerHardwareBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new ComputerHardwareBusinessModel();
    }

    public function testDualStreamRevenueAndFxSensitivity(): void
    {
        $stock = new Stock();
        $stock->setTicker('DELL');
        $stock->setBeta('1.1');

        $baseMacro = new MacroStateDTO(
            exchangeRateIndexEma: 100.0,
            industrialMetalsIndexEma: 100.0
        );

        $strongDollarMacro = new MacroStateDTO(
            exchangeRateIndexEma: 120.0, // Strong domestic currency creates export headwind
            industrialMetalsIndexEma: 100.0
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baseResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $baseMacro,
            mathUtility: $mathMock
        );

        $strongDollarResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $strongDollarMacro,
            mathUtility: $mathMock
        );

        $this->assertArrayHasKey('enterprise_hardware', $baseResult->streamRevenue);
        $this->assertArrayHasKey('consumer_hardware', $baseResult->streamRevenue);

        // Strong dollar dampens foreign/export demand
        $this->assertLessThan($baseResult->actualRevenue, $strongDollarResult->actualRevenue);
    }

    public function testConsumerDevicesCarryTheHigherCostRatioWhateverTheMix(): void
    {
        // A consumer-only demand lift shifts the mix toward the lower-margin stream, so the blended variable cost
        // ratio rises. Checked on PENG's 85/15 enterprise mix, whose engine ratio (0.26) sits below the old fixed
        // 0.35 enterprise ratio and used to leave consumer hardware costing nothing, and on the default 60/40 mix.
        foreach (['PENG' => 0.26, 'GEN_HW' => 0.35] as $ticker => $variableCostRatio) {
            $blendedCost = function (float $sentiment) use ($ticker, $variableCostRatio): float {
                $stock = new Stock();
                $stock->setTicker($ticker);
                $stock->setBeta('1.0');
                $math = $this->createStub(MathUtility::class);
                $math->method('generatePersistentZ')->willReturn(0.0);
                $macro = new MacroStateDTO(consumerSentimentIndexEma: $sentiment);

                return $this->model->computeActualFinancials($stock, 100_000_000.0, $variableCostRatio, 20_000_000.0, 0.10, $macro, $math)->clampedMargin;
            };

            $neutral = \App\Service\Macro\MacroEngine::SENTIMENT_TREND_LEVEL;
            $this->assertGreaterThan($blendedCost($neutral), $blendedCost($neutral + 20.0), $ticker);
        }
    }

    public function testMetalsAreBelowMaterialityForASystemsAssembler(): void
    {
        // BEA 2017: a computer maker buys boards, chips and drives as components; metals through the whole supply chain are
        // 1.3% of its variable costs, under InputOutputExposures::MATERIALITY_FLOOR, so a metals spike is no margin event.
        $stock = new Stock();
        $stock->setTicker('HPE');
        $stock->setBeta('1.0');
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);
        $baseResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, new MacroStateDTO(industrialMetalsIndexEma: 100.0), $mathMock);
        $metalsSpikeResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.35, 20_000_000.0, 0.0, new MacroStateDTO(industrialMetalsIndexEma: 150.0), $mathMock);

        $this->assertArrayNotHasKey('metals', ComputerHardwareBusinessModel::INPUT_COST_EXPOSURES);
        $this->assertEqualsWithDelta($baseResult->clampedMargin, $metalsSpikeResult->clampedMargin, 1e-12);
    }

    public function testTheCostBaseStaffsToBothBooksUnitShifts(): void
    {
        $stock = new Stock();
        $stock->setTicker('HW_GAP');
        $recession = new MacroStateDTO(outputGapEma: -0.03, consumerSentimentIndexEma: \App\Service\Macro\MacroEngine::SENTIMENT_TREND_LEVEL - 10.0, exchangeRateIndexEma: 100.0);

        $this->assertEqualsWithDelta(0.0, $this->model->getMacroPhysics($stock, $recession)['macro_demand_shift'], 1e-12);

        // (0.80 + 0.45) x 1.30 = 1.625 per unit: 0.60 x (-0.03 x 1.625) + 0.40 x (-0.10 x 1.625) = -0.09425.
        $shift = $this->model->resolveSectorActivityShift($stock, $recession);
        $this->assertEqualsWithDelta(-0.09425, $shift, 1e-9);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);
        $booked = $this->model->computeActualFinancials($stock, 1000.0, 0.35, 300.0, 0.0, $recession, $mathMock)->actualRevenue / 1000.0 - 1.0;
        $this->assertEqualsWithDelta($booked, $shift, 1e-9, 'The base staffs to the units the books ship.');
    }
}
