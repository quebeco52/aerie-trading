<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\DTO\SovereignCurveDTO;
use App\Entity\Bond;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Market\BondPricingEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Tests\Support\MacroStateBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Financial invariants of the sovereign bond desk.
 *
 * The properties here are the ones that make a bond a bond rather than a number that moves: it pulls to
 * par, its duration decays as it ages, its price responds to a rate move by exactly what its own published
 * risk measures say it will, and the yield it is discounted at is the yield the macro engine quotes. Each
 * of those is a place where an implementation can look right on a chart and still be arbitrageable.
 */
class BondDeskTest extends TestCase
{
    private BondPricingEngine $engine;
    private MathUtility $math;

    protected function setUp(): void
    {
        $this->math = new MathUtility();
        $this->engine = new BondPricingEngine($this->math);
    }

    private function curve(float $level = 0.0425, float $slope = -0.0175, float $curvature1 = 0.0, float $curvature2 = 0.0): SovereignCurveDTO
    {
        return new SovereignCurveDTO(
            level: $level,
            slope: $slope,
            curvature1: $curvature1,
            curvature2: $curvature2,
            baseTermPremium: MacroEngine::NS_BASE_TERM_PREMIUM,
            longEndPremium: MacroEngine::NS_BASE_TERM_PREMIUM,
            balanceSheetIntensity: 0.0,
        );
    }

    private function bond(float $tenor, float $couponRate, float $issuedAt = 0.0): Bond
    {
        return (new Bond())
            ->setTicker(sprintf('G%02d-TEST', (int) $tenor))
            ->setName('test issue')
            ->setTenorYears((string) $tenor)
            ->setCouponRate((string) $couponRate)
            ->setFaceValue((string) FinancialConstants::BOND_FACE_VALUE)
            ->setIssuedAtTime($issuedAt)
            ->setMaturesAtTime($issuedAt + $tenor)
            ->setLastCouponTime($issuedAt);
    }

    // --- Curve Consistency ---

    /**
     * The desk must discount at the same rates the macro engine publishes.
     *
     * A separate curve implementation on the trading side would quote a ten-year yield on the macro
     * dashboard while pricing the ten-year bond off something slightly different, and the gap between a
     * published rate and the instrument that pays it is a risk-free trade for whoever spots it.
     */
    public function testDeskYieldsMatchThePublishedBenchmarkCurveExactly(): void
    {
        $subsystem = new MonetaryPolicySubsystem($this->math);
        $state = MacroStateBuilder::create()->build();

        $yieldData = $subsystem->calculateYieldCurve($state, MacroEngine::TARGET_INFLATION, $state->naturalRate);

        $curve = new SovereignCurveDTO(
            level: $yieldData['level'],
            slope: $yieldData['beta1'],
            curvature1: $yieldData['curvature'],
            curvature2: $yieldData['curvature2'],
            baseTermPremium: $yieldData['base_term_premium'],
            longEndPremium: $yieldData['long_end_premium'],
            balanceSheetIntensity: $state->balanceSheetIntensity,
        );

        foreach ([2.0 => 'yield_2y', 5.0 => 'yield_5y', 10.0 => 'yield_10y', 30.0 => 'yield_30y'] as $tenor => $key) {
            $this->assertEqualsWithDelta(
                $yieldData[$key],
                $this->engine->zeroYield($curve, $tenor),
                1e-12,
                "Desk and macro engine disagree on the {$tenor}y zero yield."
            );
        }
    }

    /**
     * The Svensson evaluation exists in exactly one place.
     *
     * Structural rather than numerical, and here because it is the rule the numerical test above can only
     * check for the callers that happen to exist today. Anything that needs a sovereign zero yield — the
     * macro subsystem, the bond desk, a chart — must route through MathUtility, because a second copy of
     * the formula does not fail when it is written. It fails silently later, the first time
     * SVENSSON_LAMBDA_1 or the effective lower bound is retuned and only one copy is updated.
     *
     * The browser is included on purpose: a curve drawn from re-derived factors is a picture of a
     * different curve than the one pricing the bonds listed beneath it.
     */
    public function testTheSvenssonEvaluationIsNotReimplementedAnywhere(): void
    {
        $root = \dirname(__DIR__, 2);
        $offenders = [];

        $sources = array_merge(
            glob($root . '/src/Service/Market/*.php') ?: [],
            glob($root . '/src/Controller/*.php') ?: [],
            glob($root . '/assets/js/pages/*.js') ?: [],
            glob($root . '/assets/js/stock/*.js') ?: []
        );

        foreach ($sources as $path) {
            $contents = file_get_contents($path);
            if (!is_string($contents)) {
                continue;
            }

            // The signature of a hand-rolled Nelson-Siegel loading: the decay term over its own tenor.
            if (preg_match('/1(\.0)?\s*-\s*(exp|Math\.exp)\(\s*-\s*\$?lambda/i', $contents) === 1) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Sovereign yields must come from MathUtility::calculateSovereignZeroYield, not a local copy.'
        );
    }

