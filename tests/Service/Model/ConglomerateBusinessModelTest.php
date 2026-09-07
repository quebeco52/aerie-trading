<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ConglomerateBusinessModel;
use PHPUnit\Framework\TestCase;

class ConglomerateBusinessModelTest extends TestCase
{
    private ConglomerateBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new ConglomerateBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testTriStreamEmissionAndSumConsistency(): void
    {
        $stock = new Stock();
        $stock->setTicker('GEN_CONGLOMERATE');
        $stock->setBeta('0.8');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, energyPriceIndexEma: 100.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 15_000_000.0,
            baselineVol: 0.08,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('industrial_manufacturing', $result->streamRevenue);
        $this->assertArrayHasKey('defensive_staples', $result->streamRevenue);
        $this->assertArrayHasKey('financial_investments', $result->streamRevenue);

        $this->assertArrayHasKey('industrial_manufacturing', $result->streamZ);
        $this->assertArrayHasKey('defensive_staples', $result->streamZ);
        $this->assertArrayHasKey('financial_investments', $result->streamZ);
        $this->assertArrayHasKey('event', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->streamRevenue['industrial_manufacturing']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['defensive_staples']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['financial_investments']);

        $sumStreams = $result->streamRevenue['industrial_manufacturing']
            + $result->streamRevenue['defensive_staples']
            + $result->streamRevenue['financial_investments'];

        $this->assertEqualsWithDelta($result->actualRevenue, $sumStreams, 1.0);
    }

    public function testSustainedCreditBlowoutSurgesContrarianFloat(): void
    {
        $stock = new Stock();
        $stock->setTicker('BRKW');
        $stock->setBeta('0.4');

        // Spot and trend spreads agree: the blowout has plateaued, so carry is earned with no further repricing.
        $calmMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: MacroEngine::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroEngine::BASE_CREDIT_SPREAD
        );
        $blowoutMacro = new MacroStateDTO(
            outputGapEma: -0.04,
            macroCreditSpread: 0.045,
            macroCreditSpreadEma: 0.045
        );

        $calm = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.25, 15_000_000.0, 0.0, $calmMacro, $this->mathUtility);
        $blowout = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.25, 15_000_000.0, 0.0, $blowoutMacro, $this->mathUtility);

        $this->assertGreaterThan(
            $calm->streamRevenue['financial_investments'],
            $blowout->streamRevenue['financial_investments']
        );
    }

    public function testSpreadWideningImpulseMarksFloatBookDown(): void
    {
        $stock = new Stock();
        $stock->setTicker('BRKW');
        $stock->setBeta('0.4');

        $calmMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: MacroEngine::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroEngine::BASE_CREDIT_SPREAD
        );
        // Spot spread gaps 250bps above trend: the held credit book reprices downward before any carry is earned.
        $wideningMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: 0.045,
            macroCreditSpreadEma: MacroEngine::BASE_CREDIT_SPREAD
        );

        $calm = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.25, 15_000_000.0, 0.0, $calmMacro, $this->mathUtility);
        $widening = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.25, 15_000_000.0, 0.0, $wideningMacro, $this->mathUtility);

        $this->assertLessThan(
            $calm->streamRevenue['financial_investments'],
            $widening->streamRevenue['financial_investments']
        );
    }

    public function testCorporateDefaultsErodeContrarianFloatCarry(): void
    {
        $stock = new Stock();
        $stock->setTicker('BRKW');
        $stock->setBeta('0.4');

        $cleanBlowout = new MacroStateDTO(
            outputGapEma: -0.04,
            macroCreditSpread: 0.045,
            macroCreditSpreadEma: 0.045
        );
        // Same spread level, but the spread is now compensating for a genuine default wave.
        $defaultWave = new MacroStateDTO(
            outputGapEma: -0.04,
            macroCreditSpread: 0.045,
            macroCreditSpreadEma: 0.045,
            corporateDefaultRate: 0.090,
            corporateDefaultRateEma: 0.090
        );

        $clean = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.25, 15_000_000.0, 0.0, $cleanBlowout, $this->mathUtility);
        $losses = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.25, 15_000_000.0, 0.0, $defaultWave, $this->mathUtility);

        $this->assertLessThan(
            $clean->streamRevenue['financial_investments'],
            $losses->streamRevenue['financial_investments']
        );
    }

    public function testBaselineCreditSpreadYieldsNoContrarianAlpha(): void
    {
        $stock = new Stock();
        $stock->setTicker('TRIV');
        $stock->setBeta('0.6');

        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: MacroEngine::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroEngine::BASE_CREDIT_SPREAD
        );

        $result = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.20, 10_000_000.0, 0.0, $macro, $this->mathUtility);

        // TRIV holds 10% float; a calm economy must not book a standing distress bonus on it.
        $this->assertEqualsWithDelta(10_000_000.0, $result->streamRevenue['financial_investments'], 1.0);
    }

    public function testInflationPassesThroughToNominalRevenueBase(): void
    {
        $stock = new Stock();
        $stock->setTicker('BRKW');
        $stock->setBeta('0.4');

        $stable = $this->model->getMacroPhysics($stock, new MacroStateDTO(tipsBreakevenEma: 0.02));
        $inflationary = $this->model->getMacroPhysics($stock, new MacroStateDTO(tipsBreakevenEma: 0.06));

        // Cyclicality is handled per-stream, so the blended demand shift stays neutralized.
        $this->assertSame(0.0, $stable['macro_demand_shift']);

        // Tollbooth escalators and staples list-price resets must reprice output, not just absorb cost inflation.
        $this->assertGreaterThan(1.0, $stable['pricing_power_multiplier']);
        $this->assertGreaterThan($stable['pricing_power_multiplier'], $inflationary['pricing_power_multiplier']);
    }

    public function testTrivAndBrkwParameterResolution(): void
    {
        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $triv = new Stock();
        $triv->setTicker('TRIV');
        $triv->setBeta('0.6');

        $brkw = new Stock();
        $brkw->setTicker('BRKW');
        $brkw->setBeta('0.2');

        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: MacroEngine::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroEngine::BASE_CREDIT_SPREAD
        );

        $trivRes = $this->model->computeActualFinancials($triv, 100_000_000.0, 0.20, 10_000_000.0, 0.0, $macro, $mathMock);
        $brkwRes = $this->model->computeActualFinancials($brkw, 100_000_000.0, 0.20, 10_000_000.0, 0.0, $macro, $mathMock);

        // TRIV: 60% Industrial, 30% Defensive, 10% Float
        $this->assertEqualsWithDelta(60_000_000.0, $trivRes->streamRevenue['industrial_manufacturing'], 1.0);
        $this->assertEqualsWithDelta(30_000_000.0, $trivRes->streamRevenue['defensive_staples'], 1.0);
        $this->assertEqualsWithDelta(10_000_000.0, $trivRes->streamRevenue['financial_investments'], 1.0);

        // BRKW: 30% Industrial, 45% Defensive, 25% Float
        $this->assertEqualsWithDelta(30_000_000.0, $brkwRes->streamRevenue['industrial_manufacturing'], 1.0);
        $this->assertEqualsWithDelta(45_000_000.0, $brkwRes->streamRevenue['defensive_staples'], 1.0);
        $this->assertEqualsWithDelta(25_000_000.0, $brkwRes->streamRevenue['financial_investments'], 1.0);
    }
}
