<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\ConglomerateBusinessModel;
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

    public function testContrarianFloatSurgesDuringRecession(): void
    {
        $stock = new Stock();
        $stock->setTicker('BRKW');
        $stock->setBeta('0.4');

        $normalMacro    = new MacroStateDTO(outputGapEma: 0.0, macroCreditSpread: 0.015);
        $recessionMacro = new MacroStateDTO(outputGapEma: -0.04, macroCreditSpread: 0.040); // 400bps credit spread blowout

        $normalResult = $this->model->computeActualFinancials(
            $stock,
            100_000_000.0,
            0.25,
            15_000_000.0,
            0.0,
            $normalMacro,
            $this->mathUtility
        );

        $recessionResult = $this->model->computeActualFinancials(
            $stock,
            100_000_000.0,
            0.25,
            15_000_000.0,
            0.0,
            $recessionMacro,
            $this->mathUtility
        );

        // Contrarian float revenue surges when credit spreads and recession distress spike
        $this->assertGreaterThan(
            $normalResult->streamRevenue['financial_investments'],
            $recessionResult->streamRevenue['financial_investments']
        );
    }

    public function testTrivAndBrkwParameterResolution(): void
    {
        $mathMock = $this->createMock(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $triv = new Stock();
        $triv->setTicker('TRIV');
        $triv->setBeta('0.6');

        $brkw = new Stock();
        $brkw->setTicker('BRKW');
        $brkw->setBeta('0.2');

        $macro = new MacroStateDTO(outputGapEma: 0.0, macroCreditSpread: 0.015);

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
