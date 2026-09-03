<?php

namespace App\Tests\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

class MonetaryPolicySubsystemTest extends TestCase
{
    private MathUtility $mathUtility;
    private MonetaryPolicySubsystem $subsystem;

    protected function setUp(): void
    {
        $this->mathUtility = new MathUtility();
        $this->subsystem = new MonetaryPolicySubsystem($this->mathUtility);
    }

    public function testTaylorTargetRateReflectsInflationAndOutputGap(): void
    {
        $state = new MacroState();
        $state->inflation = 0.04;
        $state->inflationEma = 0.04;
        $state->outputGap = 0.02;

        $target = $this->subsystem->calculateTargetRate($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        // r* (0.015) + pi_ema (0.04) + 0.5*(0.04 - 0.02) + 0.4*(0.02) = 0.015 + 0.04 + 0.010 + 0.008 = 0.068
        $this->assertEqualsWithDelta(0.068, $target, 0.001);
    }

    public function testEvansRuleEnforcesZeroLowerBoundDuringHighUnemployment(): void
    {
        $state = new MacroState();
        $state->unemploymentRateEma = 0.075; // > 6.5%
        $state->inflationEma = 0.018;        // < 2.5%

        $target = $this->subsystem->calculateTargetRate($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $this->assertEquals(0.00, $target);
    }

    public function testSvenssonYieldCurveFittingGeneratesConsistentTenors(): void
    {
        $state = new MacroState();
        $state->policyRate = 0.03;
        $state->targetRate = 0.04;
        $state->tipsBreakeven = 0.02;
        $state->outputGap = 0.01;

        $curve = $this->subsystem->calculateYieldCurveAndQE($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        $this->assertArrayHasKey('yield_2y', $curve);
        $this->assertArrayHasKey('yield_5y', $curve);
        $this->assertArrayHasKey('yield_10y', $curve);
        $this->assertArrayHasKey('yield_30y', $curve);
        $this->assertArrayHasKey('risk_neutral_10y', $curve);
        $this->assertArrayHasKey('term_premium_10y', $curve);

        $this->assertGreaterThan(0.0, $curve['yield_10y']);
        $this->assertEqualsWithDelta($curve['yield_10y'], $curve['risk_neutral_10y'] + $curve['term_premium_10y'], 0.0001);
    }
}