    public function testTheFittedSlopeIsNotTheReportingSlope(): void
    {
        // Guards the one substitution that produces a plausible-looking but wrong curve: MacroState::$nsSlope
        // is the 10y-minus-policy reporting metric, while the curve function wants beta1.
        $state = MacroStateBuilder::create()->build();
        $subsystem = new MonetaryPolicySubsystem($this->math);

        $yieldData = $subsystem->calculateYieldCurve($state, MacroEngine::TARGET_INFLATION, $state->naturalRate);

        $this->assertNotEqualsWithDelta($yieldData['beta1'], $yieldData['yield_10y'] - $state->policyRate, 1e-6);
    }

    // --- Duration & Convexity Predict Repricing ---

    /**
     * The desk's own risk numbers must predict what the desk's own prices do.
     *
     * dP/P = -D_mod * dy + 0.5 * C * dy^2, shifting the bond's own yield, which is the rate those two
     * measures are defined against. If a portfolio's reported duration does not reproduce its realized P&L
     * under a rate move, then either the risk report or the pricing is wrong and there is no way to tell
     * which from inside the game.
     */
    public function testDurationAndConvexityPredictThePriceMoveUnderAParallelYieldShift(): void
    {
        $base = $this->curve();

        foreach ([0.0025, -0.0025, 0.0100, -0.0100] as $shift) {
            foreach (FinancialConstants::BOND_AUCTION_TENORS as $tenor) {
                $coupon = $this->engine->parCouponRate($base, (float) $tenor, FinancialConstants::BOND_FACE_VALUE);
                $bond = $this->bond((float) $tenor, $coupon);

                $flows = $this->engine->remainingCashFlows($bond, 0.0);
                $before = $this->engine->value($bond, $base, 0.0);
                $yield = $before->yieldToMaturity;

                $priceAt = fn (float $y): float => $this->math->calculateBondPresentValue(
                    $flows,
                    static fn (float $tau): float => $y
                );

                $actual = ($priceAt($yield + $shift) - $priceAt($yield)) / $priceAt($yield);
                $predicted = (-$before->modifiedDuration * $shift) + (0.5 * $before->convexity * $shift * $shift);

                // What a second-order expansion leaves behind is the third-order term, whose leading
                // magnitude is D^3 * dy^3 / 6. Three times that is a safe ceiling and still tight enough to
                // catch a duration that is wrong by any meaningful amount.
                $thirdOrderBound = 0.5 * ($before->modifiedDuration ** 3) * abs($shift ** 3);

                $this->assertEqualsWithDelta(
                    $predicted,
                    $actual,
                    $thirdOrderBound,
                    sprintf('%dy bond mispredicted under a %.2f%% yield shift.', $tenor, $shift * 100)
                );
            }
        }
    }

