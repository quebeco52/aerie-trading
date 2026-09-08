<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\ModelParam;
use App\Data\StockModelTuning;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ConstructionBusinessModel;
use App\DTO\StreamContext;
use App\Service\Event\ShockEvent;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ConstructionBusinessModelTest extends TestCase
{
    private ConstructionBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new ConstructionBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testTriStreamEmissionAndSumConsistency(): void
    {
        $stock = new Stock();
        $stock->setTicker('GEN_CONST');
        $stock->setBeta('1.1');

        $macro = new MacroStateDTO(outputGapEma: 0.01, policyRateEma: 0.03, inflationEma: 0.02, energyPriceIndexEma: 100.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.25,
            fixedCosts: 10_000_000.0,
            baselineVol: 0.12,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('civil_infrastructure', $result->streamRevenue);
        $this->assertArrayHasKey('commercial_epc', $result->streamRevenue);
        $this->assertArrayHasKey('facilities_maintenance', $result->streamRevenue);

        $this->assertArrayHasKey('civil_infrastructure', $result->streamZ);
        $this->assertArrayHasKey('commercial_epc', $result->streamZ);
        $this->assertArrayHasKey('facilities_maintenance', $result->streamZ);
        $this->assertArrayHasKey('event', $result->streamZ);

        $this->assertGreaterThan(0.0, $result->streamRevenue['civil_infrastructure']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['commercial_epc']);
        $this->assertGreaterThan(0.0, $result->streamRevenue['facilities_maintenance']);

        $sumStreams = $result->streamRevenue['civil_infrastructure']
            + $result->streamRevenue['commercial_epc']
            + $result->streamRevenue['facilities_maintenance'];

        $this->assertEqualsWithDelta($result->actualRevenue, $sumStreams, 1.0);
    }

    public function testIbhiTickerParameterResolution(): void
    {
        $stock = new Stock();
        $stock->setTicker('IBHI');
        $stock->setBeta('1.0');

        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            policyRateEma: \App\Service\Macro\MacroEngine::BASE_NATURAL_RATE,
            inflationEma: 0.02,
            energyPriceIndexEma: 100.0
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.20,
            fixedCosts: 10_000_000.0,
            baselineVol: 0.00, // zero vol to test baseline weights
            macroState: $macro,
            mathUtility: $mathMock
        );

        // IBHI tuned weights: 60% Civil, 25% Commercial, 15% Maintenance
        $this->assertEqualsWithDelta(60_000_000.0, $result->streamRevenue['civil_infrastructure'], 1.0);
        $this->assertEqualsWithDelta(25_000_000.0, $result->streamRevenue['commercial_epc'], 1.0);
        $this->assertEqualsWithDelta(15_000_000.0, $result->streamRevenue['facilities_maintenance'], 1.0);
    }

    public function testPricingPowerMitigatesMaterialCostInflation(): void
    {
        $stockDefault = new Stock();
        $stockDefault->setTicker('GEN_CONST');
        $stockDefault->setBeta('1.0');

        // High inflation + energy spike
        $macro = new MacroStateDTO(
            outputGapEma: 0.0,
            policyRateEma: 0.03,
            inflationEma: 0.06, // 400bps above 2% target
            energyPriceIndexEma: 140.0 // 40% energy spike
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $resultDefault = $this->model->computeActualFinancials(
            $stockDefault,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.30,
            fixedCosts: 10_000_000.0,
            baselineVol: 0.0,
            macroState: $macro,
            mathUtility: $mathMock
        );

        // Under high material cost inflation, variable cost margin expands (realizedVariableMargin increases)
        $this->assertGreaterThan(0.30, $resultDefault->clampedMargin);
        $this->assertLessThan(1.50, $resultDefault->clampedMargin);
    }

    public function testGovernmentSpendingAndPropertyIndicesBoostConstruction(): void
    {
        $stock = new Stock();
        $stock->setTicker('IBHI');
        $stock->setBeta('1.0');

        $baseMacro = new MacroStateDTO(
            governmentSpendingIndexEma: 100.0,
            commercialPropertyIndexEma: 100.0,
            residentialPropertyIndexEma: 100.0
        );

        $boostMacro = new MacroStateDTO(
            governmentSpendingIndexEma: 120.0,
            commercialPropertyIndexEma: 110.0,
            residentialPropertyIndexEma: 110.0
        );

        $mathMock = $this->createStub(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baseResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.20, 10_000_000.0, 0.0, $baseMacro, $mathMock);
        $boostResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.20, 10_000_000.0, 0.0, $boostMacro, $mathMock);

        $this->assertGreaterThan($baseResult->streamRevenue['civil_infrastructure'], $boostResult->streamRevenue['civil_infrastructure']);
        $this->assertGreaterThan($baseResult->streamRevenue['commercial_epc'], $boostResult->streamRevenue['commercial_epc']);
    }

    public function testSeasonalityFactorsAndBreakEvenClearance(): void
    {
        $factors = $this->model->getSeasonalityFactors();
        $this->assertCount(4, $factors);
        $this->assertEqualsWithDelta(4.0, array_sum($factors), 1e-4, 'Seasonality factors must sum to 4.0.');

        // IBHI financial parameters: fcr = 0.60, operating margin = 0.09
        $fcr = 0.60;
        $m = 0.09;
        $vm = (1.0 - $m) * (1.0 - $fcr);
        $breakEvenFactor = ($fcr * (1.0 - $m)) / (1.0 - $vm); // ~0.8585

        foreach ($factors as $quarterIndex => $factor) {
            $this->assertGreaterThan(
                $breakEvenFactor,
                $factor,
                sprintf('Quarter %d factor (%.2f) must clear break-even factor (%.4f) for diversified EPC.', $quarterIndex + 1, $factor, $breakEvenFactor)
            );
        }
    }
    public function testTailEventPersistsAsAMarkovRegimeWithRandomExit(): void
    {
        $model = new ConstructionBusinessModel();
        $regimeKey = StreamContext::REGIME_STATE_PREFIX . ConstructionBusinessModel::REGIME_PROJECT_OVERRUN;
        $macro = new MacroStateDTO();

        $run = function (array $momentum, bool $exits, float $onsetZ = 0.0) use ($model, $macro) {
            $stock = new Stock();
            $stock->setTicker('IBHI');
            $stock->setBeta('1.0');
            $stock->setEarningsMomentumZ($momentum);
            // Partial mock: stream draws and regime dice are scripted, the mix-drift weight math stays real.
            $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generatePersistentZ', 'checkProbability'])->getMock();
            // The onset stream is recognised by its sentinel previous value; every other draw is flat.
            $math->method('generatePersistentZ')->willReturnCallback(fn (float $prev): float => $prev === -9.9 ? $onsetZ : 0.0);
            $math->method('checkProbability')->willReturn($exits);
            return $model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, $macro, $math);
        };

        $clean = $run([], false);
        $this->assertNull($clean->eventType);

        // Quarter 1: the trigger fires and the regime starts.
        $onset = $run(['event' => -9.9], false, -3.0);
        $this->assertSame(ShockEvent::PROJECT_DELAY, $onset->eventType);
        $this->assertSame(1.0, $onset->streamZ[$regimeKey]);

        // Quarter 2: no new trigger, no exit -> the regime is still active and its cost persists silently.
        $ongoing = $run($onset->streamZ, false);
        $this->assertNull($ongoing->eventType, 'a continuing regime is not re-announced');
        $this->assertSame(2.0, $ongoing->streamZ[$regimeKey]);
        $this->assertGreaterThan($clean->clampedMargin, $ongoing->clampedMargin, 'ongoing regime cost must persist after the onset quarter');

        // Quarter 3: the exit hazard fires -> back to baseline.
        $after = $run($ongoing->streamZ, true);
        $this->assertSame(0.0, $after->streamZ[$regimeKey]);
        $this->assertEqualsWithDelta($clean->clampedMargin, $after->clampedMargin, 1e-9, 'costs return to baseline once the regime exits');
    }

}

