<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\CommodityBusinessModel;
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

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $normalResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $normalMacro,
            mathUtility: $mathMock
        );

        $spikeResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $spikeMacro,
            mathUtility: $mathMock
        );

        // Spot price revenue surges aggressively under high inflation + energy spike (Schwartz convenience yield)
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
        $mathMock = $this->createStub(MathUtility::class);
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

    public function testRicardianMarginalCostIncreasesVariableCostRatioOnExtractionSurge(): void
    {
        $stock = new Stock();
        $stock->setTicker('GEN_COMMODITY');
        $stock->setBeta('1.0');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, energyPriceIndexEma: 100.0);

        // Neutral run: Z = 0 across all streams
        $mathNeutral = $this->createStub(MathUtility::class);
        $mathNeutral->method('generatePersistentZ')->willReturn(0.0);

        $resNeutral = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $mathNeutral
        );

        // Surge extraction volume: extraction_volume Z = 2.0, other streams = 0.0
        $mathSurge = $this->createStub(MathUtility::class);
        $mathSurge->method('generatePersistentZ')->willReturnOnConsecutiveCalls(
            2.0, // extraction_volume
            0.0, // spot_price
            0.0, // refining_spread
            0.0  // event
        );

        $resSurge = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $mathSurge
        );

        // Ricardian friction increases the variable cost ratio (clampedMargin)
        $this->assertGreaterThan($resNeutral->clampedMargin, $resSurge->clampedMargin);
    }

    public function testEnergyPriceSpikeSqueezesRefiningCrackSpreadMargin(): void
    {
        $stock = new Stock();
        $stock->setTicker('CASC');
        $stock->setBeta('1.0');

        // Neutral energy vs heavy energy feedstock price spike with neutral demand (outputGap = 0)
        $neutralMacro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, energyPriceIndexEma: 100.0);
        $spikeMacro   = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, energyPriceIndexEma: 180.0);

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $neutralRes = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.0, $neutralMacro, $mathMock);
        $spikeRes   = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.0, $spikeMacro, $mathMock);

        // Input drag reduces downstream refining spread revenue during an energy price spike
        $this->assertGreaterThan($spikeRes->streamRevenue['refining_spread'], $neutralRes->streamRevenue['refining_spread']);
    }

    public function testEnvironmentalDisasterImposesPenaltyAndExtractionThrottling(): void
    {
        $stock = new Stock();
        $stock->setTicker('GEN_COMMODITY');
        $stock->setBeta('1.0');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, energyPriceIndexEma: 100.0);

        $mathDisaster = $this->createStub(MathUtility::class);
        $mathDisaster->method('generatePersistentZ')->willReturnOnConsecutiveCalls(
            0.0,   // extraction_volume
            0.0,   // spot_price
            0.0,   // refining_spread
            -2.70  // event Z < -2.60 (ENVIRONMENTAL_DISASTER)
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $macro,
            mathUtility: $mathDisaster
        );

        $this->assertSame(ShockEvent::ENVIRONMENTAL_DISASTER, $result->eventType);
        $this->assertTrue($result->isPublicEvent);

        // Baseline extraction revenue without disaster would be 100M * 0.45 = 45M.
        // With disaster multiplier 0.85: 45M * 0.85 = 38.25M.
        $this->assertEqualsWithDelta(38_250_000.0, $result->streamRevenue['extraction_volume'], 1.0);

        // Disaster penalty adds 0.08 on top of the physical extraction/refining cost base (0.2883 + 0.08 = 0.3683)
        $this->assertEqualsWithDelta(0.3683, $result->clampedMargin, 0.001);
    }

    public function testGeopoliticalSanctionsThrottlesExtractionVolume(): void
    {
        $stock = new Stock();
        $stock->setTicker('GEN_COMMODITY');
        $stock->setBeta('1.0');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, energyPriceIndexEma: 100.0);

        $mathSanctions = $this->createStub(MathUtility::class);
        $mathSanctions->method('generatePersistentZ')->willReturnOnConsecutiveCalls(
            0.0,   // extraction_volume
            0.0,   // spot_price
            0.0,   // refining_spread
            -2.30  // event Z < -2.20 and > -2.60 (GEOPOLITICAL_SANCTIONS)
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $macro,
            mathUtility: $mathSanctions
        );

        $this->assertSame(ShockEvent::GEOPOLITICAL_SANCTIONS, $result->eventType);
        // Baseline 45M * 0.75 = 33.75M
        $this->assertEqualsWithDelta(33_750_000.0, $result->streamRevenue['extraction_volume'], 1.0);
    }

    public function testGeopoliticalExportBanSurgesSpotPrices(): void
    {
        $stock = new Stock();
        $stock->setTicker('GEN_COMMODITY');
        $stock->setBeta('1.0');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02, energyPriceIndexEma: 100.0);

        $mathExportBan = $this->createStub(MathUtility::class);
        $mathExportBan->method('generatePersistentZ')->willReturnOnConsecutiveCalls(
            0.0,  // extraction_volume
            0.0,  // spot_price
            0.0,  // refining_spread
            2.50  // event Z > 2.40 (GEOPOLITICAL_EXPORT_BAN)
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $macro,
            mathUtility: $mathExportBan
        );

        $this->assertSame(ShockEvent::GEOPOLITICAL_EXPORT_BAN, $result->eventType);
        // Baseline spot revenue 100M * 0.35 = 35M. With 1.30 mult = 45.5M.
        $this->assertEqualsWithDelta(45_500_000.0, $result->streamRevenue['spot_price'], 1.0);
    }

    public function testAssetDepreciationDecayAndModernizationGain(): void
    {
        $stock = new Stock();
        $stock->setOperatingMargin('0.20');

        // Underinvestment (reinvestmentRatio = 0.50)
        $this->model->applyAssetDepreciationDecay($stock, 0.50, 0.25);
        $decayedMargin = (float) $stock->getOperatingMargin();
        $this->assertLessThan(0.20, $decayedMargin);

        // Overinvestment (reinvestmentRatio = 1.50)
        $this->model->applyAssetDepreciationDecay($stock, 1.50, 0.25);
        $modernizedMargin = (float) $stock->getOperatingMargin();
        $this->assertGreaterThan($decayedMargin, $modernizedMargin);
    }

    public function testRefiningCrackSpreadDrivesDownstreamRefiningRevenue(): void
    {
        $stock = new Stock();
        $stock->setTicker('REFINER');
        $stock->setBeta('1.0');

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);
        $macroNormal = new MacroStateDTO(
            outputGapEma: 0.0,
            refiningCrackSpreadEma: 22.0
        );

        $resultNormal = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $macroNormal,
            mathUtility: $mathMock
        );

        $macroSpike = new MacroStateDTO(
            outputGapEma: 0.0,
            refiningCrackSpreadEma: 35.0 // Wide 3:2:1 crack spread
        );

        $resultSpike = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $macroSpike,
            mathUtility: $mathMock
        );

        $this->assertGreaterThan($resultNormal->streamRevenue['refining_spread'], $resultSpike->streamRevenue['refining_spread'], 'Elevated 3:2:1 refining crack spread index must expand refining revenue.');
    }
}
