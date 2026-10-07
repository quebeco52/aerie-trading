<?php

namespace App\Tests\Service\Macro\Subsystem;

use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
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
        $state->outputGapEma = 0.02;

        $target = $this->subsystem->calculateTargetRate($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        // r* + pi_blend + w_pi * (pi_blend - pi*) + w_y * gap, with a settled gap so the projection adds nothing
        $expected = MacroEngine::BASE_NATURAL_RATE + 0.04
            + (MonetaryPolicySubsystem::TAYLOR_INFLATION_WEIGHT * (0.04 - MacroEngine::TARGET_INFLATION))
            + (MonetaryPolicySubsystem::TAYLOR_OUTPUT_GAP_WEIGHT * 0.02);
        $this->assertEqualsWithDelta($expected, $target, 0.001);
    }

    /**
     * The rule answers demand and sees through the part of the gap a productivity gain opens (Blinder & Yellen
     * 2001): an economy running 1% above potential because output caught up with new technology first sets the
     * same rate as one at potential, and a 1% demand boom still gets the full gap weight.
     */
    public function testTheRuleSeesThroughTheProductivitySupplyGap(): void
    {
        $atPotential = new MacroState();
        $atPotential->outputGap = 0.0;
        $atPotential->outputGapEma = 0.0;
        $productivity = new MacroState();
        $productivity->outputGap = 0.01;
        $productivity->outputGapEma = 0.01;
        $productivity->productivitySupplyGap = 0.01;
        $demand = new MacroState();
        $demand->outputGap = 0.01;
        $demand->outputGapEma = 0.01;

        $base = $this->subsystem->calculateTargetRate($atPotential, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $this->assertEqualsWithDelta($base, $this->subsystem->calculateTargetRate($productivity, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE), 1e-12);
        $this->assertEqualsWithDelta(
            MonetaryPolicySubsystem::TAYLOR_OUTPUT_GAP_WEIGHT * 0.01,
            $this->subsystem->calculateTargetRate($demand, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE) - $base,
            1e-12
        );
    }

    /**
     * A rate committee's supermajority leans the rule (Bordo & Istrefi 2023, Table 6): a dovish one answers each point of
     * inflation 0.35 less, a hawkish one each point of output gap 0.17 less, each centred on the quarter of the FOMC's
     * meetings it held, so a committee in the FOMC's mix adds nothing on average. With no committee handed over the
     * rule is untouched.
     */
    public function testACommitteeSupermajorityLeansTheRule(): void
    {
        $dove = MonetaryPolicySubsystem::DOVISH_MAJORITY_INFLATION_RESPONSE;
        $hawk = MonetaryPolicySubsystem::HAWKISH_MAJORITY_GAP_RESPONSE;
        $this->assertSame(0.0, MonetaryPolicySubsystem::committeeMajorityTerm(null, 0.03, -0.02));
        $this->assertEqualsWithDelta(-$dove * 0.03 * 0.75 + ($hawk * -0.02 * 0.25), MonetaryPolicySubsystem::committeeMajorityTerm(-1.0, 0.03, -0.02), 1e-15);
        $this->assertEqualsWithDelta($dove * 0.03 * 0.25 - ($hawk * -0.02 * 0.75), MonetaryPolicySubsystem::committeeMajorityTerm(1.0, 0.03, -0.02), 1e-15);
        $this->assertEqualsWithDelta($dove * 0.03 * 0.25 + ($hawk * -0.02 * 0.25), MonetaryPolicySubsystem::committeeMajorityTerm(0.0, 0.03, -0.02), 1e-15);
        $average = (MonetaryPolicySubsystem::DOVISH_MAJORITY_SHARE * MonetaryPolicySubsystem::committeeMajorityTerm(-1.0, 0.03, -0.02))
            + (MonetaryPolicySubsystem::HAWKISH_MAJORITY_SHARE * MonetaryPolicySubsystem::committeeMajorityTerm(1.0, 0.03, -0.02))
            + ((1.0 - MonetaryPolicySubsystem::DOVISH_MAJORITY_SHARE - MonetaryPolicySubsystem::HAWKISH_MAJORITY_SHARE) * MonetaryPolicySubsystem::committeeMajorityTerm(0.0, 0.03, -0.02));
        $this->assertEqualsWithDelta(0.0, $average, 1e-15, 'A committee in the FOMC\'s mix leans the rule nowhere on average.');

        $base = $this->subsystem->calculateTargetRate(new MacroState(), MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $unseated = new MacroState();
        $unseated->authorityMajority = -1.0;
        $this->assertEqualsWithDelta($base, $this->subsystem->calculateTargetRate($unseated, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE), 1e-12, 'A majority the macro was never handed changes nothing.');
        $dovish = new MacroState();
        $dovish->authorityCommitteeSeated = 1.0;
        $dovish->authorityMajority = -1.0;
        $measure = $this->subsystem->calculateExpectedInflation($dovish, MacroEngine::TARGET_INFLATION);
        $this->assertEqualsWithDelta(-$dove * $measure * 0.75, $this->subsystem->calculateTargetRate($dovish, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE) - $base, 1e-12);
    }

    /**
     * While the Authority gives ground to the cabinet it cuts the rule's rate by the concession and tolerates the
     * inflation the public has come to expect of it; holding firm, it leans against that drift as against any other.
     */
    public function testGivingGroundCutsTheRateAndToleratesTheDrift(): void
    {
        $base = $this->subsystem->calculateTargetRate(new MacroState(), MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $conceding = new MacroState();
        $conceding->authorityConcession = 1.0;
        $this->assertEqualsWithDelta(-MonetaryPolicySubsystem::PRESSURE_RATE_CONCESSION, $this->subsystem->calculateTargetRate($conceding, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE) - $base, 1e-12);

        $firm = new MacroState();
        $firm->inflationAnchorDrift = 0.01;
        $tolerant = new MacroState();
        $tolerant->inflationAnchorDrift = 0.01;
        $tolerant->authorityConcession = 1.0;
        $leaning = $this->subsystem->calculateTargetRate($firm, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $tolerating = $this->subsystem->calculateTargetRate($tolerant, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $this->assertEqualsWithDelta(MonetaryPolicySubsystem::TAYLOR_INFLATION_WEIGHT * 0.01, $leaning - $tolerating - MonetaryPolicySubsystem::PRESSURE_RATE_CONCESSION, 1e-12, 'Holding firm, the rule leans against a point of drift by its inflation weight; giving ground, not at all.');
    }

    /**
     * The inflation the public expects the Authority to tolerate climbs while it gives ground, at the fitted rate less
     * what the public relearns, and afterwards decays at Kozicki & Tinsley's speed: exact over any step.
     */
    public function testTheInflationAnchorDriftsUnderConcessionAndRelearnsAfter(): void
    {
        $rate = MonetaryPolicySubsystem::PRESSURE_ANCHOR_DRIFT_RATE;
        $speed = MonetaryPolicySubsystem::KOZICKI_TINSLEY_ADAPTATION_SPEED;
        $conceding = new MacroState();
        $conceding->authorityConcession = 1.0;
        $this->subsystem->updateInflationAnchor($conceding, 0.5);
        $this->assertEqualsWithDelta(($rate / $speed) * (1.0 - exp(-$speed * 0.5)), $conceding->inflationAnchorDrift, 1e-15);
        $this->assertEqualsWithDelta($rate * 0.5, $conceding->inflationAnchorDrift, 0.001, 'Half a year of giving ground moves expectations about half the yearly rate.');

        $stepped = new MacroState();
        $stepped->authorityConcession = 1.0;
        for ($day = 0; $day < 126; ++$day) {
            $this->subsystem->updateInflationAnchor($stepped, 0.5 / 126.0);
        }
        $this->assertEqualsWithDelta($conceding->inflationAnchorDrift, $stepped->inflationAnchorDrift, 1e-15, 'Daily steps land where one half-year step does.');

        $released = clone $conceding;
        $released->authorityConcession = 0.0;
        $this->subsystem->updateInflationAnchor($released, 5.0);
        $this->assertEqualsWithDelta($conceding->inflationAnchorDrift * exp(-$speed * 5.0), $released->inflationAnchorDrift, 1e-15);
        $this->assertEqualsWithDelta(0.5, $released->inflationAnchorDrift / $conceding->inflationAnchorDrift, 0.01, 'Half the drift is relearned in five years.');
    }

    public function testTaylorRuleBlendsCoreInflationWithTIPSBreakeven(): void
    {
        $state = new MacroState();
        $state->inflationEma = 0.020;
        $state->tipsBreakeven = 0.040; // Forward expectations unanchored
        $state->outputGap = 0.0;

        $target = $this->subsystem->calculateTargetRate($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        // pi_blend = 0.70*0.02 + 0.30*0.04 = 0.026; target = r* + pi_blend + w_pi * (pi_blend - pi*)
        $blend = (MonetaryPolicySubsystem::TAYLOR_INFLATION_CORE_WEIGHT * 0.020) + (MonetaryPolicySubsystem::TAYLOR_INFLATION_ANCHOR_WEIGHT * 0.040);
        $this->assertEqualsWithDelta(0.026, $blend, 1e-12);
        $expected = MacroEngine::BASE_NATURAL_RATE + $blend + (MonetaryPolicySubsystem::TAYLOR_INFLATION_WEIGHT * ($blend - MacroEngine::TARGET_INFLATION));
        $this->assertEqualsWithDelta($expected, $target, 0.001);
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

    /** Threshold guidance was a floor policy (FOMC, Dec 2012): with a point of rate room left, the rule's hikes go ahead. */
    public function testEvansRuleDoesNotHoldARateAboveTheFloor(): void
    {
        $state = new MacroState();
        $state->policyRate = 0.010;
        $state->unemploymentRateEma = 0.075;
        $state->nairu = MacroEngine::NATURAL_UNEMPLOYMENT;
        $state->inflationEma = 0.018;
        $state->tipsBreakeven = 0.018;

        $newPolicyRate = $this->subsystem->updatePolicyRate($state, 0.02, 0.25);

        $this->assertGreaterThan(0.010, $newPolicyRate);
        $this->assertSame(0.0, $this->constraintsFor($state, 0.02)['evansHold']);
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
        // Central bank starts with a portfolio from prior QE, in a calm economy that trips neither QT threshold
        // (QT_ACTIVATION_GAP_THRESHOLD 1.0%, QT_ACTIVATION_INFLATION_THRESHOLD 2.2%), so only the clock can end
        // the reinvestment phase.
        $state = new MacroState();
        $state->policyRate = 0.03;
        $state->balanceSheetIntensity = 0.008;
        $state->balanceSheetHoldTimer = 0.0;
        $state->outputGap = 0.005;
        $state->inflation = 0.020;

        // Step 1: dt = 1.0 year -> hold timer becomes 1.0 (< BALANCE_SHEET_REINVESTMENT_HOLD_YEARS)
        $curveYear1 = $this->subsystem->calculateYieldCurveAndQE($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 1.0);
        $this->assertEqualsWithDelta(1.0, $curveYear1['new_hold_timer'], 0.01);
        $this->assertEqualsWithDelta(0.008, $curveYear1['new_balance_sheet_intensity'], 0.001, 'Balance sheet must remain held during reinvestment phase.');

        // Step 2: the clock crosses the hold window and passive runoff starts
        $state->balanceSheetHoldTimer = 1.0;
        $curveYear2 = $this->subsystem->calculateYieldCurveAndQE($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, 1.1);
        $this->assertGreaterThanOrEqual(MonetaryPolicySubsystem::BALANCE_SHEET_REINVESTMENT_HOLD_YEARS, $curveYear2['new_hold_timer']);
        $this->assertLessThan(0.008, $curveYear2['new_balance_sheet_intensity'], 'Balance sheet runoff (QT) must begin once hold timer expires.');
        $this->assertGreaterThanOrEqual(0.0, $curveYear2['new_balance_sheet_intensity'], 'Runoff stops at zero; the bank cannot go net short.');
    }

    public function testOverheatingEndsTheReinvestmentHoldEarly(): void
    {
        // Same portfolio and the same fresh hold clock, but an economy past both QT thresholds. The Fed reinvested
        // for three years after QE3 and for three months after the 2021 round: inflation, not the clock, decided.
        $state = new MacroState();
        $state->policyRate = 0.03;
        $state->balanceSheetIntensity = 0.008;
        $state->balanceSheetHoldTimer = 0.0;
        $state->outputGap = 0.025;
        $state->inflation = 0.035;

        $step = $this->subsystem->calculateBalanceSheetOperations($state, 0.25);

        $this->assertLessThan(0.008, $step['new_balance_sheet_intensity'], 'Overheating must end the reinvestment hold before the clock does.');
        $this->assertGreaterThan(0.0, $step['new_qt_intensity'], 'The portfolio being unwound is what QT intensity reports.');
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
        $expectedCurvature = (MonetaryPolicySubsystem::SVENSSON_CURVATURE1_TARGET_SCALE * 0.06) + (MonetaryPolicySubsystem::SVENSSON_CURVATURE1_GAP_SCALE * 0.02);
        $this->assertEqualsWithDelta($expectedCurvature, $curve['curvature'], 0.0001);

        // 2Y yield should price policy hikes above the 0% policy rate
        $this->assertGreaterThan($state->policyRate, $curve['yield_2y']);
        // 10Y yield anchored by fundamentals, yields realistic spread
        $this->assertGreaterThan(0.0, $curve['yield_10y']);
    }

    /**
     * The Fed's 2025 framework is symmetric flexible inflation targeting: an overshoot and a shortfall of the same
     * size move the target by the same amount in opposite directions, with no make-up for the past.
     */
    public function testTheRuleRespondsSymmetricallyToInflationEitherSideOfTarget(): void
    {
        $targetAt = function (float $inflation): float {
            $state = new MacroState();
            $state->inflationEma = $inflation;
            $state->supercoreInflationEma = $inflation;
            $state->coreGoodsInflationEma = $inflation;
            $state->tipsBreakeven = MacroEngine::TARGET_INFLATION;
            $state->outputGap = 0.0;
            $state->outputGapEma = 0.0;

            return $this->subsystem->calculateTargetRate($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        };

        $neutral = $targetAt(MacroEngine::TARGET_INFLATION);
        $over = $targetAt(MacroEngine::TARGET_INFLATION + 0.01) - $neutral;
        $under = $neutral - $targetAt(MacroEngine::TARGET_INFLATION - 0.01);

        $this->assertEqualsWithDelta($over, $under, 1e-12);
        $this->assertGreaterThan(0.007, $over, 'The Taylor principle: the nominal target moves by more than the core-weighted share of inflation.');
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

        // The shift follows the ACM duration scale the premium itself uses: the 30Y carries half again the
        // 10Y's extraction (Gagnon et al. 2011), the 2Y under a third of it.
        $this->assertEqualsWithDelta(MathUtility::calculateTermPremiumDurationScale(30.0) * $shift10y, $shift30y, 0.0001);
        $this->assertEqualsWithDelta(MathUtility::calculateTermPremiumDurationScale(2.0) * $shift10y, $shift2y, 0.0001);
        $this->assertGreaterThan(0.35 * $shift10y, $shift2y, 'Shifts are negative: the two-year suppression is the smaller magnitude.');
        $this->assertEqualsWithDelta(-0.020, $shift10y, 0.0001, '10Y yield suppression under 200bps QE intensity must be exactly -200bps');
    }

    public function testQeActivationWaitsForTheFloor(): void
    {
        $dt = 0.25;

        // A point and a half of rate room in a recession: the bank cuts, it does not buy (the old 2.5% trigger did).
        $stateWithRoom = new MacroState();
        $stateWithRoom->policyRate = 0.015;
        $stateWithRoom->targetRate = 0.010;
        $stateWithRoom->outputGap = -0.020;
        $roomCurve = $this->subsystem->calculateYieldCurveAndQE($stateWithRoom, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, $dt);
        $this->assertEquals(0.0, $roomCurve['new_qe_intensity'], 'QE must not activate while the rule\'s rate is reachable.');

        // At the floor with the rule asking for less: purchases begin.
        $stateAtFloor = new MacroState();
        $stateAtFloor->policyRate = MacroEngine::EFFECTIVE_LOWER_BOUND;
        $stateAtFloor->targetRate = -0.015;
        $stateAtFloor->outputGap = -0.020;
        $floorCurve = $this->subsystem->calculateYieldCurveAndQE($stateAtFloor, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE, $dt);
        $this->assertGreaterThan(0.0, $floorCurve['new_qe_intensity'], 'QE must activate once the rule asks for a rate below the floor.');
    }

    /**
     * Tightening raises term premia, it does not squeeze them (Hanson & Stein 2015; Gertler & Karadi 2015), and
     * ACM's premium rose through the 1994 and 2022-23 hikes. A compression keyed to the stance ran the other way:
     * past neutral each hike LOWERED the ten-year (slope loading 0.317 against a 0.45 squeeze), which pinned the
     * premium to its floor through a live boom and put the no-fund curve inverted 24% of the time against the US 15%.
     */
    public function testARestrictiveStanceLiftsTheTenYearAndLeavesItsPremium(): void
    {
        $neutral = new MacroState();
        $neutral->policyRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;
        $neutral->targetRate = $neutral->policyRate;
        $neutral->tipsBreakeven = MacroEngine::TARGET_INFLATION;

        $restrictive = clone $neutral;
        $restrictive->policyRate = $neutral->policyRate + 0.02;
        $restrictive->targetRate = $restrictive->policyRate;

        $curveNeutral = $this->subsystem->calculateYieldCurve($neutral, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $curveRestrictive = $this->subsystem->calculateYieldCurve($restrictive, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $slopeLoad10y = (1.0 - exp(-10.0 * MacroEngine::SVENSSON_SLOPE_LAMBDA)) / (10.0 * MacroEngine::SVENSSON_SLOPE_LAMBDA);
        $this->assertEqualsWithDelta($curveNeutral['term_premium_10y'], $curveRestrictive['term_premium_10y'], 1e-12, 'The stance moves expectations, not the premium.');
        $this->assertEqualsWithDelta($slopeLoad10y * 0.02, $curveRestrictive['yield_10y'] - $curveNeutral['yield_10y'], 1e-12, 'Two points of restriction lift the ten-year by its slope loading.');
    }

    public function testCalculateBalanceSheetOperationsDirectly(): void
    {
        $state = new MacroState();
        $state->policyRate = MacroEngine::EFFECTIVE_LOWER_BOUND;
        $state->targetRate = -0.02; // the rule wants two points the floor forbids
        $state->outputGap = -0.025;
        $state->balanceSheetIntensity = 0.0;

        $bs = $this->subsystem->calculateBalanceSheetOperations($state, 0.25);

        $this->assertGreaterThan(0.0, $bs['new_balance_sheet_intensity']);
        $this->assertGreaterThan(0.0, $bs['new_qe_intensity']);
        $this->assertEquals(0.0, $bs['new_qt_intensity']);
        $this->assertEquals(0.0, $bs['new_hold_timer']);
    }

    /** The programme is sized by the cut the floor forbids: a point short buys a third of the full stock, three points buy all of it. */
    public function testPurchasesScaleWithTheShortfallBelowTheFloor(): void
    {
        $dt = 0.25;
        $ramp = 1.0 - exp(-MonetaryPolicySubsystem::BALANCE_SHEET_RAMP_SPEED * $dt);
        $doseFor = function (float $shortfall) use ($dt): float {
            $state = new MacroState();
            $state->policyRate = MacroEngine::EFFECTIVE_LOWER_BOUND;
            $state->targetRate = MacroEngine::EFFECTIVE_LOWER_BOUND - $shortfall;
            $state->outputGap = -0.03;
            $state->balanceSheetIntensity = 0.0;

            return $this->subsystem->calculateBalanceSheetOperations($state, $dt)['new_balance_sheet_intensity'];
        };

        $this->assertEqualsWithDelta(MonetaryPolicySubsystem::QE_MAX_SUPPRESSION / 3.0 * $ramp, $doseFor(0.01), 1e-12);
        $this->assertEqualsWithDelta(MonetaryPolicySubsystem::QE_MAX_SUPPRESSION * $ramp, $doseFor(MonetaryPolicySubsystem::QE_FULL_PROGRAM_SHORTFALL), 1e-12);
        $this->assertEqualsWithDelta(MonetaryPolicySubsystem::QE_MAX_SUPPRESSION * $ramp, $doseFor(0.05), 1e-12, 'Past the full programme the dose is capped.');
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
        $stateNormal->financialConditionsIndex = 0.0;

        $this->subsystem->calculateRecessionProbability($stateNormal);
        $this->assertLessThan(0.20, $stateNormal->recessionProbability, 'Steep curve and neutral financial conditions must yield low recession probability.');

        $stateInverted = new MacroState();
        $stateInverted->yield10y = 0.035;
        $stateInverted->policyRate = 0.055; // -200bps inverted curve
        $stateInverted->termPremium10y = -0.005;
        $stateInverted->financialConditionsIndex = 1.80;

        $this->subsystem->calculateRecessionProbability($stateInverted);
        $this->assertGreaterThan(0.70, $stateInverted->recessionProbability, 'Inverted yield curve and tight FCI must yield high recession probability.');
    }

    public function testBroadMoneyGrowsWithTheLabourForce(): void
    {
        $quiet = new MonetaryPolicySubsystem(new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
        });
        $neutralGrowth = MacroEngine::TARGET_INFLATION + MacroEngine::TFP_DRIFT + MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE;
        $structural = new MacroState();
        $structural->moneySupplyGrowth = $neutralGrowth;
        $faster = clone $structural;
        $faster->laborForceGrowthRate = MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + 0.005;

        $quiet->calculateMoneySupplyGrowth($structural, 0.25, MacroEngine::TFP_DRIFT);
        $quiet->calculateMoneySupplyGrowth($faster, 0.25, MacroEngine::TFP_DRIFT);

        $this->assertEqualsWithDelta($neutralGrowth, $structural->moneySupplyGrowth, 1e-12, 'At the structural rate money grows with neutral nominal GDP.');
        $this->assertGreaterThan($structural->moneySupplyGrowth, $faster->moneySupplyGrowth, 'A faster-growing labour force needs faster money growth.');
    }

    /** M2 growth's trend is measured, a decade's average of the growth itself, not the fixed base its readers once used. */
    public function testMoneyGrowthTrendIsADecadesAverageOfTheGrowth(): void
    {
        $quiet = new MonetaryPolicySubsystem(new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
        });
        $neutralGrowth = MacroEngine::TARGET_INFLATION + MacroEngine::TFP_DRIFT + MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE;
        $state = new MacroState();
        $state->moneySupplyGrowth = $neutralGrowth;
        $state->moneySupplyGrowthTrend = MacroEngine::M2_BASE_GROWTH;

        for ($tick = 0; $tick < 40; ++$tick) {
            $quiet->calculateMoneySupplyGrowth($state, 0.25, MacroEngine::TFP_DRIFT);
        }

        $this->assertEqualsWithDelta($neutralGrowth, $state->moneySupplyGrowth, 1e-12);
        $this->assertEqualsWithDelta(
            $neutralGrowth + ((MacroEngine::M2_BASE_GROWTH - $neutralGrowth) * exp(-10.0 / MonetaryPolicySubsystem::M2_TREND_YEARS)),
            $state->moneySupplyGrowthTrend,
            1e-12,
            'Ten years at neutral growth close the trend on it at its decade time constant.'
        );
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
        $this->holdSoundFiscalPosition($state);

        $curve = $this->subsystem->calculateYieldCurve($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $scale2y = MathUtility::calculateTermPremiumDurationScale(2.0, MacroEngine::TERM_PREMIUM_DURATION_HORIZON_YEARS);
        $scale30y = MathUtility::calculateTermPremiumDurationScale(30.0, MacroEngine::TERM_PREMIUM_DURATION_HORIZON_YEARS);
        $this->assertLessThan(0.35, $scale2y, 'the two-year note carries under a third of the ten-year premium');
        $this->assertGreaterThan(1.0, $scale30y, 'the thirty-year bond carries more than the ten-year');

        $expectedSpread = MacroEngine::NS_BASE_TERM_PREMIUM * (1.0 - $scale2y);
        $this->assertEqualsWithDelta($expectedSpread, $curve['yield_10y'] - $curve['yield_2y'], 0.0010, 'the neutral 2s10s slope is the premium the ten-year earns over the two-year');
        $this->assertEqualsWithDelta(MacroEngine::NS_BASE_TERM_PREMIUM, $curve['yield_10y'] - $state->policyRate, 0.0010, 'at neutral the ten-year sits one term premium over the policy rate');
        $this->assertGreaterThan($curve['yield_10y'], $curve['yield_30y'], 'the long end keeps rising');
        $expectedLongEnd = MacroEngine::NS_BASE_TERM_PREMIUM * ($scale30y - 1.0);
        $this->assertEqualsWithDelta($expectedLongEnd, $curve['yield_30y'] - $curve['yield_10y'], 0.0010, 'the 10s30s slope is the extra duration compensation the structural regime earns past ten years');
    }

    /**
     * A restrictive stance inverts the curve through expectations: the two-year prices the cuts that follow,
     * and the ten-year carries only its slope loading of the stance. Whether that inverts the 2s10s depends on
     * the premium the ten-year carries: the 2000, 2006 and 2023 peaks inverted, the 1995 peak at a ~2% premium
     * did not. A low-premium era inverts; the baseline premium only flattens.
     */
    public function testRestrictiveStanceInvertsTheCurve(): void
    {
        $state = new MacroState();
        $state->policyRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION + 0.020; // 200bps above neutral
        $state->targetRate = $state->policyRate - 0.010; // cuts ahead
        $state->tipsBreakeven = 0.025;
        $state->outputGap = 0.005;
        $state->marketVolatilityEma = 0.15;

        $neutral = clone $state;
        $neutral->policyRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;
        $neutral->targetRate = $neutral->policyRate;
        $curveNeutral = $this->subsystem->calculateYieldCurve($neutral, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $curve = $this->subsystem->calculateYieldCurve($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $this->assertLessThan($state->policyRate, $curve['yield_2y'], 'the two-year prices the cuts ahead');
        $this->assertLessThan(
            $curveNeutral['yield_10y'] - $curveNeutral['yield_2y'],
            $curve['yield_10y'] - $curve['yield_2y'],
            'at the baseline premium the stance flattens the curve'
        );

        $lowPremium = clone $state;
        // A low-premium era is global, as the 2010s were: the District's own factor and the mainland's both at the floor.
        $lowPremium->termPremiumRegime = 0.0;
        $lowPremium->foreignTermPremiumRegime = 0.0;
        $curveLowPremium = $this->subsystem->calculateYieldCurve($lowPremium, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $this->assertLessThan(-0.0025, $curveLowPremium['yield_10y'] - $curveLowPremium['yield_2y'], 'in a low-premium era the curve inverts by a meaningful margin');
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
        $expectedPremiumChange = MonetaryPolicySubsystem::TERM_PREMIUM_IRP_EXPECTATION_SCALE * 0.02;
        $this->assertEqualsWithDelta(
            $expectedPremiumChange,
            $curveUnanchored['term_premium_10y'] - $curveAnchored['term_premium_10y'],
            0.00001,
            'Higher breakevens must move the term premium by the inflation risk premium only; the level shift belongs to the risk-neutral rate.'
        );

        // The model-consistent endpoint carries the breakeven in full; the Kozicki-Tinsley endpoint does not.
        $levelShift = (1.0 - MonetaryPolicySubsystem::KOZICKI_TINSLEY_ENDPOINT_WEIGHT) * 0.02;
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
        $state->termPremiumRegime = 0.0; // the 2010s era
        $state->inversionDuration = 0.0;
        $this->holdSoundFiscalPosition($state); // the fiscal supply premium is not a compression channel

        $curve = $this->subsystem->calculateYieldCurve($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $this->assertLessThan(0.0, $curve['term_premium_10y'], 'A low-premium era plus flight to safety must push the ten-year term premium negative, as ACM shows for 2016-2021.');
        $this->assertGreaterThanOrEqual(
            MonetaryPolicySubsystem::MIN_TERM_PREMIUM_10Y - 0.00001,
            $curve['term_premium_10y'],
            'The term premium must respect the structural floor.'
        );

        $extreme = clone $state;
        $extreme->policyRate = 0.12;
        $extreme->targetRate = 0.12;
        $extreme->marketVolatilityEma = 1.50;
        $curveExtreme = $this->subsystem->calculateYieldCurve($extreme, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $this->assertEqualsWithDelta(MonetaryPolicySubsystem::MIN_TERM_PREMIUM_10Y, $curveExtreme['term_premium_10y'], 0.00001, 'Stacked compression channels bottom out at the floor.');
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

        $this->assertEqualsWithDelta(0.015 * exp(-MonetaryPolicySubsystem::TERM_PREMIUM_SHOCK_KAPPA), $state->termPremiumShock, 0.00001, 'The transitory shock decays at its OU rate toward zero.');
        $expectedRegime = MacroEngine::NS_BASE_TERM_PREMIUM + (0.020 - MacroEngine::NS_BASE_TERM_PREMIUM) * exp(-MonetaryPolicySubsystem::TERM_PREMIUM_REGIME_KAPPA);
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

        $this->assertEqualsWithDelta(MonetaryPolicySubsystem::TERM_PREMIUM_SHOCK_CAP, $state->termPremiumShock, 0.00001);
        $this->assertEqualsWithDelta(MonetaryPolicySubsystem::MAX_TERM_PREMIUM_REGIME, $state->termPremiumRegime, 0.00001);
    }

    public function testTransitoryTermPremiumShockMovesTheLongEndMoreThanTheTwoYear(): void
    {
        $calm = new MacroState();
        $calm->policyRate = 0.03;
        $calm->targetRate = 0.03;
        $calm->tipsBreakeven = MacroEngine::TARGET_INFLATION;
        $calm->outputGap = 0.0;

        // A 100bps move in the District premium's transitory part, all of it from the District's own factor.
        $tantrum = clone $calm;
        $tantrum->termPremiumShock = 0.01 / sqrt(1.0 - (MonetaryPolicySubsystem::GLOBAL_TERM_PREMIUM_LOADING ** 2));

        $curveCalm = $this->subsystem->calculateYieldCurve($calm, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $curveTantrum = $this->subsystem->calculateYieldCurve($tantrum, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $move10y = $curveTantrum['yield_10y'] - $curveCalm['yield_10y'];
        $move2y = $curveTantrum['yield_2y'] - $curveCalm['yield_2y'];
        $this->assertEqualsWithDelta(0.01, $move10y, 0.00001, 'A 100bps premium shock lands in full on the ten-year.');
        $this->assertLessThan(0.35 * $move10y, $move2y, 'The two-year carries under a third of it, so the shock bear-steepens the curve.');
        $move30y = $curveTantrum['yield_30y'] - $curveCalm['yield_30y'];
        $this->assertEqualsWithDelta($move10y, $move30y, 0.00001, 'The thirty-year moves with the ten-year: the 10s30s spread is stable through a tantrum, not amplified half again.');
        $this->assertEqualsWithDelta($curveCalm['risk_neutral_10y'], $curveTantrum['risk_neutral_10y'], 0.00001, 'The expected policy path is untouched; the shock is all premium.');
    }

    /** A mainland premium shock is the global factor: it reaches the District ten-year at the loading, as premium alone. */
    public function testAMainlandTermPremiumShockReachesTheDistrictTenYearAtTheGlobalLoading(): void
    {
        $calm = new MacroState();
        $calm->policyRate = 0.03;
        $calm->targetRate = 0.03;
        $calm->tipsBreakeven = MacroEngine::TARGET_INFLATION;
        $calm->outputGap = 0.0;
        $mainlandTantrum = clone $calm;
        $mainlandTantrum->foreignTermPremiumShock = 0.01;

        $curveCalm = $this->subsystem->calculateYieldCurve($calm, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $curveTantrum = $this->subsystem->calculateYieldCurve($mainlandTantrum, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $this->assertEqualsWithDelta(0.01 * MonetaryPolicySubsystem::GLOBAL_TERM_PREMIUM_LOADING, $curveTantrum['yield_10y'] - $curveCalm['yield_10y'], 1e-9);
        $this->assertEqualsWithDelta($curveCalm['risk_neutral_10y'], $curveTantrum['risk_neutral_10y'], 1e-12, 'The District path is untouched.');
    }

    /** The one-factor split keeps each premium part's variance: the own and global weights' squares sum to one. */
    public function testTheGlobalSplitKeepsTheDistrictPremiumVariance(): void
    {
        $own = new MacroState();
        $own->termPremiumShock = 1.0;
        $own->foreignTermPremiumShock = 0.0;
        $global = new MacroState();
        $global->termPremiumShock = 0.0;
        $global->foreignTermPremiumShock = 1.0;

        $ownWeight = MonetaryPolicySubsystem::districtTermPremiumFactors($own)['shock'];
        $globalWeight = MonetaryPolicySubsystem::districtTermPremiumFactors($global)['shock'];
        $this->assertEqualsWithDelta(1.0, ($ownWeight ** 2) + ($globalWeight ** 2), 1e-12);
        $this->assertEqualsWithDelta(MonetaryPolicySubsystem::GLOBAL_TERM_PREMIUM_LOADING, $globalWeight, 1e-12);
    }

    /** The mainland's bonds now carry its premium: a mainland tantrum lifts the yield the fund's foreign paper is priced on. */
    public function testTheMainlandBondYieldCarriesItsOwnTermPremium(): void
    {
        $fund = new SovereignFundSubsystem(new MathUtility());
        $calm = new MacroState();
        $calm->foreignPolicyRate = MacroEngine::MAINLAND_NEUTRAL_RATE;
        $calm->foreignTermPremiumShock = 0.0;
        $calm->foreignTermPremiumRegime = MacroEngine::NS_BASE_TERM_PREMIUM;
        $tantrum = clone $calm;
        $tantrum->foreignTermPremiumShock = 0.01;

        $this->assertEqualsWithDelta(0.01, $fund->foreignZeroYield($tantrum, 10.0) - $fund->foreignZeroYield($calm, 10.0), 1e-9);
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
        // single-decay fit, measured on the risk-neutral rate.
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
        $this->assertEqualsWithDelta(1.0 - exp(-3.0 * MonetaryPolicySubsystem::KOZICKI_TINSLEY_ADAPTATION_SPEED), $closed, 0.01, 'Kozicki-Tinsley endpoint adapts at its slow learning speed.');
        $this->assertLessThan(0.5, $closed, 'Three years is not enough to convince the market that neutral has moved.');
    }

    public function testADecadeOfHighPolicyRepricesTheLongEndAndUninvertsTheCurve(): void
    {
        $fresh = new MacroState();
        $fresh->policyRate = 0.050;
        $fresh->targetRate = 0.050;
        $fresh->tipsBreakeven = 0.027;
        $fresh->outputGap = 0.015;
        $fresh->termPremiumRegime = 0.0; // a low-premium era, where the static anchor inverted for years
        $fresh->perceivedNeutralRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;

        $decade = clone $fresh;
        $decade->perceivedNeutralRate = 0.048; // the market has learned that 5% is where policy lives

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
            -MonetaryPolicySubsystem::TAYLOR_LONG_RATE_OFFSET * 0.010,
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
        $state->sovereignRiskSpreadEma = 0.0;

        // QE compresses the ten-year premium by the habitat shift; the rule must read that as its own doing.
        $qe = clone $state;
        $qe->balanceSheetIntensity = 0.008;
        $qe->qeIntensity = 0.0; // isolate the long-rate channel from the Wu-Xia shadow term
        $qe->termPremium10yEma = MacroEngine::NS_BASE_TERM_PREMIUM - 0.008;

        $this->assertEqualsWithDelta(0.0, $this->subsystem->calculateLongRateGap($qe, MacroEngine::BASE_NATURAL_RATE), 0.00001, 'Balance-sheet compression is excluded from the long-rate gap.');
    }

    /**
     * Debt is priced once, through the Laubach (2009) sovereign spread, and the rule does not ease against it: a central
     * bank cutting as the debt premium rises is fiscal dominance, not the Bernanke (2006) term-premium offset.
     */
    public function testSovereignCreditRiskIsPricedOnceAndNotEasedAgainst(): void
    {
        $sound = new MacroState();
        $sound->sovereignRiskSpreadEma = 0.0;
        $sound->termPremium10yEma = MacroEngine::NS_BASE_TERM_PREMIUM;

        $indebted = clone $sound;
        $indebted->sovereignRiskSpreadEma = 0.005;
        $indebted->termPremium10yEma = MacroEngine::NS_BASE_TERM_PREMIUM + 0.005;
        $indebted->sovereignDebtToGdpEma = 1.30;

        $this->assertEqualsWithDelta(
            $this->subsystem->calculateLongRateGap($sound, MacroEngine::BASE_NATURAL_RATE),
            $this->subsystem->calculateLongRateGap($indebted, MacroEngine::BASE_NATURAL_RATE),
            1e-12
        );

        $debtOnly = clone $sound;
        $debtOnly->sovereignDebtToGdpEma = 1.30;
        $this->assertSame(
            $this->subsystem->calculateYieldCurve($sound, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE)['curvature2'],
            $this->subsystem->calculateYieldCurve($debtOnly, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE)['curvature2'],
            'Debt reaches the curve through the sovereign spread alone.'
        );
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
        $this->assertLessThan(0.015 * MonetaryPolicySubsystem::KOZICKI_TINSLEY_ENDPOINT_WEIGHT, $gap, 'Only the share of the endpoint drift that reaches the ten-year counts.');
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
        $expectationsDriven->financialConditionsIndex = 0.0;

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

    /**
     * The inversion clock is armed off the STRUCTURAL slope, which does not exist until the curve has been
     * fitted. It therefore belongs with the fitted curve and not in updateMarketExpectations, which runs a
     * step earlier and would hand it the previous tick's curve.
     */
    public function testTheInversionClockRunsOffTheCurveItWasJustHanded(): void
    {
        $state = new MacroState();
        $state->policyRate = 0.05;
        $state->inversionDuration = 0.0;

        $inverted = $this->curveWith($state, 0.03);   // structural 10y under a 5% policy rate
        $this->subsystem->applyYieldCurve($state, $inverted, 0.25);

        $this->assertLessThan(0.0, $state->structuralSlope, 'A 3% structural long end under a 5% policy rate is inverted.');
        $this->assertEqualsWithDelta(0.25, $state->inversionDuration, 1e-12, 'The clock accumulates while inverted.');

        $this->subsystem->applyYieldCurve($state, $inverted, 0.25);
        $this->assertEqualsWithDelta(0.50, $state->inversionDuration, 1e-12, 'And keeps accumulating.');

        // One upward-sloping curve resets it outright: the alarm counts a CONTINUOUS inversion.
        $this->subsystem->applyYieldCurve($state, $this->curveWith($state, 0.07), 0.25);
        $this->assertGreaterThan(0.0, $state->structuralSlope);
        $this->assertSame(0.0, $state->inversionDuration, 'Un-inverting resets the clock rather than pausing it.');
    }

    /**
     * A fitted curve whose structural 10y is the given rate, with every other factor left flat.
     *
     * @return array<string, float>
     */
    private function curveWith(MacroState $state, float $structural10y): array
    {
        return [
            'level' => $structural10y, 'curvature' => 0.0, 'curvature2' => 0.0,
            'beta1' => $state->policyRate - $structural10y,
            'base_term_premium' => 0.0, 'long_end_premium' => 0.0,
            'structural_10y' => $structural10y,
            'yield_2y' => $state->policyRate, 'yield_5y' => $structural10y,
            'yield_10y' => $structural10y, 'yield_30y' => $structural10y,
            'risk_neutral_10y' => $structural10y, 'term_premium_10y' => 0.0,
        ];
    }

    /** The gap weight is the same in slack as in a boom (1987-2008: impact 0.29 in slack vs 0.24 in booms, not distinguishable). */
    public function testTheGapWeightIsTheSameInSlackAsInABoom(): void
    {
        $targetFor = function (float $gap): float {
            $state = new MacroState();
            $state->inflation = MacroEngine::TARGET_INFLATION;
            $state->inflationEma = MacroEngine::TARGET_INFLATION;
            $state->tipsBreakeven = MacroEngine::TARGET_INFLATION;
            $state->supercoreInflationEma = MacroEngine::TARGET_INFLATION;
            $state->coreGoodsInflationEma = MacroEngine::TARGET_INFLATION;
            $state->outputGap = $gap;
            $state->outputGapEma = $gap;

            return $this->subsystem->calculateTargetRate($state, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        };

        $neutral = $targetFor(0.0);
        $this->assertEqualsWithDelta(MonetaryPolicySubsystem::TAYLOR_OUTPUT_GAP_WEIGHT, ($targetFor(0.02) - $neutral) / 0.02, 1e-9);
        $this->assertEqualsWithDelta(MonetaryPolicySubsystem::TAYLOR_OUTPUT_GAP_WEIGHT, ($neutral - $targetFor(-0.03)) / 0.03, 1e-9);
    }

    /** The Bernanke (2015) blend: 70% sectoral core, 30% breakeven -- the one expected-inflation measure both the Taylor rule and the IS curve use. */
    public function testExpectedInflationBlendsSectoralCoreWithTheBreakeven(): void
    {
        $state = new MacroState();
        $state->supercoreInflationEma = 0.04;
        $state->coreGoodsInflationEma = 0.01;
        $state->tipsBreakeven = 0.025;
        $state->inflation = 0.09; // Headline must not enter.
        $state->inflationEma = 0.09;

        $coreWeight = MacroAggregateSubsystem::INFLATION_WEIGHT_SUPERCORE + MacroAggregateSubsystem::INFLATION_WEIGHT_GOODS;
        $core = ((MacroAggregateSubsystem::INFLATION_WEIGHT_SUPERCORE * 0.04) + (MacroAggregateSubsystem::INFLATION_WEIGHT_GOODS * 0.01)) / $coreWeight;
        $expected = (MonetaryPolicySubsystem::TAYLOR_INFLATION_CORE_WEIGHT * $core)
            + (MonetaryPolicySubsystem::TAYLOR_INFLATION_ANCHOR_WEIGHT * 0.025);

        $this->assertEqualsWithDelta($expected, $this->subsystem->calculateExpectedInflation($state, MacroEngine::TARGET_INFLATION), 1e-12);
    }

    /** Before the sectoral baskets have moved off the target the blend falls back to the headline EMA, as the Taylor rule always has. */
    public function testExpectedInflationFallsBackToTheHeadlineEmaOnAColdStart(): void
    {
        $state = new MacroState();
        $state->supercoreInflationEma = MacroEngine::TARGET_INFLATION;
        $state->coreGoodsInflationEma = MacroEngine::TARGET_INFLATION;
        $state->inflationEma = 0.03;
        $state->tipsBreakeven = 0.02;

        $expected = (MonetaryPolicySubsystem::TAYLOR_INFLATION_CORE_WEIGHT * 0.03)
            + (MonetaryPolicySubsystem::TAYLOR_INFLATION_ANCHOR_WEIGHT * 0.02);

        $this->assertEqualsWithDelta($expected, $this->subsystem->calculateExpectedInflation($state, MacroEngine::TARGET_INFLATION), 1e-12);
    }

    /** Diebold-Li (2006): a repricing of the expected path is expectations, not premium, and cannot move today's rate. */
    public function testExpectedPathShockRepricesTheBellyAndLeavesThePremiumAlone(): void
    {
        $calm = new MacroState();
        $calm->policyRate = 0.03;
        $calm->targetRate = 0.03;
        $calm->tipsBreakeven = MacroEngine::TARGET_INFLATION;
        $calm->outputGap = 0.0;

        $hawkish = clone $calm;
        $hawkish->expectedPathShock = 0.02;

        $curveCalm = $this->subsystem->calculateYieldCurve($calm, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $curveHawkish = $this->subsystem->calculateYieldCurve($hawkish, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $lambda = MacroEngine::SVENSSON_LAMBDA_1;
        $loading = static fn (float $tau): float => ((1.0 - exp(-$lambda * $tau)) / ($lambda * $tau)) - exp(-$lambda * $tau);

        $move2y = $curveHawkish['yield_2y'] - $curveCalm['yield_2y'];
        $move10y = $curveHawkish['yield_10y'] - $curveCalm['yield_10y'];
        $this->assertEqualsWithDelta(0.02 * $loading(2.0), $move2y, 0.00001, 'The two-year carries the curvature loading of the shock (~0.29).');
        $this->assertEqualsWithDelta(0.02 * $loading(10.0), $move10y, 0.00001, 'The ten-year carries under half as much (~0.14).');

        $this->assertEqualsWithDelta($curveCalm['term_premium_10y'], $curveHawkish['term_premium_10y'], 1e-9, 'The premium is untouched: the shock is all expectations.');
        $this->assertEqualsWithDelta($move10y, $curveHawkish['risk_neutral_10y'] - $curveCalm['risk_neutral_10y'], 1e-9, 'The whole ten-year move lands in the expected policy path.');

        $overnightCalm = $this->subsystem->calculateSvenssonTenor(0.001, $curveCalm['level'], $curveCalm['beta1'], $curveCalm['curvature'], $curveCalm['curvature2'], $calm);
        $overnightHawkish = $this->subsystem->calculateSvenssonTenor(0.001, $curveHawkish['level'], $curveHawkish['beta1'], $curveHawkish['curvature'], $curveHawkish['curvature2'], $hawkish);
        $this->assertEqualsWithDelta($overnightCalm, $overnightHawkish, 0.00001, 'Today\'s rate is known; the repricing cannot move the front of the curve.');
    }

    public function testExpectedPathShockLoadsTheShortEndHarderThanTheLongEnd(): void
    {
        $calm = new MacroState();
        $calm->policyRate = 0.03;
        $calm->targetRate = 0.03;
        $calm->tipsBreakeven = MacroEngine::TARGET_INFLATION;

        $dovish = clone $calm;
        $dovish->expectedPathShock = -0.02;

        $curveCalm = $this->subsystem->calculateYieldCurve($calm, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $curveDovish = $this->subsystem->calculateYieldCurve($dovish, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $moves = [];
        foreach (['yield_2y', 'yield_5y', 'yield_10y', 'yield_30y'] as $tenor) {
            $moves[$tenor] = abs($curveDovish[$tenor] - $curveCalm[$tenor]);
        }

        $this->assertGreaterThan($moves['yield_5y'], $moves['yield_2y'], 'The belly repricing bites hardest at the two-year.');
        $this->assertGreaterThan($moves['yield_10y'], $moves['yield_5y']);
        $this->assertGreaterThan($moves['yield_30y'], $moves['yield_10y']);
        $this->assertLessThan(0.2 * $moves['yield_2y'], $moves['yield_30y'], 'The thirty-year carries about a sixth of the two-year move: the long-run anchor is not in question.');
    }

    public function testExpectedPathShockDecaysAtItsOwnRateAndIsCapped(): void
    {
        $quiet = new class extends MathUtility {
            public function generateStandardNormal(): float { return 0.0; }
        };
        $state = new MacroState();
        $state->expectedPathShock = 0.03;
        (new MonetaryPolicySubsystem($quiet))->updateTermPremiumDynamics($state, 1.0);
        $this->assertEqualsWithDelta(0.03 * exp(-MonetaryPolicySubsystem::EXPECTED_PATH_SHOCK_KAPPA), $state->expectedPathShock, 0.00001, 'Without news the repricing fades at its OU rate, a half-life of months rather than years.');

        $extreme = new class extends MathUtility {
            public function generateStandardNormal(): float { return 40.0; }
        };
        $state = new MacroState();
        (new MonetaryPolicySubsystem($extreme))->updateTermPremiumDynamics($state, 1.0);
        $this->assertEqualsWithDelta(MonetaryPolicySubsystem::EXPECTED_PATH_SHOCK_CAP, $state->expectedPathShock, 0.00001, 'A repricing is capped: it is never a regime change.');
    }


    /**
     * Pensions and insurers are the marginal buyer past ten years. Below the neutral long-end level they
     * add nothing; above it their duration demand takes a quarter of the excess back out of the thirty-year.
     */
    public function testLiabilityDrivenDemandCompressesTheLongEndOnlyAboveItsHurdle(): void
    {
        $scale30y = MathUtility::calculateTermPremiumDurationScale(30.0, MacroEngine::TERM_PREMIUM_DURATION_HORIZON_YEARS);
        $hurdle = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION + (MacroEngine::NS_BASE_TERM_PREMIUM * $scale30y);

        $build = static function (float $yield30yEma): MacroState {
            $state = new MacroState();
            $state->policyRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;
            $state->targetRate = $state->policyRate;
            $state->tipsBreakeven = MacroEngine::TARGET_INFLATION;
            $state->outputGap = 0.0;
            $state->marketVolatilityEma = 0.15;
            $state->yield30yEma = $yield30yEma;

            return $state;
        };

        $atHurdle = $this->subsystem->calculateYieldCurve($build($hurdle), MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $belowHurdle = $this->subsystem->calculateYieldCurve($build($hurdle - 0.02), MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $aboveHurdle = $this->subsystem->calculateYieldCurve($build($hurdle + 0.02), MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $tensThirties = static fn (array $curve): float => $curve['yield_30y'] - $curve['yield_10y'];

        $this->assertEqualsWithDelta($tensThirties($atHurdle), $tensThirties($belowHurdle), 1e-9, 'Below the hurdle there is no habitat bid to price.');
        $expectedCompression = 0.02 * MonetaryPolicySubsystem::HABITAT_LONG_END_DEMAND_SENSITIVITY * ($scale30y - 1.0);
        $this->assertEqualsWithDelta($expectedCompression, $tensThirties($atHurdle) - $tensThirties($aboveHurdle), 0.0002, 'Two hundred basis points of excess long yield draws in enough duration demand to flatten 10s30s by a quarter of it.');
        $this->assertEqualsWithDelta($atHurdle['yield_10y'], $aboveHurdle['yield_10y'], 1e-9, 'The bid is for the long end only; the ten-year is untouched.');
    }


    /** Laubach (2009): the fiscal premium is a level the ten-year carries in full and the two-year by its duration share, and it is premium, not expectations. */
    public function testTheSovereignRiskPremiumLiftsTheLongEndAsTermPremiumNotExpectations(): void
    {
        $sound = new MacroState();
        $sound->policyRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;
        $sound->targetRate = $sound->policyRate;
        $sound->tipsBreakeven = MacroEngine::TARGET_INFLATION;
        $sound->outputGap = 0.0;
        $sound->marketVolatilityEma = 0.15;
        $this->holdSoundFiscalPosition($sound);

        $stressed = clone $sound;
        $stressed->sovereignRiskSpreadEma = 0.01;

        $curveSound = $this->subsystem->calculateYieldCurve($sound, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);
        $curveStressed = $this->subsystem->calculateYieldCurve($stressed, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE);

        $scale2y = MathUtility::calculateTermPremiumDurationScale(2.0, MacroEngine::TERM_PREMIUM_DURATION_HORIZON_YEARS);
        $this->assertEqualsWithDelta(0.01, $curveStressed['yield_10y'] - $curveSound['yield_10y'], 0.0002, 'The ten-year carries the whole premium.');
        $this->assertEqualsWithDelta(0.01 * $scale2y, $curveStressed['yield_2y'] - $curveSound['yield_2y'], 0.0002, 'The two-year carries its duration share of it.');
        $this->assertEqualsWithDelta(0.01, $curveStressed['term_premium_10y'] - $curveSound['term_premium_10y'], 0.0002, 'and it is booked as term premium');
        $this->assertEqualsWithDelta($curveSound['risk_neutral_10y'], $curveStressed['risk_neutral_10y'], 1e-9, 'not as an expected policy path.');
        $this->assertEqualsWithDelta(0.01, $curveStressed['base_term_premium'] - $curveSound['base_term_premium'], 1e-9, 'The desk prices off the same premium the curve was fitted with.');
    }


    // --- Deposits Channel ---

    private function settledDepositChannel(float $policyRateEma): MacroState
    {
        $state = new MacroState();
        $state->policyRateEma = $policyRateEma;
        for ($i = 0; $i < 3000; $i++) {
            $this->subsystem->calculateDepositChannel($state, 0.01);
        }

        return $state;
    }

    /** Drechsler, Savov & Schnabl (2017): deposits pass 0.46 of the policy rate through at every level, so the spread banks keep scales with the rate. */
    public function testTheSystemPassesTheSameShareOfTheRateThroughAtEveryLevel(): void
    {
        foreach ([0.0, MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION, 0.05] as $policyRate) {
            $this->assertEqualsWithDelta(0.46, $this->settledDepositChannel($policyRate)->systemDepositBeta, 1e-12);
        }
    }

    public function testMoneyFundsGainShareOnlyWhenTheDepositSpreadOpens(): void
    {
        $neutral = $this->settledDepositChannel(MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION);
        $tight = $this->settledDepositChannel(0.05);
        $floor = $this->settledDepositChannel(0.0);

        $this->assertEqualsWithDelta(MacroEngine::MMF_SHARE_BASE, $neutral->moneyMarketFundShare, 0.005, 'At the neutral spread the share sits at its base.');
        $this->assertGreaterThan($neutral->moneyMarketFundShare + 0.03, $tight->moneyMarketFundShare, 'A wide deposit spread pulls several points of liquid assets into money funds.');
        $this->assertLessThan($neutral->moneyMarketFundShare, $floor->moneyMarketFundShare, 'and a decade at the lower bound sends them back.');
        $this->assertGreaterThanOrEqual(MonetaryPolicySubsystem::MIN_MMF_SHARE, $floor->moneyMarketFundShare);
    }

    /**
     * The Evans hold is a gap to the NAIRU, not a fixed unemployment rate: once hysteresis has scarred the NAIRU past
     * the old 5% line, unemployment at the NAIRU is full employment and must not keep the rate at the floor.
     */
    public function testTheEvansHoldIsMeasuredAgainstTheNairu(): void
    {
        $scarred = new MacroState();
        $scarred->policyRate = 0.0;
        $scarred->nairu = 0.052;
        $scarred->unemploymentRateEma = 0.053; // over the old absolute 5%, a tenth of a point over this NAIRU
        $scarred->inflationEma = 0.018;
        $scarred->tipsBreakeven = 0.018;

        $this->assertGreaterThan(0.0, $this->subsystem->updatePolicyRate($scarred, 0.03, 0.25), 'Full employment at a scarred NAIRU lifts off.');

        $slack = clone $scarred;
        $slack->unemploymentRateEma = $scarred->nairu + MonetaryPolicySubsystem::EVANS_RULE_UNEMPLOYMENT_GAP + 0.002;
        $this->assertSame(0.0, $this->subsystem->updatePolicyRate($slack, 0.03, 0.25), 'Slack past the threshold still holds the floor.');
    }

    /**
     * The balance sheet reaches demand through the curve, so the rule does not read it again: a held stock must not
     * pull the target under the rule once the economy has recovered.
     */
    public function testTheHeldBalanceSheetDoesNotLowerTheRule(): void
    {
        $recovered = new MacroState();
        $recovered->policyRate = 0.02;
        $recovered->outputGap = 0.012;
        $recovered->outputGapEma = 0.012;
        $recovered->qeIntensity = 0.0;
        // Only the QE reading the rule used to subtract; the stock's own term-premium channel is the curve's business.
        $withStock = clone $recovered;
        $withStock->qeIntensity = 0.0095;

        $this->assertSame(
            $this->subsystem->calculateTargetRate($recovered, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE),
            $this->subsystem->calculateTargetRate($withStock, MacroEngine::TARGET_INFLATION, MacroEngine::BASE_NATURAL_RATE)
        );
    }

    // --- Diagnostics: what held the rate ---

    /** @return array<string, float> Constraint => share of one recorded tick. */
    private function constraintsFor(MacroState $state, float $targetRate): array
    {
        $probe = new MacroDiagnosticsProbe();
        $probe->enable();
        (new MonetaryPolicySubsystem(new MathUtility(), $probe))->updatePolicyRate($state, $targetRate, 0.25);

        return $probe->snapshot()['current']['policy']['constraints'];
    }

    public function testTheEvansHoldIsReportedWhileItPinsTheRate(): void
    {
        $state = new MacroState();
        $state->policyRate = 0.0;
        $state->unemploymentRateEma = 0.07;
        $state->inflationEma = 0.018;
        $state->tipsBreakeven = 0.018;

        $constraints = $this->constraintsFor($state, 0.03);

        $this->assertSame(1.0, $constraints['evansHold']);
        $this->assertSame(0.0, $constraints['hikeCeiling'], 'A held rate is not also rationed by the ceiling.');
    }

    public function testTheHikeCeilingIsReportedPastItsKnee(): void
    {
        $state = new MacroState();
        $state->policyRate = 0.02;
        $state->inflation = 0.025;

        $this->assertSame(1.0, $this->constraintsFor($state, 0.09)['hikeCeiling'], 'A 700bp distance is far past the 150bp knee.');
        $this->assertSame(0.0, $this->constraintsFor($state, 0.025)['hikeCeiling'], 'A 50bp distance adjusts freely.');
    }

    public function testAMidCycleRateMovesUnconstrained(): void
    {
        $state = new MacroState();
        $state->policyRate = 0.03;
        $state->inflation = 0.022;

        $constraints = $this->constraintsFor($state, 0.035);

        $this->assertSame(['lowerBound' => 0.0, 'evansHold' => 0.0, 'hikeCeiling' => 0.0, 'panicSpeed' => 0.0, 'targetCap' => 0.0], $constraints);
    }

    public function testTheLowerBoundAndPanicSpeedAreReported(): void
    {
        $atFloor = new MacroState();
        $atFloor->policyRate = 0.0;
        $atFloor->unemploymentRateEma = MacroEngine::NATURAL_UNEMPLOYMENT;
        $this->assertSame(1.0, $this->constraintsFor($atFloor, MacroEngine::EFFECTIVE_LOWER_BOUND)['lowerBound']);

        $panic = new MacroState();
        $panic->policyRate = 0.04;
        $panic->inflation = 0.08;
        $this->assertSame(1.0, $this->constraintsFor($panic, 0.045)['panicSpeed']);
    }

    /** Debt inside the 70% the Bohn reaction defends and no market premium on it: the curve carries no fiscal term. */
    private function holdSoundFiscalPosition(MacroState $state): void
    {
        $state->sovereignDebtToGdpEma = CreditFiscalSubsystem::SOVEREIGN_DEBT_NEUTRAL_THRESHOLD;
        $state->sovereignRiskSpreadEma = 0.0;
    }
}
