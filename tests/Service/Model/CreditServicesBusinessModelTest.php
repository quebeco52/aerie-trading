<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\CreditServicesBusinessModel;
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

        $mathMock = $this->createStub(MathUtility::class);
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

    public function testRecessionRiskExpandsForwardCeclReserves(): void
    {
        $stock = new Stock();
        $stock->setTicker('DFS');
        $stock->setBeta('1.1');

        $lowRecessionMacro = new MacroStateDTO(recessionProbabilityEma: 0.05, macroCreditSpreadEma: 0.020);
        $highRecessionMacro = new MacroStateDTO(recessionProbabilityEma: 0.60, macroCreditSpreadEma: 0.020);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $lowResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.55, 20_000_000.0, 0.0, $lowRecessionMacro, $mathMock);
        $highResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.55, 20_000_000.0, 0.0, $highRecessionMacro, $mathMock);

        $this->assertGreaterThan(
            $lowResult->clampedMargin,
            $highResult->clampedMargin,
            'Elevated forward recession risk must trigger proactive CECL reserve builds on revolving loan portfolios.'
        );
    }

    public function testSloosCreditTighteningDampsRevolvingLendingVolume(): void
    {
        $stock = new Stock();
        $stock->setTicker('COF');
        $stock->setBeta('1.0');

        $looseMacro = new MacroStateDTO(sloosTighteningIndexEma: -0.10);
        $tightMacro = new MacroStateDTO(sloosTighteningIndexEma: 0.50);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $looseResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.55, 20_000_000.0, 0.0, $looseMacro, $mathMock);
        $tightResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.55, 20_000_000.0, 0.0, $tightMacro, $mathMock);

        $this->assertGreaterThan(
            $tightResult->streamRevenue['lending'],
            $looseResult->streamRevenue['lending'],
            'Commercial bank credit tightening gates revolving credit originations and contracts lending asset growth.'
        );
    }
}