    /**
     * A parallel shift of the ZERO curve is not a parallel shift of a coupon bond's yield to maturity, and
     * the gap widens with maturity.
     *
     * Yield to maturity is a nonlinear average of the zero rates a bond's flows are discounted at, so moving
     * every zero rate by 25bp moves a thirty-year's YTM by slightly less. The duration prediction therefore
     * carries a small residual that is second order, not third, and grows with the spread of the cash flows.
     * This is a property of yield-to-maturity arithmetic rather than a defect, and it is asserted here so
     * that a future change which accidentally removes it — by discounting every flow at one rate, say — is
     * caught rather than looking like an improvement in accuracy.
     */
    public function testACurveShiftRepricesNearTheDurationPredictionWithLongIssuesDeviatingMost(): void
    {
        $base = $this->curve();
        $shift = 0.0025;
        $shifted = $this->curve(0.0425 + $shift);

        $previousRelativeError = 0.0;

        foreach (FinancialConstants::BOND_AUCTION_TENORS as $tenor) {
            $coupon = $this->engine->parCouponRate($base, (float) $tenor, FinancialConstants::BOND_FACE_VALUE);
            $bond = $this->bond((float) $tenor, $coupon);

            $before = $this->engine->value($bond, $base, 0.0);
            $after = $this->engine->value($bond, $shifted, 0.0);

            $actual = ($after->dirtyPrice - $before->dirtyPrice) / $before->dirtyPrice;
            $predicted = (-$before->modifiedDuration * $shift) + (0.5 * $before->convexity * $shift * $shift);

            $this->assertLessThan(0.0, $actual, 'A yield rise must lower the price of every issue.');

            $relativeError = abs($actual - $predicted) / abs($predicted);

            $this->assertLessThan(
                0.05,
                $relativeError,
                sprintf('%dy bond deviates more than 5%% from its duration prediction.', $tenor)
            );

            $this->assertGreaterThan(
                $previousRelativeError,
                $relativeError,
                sprintf('%dy bond should deviate more than the shorter issue, not less.', $tenor)
            );

            $previousRelativeError = $relativeError;
        }
    }

    public function testLongerBondsCarryMoreDurationAndMoreConvexity(): void
    {
        $curve = $this->curve();
        $previousDuration = 0.0;
        $previousConvexity = 0.0;

        foreach (FinancialConstants::BOND_AUCTION_TENORS as $tenor) {
            $coupon = $this->engine->parCouponRate($curve, (float) $tenor, FinancialConstants::BOND_FACE_VALUE);
            $valuation = $this->engine->value($this->bond((float) $tenor, $coupon), $curve, 0.0);

            $this->assertGreaterThan($previousDuration, $valuation->modifiedDuration);
            $this->assertGreaterThan($previousConvexity, $valuation->convexity);
            $this->assertLessThan((float) $tenor, $valuation->modifiedDuration, 'Coupon bond duration must be under its maturity.');

            $previousDuration = $valuation->modifiedDuration;
            $previousConvexity = $valuation->convexity;
        }
    }

    // --- Auction Pricing ---

    public function testParStruckCouponsPriceTheNewIssueAtApproximatelyPar(): void
    {
        $curve = $this->curve();
        $face = FinancialConstants::BOND_FACE_VALUE;

        foreach (FinancialConstants::BOND_AUCTION_TENORS as $tenor) {
            $coupon = $this->engine->parCouponRate($curve, (float) $tenor, $face);
            $valuation = $this->engine->value($this->bond((float) $tenor, $coupon), $curve, 0.0);

            // Not exactly par: the coupon is struck in eighths of a percent, so the issue prices with the
            // small premium or discount that rounding leaves. The gap is bounded by half an increment of
            // coupon across the bond's duration.
            $maxDeviation = 0.5 * FinancialConstants::BOND_COUPON_RATE_INCREMENT * $valuation->modifiedDuration * $face;

            $this->assertEqualsWithDelta($face, $valuation->cleanPrice, max(1.0, $maxDeviation));
        }
    }

    public function testStruckCouponsLandOnTheAuctionsEighthOfAPercent(): void
    {
        $curve = $this->curve();
        $increment = FinancialConstants::BOND_COUPON_RATE_INCREMENT;

        foreach (FinancialConstants::BOND_AUCTION_TENORS as $tenor) {
            $coupon = $this->engine->parCouponRate($curve, (float) $tenor, FinancialConstants::BOND_FACE_VALUE);

            $this->assertEqualsWithDelta(0.0, fmod($coupon + ($increment / 2.0), $increment) - ($increment / 2.0), 1e-9);
            $this->assertGreaterThanOrEqual(FinancialConstants::BOND_MIN_COUPON_RATE, $coupon);
        }
    }

    // --- Ageing ---

