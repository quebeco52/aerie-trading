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

    public function testTighteningCompressionDecaysWithProlongedRestrictiveStance(): void
    {
        $stateFreshInversion = new MacroState();
        $stateFreshInversion->policyRate = 0.055; // Restrictive policy rate well above r* + pi = 0.035
        $stateFreshInversion->tipsBreakeven = 0.02;
        $stateFreshInversion->outputGap = 0.01;
        $stateFreshInversion->restrictiveDuration = 0.0; // Fresh tightening cycle

        $yieldsFresh = $this->subsystem->calculateYieldCurveAndQE($stateFreshInversion, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        $stateProlongedInversion = new MacroState();
        $stateProlongedInversion->policyRate = 0.055; // Same restrictive policy rate
        $stateProlongedInversion->tipsBreakeven = 0.02;
        $stateProlongedInversion->outputGap = 0.01;
        $stateProlongedInversion->restrictiveDuration = 3.0; // 3 years of prolonged high rates

        $yieldsProlonged = $this->subsystem->calculateYieldCurveAndQE($stateProlongedInversion, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 0.25);

        // After 3 years of restrictive policy, compression decays and term premium rebounds
        $this->assertGreaterThan($yieldsFresh['term_premium_10y'], $yieldsProlonged['term_premium_10y'], 'Prolonged restrictive stance must attenuate tightening compression and restore term premium');
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

    /**
     * A neutral stance (policy at r* plus target, no hikes or cuts priced, no gap) still slopes up, because
     * the term premium rises with duration: the ten-year point carries the whole benchmark premium and the
     * two-year note under a third of it. That difference is what a normal 2s10s slope is made of. Folding
     * the premium into the curve level, as before, handed the two-year the full premium and left the neutral
     * curve almost flat.
     */
    public function testTermPremiumRisesWithDurationSoTheNeutralCurveSlopesUp(): void
    {
        $state = new MacroState();
        $state->policyRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;
        $state->targetRate = $state->policyRate;
        $state->tipsBreakeven = MacroEngine::TARGET_INFLATION;
        $state->outputGap = 0.0;
        $state->marketVolatilityEma = 0.15;

        $curve = $this->subsystem->calculateYieldCurve($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $scale2y = MathUtility::calculateTermPremiumDurationScale(2.0, MacroEngine::TERM_PREMIUM_DURATION_HORIZON_YEARS);
        $scale30y = MathUtility::calculateTermPremiumDurationScale(30.0, MacroEngine::TERM_PREMIUM_DURATION_HORIZON_YEARS);
        $this->assertLessThan(0.35, $scale2y, 'the two-year note carries under a third of the ten-year premium');
        $this->assertGreaterThan(1.0, $scale30y, 'the thirty-year bond carries more than the ten-year');

        $expectedSpread = MacroEngine::NS_BASE_TERM_PREMIUM * (1.0 - $scale2y);
        $this->assertEqualsWithDelta($expectedSpread, $curve['yield_10y'] - $curve['yield_2y'], 0.0010, 'the neutral 2s10s slope is the premium the ten-year earns over the two-year');
        $this->assertEqualsWithDelta(MacroEngine::NS_BASE_TERM_PREMIUM, $curve['yield_10y'] - $state->policyRate, 0.0010, 'at neutral the ten-year sits one term premium over the policy rate');
        $this->assertGreaterThan($curve['yield_10y'], $curve['yield_30y'], 'the long end keeps rising');
    }

    /**
     * A restrictive stance inverts the curve through two channels at once: the two-year prices the cuts
     * that follow, and the premium is compressed toward nothing while policy sits well above neutral.
     */
    public function testRestrictiveStanceInvertsTheCurve(): void
    {
        $state = new MacroState();
        $state->policyRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION + 0.020; // 200bps above neutral
        $state->targetRate = $state->policyRate - 0.010; // cuts ahead
        $state->tipsBreakeven = 0.025;
        $state->outputGap = 0.005;
        $state->marketVolatilityEma = 0.15;

        $curve = $this->subsystem->calculateYieldCurve($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $this->assertLessThan($state->policyRate, $curve['yield_2y'], 'the two-year prices the cuts ahead');
        $this->assertLessThan(-0.0025, $curve['yield_10y'] - $curve['yield_2y'], 'the curve inverts by a meaningful margin');
    }

    public function testBreakevenShareOfTheLevelLandsInExpectationsNotTermPremium(): void
    {
        $anchored = new MacroState();
        $anchored->policyRate = 0.03;
        $anchored->targetRate = 0.03;
        $anchored->tipsBreakeven = MacroEngine::TARGET_INFLATION;
        $anchored->outputGap = 0.0;

        $unanchored = clone $anchored;
        $unanchored->tipsBreakeven = MacroEngine::TARGET_INFLATION + 0.02;

        $curveAnchored = $this->subsystem->calculateYieldCurve($anchored, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $curveUnanchored = $this->subsystem->calculateYieldCurve($unanchored, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        // The only premium channel that reads the breakeven is the Wright (2011) inflation risk premium.
        $expectedPremiumChange = MacroEngine::TERM_PREMIUM_IRP_EXPECTATION_SCALE * 0.02;
        $this->assertEqualsWithDelta(
            $expectedPremiumChange,
            $curveUnanchored['term_premium_10y'] - $curveAnchored['term_premium_10y'],
            0.00001,
            'Higher breakevens must move the term premium by the inflation risk premium only; the level shift belongs to the risk-neutral rate.'
        );

        // Only the model-consistent share of the anchor carries the breakeven; the Kozicki-Tinsley endpoint does not.
        $levelShift = (1.0 - MacroEngine::KOZICKI_TINSLEY_ENDPOINT_WEIGHT) * (1.0 - MacroEngine::LONG_RUN_INFLATION_ANCHOR_WEIGHT) * 0.02;
        $this->assertGreaterThan(
            $curveAnchored['risk_neutral_10y'] + 0.5 * $levelShift,
            $curveUnanchored['risk_neutral_10y'],
            'The expected-inflation share of the level must show up in the ACM risk-neutral ten-year rate.'
        );
    }

    public function testTermPremiumCanTurnNegativeButNotBelowTheFloor(): void
    {
        $state = new MacroState();
        $state->policyRate = 0.06;
        $state->targetRate = 0.06;
        $state->tipsBreakeven = MacroEngine::TARGET_INFLATION;
        $state->outputGap = -0.03;
        $state->marketVolatilityEma = 0.60; // panic: flight to safety
        $state->inversionDuration = 0.0;

        $curve = $this->subsystem->calculateYieldCurve($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $this->assertLessThan(0.0, $curve['term_premium_10y'], 'Restrictive policy plus flight to safety must push the ten-year term premium negative, as ACM shows for 2016-2021.');
        $this->assertGreaterThanOrEqual(
            MacroEngine::MIN_TERM_PREMIUM_10Y - 0.00001,
            $curve['term_premium_10y'],
            'The term premium must respect the structural floor.'
        );

        $extreme = clone $state;
        $extreme->policyRate = 0.12;
        $extreme->targetRate = 0.12;
        $extreme->marketVolatilityEma = 1.50;
        $curveExtreme = $this->subsystem->calculateYieldCurve($extreme, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $this->assertEqualsWithDelta(MacroEngine::MIN_TERM_PREMIUM_10Y, $curveExtreme['term_premium_10y'], 0.00001, 'Stacked compression channels bottom out at the floor.');
    }

    public function testTermPremiumShockDecaysAndRegimeRevertsToBaselineWithoutInnovations(): void
    {
        $quiet = new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
        };
        $subsystem = new MonetaryPolicySubsystem($quiet);

        $state = new MacroState();
        $state->termPremiumShock = 0.015;
        $state->termPremiumRegime = 0.020;

        $subsystem->updateTermPremiumDynamics($state, 1.0);

        $this->assertEqualsWithDelta(0.015 * exp(-MacroEngine::TERM_PREMIUM_SHOCK_KAPPA), $state->termPremiumShock, 0.00001, 'The transitory shock decays at its OU rate toward zero.');
        $expectedRegime = MacroEngine::NS_BASE_TERM_PREMIUM + (0.020 - MacroEngine::NS_BASE_TERM_PREMIUM) * exp(-MacroEngine::TERM_PREMIUM_REGIME_KAPPA);
        $this->assertEqualsWithDelta($expectedRegime, $state->termPremiumRegime, 0.00001, 'The regime drifts slowly back toward the structural baseline.');
        $this->assertGreaterThan(0.019, $state->termPremiumRegime, 'An eight-year half-life barely moves the regime in a year.');
    }

    public function testTermPremiumStatesAreClampedToTheirStructuralBounds(): void
    {
        $extreme = new class extends MathUtility {
            public function generateStandardNormal(): float { return 40.0; }
        };
        $subsystem = new MonetaryPolicySubsystem($extreme);

        $state = new MacroState();
        $subsystem->updateTermPremiumDynamics($state, 1.0);

        $this->assertEqualsWithDelta(MacroEngine::TERM_PREMIUM_SHOCK_CAP, $state->termPremiumShock, 0.00001);
        $this->assertEqualsWithDelta(MacroEngine::MAX_TERM_PREMIUM_REGIME, $state->termPremiumRegime, 0.00001);
    }

    public function testTransitoryTermPremiumShockMovesTheLongEndMoreThanTheTwoYear(): void
    {
        $calm = new MacroState();
        $calm->policyRate = 0.03;
        $calm->targetRate = 0.03;
        $calm->tipsBreakeven = MacroEngine::TARGET_INFLATION;
        $calm->outputGap = 0.0;

        $tantrum = clone $calm;
        $tantrum->termPremiumShock = 0.01;

        $curveCalm = $this->subsystem->calculateYieldCurve($calm, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $curveTantrum = $this->subsystem->calculateYieldCurve($tantrum, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $move10y = $curveTantrum['yield_10y'] - $curveCalm['yield_10y'];
        $move2y = $curveTantrum['yield_2y'] - $curveCalm['yield_2y'];
        $this->assertEqualsWithDelta(0.01, $move10y, 0.00001, 'A 100bps premium shock lands in full on the ten-year.');
        $this->assertLessThan(0.35 * $move10y, $move2y, 'The two-year carries under a third of it, so the shock bear-steepens the curve.');
        $this->assertEqualsWithDelta($curveCalm['risk_neutral_10y'], $curveTantrum['risk_neutral_10y'], 0.00001, 'The expected policy path is untouched; the shock is all premium.');
    }

    public function testBlissSlopeDecayKeepsTheTwoYearNearThePolicyRateAtTheLowerBound(): void
    {
        $zlb = new MacroState();
        $zlb->policyRate = 0.0025;
        $zlb->targetRate = 0.0025;
        $zlb->tipsBreakeven = MacroEngine::TARGET_INFLATION;
        $zlb->outputGap = -0.02;
        $zlb->termPremiumShock = 0.0;

        $curve = $this->subsystem->calculateYieldCurve($zlb, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $this->assertLessThan(0.0125, $curve['yield_2y'] - $zlb->policyRate, 'At the lower bound the two-year sits within ~120bps of policy (2012-2015 ran +20 to +50bps); the Diebold-Li decay left it 140bps above.');
        $this->assertGreaterThan(0.015, $curve['yield_10y'] - $curve['yield_2y'], 'A lower-bound curve is steep, as 2010-2013 were.');

        // Ten-year expectations beta to the policy rate is about a third, the empirical value, not the 0.14 of the
        // single-decay fit. Measured on the risk-neutral rate so the premium compression of a tightening does not blur it.
        $hiked = clone $zlb;
        $hiked->policyRate = 0.0425;
        $hiked->targetRate = 0.0425;
        $curveHiked = $this->subsystem->calculateYieldCurve($hiked, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $beta10y = ($curveHiked['risk_neutral_10y'] - $curve['risk_neutral_10y']) / ($hiked->policyRate - $zlb->policyRate);
        $this->assertEqualsWithDelta(0.317, $beta10y, 0.01, 'Ten-year expectations beta to policy near a third.');
    }

    public function testShiftingEndpointLearnsTheLongRunPolicyRateSlowly(): void
    {
        $state = new MacroState();
        $state->policyRate = 0.055;
        $state->naturalRate = MacroEngine::BASE_NATURAL_RATE;
        $start = $state->perceivedNeutralRate;

        // Three years at 5.5%: the gap closes at the adaptation speed (half-life ~5 years), so about a third.
        for ($tick = 0; $tick < 3 * 252; $tick++) {
            $this->subsystem->updateMarketExpectations($state, 1.0 / 252.0);
        }
        $closed = ($state->perceivedNeutralRate - $start) / (0.055 - $start);
        $this->assertEqualsWithDelta(1.0 - exp(-3.0 * MacroEngine::KOZICKI_TINSLEY_ADAPTATION_SPEED), $closed, 0.01, 'Kozicki-Tinsley endpoint adapts at its slow learning speed.');
        $this->assertLessThan(0.5, $closed, 'Three years is not enough to convince the market that neutral has moved.');
        $this->assertEqualsWithDelta(3.0, $state->restrictiveDuration, 0.01, 'The restrictive clock counts the whole stretch above neutral.');

        // Back at neutral the clock unwinds within months instead of resetting to zero on the first tick.
        $state->policyRate = 0.030;
        $this->subsystem->updateMarketExpectations($state, 1.0 / 252.0);
        $this->assertGreaterThan(2.9, $state->restrictiveDuration, 'A single tick at neutral must not erase the clock.');
        for ($tick = 0; $tick < 252; $tick++) {
            $this->subsystem->updateMarketExpectations($state, 1.0 / 252.0);
        }
        $this->assertLessThan(0.5, $state->restrictiveDuration, 'A year at neutral unwinds it.');
    }

    public function testADecadeOfHighPolicyRepricesTheLongEndAndUninvertsTheCurve(): void
    {
        $fresh = new MacroState();
        $fresh->policyRate = 0.050;
        $fresh->targetRate = 0.050;
        $fresh->tipsBreakeven = 0.027;
        $fresh->outputGap = 0.015;
        $fresh->termPremiumRegime = 0.006; // a low-premium era, where the static anchor inverted for years
        $fresh->perceivedNeutralRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;
        $fresh->restrictiveDuration = 0.0;

        $decade = clone $fresh;
        $decade->perceivedNeutralRate = 0.048; // the market has learned that 5% is where policy lives
        $decade->restrictiveDuration = 10.0;

        $curveFresh = $this->subsystem->calculateYieldCurve($fresh, MacroEngine::TARGET_INFLATION, 0.018);
        $curveDecade = $this->subsystem->calculateYieldCurve($decade, MacroEngine::TARGET_INFLATION, 0.018);

        $this->assertLessThan(0.0, $curveFresh['yield_10y'] - $curveFresh['yield_2y'], 'A fresh 5% stance against a 3.5% anchor inverts the curve.');
        $this->assertGreaterThan($curveFresh['level'], $curveDecade['level'], 'The anchor rises with the perceived endpoint.');
        $this->assertGreaterThan(0.0, $curveDecade['yield_10y'] - $curveDecade['yield_2y'], 'After a decade the curve is flat-to-positive, as 1995-1999 was, not inverted.');
    }

    public function testADecadeAtTheFloorDragsTheTenYearDown(): void
    {
        $zlb = new MacroState();
        $zlb->policyRate = 0.0025;
        $zlb->targetRate = 0.0025;
        $zlb->tipsBreakeven = 0.018;
        $zlb->outputGap = -0.005;

        $learned = clone $zlb;
        $learned->perceivedNeutralRate = 0.008;

        $curveFresh = $this->subsystem->calculateYieldCurve($zlb, MacroEngine::TARGET_INFLATION, 0.008);
        $curveLearned = $this->subsystem->calculateYieldCurve($learned, MacroEngine::TARGET_INFLATION, 0.008);

        $this->assertLessThan($curveFresh['yield_10y'] - 0.005, $curveLearned['yield_10y'], 'A learned low endpoint pulls the ten-year down by more than 50bps.');
        $this->assertLessThan(0.03, $curveLearned['yield_10y'], 'The ten-year can reach the 2012-2016 range once the floor is expected to last.');
    }

    public function testTaylorRuleLeansAgainstATermPremiumAboveBaseline(): void
    {
        $neutral = new MacroState();
        $neutral->inflation = MacroEngine::TARGET_INFLATION;
        $neutral->inflationEma = MacroEngine::TARGET_INFLATION;
        $neutral->tipsBreakeven = MacroEngine::TARGET_INFLATION;
        $neutral->outputGap = 0.0;
        $neutral->outputGapEma = 0.0;
        $neutral->termPremium10yEma = MacroEngine::NS_BASE_TERM_PREMIUM;

        $highPremium = clone $neutral;
        $highPremium->termPremium10yEma = MacroEngine::NS_BASE_TERM_PREMIUM + 0.010;

        $targetNeutral = $this->subsystem->calculateTargetRate($neutral, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $targetHigh = $this->subsystem->calculateTargetRate($highPremium, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $this->assertEqualsWithDelta(
            -MacroEngine::TAYLOR_LONG_RATE_OFFSET * 0.010,
            $targetHigh - $targetNeutral,
            0.00001,
            'Bernanke (2006): a 100bps term premium is met with ~50bps easier policy.'
        );
    }

    public function testTaylorRuleDoesNotUndoItsOwnQuantitativeEasing(): void
    {
        $state = new MacroState();
        $state->inflation = MacroEngine::TARGET_INFLATION;
        $state->inflationEma = MacroEngine::TARGET_INFLATION;
        $state->tipsBreakeven = MacroEngine::TARGET_INFLATION;
        $state->outputGap = 0.0;
        $state->outputGapEma = 0.0;
        $state->termPremium10yEma = MacroEngine::NS_BASE_TERM_PREMIUM;

        // QE compresses the ten-year premium by the habitat shift; the rule must read that as its own doing.
        $qe = clone $state;
        $qe->balanceSheetIntensity = 0.008;
        $qe->qeIntensity = 0.0; // isolate the long-rate channel from the Wu-Xia shadow term
        $qe->termPremium10yEma = MacroEngine::NS_BASE_TERM_PREMIUM - 0.008;

        $this->assertEqualsWithDelta(0.0, $this->subsystem->calculateLongRateGap($qe, MacroEngine::BASE_NATURAL_RATE), 0.00001, 'Balance-sheet compression is excluded from the long-rate gap.');
    }

    public function testTaylorRuleLeansAgainstAMarketThatHasRepricedNeutralUpward(): void
    {
        $state = new MacroState();
        $state->inflation = MacroEngine::TARGET_INFLATION;
        $state->inflationEma = MacroEngine::TARGET_INFLATION;
        $state->tipsBreakeven = MacroEngine::TARGET_INFLATION;
        $state->outputGap = 0.0;
        $state->outputGapEma = 0.0;
        $state->termPremium10yEma = MacroEngine::NS_BASE_TERM_PREMIUM;

        $repriced = clone $state;
        $repriced->perceivedNeutralRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION + 0.015;

        $gap = $this->subsystem->calculateLongRateGap($repriced, MacroEngine::BASE_NATURAL_RATE);
        $this->assertGreaterThan(0.0, $gap);
        $this->assertLessThan(0.015 * MacroEngine::KOZICKI_TINSLEY_ENDPOINT_WEIGHT, $gap, 'Only the share of the endpoint drift that reaches the ten-year counts.');
        $this->assertLessThan(
            $this->subsystem->calculateTargetRate($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE),
            $this->subsystem->calculateTargetRate($repriced, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE),
            'A market pricing neutral above the model endpoint gets easier policy, which then teaches it a lower endpoint.'
        );
    }


    public function testRecessionProbabilityRisesWhenSteepnessIsPremiumDrivenRatherThanExpected(): void
    {
        // Same 10Y-minus-policy slope, but in one state the slope is made of term premium: the expected
        // policy path (slope minus premium) is deeply negative, which is the recession signal
        // (Rosenberg & Maurer 2008), so the probit must read higher there, not lower.
        $expectationsDriven = new MacroState();
        $expectationsDriven->policyRate = 0.04;
        $expectationsDriven->yield10y = 0.045;
        $expectationsDriven->termPremium10y = 0.005;
        $expectationsDriven->financialConditionsIndexEma = 0.0;

        $premiumDriven = clone $expectationsDriven;
        $premiumDriven->termPremium10y = 0.020;

        $this->subsystem->calculateRecessionProbability($expectationsDriven);
        $this->subsystem->calculateRecessionProbability($premiumDriven);

        $this->assertGreaterThan(0.0, MacroEngine::RECESSION_PROBIT_BETA_TP, 'The premium coefficient must add the premium back, never subtract it twice.');
        $this->assertGreaterThan(
            $expectationsDriven->recessionProbability,
            $premiumDriven->recessionProbability,
            'A curve held up only by term premium hides an inverted expected policy path and must read more recessionary.'
        );
    }
}
