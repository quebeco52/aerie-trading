<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\TechBusinessModel;
use App\DTO\StreamContext;
use App\Service\Event\ShockEvent;
use App\DTO\MacroStateDTO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TechBusinessModelTest extends TestCase
{
    public function testSaaSARROperatingLeverageAndContinuousWageInflation(): void
    {
        $model = new TechBusinessModel();
        $stock = new Stock();
        $stock->setTicker('TECH');
        $stock->setBeta('1.5');

        $mathUtilityMock = $this->createStub(MathUtility::class);
        // sequence: subscriptionZ=2.0 (strong cloud ARR expansion), adZ=0, eventZ=0, analystError=0
        $mathUtilityMock->method('generateStandardNormal')
            ->willReturnOnConsecutiveCalls(2.0, 0.0, 0.0, 0.0);

        $macroState = \App\DTO\MacroStateDTO::fromArray(['inflation_ema' => 0.02]);
        $result = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.30,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock
        );

        // SaaS ARR expansion lowers variable cost percentage below 30%
        $this->assertLessThan(1000.0 * 0.30, $result->actualVariableCosts);
    }

    public function testSoftwareTechDebtAndCloudPlatformModernization(): void
    {
        $model = new TechBusinessModel();

        // R = 0.5 -> Software tech debt decay
        $stock = new Stock();
        $stock->setOperatingMargin('0.35');
        $model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $decayed = (float) $stock->getOperatingMargin();
        $this->assertLessThan(0.35, $decayed);
        $this->assertGreaterThanOrEqual(TechBusinessModel::MIN_OPERATING_MARGIN_FLOOR, $decayed);

        // R = 1.5 -> Cloud ARR platform modernization
        $stock->setOperatingMargin('0.35');
        $model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $expanded = (float) $stock->getOperatingMargin();
        $this->assertGreaterThan(0.35, $expanded);
        $this->assertLessThanOrEqual(TechBusinessModel::MAX_OPERATING_MARGIN_CEILING, $expanded);
    }
    public function testTailEventPersistsAsAMarkovRegimeWithRandomExit(): void
    {
        $model = new TechBusinessModel();
        $regimeKey = StreamContext::REGIME_STATE_PREFIX . TechBusinessModel::REGIME_CONSENT_DECREE;
        $macro = new MacroStateDTO();

        $run = function (array $momentum, bool $exits, float $onsetZ = 0.0) use ($model, $macro) {
            $stock = new Stock();
            $stock->setTicker('TECH');
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
        $this->assertSame(ShockEvent::REGULATORY_FINE, $onset->eventType);
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
