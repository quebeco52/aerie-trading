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
        $state->tipsBreakeven = 0.04;
        $state->outputGap = 0.02;

        $target = $this->subsystem->calculateTargetRate($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        // r* (0.015) + pi_blend (0.04) + 0.5*(0.04 - 0.02) + 0.5*(0.02) = 0.015 + 0.04 + 0.010 + 0.010 = 0.075
        $this->assertEqualsWithDelta(0.075, $target, 0.001);
    }

    public function testTaylorRuleBlendsCoreInflationWithTIPSBreakeven(): void
    {
        $state = new MacroState();
        $state->inflationEma = 0.020;
        $state->tipsBreakeven = 0.040; // Forward expectations unanchored
        $state->outputGap = 0.0;

        $target = $this->subsystem->calculateTargetRate($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        // pi_blend = 0.70*0.02 + 0.30*0.04 = 0.026
        // target = 0.015 + 0.026 + 0.5*(0.026 - 0.02) + 0 = 0.015 + 0.026 + 0.003 = 0.044
        $this->assertEqualsWithDelta(0.044, $target, 0.001);
    }

    public function testEvansRulePreventsPrematureLiftoffAtZlb(): void
    {
        $state = new MacroState();
        $state->policyRate = 0.00; // At ZLB
        $state->unemploymentRateEma = 0.075; // > 5.0%
        $state->inflationEma = 0.018;        // < 2.5%
        $state->tipsBreakeven = 0.018;
        $state->outputGap = -0.005;

        $target = $this->subsystem->calculateTargetRate($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $this->assertGreaterThan(0.0, $target, 'Wu-Xia shadow rate must remain continuous and positive');

        // Under Evans Rule forward guidance, the policy rate remains anchored at ZLB (0.00%)
        $newPolicyRate = $this->subsystem->updatePolicyRate($state, $target, 0.25);
        $this->assertEquals(0.00, $newPolicyRate, 'Evans Rule must lock policy rate at 0.00% when at ZLB with elevated unemployment.');
    }

    public function testEvansRuleDoesNotFireDuringNormalExpansion(): void
    {
        $state = new MacroState();
        $state->policyRate = 0.040;           // Normal expansion rate (well above ZLB threshold)
        $state->unemploymentRateEma = 0.052; // Slightly above 5.0% threshold
        $state->inflationEma = 0.021;        // Contained inflation < 2.5%
        $state->tipsBreakeven = 0.021;
        $state->outputGap = 0.005;

        $target = $this->subsystem->calculateTargetRate($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $newPolicyRate = $this->subsystem->updatePolicyRate($state, $target, 0.25);
        // Normal Taylor rate should be ~3.8%, and rate hikes proceed
        $this->assertGreaterThan(0.030, $target, 'Evans Rule must NOT fire when central bank is in normal expansion regime.');
    }

    public function testEvansRuleReleasesWhenInflationCeilingBreached(): void
    {
        $state = new MacroState();
        $state->policyRate = 0.00;           // At ZLB
        $state->unemploymentRateEma = 0.070; // High unemployment > 5.0%
        $state->inflationEma = 0.028;        // Inflation breaches 2.5% ceiling
        $state->tipsBreakeven = 0.028;
        $state->outputGap = -0.01;

        $target = $this->subsystem->calculateTargetRate($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $newPolicyRate = $this->subsystem->updatePolicyRate($state, $target, 0.25);
        $this->assertGreaterThan(0.00, $newPolicyRate, 'Evans Rule must release and allow rate hikes when inflation breaches ceiling.');
    }

    public function testEvansRuleReleasesWhenUnemploymentThresholdAchieved(): void
    {
        $state = new MacroState();
        $state->policyRate = 0.00;           // At ZLB
        $state->unemploymentRateEma = 0.048; // Unemployment normalized <= 5.0%
        $state->inflationEma = 0.020;        // Contained inflation
        $state->tipsBreakeven = 0.020;
        $state->outputGap = 0.005;

        $target = $this->subsystem->calculateTargetRate($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $newPolicyRate = $this->subsystem->updatePolicyRate($state, $target, 0.25);
        $this->assertGreaterThan(0.00, $newPolicyRate, 'Evans Rule must release when unemployment drops below threshold.');
    }

    public function testCalculateTargetRateReturnsUnclampedShadowRateInDeepRecession(): void
    {
        $state = new MacroState();
        $state->policyRate = 0.00;
        $state->inflationEma = 0.005;
        $state->tipsBreakeven = 0.005;
        $state->outputGap = -0.06; // Deep recession
        $state->unemploymentRateEma = 0.045; // Unemployment below Evans threshold so Evans does not lock to 0.00

        $target = $this->subsystem->calculateTargetRate($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $this->assertLessThan(MacroEngine::EFFECTIVE_LOWER_BOUND, $target, 'Deep recession must yield an unclamped shadow rate below the ELB.');
    }

    public function testPolicyRateClampedAtEffectiveLowerBound(): void
    {
        $state = new MacroState();
        $state->policyRate = 0.005;
        $state->inflation = 0.00;
        $state->outputGap = -0.05;

        // Extreme negative target rate (-10%)
        $negativeTarget = -0.10;
        $newRate = $this->subsystem->updatePolicyRate($state, $negativeTarget, 1.0);

        $this->assertGreaterThanOrEqual(MacroEngine::EFFECTIVE_LOWER_BOUND, $newRate, 'Policy rate must not breach the Effective Lower Bound.');
        $this->assertEqualsWithDelta(MacroEngine::EFFECTIVE_LOWER_BOUND, $newRate, 0.001);
    }

    public function testSvenssonTermStructurePreservesShortRateAnchor(): void
    {
        $state = new MacroState();
        $state->policyRate = 0.035;
        $state->tipsBreakeven = 0.025;
        $state->marketVolatilityEma = 0.22;
        $state->outputGap = 0.01;

        $level = 0.040;
        $nsBeta1 = $state->policyRate - $level; // -0.005
        $nsBeta2 = 0.010;
        $nsBeta3 = 0.005;

        // At tau = 0, Svensson yield must equal policy rate exactly
        $yieldAtZero = $this->subsystem->calculateSvenssonTenor(0.0, $level, $nsBeta1, $nsBeta2, $nsBeta3, $state);
        $this->assertEqualsWithDelta($state->policyRate, $yieldAtZero, 0.00001, 'Yield at maturity 0 must equal policy rate.');
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
        $this->assertArrayHasKey('new_hold_timer', $curve);

        $this->assertGreaterThan(0.0, $curve['yield_10y']);
        $this->assertEqualsWithDelta($curve['yield_10y'], $curve['risk_neutral_10y'] + $curve['term_premium_10y'], 0.0001);
    }

    public function testAcmDecompositionIncludesBeta2PolicyExpectations(): void
    {
        $stateLowTarget = new MacroState();
        $stateLowTarget->policyRate = 0.03;
        $stateLowTarget->targetRate = 0.01; // Easing path expected
        $stateLowTarget->tipsBreakeven = 0.02;
        $stateLowTarget->outputGap = 0.0;

        $stateHighTarget = clone $stateLowTarget;
        $stateHighTarget->targetRate = 0.06; // Aggressive hiking path expected

        $curveLow = $this->subsystem->calculateYieldCurveAndQE($stateLowTarget, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);
        $curveHigh = $this->subsystem->calculateYieldCurveAndQE($stateHighTarget, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        $this->assertGreaterThan(
            $curveLow['risk_neutral_10y'],
            $curveHigh['risk_neutral_10y'],
            'ACM risk-neutral rate must increase when central bank hiking expectations (beta2) increase.'
        );
    }

    public function testBalanceSheetReinvestmentHoldPeriodBeforeQt(): void
    {
        // Central bank starts with balance sheet intensity from prior QE
        $state = new MacroState();
        $state->policyRate = 0.03;
        $state->balanceSheetIntensity = 0.015;
        $state->balanceSheetHoldTimer = 0.0;
        // Overheating economy meets QT activation criteria (gap > 0.015, inflation > 0.030)
        $state->outputGap = 0.025;
        $state->inflation = 0.035;

        // Step 1: dt = 1.0 year -> hold timer becomes 1.0 (< 2.0 years)
        $curveYear1 = $this->subsystem->calculateYieldCurveAndQE($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 1.0);
        $this->assertEqualsWithDelta(1.0, $curveYear1['new_hold_timer'], 0.01);
        $this->assertEqualsWithDelta(0.015, $curveYear1['new_balance_sheet_intensity'], 0.001, 'Balance sheet must remain held during reinvestment phase.');

        // Step 2: dt = 1.1 years -> hold timer reaches 2.1 (>= 2.0 years)
        $state->balanceSheetHoldTimer = 1.0;
        $curveYear2 = $this->subsystem->calculateYieldCurveAndQE($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 1.1);
        $this->assertGreaterThanOrEqual(MacroEngine::BALANCE_SHEET_REINVESTMENT_HOLD_YEARS, $curveYear2['new_hold_timer']);
        $this->assertLessThan(0.015, $curveYear2['new_balance_sheet_intensity'], 'Balance sheet runoff (QT) must begin once hold timer expires.');
    }

    public function testSvenssonCurvatureScalingReflectsDieboldLiEmpiricalCalibration(): void
    {
        $state = new MacroState();
        $state->policyRate = 0.00; // Liftoff scenario
        $state->targetRate = 0.06; // Terminal rate priced at 6%
        $state->tipsBreakeven = 0.02;
        $state->outputGap = 0.02;

        $curve = $this->subsystem->calculateYieldCurveAndQE($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        // Curvature beta2 = (SVENSSON_CURVATURE1_TARGET_SCALE * 0.06) + (SVENSSON_CURVATURE1_GAP_SCALE * 0.02)
        $expectedCurvature = (MacroEngine::SVENSSON_CURVATURE1_TARGET_SCALE * 0.06) + (MacroEngine::SVENSSON_CURVATURE1_GAP_SCALE * 0.02);
        $this->assertEqualsWithDelta($expectedCurvature, $curve['curvature'], 0.0001);

        // 2Y yield should price policy hikes above the 0% policy rate
        $this->assertGreaterThan($state->policyRate, $curve['yield_2y']);
        // 10Y yield anchored by fundamentals, yields realistic spread
        $this->assertGreaterThan(0.0, $curve['yield_10y']);
    }

    public function testFaitAccommodativeBufferAfterInflationShortfall(): void
    {
        $stateNeutral = new MacroState();
        $stateNeutral->inflationEma = 0.025; // Moderate overshoot
        $stateNeutral->tipsBreakeven = 0.025;
        $stateNeutral->outputGap = 0.01;
        $stateNeutral->cumulativeInflationGap = 0.0; // No historical shortfall

        $targetNeutral = $this->subsystem->calculateTargetRate($stateNeutral, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $statePostRecession = new MacroState();
        $statePostRecession->inflationEma = 0.025; // Same overshoot
        $statePostRecession->tipsBreakeven = 0.025;
        $statePostRecession->outputGap = 0.01;
        $statePostRecession->cumulativeInflationGap = -0.04; // Prior 2-year low inflation shortfall

        $targetPostRecession = $this->subsystem->calculateTargetRate($statePostRecession, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        // FAIT make-up strategy must keep target rate lower to tolerate overshoot after shortfall
        $this->assertLessThan($targetNeutral, $targetPostRecession);
        $this->assertEqualsWithDelta(0.010, $targetNeutral - $targetPostRecession, 0.003, 'FAIT offset should provide ~100bps accommodation buffer');
    }

    public function testPreferredHabitatDurationExtraction(): void
    {
        $shift2y = $this->mathUtility->calculatePreferredHabitatTermPremiumShift(0.02, 2.0, MacroEngine::PREFERRED_HABITAT_DURATION_SENSITIVITY);
        $shift10y = $this->mathUtility->calculatePreferredHabitatTermPremiumShift(0.02, 10.0, MacroEngine::PREFERRED_HABITAT_DURATION_SENSITIVITY);
        $shift30y = $this->mathUtility->calculatePreferredHabitatTermPremiumShift(0.02, 30.0, MacroEngine::PREFERRED_HABITAT_DURATION_SENSITIVITY);

        // QE extracts duration, so term premium shifts must be negative (suppression)
        $this->assertLessThan(0.0, $shift2y);
        $this->assertLessThan(0.0, $shift10y);
        $this->assertLessThan(0.0, $shift30y);

        // 30Y duration extraction must be 3x greater than 10Y and 15x greater than 2Y
        $this->assertEqualsWithDelta(3.0 * $shift10y, $shift30y, 0.0001);
        $this->assertEqualsWithDelta(5.0 * $shift2y, $shift10y, 0.0001);
        $this->assertEqualsWithDelta(-0.020, $shift10y, 0.0001, '10Y yield suppression under 200bps QE intensity must be exactly -200bps');
    }

    public function testQeActivationRespectsRateRoomThreshold(): void
    {
        $dt = 0.25;

        // Rate above threshold (3.0% > 2.5% threshold): conventional room remains, no QE even in recession
        $stateHighRate = new MacroState();
        $stateHighRate->policyRate = 0.030;
        $stateHighRate->outputGap = -0.020;
        $highRateCurve = $this->subsystem->calculateYieldCurveAndQE($stateHighRate, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, $dt);
        $this->assertEquals(0.0, $highRateCurve['new_qe_intensity'], 'QE must not activate when policy rate has conventional cutting room (> 2.5%)');

        // Rate below threshold (1.5% < 2.5% threshold): conventional room exhausted, QE triggers
        $stateLowRate = new MacroState();
        $stateLowRate->policyRate = 0.015;
        $stateLowRate->outputGap = -0.020;
        $lowRateCurve = $this->subsystem->calculateYieldCurveAndQE($stateLowRate, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, $dt);
        $this->assertGreaterThan(0.0, $lowRateCurve['new_qe_intensity'], 'QE must activate when policy rate is low and output gap is negative');
    }

    public function testTighteningCompressionDecaysWithProlongedInversion(): void
    {
        $stateFreshInversion = new MacroState();
        $stateFreshInversion->policyRate = 0.055; // Restrictive policy rate well above r* + pi = 0.035
        $stateFreshInversion->tipsBreakeven = 0.02;
        $stateFreshInversion->outputGap = 0.01;
        $stateFreshInversion->inversionDuration = 0.0; // Fresh tightening cycle

        $yieldsFresh = $this->subsystem->calculateYieldCurveAndQE($stateFreshInversion, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        $stateProlongedInversion = new MacroState();
        $stateProlongedInversion->policyRate = 0.055; // Same restrictive policy rate
        $stateProlongedInversion->tipsBreakeven = 0.02;
        $stateProlongedInversion->outputGap = 0.01;
        $stateProlongedInversion->inversionDuration = 3.0; // 3 years of prolonged high rates

        $yieldsProlonged = $this->subsystem->calculateYieldCurveAndQE($stateProlongedInversion, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        // After 3 years of persistent inversion, compression decays and term premium rebounds
        $this->assertGreaterThan($yieldsFresh['term_premium_10y'], $yieldsProlonged['term_premium_10y'], 'Prolonged inversion must attenuate tightening compression and restore term premium');
        $this->assertGreaterThan($yieldsFresh['yield_10y'], $yieldsProlonged['yield_10y'], '10Y yield must steepen as compression decays');
    }

    public function testCalculateBalanceSheetOperationsDirectly(): void
    {
        $state = new MacroState();
        $state->policyRate = 0.010;
        $state->outputGap = -0.025;
        $state->balanceSheetIntensity = 0.0;

        $bs = $this->subsystem->calculateBalanceSheetOperations($state, 0.25);

        $this->assertGreaterThan(0.0, $bs['new_balance_sheet_intensity']);
        $this->assertGreaterThan(0.0, $bs['new_qe_intensity']);
        $this->assertEquals(0.0, $bs['new_qt_intensity']);
        $this->assertEquals(0.0, $bs['new_hold_timer']);
    }

    public function testCalculateYieldCurveDirectly(): void
    {
        $state = new MacroState();
        $state->policyRate = 0.035;
        $state->targetRate = 0.035;
        $state->outputGap = 0.0;
        $state->tipsBreakeven = 0.02;
        $state->balanceSheetIntensity = 0.0;

        $yieldCurve = $this->subsystem->calculateYieldCurve($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $this->assertArrayHasKey('yield_2y', $yieldCurve);
        $this->assertArrayHasKey('yield_5y', $yieldCurve);
        $this->assertArrayHasKey('yield_10y', $yieldCurve);
        $this->assertArrayHasKey('yield_30y', $yieldCurve);
        $this->assertArrayHasKey('risk_neutral_10y', $yieldCurve);
        $this->assertArrayHasKey('term_premium_10y', $yieldCurve);
        $this->assertArrayNotHasKey('new_balance_sheet_intensity', $yieldCurve);
    }

    public function testCalculateRecessionProbability(): void
    {
        $stateNormal = new MacroState();
        $stateNormal->yield10y = 0.045;
        $stateNormal->policyRate = 0.025; // +200bps steep curve
        $stateNormal->termPremium10y = 0.005;
        $stateNormal->financialConditionsIndexEma = 0.0;

        $this->subsystem->calculateRecessionProbability($stateNormal);
        $this->assertLessThan(0.20, $stateNormal->recessionProbability, 'Steep curve and neutral financial conditions must yield low recession probability.');

        $stateInverted = new MacroState();
        $stateInverted->yield10y = 0.035;
        $stateInverted->policyRate = 0.055; // -200bps inverted curve
        $stateInverted->termPremium10y = -0.005;
        $stateInverted->financialConditionsIndexEma = 1.80;

        $this->subsystem->calculateRecessionProbability($stateInverted);
        $this->assertGreaterThan(0.70, $stateInverted->recessionProbability, 'Inverted yield curve and tight FCI must yield high recession probability.');
    }

    public function testCalculateMoneySupplyGrowth(): void
    {
        $dt = 0.25;
        $tfp = MacroEngine::TFP_DRIFT;
        $neutralGrowth = MacroEngine::TARGET_INFLATION + $tfp + MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE;

        $stateQe = new MacroState();
        $stateQe->balanceSheetIntensity = 0.50; // Active QE
        $stateQe->sloosTighteningIndexEma = -0.10;
        $stateQe->outputGap = 0.02;
        $stateQe->moneySupplyGrowth = $neutralGrowth;

        $this->subsystem->calculateMoneySupplyGrowth($stateQe, $dt, $tfp);
        $this->assertGreaterThan($neutralGrowth, $stateQe->moneySupplyGrowth, 'QE and bank lending expansion must accelerate broad money growth');
    }
}

