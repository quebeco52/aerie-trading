<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\UtilityBusinessModel;
use App\DTO\StreamContext;
use App\Service\Event\ShockEvent;
use App\DTO\MacroStateDTO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class UtilityBusinessModelTest extends TestCase
{
    public function testMerchantSparkSpreadImpactsVariableCosts(): void
    {
        $model = new UtilityBusinessModel();
        $stock = new Stock();
        $stock->setTicker('UTIL');
        $stock->setBeta('0.5');

        $mathUtilityMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal'])
            ->getMock();
        // Sequence of generateStandardNormal calls:
        // 1. firm-wide demand innovation = 0.0
        // 2. regulatedZ = 0.0
        // 3. unregulatedZ idiosyncratic = 2.5 (composite 2.0: positive merchant spark spread readout)
        // 4. eventZ = 0.0
        $mathUtilityMock->expects($this->exactly(4))
            ->method('generateStandardNormal')
            ->willReturnOnConsecutiveCalls(0.0, 0.0, 2.5, 0.0);

        $macroState = \App\DTO\MacroStateDTO::fromArray(['inflation_ema' => 0.02]);
        $result = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.40,
            100.0,
            0.20,
            $macroState,
            $mathUtilityMock
        );

        // Positive unregulated trading Z increases merchant revenue
        $this->assertGreaterThan(1000.0, $result->actualRevenue);
    }

    public function testRateBaseCapexBurnValuation(): void
    {
        $model = new UtilityBusinessModel();

        $revenueFloorValue = 80.0;
        $peFairValue = 100.0;
        $fcfPerShare = -0.50; // Negative FCF due to rate-base T&D expansion CapEx
        $liveWacc = 0.06;
        $mathUtility = new MathUtility();

        $fairValue = $model->calculateEarningsValue(
            $revenueFloorValue,
            $peFairValue,
            $fcfPerShare,
            $liveWacc,
            $mathUtility
        );

        $expected = max(80.0, 100.0 * UtilityBusinessModel::NEGATIVE_FCF_VAL_DISCOUNT);
        $this->assertEquals($expected, $fairValue);
    }

    public function testUtilityIsUnderLeveraged(): void
    {
        $model = new UtilityBusinessModel();

        // Ke > Kd + 0.01, ICR >= 2.5, debtRatio < tolerance * 0.70 -> True
        $this->assertTrue(
            $model->isUnderLeveraged(
                0.30,
                0.60,
                3.0,
                2.0,
                0.08,
                0.05
            )
        );

        // ICR < 2.5 -> False
        $this->assertFalse(
            $model->isUnderLeveraged(
                0.30,
                0.60,
                2.0,
                2.0,
                0.08,
                0.05
            )
        );
    }
    public function testTailEventPersistsAsAMarkovRegimeWithRandomExit(): void
    {
        $model = new UtilityBusinessModel();
        $regimeKey = StreamContext::REGIME_STATE_PREFIX . UtilityBusinessModel::REGIME_INFRASTRUCTURE_LIABILITY;
        $macro = new MacroStateDTO();

        $run = function (array $momentum, bool $exits, float $onsetZ = 0.0) use ($model, $macro) {
            $stock = new Stock();
            $stock->setTicker('UTIL');
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
        $this->assertSame(ShockEvent::INFRASTRUCTURE_FAILURE, $onset->eventType);
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

    public function testLiabilityRegimeCommitsGridHardeningCapex(): void
    {
        $model = new UtilityBusinessModel();
        $macro = new MacroStateDTO();

        $run = function (array $momentum) use ($model, $macro) {
            $stock = new Stock();
            $stock->setTicker('UTIL');
            $stock->setBeta('0.5');
            $stock->setEarningsMomentumZ($momentum);
            $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generatePersistentZ', 'checkProbability'])->getMock();
            $math->method('generatePersistentZ')->willReturn(0.0);
            $math->method('checkProbability')->willReturn(false);
            return $model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, $macro, $math);
        };

        $clean = $run([]);
        $this->assertSame(0.0, $clean->scheduledCapex);

        $rebuilding = $run([StreamContext::REGIME_STATE_PREFIX . UtilityBusinessModel::REGIME_INFRASTRUCTURE_LIABILITY => 2.0]);
        $this->assertEqualsWithDelta(100_000_000.0 * 4.0 * UtilityBusinessModel::GRID_HARDENING_CAPEX_RATIO, $rebuilding->scheduledCapex, 1.0);
    }

}
