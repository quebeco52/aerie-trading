<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Service\Corporate\EarningsEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\StochasticProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Merton (1976) compensation of the fundamental jump that lands on a quarter's demand (EarningsEngine). The jump
 * is skewed down (mean -sigma/2 of the price jump), so uncompensated it lowered every firm's revenue by lambda x
 * E[e^J - 1] x dt each quarter, 0.2-0.7% at the seeded intensities, a level bias nothing else offset.
 */
class FundamentalJumpCompensationTest extends TestCase
{
    private const SEEDS = [5, 23, 61, 97];

    private const QUARTERS_PER_SEED = 100_000;

    /** A high-intensity seeded name: price jumps 4 a year at 0.15, scaled down to the fundamental jump. */
    private const PRICE_JUMP_INTENSITY = 4.0;

    private const PRICE_JUMP_VOL = 0.15;

    public function testTheCompensatedJumpLeavesDemandUnbiased(): void
    {
        $intensity = self::PRICE_JUMP_INTENSITY * EarningsEngine::FUNDAMENTAL_JUMP_INTENSITY_SCALE;
        $vol = self::PRICE_JUMP_VOL * EarningsEngine::FUNDAMENTAL_JUMP_VOL_SCALE;
        $mean = -self::PRICE_JUMP_VOL * EarningsEngine::FUNDAMENTAL_JUMP_MEAN_SCALE;
        $dt = FinancialConstants::QUARTER_YEARS;

        $sum = 0.0;
        $sumSq = 0.0;
        $jumps = 0;
        $n = 0;
        foreach (self::SEEDS as $seed) {
            mt_srand($seed);
            $math = new MathUtility();
            for ($i = 0; $i < self::QUARTERS_PER_SEED; ++$i) {
                $draw = $math->calculateJumpDiffusion($intensity, $mean, $vol, $dt);
                $jumps += $draw['multiplier'] !== 1.0 ? 1 : 0;
                $term = EarningsEngine::compensatedFundamentalJump((float) $draw['multiplier'], $intensity, $mean, $vol, $dt);
                $sum += $term;
                $sumSq += $term * $term;
                ++$n;
            }
        }

        $average = $sum / $n;
        $standardError = sqrt((($sumSq / $n) - ($average * $average)) / $n);
        $bias = $intensity * StochasticProcesses::lognormalJumpCompensator($mean, $vol) * $dt;

        $this->assertGreaterThan(0.05 * $n, $jumps, 'The fundamental jump does arrive.');
        $this->assertLessThan(-0.002, $bias, 'Control: uncompensated, the jump would bias demand down by a fifth of a percent a quarter.');
        $this->assertEqualsWithDelta(0.0, $average, 4.0 * $standardError, 'Compensated, the jump has no mean.');
    }

    /** E[e^J - 1] for J ~ N(m, s^2) is e^(m + s^2/2) - 1. */
    public function testTheLognormalCompensatorIsTheMomentGeneratingFunction(): void
    {
        $this->assertEqualsWithDelta(0.0, StochasticProcesses::lognormalJumpCompensator(0.0, 0.0), 1e-15);
        $this->assertEqualsWithDelta(exp(-0.075 + 0.5 * 0.075 * 0.075) - 1.0, StochasticProcesses::lognormalJumpCompensator(-0.075, 0.075), 1e-15);
        $this->assertEqualsWithDelta(exp(0.02) - 1.0, StochasticProcesses::lognormalJumpCompensator(0.0, 0.2), 1e-15);
    }
}
