<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\CreditServicesBusinessModel;
use PHPUnit\Framework\TestCase;

class CreditServicesBusinessModelTest extends TestCase
{
    private CreditServicesBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new CreditServicesBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testDualStreamLendingAndSwipeInterchange(): void
    {
        $stock = new Stock();
        $stock->setTicker('COF');
        $stock->setBeta('1.1');

        $macro = new MacroStateDTO(
            inflationEma: 0.02,
            consumerSentimentIndexEma: 100.0,
            retailDefaultRateEma: 0.025,
            unemploymentRateEma: 0.045
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.55,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.05,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('lending', $result->streamRevenue);
        $this->assertArrayHasKey('swipe', $result->streamRevenue);
        $this->assertGreaterThan(0.0, $result->streamRevenue['lending']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['swipe']);
    }

    public function testUnemploymentSpikeIncreasesChargeOffProvisions(): void
    {
        $stock = new Stock();
        $stock->setTicker('COF');
        $stock->setBeta('1.0');

        $lowUnemploymentMacro = new MacroStateDTO(
            inflationEma: 0.02,
            consumerSentimentIndexEma: 100.0,
            retailDefaultRateEma: 0.025,
            unemploymentRateEma: 0.040
        );

        $highUnemploymentMacro = new MacroStateDTO(
            inflationEma: 0.02,
            consumerSentimentIndexEma: 100.0,
            retailDefaultRateEma: 0.025,
            unemploymentRateEma: 0.080
        );

        $mathMock = $this->createMock(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baseResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.55,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $lowUnemploymentMacro,
            mathUtility: $mathMock
        );

        $surgeResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.55,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $highUnemploymentMacro,
            mathUtility: $mathMock
        );

        $this->assertGreaterThan(
            $baseResult->clampedMargin,
            $surgeResult->clampedMargin,
            'Elevated unemployment must increase credit card charge-offs and expand the variable cost margin.'
        );
        $this->assertLessThan($baseResult->ebit, $surgeResult->ebit);
    }
}
