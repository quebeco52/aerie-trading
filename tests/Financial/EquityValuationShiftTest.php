<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The market factor's moves are news of the discount rate: they reprice equity value by their unexpected log return
 * (AssetMarketSubsystem::updateSystemicMarketFactor()), and the repricing fades, so over the long run it averages
 * nothing and leaves no trend in value.
 */
#[Group('unit')]
final class EquityValuationShiftTest extends TestCase
{
    private const DT = 1.0 / 360.0;

    /** Each tick the shift fades by its half-life and takes the factor's log return net of the jumps' expected one. */
    public function testEachTickAddsTheFactorsUnexpectedReturnToTheFadedShift(): void
    {
        $subsystem = new AssetMarketSubsystem(MathUtility::ownStream(11));
        $state = new MacroState();
        $state->equityValuationShift = -1.0;
        $fade = exp(-AssetMarketSubsystem::equityValuationShiftFade() * self::DT);
        $expectedJumpLoss = -MacroConstants::SYSTEMIC_JUMP_INTENSITY * self::DT * StochasticProcesses::kouTruncatedMean(
            MacroConstants::SYSTEMIC_JUMP_PROBABILITY_UP,
            MacroConstants::SYSTEMIC_JUMP_ETA_UP,
            MacroConstants::SYSTEMIC_JUMP_ETA_DOWN,
            FinancialConstants::MAX_JUMP_LOG_RETURN,
            abs(FinancialConstants::MIN_JUMP_LOG_RETURN),
        );
        $this->assertGreaterThan(0.0, $expectedJumpLoss);

        $calmTicks = 0;
        for ($tick = 0; $tick < 2000; $tick++) {
            $before = $state->equityValuationShift;
            $subsystem->updateSystemicMarketFactor($state, self::DT);
            if ($state->marketJumpMultiplier === 1.0) {
                $calmTicks++;
                $diffusion = $state->marketVolatility * sqrt(self::DT) * $state->marketZ;
                $this->assertEqualsWithDelta(($before * $fade) + $diffusion + $expectedJumpLoss, $state->equityValuationShift, 1e-12);
            }
        }
        $this->assertGreaterThan(1900, $calmTicks);
    }

    /** Over a long run the shift wanders around zero, with the spread the factor's variance and the fade give it. */
    public function testTheShiftAveragesZeroOverTheLongRun(): void
    {
        $subsystem = new AssetMarketSubsystem(MathUtility::ownStream(20261027));
        $state = new MacroState();
        $fade = AssetMarketSubsystem::equityValuationShiftFade();

        $shifts = [];
        for ($tick = 1; $tick <= 400 * 360; $tick++) {
            $subsystem->updateSystemicMarketFactor($state, self::DT);
            if ($tick % 90 === 0) {
                $shifts[] = $state->equityValuationShift;
            }
        }

        $mean = array_sum($shifts) / count($shifts);
        $sd = sqrt(array_sum(array_map(static fn(float $shift): float => ($shift - $mean) ** 2, $shifts)) / count($shifts));
        $expectedSd = $state->marketVolatility / sqrt(2.0 * $fade);

        // A fade of ln2 / 5 a year leaves ~40 independent draws in 400 years: the mean's standard error is ~0.05.
        $this->assertLessThan(0.15, abs($mean), 'the jumps\' expected loss must be given back');
        $this->assertGreaterThan(0.8 * $expectedSd, $sd);
        $this->assertLessThan(1.5 * $expectedSd, $sd);
    }
}
