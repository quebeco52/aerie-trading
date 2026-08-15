<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\CommodityBusinessModel;
use PHPUnit\Framework\TestCase;

class CommodityBusinessModelTest extends TestCase
{
    private CommodityBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new CommodityBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testTriStreamEmissionAndSumConsistency(): void
    {
        $stock = new Stock();
        $stock->setTicker('GEN_COMMODITY');
        $stock->setBeta('1.2');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, energyPriceIndexEma: 100.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 25_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('extraction_volume', $result->streamRevenue);
        $this->assertArrayHasKey('spot_price', $result->streamRevenue);
        $this->assertArrayHasKey('refining_spread', $result->streamRevenue);

        $this->assertArrayHasKey('extraction_volume', $result->streamZ);
        $this->assertArrayHasKey('spot_price', $result->streamZ);
        $this->assertArrayHasKey('refining_spread', $result->streamZ);
        $this->assertArrayHasKey('event', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->streamRevenue['extraction_volume']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['spot_price']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['refining_spread']);

        $sumStreams = $result->streamRevenue['extraction_volume']
            + $result->streamRevenue['spot_price']
            + $result->streamRevenue['refining_spread'];

        $this->assertEqualsWithDelta($result->actualRevenue, $sumStreams, 1.0);
    }

    public function testInflationAndEnergySpikeExplodesSpotRevenue(): void
    {
        $stock = new Stock();
        $stock->setTicker('SINK');
        $stock->setBeta('1.5');

        $normalMacro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, energyPriceIndexEma: 100.0);
        $spikeMacro  = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.06, energyPriceIndexEma: 200.0);

        $normalResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $normalMacro,
            mathUtility: $this->mathUtility
        );

        $spikeResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $spikeMacro,
            mathUtility: $this->mathUtility
        );

        // Spot price revenue surges aggressively under high inflation + energy spike
        $this->assertGreaterThan(
            $normalResult->streamRevenue['spot_price'] * 1.5,
            $spikeResult->streamRevenue['spot_price']
        );
    }

    public function testRefiningCrackSpreadSurgesWithTightOutputGap(): void
    {
        $stock = new Stock();
        $stock->setTicker('CASC');
        $stock->setBeta('1.0');

        $recessionMacro = new MacroStateDTO(outputGapEma: -0.03, inflationEma: 0.02, energyPriceIndexEma: 100.0);
        $boomMacro      = new MacroStateDTO(outputGapEma: 0.04, inflationEma: 0.02, energyPriceIndexEma: 100.0);

        $recessionResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.40,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $recessionMacro,
            mathUtility: $this->mathUtility
        );

        $boomResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.40,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $boomMacro,
            mathUtility: $this->mathUtility
        );

        // Downstream crack spread arbitrage expands significantly in economic booms
        $this->assertGreaterThan(
            $recessionResult->streamRevenue['refining_spread'],
            $boomResult->streamRevenue['refining_spread']
        );
    }

    public function testTickerParameterResolution(): void
    {
        $mathMock = $this->createMock(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $sink = new Stock();
        $sink->setTicker('SINK');
        $sink->setBeta('1.0');

        $casc = new Stock();
        $casc->setTicker('CASC');
        $casc->setBeta('1.0');

        $cndr = new Stock();
        $cndr->setTicker('CNDR');
        $cndr->setBeta('1.0');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, energyPriceIndexEma: 100.0);

        $sinkRes = $this->model->computeActualFinancials($sink, 100_000_000.0, 0.30, 20_000_000.0, 0.0, $macro, $mathMock);
        $cascRes = $this->model->computeActualFinancials($casc, 100_000_000.0, 0.30, 20_000_000.0, 0.0, $macro, $mathMock);
        $cndrRes = $this->model->computeActualFinancials($cndr, 100_000_000.0, 0.30, 20_000_000.0, 0.0, $macro, $mathMock);

        // SINK: 50% Extraction, 50% Spot, 0% Refining
        $this->assertEqualsWithDelta(50_000_000.0, $sinkRes->streamRevenue['extraction_volume'], 1.0);
        $this->assertEqualsWithDelta(50_000_000.0, $sinkRes->streamRevenue['spot_price'], 1.0);
        $this->assertEqualsWithDelta(0.0, $sinkRes->streamRevenue['refining_spread'], 1.0);

        // CASC: 25% Extraction/Throughput, 15% Spot, 60% Refining Spread
        $this->assertEqualsWithDelta(25_000_000.0, $cascRes->streamRevenue['extraction_volume'], 1.0);
        $this->assertEqualsWithDelta(15_000_000.0, $cascRes->streamRevenue['spot_price'], 1.0);
        $this->assertEqualsWithDelta(60_000_000.0, $cascRes->streamRevenue['refining_spread'], 1.0);

        // CNDR: 60% Extraction, 40% Spot, 0% Refining
        $this->assertEqualsWithDelta(60_000_000.0, $cndrRes->streamRevenue['extraction_volume'], 1.0);
        $this->assertEqualsWithDelta(40_000_000.0, $cndrRes->streamRevenue['spot_price'], 1.0);
        $this->assertEqualsWithDelta(0.0, $cndrRes->streamRevenue['refining_spread'], 1.0);
    }
}