    public function testAnIssuePullsToParAndShedsDurationAsItAges(): void
    {
        $curve = $this->curve();
        $coupon = $this->engine->parCouponRate($curve, 10.0, FinancialConstants::BOND_FACE_VALUE);
        $bond = $this->bond(10.0, $coupon);

        $previousDuration = PHP_FLOAT_MAX;

        foreach ([0.0, 2.5, 5.0, 7.5, 9.0, 9.75] as $time) {
            $valuation = $this->engine->value($bond, $curve, $time);

            $this->assertLessThan($previousDuration, $valuation->modifiedDuration, "Duration failed to decay by t={$time}.");
            $this->assertLessThanOrEqual($bond->yearsToMaturity($time) + 1e-9, $valuation->modifiedDuration);

            $previousDuration = $valuation->modifiedDuration;
        }

        // At redemption the issue is a claim on face and nothing else: no residual duration, no phantom
        // rate risk left sitting in a portfolio.
        $atMaturity = $this->engine->value($bond, $curve, 10.0);
        $this->assertEqualsWithDelta(FinancialConstants::BOND_FACE_VALUE, $atMaturity->cleanPrice, 1e-6);
        $this->assertSame(0.0, $atMaturity->modifiedDuration);
    }

    public function testCleanPriceIsContinuousAcrossACouponDateWhileDirtyPriceDrops(): void
    {
        $curve = $this->curve();
        $bond = $this->bond(10.0, 0.0475);

        $before = $this->engine->value($bond, $curve, 0.4999);
        $after = $this->engine->value($bond, $curve, 0.5001);

        // The whole reason a desk quotes clean: the accrual sawtooth is not price movement.
        $this->assertEqualsWithDelta($before->cleanPrice, $after->cleanPrice, 0.05);

        // The dirty price does drop, by the coupon that has just been detached.
        $this->assertEqualsWithDelta($bond->couponAmount(), $before->dirtyPrice - $after->dirtyPrice, 0.05);
    }

    public function testRollDownMovesAnIssuesYieldAlongAnUnchangedCurve(): void
    {
        // On an upward-sloping curve a bond that ages is discounted at progressively shorter, lower rates.
        // Without ageing there is no roll-down carry, which is half of why a ladder is a decision.
        $curve = $this->curve();
        $bond = $this->bond(10.0, $this->engine->parCouponRate($curve, 10.0, FinancialConstants::BOND_FACE_VALUE));

        $atIssue = $this->engine->value($bond, $curve, 0.0);
        $atFive = $this->engine->value($bond, $curve, 5.0);

        $this->assertGreaterThan($this->engine->zeroYield($curve, 5.0), $this->engine->zeroYield($curve, 10.0));
        $this->assertLessThan($atIssue->yieldToMaturity, $atFive->yieldToMaturity);
    }

    // --- Curve Shape Transmission ---

    /**
     * A policy move must reach the front of the curve harder than the long end.
     *
     * The whole reason the desk prices off curve factors rather than a single rate is that a change in the
     * slope factor is not a change in the level: beta1 loads on a tenor through (1 - exp(-lambda*tau)) /
     * (lambda*tau), which decays with maturity, so a tightening that lifts the two-year by three hundred
     * basis points lifts the thirty-year by well under one hundred. A desk that discounted everything at one
     * rate would move both by the same amount and could not produce an inversion at all.
     */
    public function testAPolicyMoveHitsTheFrontOfTheCurveHarderThanTheLongEnd(): void
    {
        $normal = $this->curve(level: 0.0425, slope: -0.0175);
        // Policy rate driven above the long-run level: the classic inversion.
        $inverted = $this->curve(level: 0.0425, slope: 0.0225);

        $shortMove = $this->engine->zeroYield($inverted, 2.0) - $this->engine->zeroYield($normal, 2.0);
        $longMove = $this->engine->zeroYield($inverted, 30.0) - $this->engine->zeroYield($normal, 30.0);

        $this->assertGreaterThan(0.0, $shortMove);
        $this->assertGreaterThan(0.0, $longMove);
        $this->assertGreaterThan($longMove * 3.0, $shortMove, 'The slope factor must decay with maturity.');

        // The result is an inverted curve: the two-year out-yields the ten-year.
        $this->assertGreaterThan($this->engine->zeroYield($inverted, 10.0), $this->engine->zeroYield($inverted, 2.0));
        $this->assertLessThan($this->engine->zeroYield($normal, 10.0), $this->engine->zeroYield($normal, 2.0));
    }

    public function testALevelShiftMovesEveryTenorEquallyWhereasASlopeShiftDoesNot(): void
    {
        // The distinguishing test between a real curve and a single rate wearing a curve's name.
        $base = $this->curve();

        $levelShifted = $this->curve(level: 0.0425 + 0.0050);
        $slopeShifted = $this->curve(level: 0.0425, slope: -0.0175 + 0.0050);

        $levelShort = $this->engine->zeroYield($levelShifted, 2.0) - $this->engine->zeroYield($base, 2.0);
        $levelLong = $this->engine->zeroYield($levelShifted, 30.0) - $this->engine->zeroYield($base, 30.0);

        $this->assertEqualsWithDelta($levelShort, $levelLong, 1e-12);
        $this->assertEqualsWithDelta(0.0050, $levelShort, 1e-12);

        $slopeShort = $this->engine->zeroYield($slopeShifted, 2.0) - $this->engine->zeroYield($base, 2.0);
        $slopeLong = $this->engine->zeroYield($slopeShifted, 30.0) - $this->engine->zeroYield($base, 30.0);

        $this->assertGreaterThan($slopeLong * 3.0, $slopeShort);
    }

    public function testLongIssuesLoseMoreValueThanShortOnesForTheSameYieldMove(): void
    {
        // Duration is the price of the curve reaching a bond, so a parallel move costs the long end most.
        $base = $this->curve();
        $shifted = $this->curve(level: 0.0425 + 0.0050);
        $face = FinancialConstants::BOND_FACE_VALUE;

        $previousLoss = 0.0;

        foreach (FinancialConstants::BOND_AUCTION_TENORS as $tenor) {
            $bond = $this->bond((float) $tenor, $this->engine->parCouponRate($base, (float) $tenor, $face));

            $loss = $this->engine->value($bond, $base, 0.0)->dirtyPrice
                - $this->engine->value($bond, $shifted, 0.0)->dirtyPrice;

            $this->assertGreaterThan($previousLoss, $loss, "The {$tenor}y should lose more than the shorter issue.");
            $previousLoss = $loss;
        }
    }

    public function testAZeroCouponIssuePricesBelowParAndCarriesFullMaturityDuration(): void
    {
        $curve = $this->curve();
        $valuation = $this->engine->value($this->bond(10.0, 0.0), $curve, 0.0);

        $this->assertLessThan(FinancialConstants::BOND_FACE_VALUE, $valuation->cleanPrice);
        $this->assertEqualsWithDelta(10.0, $valuation->modifiedDuration, 1e-9);
        $this->assertSame(0.0, $valuation->accruedInterest);
    }

    // --- Cash Flow Schedule ---

    public function testTheFinalCouponRidesWithTheRedemptionRatherThanBeingPaidTwice(): void
    {
        $bond = $this->bond(2.0, 0.05);
        $flows = $this->engine->remainingCashFlows($bond, 0.0);

        $this->assertCount(2 * FinancialConstants::BOND_COUPON_FREQUENCY, $flows);

        $final = $flows[count($flows) - 1];
        $this->assertEqualsWithDelta(2.0, $final['time'], 1e-9);
        $this->assertEqualsWithDelta(FinancialConstants::BOND_FACE_VALUE + $bond->couponAmount(), $final['amount'], 1e-9);
    }

    public function testAnAgedIssueOffersOnlyItsRemainingCouponsOnTheOriginalSchedule(): void
    {
        // A buyer three years into a ten-year gets the rest of the original schedule, not a fresh one
        // starting today: the coupon dates are a property of the issue, not of when it changed hands.
        $bond = $this->bond(10.0, 0.05);
        $flows = $this->engine->remainingCashFlows($bond, 3.25);

        $this->assertCount(14, $flows);
        $this->assertEqualsWithDelta(0.25, $flows[0]['time'], 1e-9);
        $this->assertEqualsWithDelta(6.75, $flows[count($flows) - 1]['time'], 1e-9);
    }

    public function testAMaturedIssueHasNoRemainingCashFlows(): void
    {
        $this->assertSame([], $this->engine->remainingCashFlows($this->bond(5.0, 0.04), 5.0));
        $this->assertSame([], $this->engine->remainingCashFlows($this->bond(5.0, 0.04), 6.0));
    }
}
