<?php

declare(strict_types=1);

namespace App\Service\Market\Bond;

use App\DTO\BondValuationDTO;
use App\DTO\SovereignCurveDTO;
use App\Entity\Bond;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FixedIncome;

/**
 * Marks sovereign bonds against the live term structure.
 *
 * Every flow is discounted at the zero rate for its own settlement distance rather than at one blended
 * yield, so the shape of the curve reaches the price and not just its level: a steepening that leaves the
 * ten-year untouched still reprices a two-year and a thirty-year in opposite directions, which is the whole
 * point of having a curve rather than a rate.
 *
 * The zero rates come from FixedIncome::calculateSovereignZeroYield, which is the same function
 * MonetaryPolicySubsystem publishes the benchmark points with. That is deliberate: a bond priced off a
 * second curve implementation would disagree with the quoted 10y by however much the two drifted apart, and
 * a disagreement between a quoted rate and the instrument that pays it is a risk-free trade.
 */
final class BondPricingEngine
{
    // --- Discount Curve Sampling ---
    /**
     * Years between pillars of the sampled discount curve.
     *
     * Measured worst case across inverted, humped, lower-bound and QE curves is 0.033 basis points, at the
     * very short end where the curvature terms are steepest; BondDiscountGridTest holds it under one. The
     * spacing is set by that error budget rather than by the pillar count — six hundred evaluations a tick
     * against five thousand lookups leaves plenty of room to be conservative.
     */
    public const PILLAR_SPACING_YEARS = 0.05;

    /** Longest tenor the grid covers; beyond it the curve is evaluated directly, which almost never happens. */
    public const PILLAR_MAX_TENOR_YEARS = 31.0;

    /**
     * The sovereign curve sampled onto a tenor grid, BEFORE the effective lower bound, for $pillarCurve.
     *
     * A mark evaluates the curve once per remaining cash flow of every issue on the ladder — three hundred
     * bonds times sixteen flows is five thousand evaluations a tick, and a profile put that one call chain at
     * thirteen percent of the whole ticker. Keying a memo on the tenor barely helped: a cash flow's tenor is
     * measured from NOW, so it moves every tick and only ever collides with issues sold at the same auction.
     * Measured reuse was 1.6x against the 3x it was built for.
     *
     * A curve is pillars and an interpolation everywhere else — that is how a desk stores one, and it is what
     * the quoted benchmark points already are. Sampling it once per curve object costs six hundred
     * evaluations and answers every cash flow from an array read.
     *
     * @var array<int, float>
     */
    private array $pillars = [];

    private ?SovereignCurveDTO $pillarCurve = null;

    /**
     * Zero-coupon yield at an arbitrary tenor off a fitted curve.
     *
     * @param SovereignCurveDTO $curve The fitted term structure.
     * @param float             $tau   Maturity in years.
     */
    public function zeroYield(SovereignCurveDTO $curve, float $tau): float
    {
        return FixedIncome::calculateSovereignZeroYield(
            tau: $tau,
            level: $curve->level,
            slope: $curve->slope,
            curvature1: $curve->curvature1,
            curvature2: $curve->curvature2,
            lambda1: MacroEngine::SVENSSON_LAMBDA_1,
            lambda2: MacroEngine::SVENSSON_LAMBDA_2,
            slopeLambda: MacroEngine::SVENSSON_SLOPE_LAMBDA,
            termPremium10y: $curve->baseTermPremium,
            longEndPremium: $curve->longEndPremium,
            termPremiumHorizonYears: MacroEngine::TERM_PREMIUM_DURATION_HORIZON_YEARS,
            balanceSheetIntensity: $curve->balanceSheetIntensity,
            habitatSensitivity: MacroEngine::PREFERRED_HABITAT_DURATION_SENSITIVITY,
            effectiveLowerBound: MacroEngine::EFFECTIVE_LOWER_BOUND
        );
    }

    /**
     * The zero rate a cash flow is discounted at, read off the sampled curve.
     *
     * Linear between pillars, then floored. The floor is applied AFTER the interpolation because it is the
     * only kink in the curve: interpolating floored values would cut the corner off the cell where the bound
     * starts binding. Everything else the curve is made of — Svensson's exponentials, the ACM duration scale,
     * the habitat shift — is smooth, so a straight line between pillars half a tenth of a year apart is
     * within a basis point of the function, which BondPricingGridTest pins.
     *
     * A tenor past the end of the grid is evaluated directly. Nothing on the ladder is issued longer than
     * thirty years, so this is a guard rather than a path.
     */
    public function discountZeroYield(SovereignCurveDTO $curve, float $tau): float
    {
        if ($this->pillarCurve !== $curve) {
            $this->pillars = [];
            $this->pillarCurve = $curve;
        }

        $tenor = max(0.0, $tau);

        if ($tenor >= self::PILLAR_MAX_TENOR_YEARS) {
            return $this->zeroYield($curve, $tenor);
        }

        $position = $tenor / self::PILLAR_SPACING_YEARS;
        $index = (int) $position;
        $weight = $position - $index;

        $low = $this->pillars[$index] ??= $this->pillarAt($curve, $index);
        $high = $this->pillars[$index + 1] ??= $this->pillarAt($curve, $index + 1);

        return max(MacroEngine::EFFECTIVE_LOWER_BOUND, $low + (($high - $low) * $weight));
    }

    /**
     * The unfloored curve at one pillar, evaluated the first time that pillar is asked for.
     *
     * ON DEMAND, NOT UP FRONT. Filling the whole grid eagerly costs the same six hundred evaluations whether
     * the ladder is three hundred issues or thirty, which turns a five-fold saving on a mature ladder into a
     * loss on a young one — a profile taken against an 86-issue ladder caught exactly that, 610 evaluations
     * spent serving 729 cash flows. Lazily the cost is the pillars actually touched, so it falls with the
     * ladder and still tops out at the grid, never at the flow count.
     */
    private function pillarAt(SovereignCurveDTO $curve, int $index): float
    {
        return FixedIncome::calculateSovereignZeroYieldUnbounded(
            tau: $index * self::PILLAR_SPACING_YEARS,
            level: $curve->level,
            slope: $curve->slope,
            curvature1: $curve->curvature1,
            curvature2: $curve->curvature2,
            lambda1: MacroEngine::SVENSSON_LAMBDA_1,
            lambda2: MacroEngine::SVENSSON_LAMBDA_2,
            slopeLambda: MacroEngine::SVENSSON_SLOPE_LAMBDA,
            termPremium10y: $curve->baseTermPremium,
            longEndPremium: $curve->longEndPremium,
            termPremiumHorizonYears: MacroEngine::TERM_PREMIUM_DURATION_HORIZON_YEARS,
            balanceSheetIntensity: $curve->balanceSheetIntensity,
            habitatSensitivity: MacroEngine::PREFERRED_HABITAT_DURATION_SENSITIVITY
        );
    }

    /**
     * The bond's remaining cash flows, timed in years from now.
     *
     * Coupon dates are anchored to the issue date, so an issue that has already paid three of its coupons
     * offers the buyer the rest of the original schedule rather than a fresh one starting today. The final
     * coupon and the redemption of face fall on the same date and are returned as one flow.
     *
     * @param Bond  $bond        The issue.
     * @param float $currentTime Simulation time in years.
     * @return array<int, array{time: float, amount: float}> Ascending in time; empty once matured.
     */
    public function remainingCashFlows(Bond $bond, float $currentTime): array
    {
        if ($bond->isMatured($currentTime)) {
            return [];
        }

        $period = $bond->couponPeriodYears();
        $coupon = $bond->couponAmount();
        $face = (float) $bond->getFaceValue();
        $maturity = $bond->getMaturesAtTime();

        $flows = [];
        $totalCoupons = (int) round(((float) $bond->getTenorYears()) * FinancialConstants::BOND_COUPON_FREQUENCY);

        for ($k = 1; $k <= $totalCoupons; $k++) {
            $paymentTime = $bond->getIssuedAtTime() + ($k * $period);

            // Already paid, or close enough to now that it belongs to the seller rather than the buyer.
            if ($paymentTime - $currentTime <= FinancialConstants::BOND_MATURITY_EPSILON) {
                continue;
            }

            $amount = $coupon;
            if ($paymentTime >= $maturity - FinancialConstants::BOND_MATURITY_EPSILON) {
                $amount += $face;
            }

            $flows[] = ['time' => $paymentTime - $currentTime, 'amount' => $amount];
        }

        // A rounding-induced gap between the last scheduled coupon and the redemption date would strand the
        // face value, so redeem explicitly if the schedule never carried it.
        if ($flows === [] || $flows[count($flows) - 1]['amount'] < $face) {
            $flows[] = ['time' => max(0.0, $maturity - $currentTime), 'amount' => $face];
        }

        return $flows;
    }

    /**
     * Interest earned by the seller since the last coupon date, on an actual/actual basis.
     *
     * @param Bond  $bond        The issue.
     * @param float $currentTime Simulation time in years.
     */
    public function accruedInterest(Bond $bond, float $currentTime): float
    {
        $period = $bond->couponPeriodYears();
        $elapsedSinceIssue = max(0.0, $currentTime - $bond->getIssuedAtTime());
        $periodsElapsed = $elapsedSinceIssue / $period;
        $fractionOfPeriod = ($periodsElapsed - floor($periodsElapsed)) * $period;

        return FixedIncome::calculateAccruedInterest($bond->couponAmount(), $fractionOfPeriod, $period);
    }

    /**
     * Marks one bond: price, yield, and the two risk measures.
     *
     * A matured issue prices at face and carries no duration; it is a cash claim awaiting redemption, and
     * reporting residual duration on it would put phantom rate risk in a portfolio.
     *
     * @param Bond              $bond        The issue to mark.
     * @param SovereignCurveDTO $curve       The fitted term structure.
     * @param float             $currentTime Simulation time in years.
     */
    public function value(Bond $bond, SovereignCurveDTO $curve, float $currentTime, float $creditSpread = 0.0): BondValuationDTO
    {
        $face = (float) $bond->getFaceValue();
        $flows = $this->remainingCashFlows($bond, $currentTime);

        if ($flows === []) {
            return new BondValuationDTO($face, $face, 0.0, 0.0, 0.0, 0.0);
        }

        // A risky claim is the same cash flows discounted at a higher rate. The spread is added to the
        // sovereign zero at EVERY tenor rather than to the yield at the end, which is what makes the credit
        // charge compound along the schedule the way it actually does: a ten-year bond pays the spread on
        // every coupon it is still waiting for, not once on its redemption.
        $spread = max(0.0, $creditSpread);
        $discount = fn (float $tau): float => $this->discountZeroYield($curve, $tau) + $spread;

        $dirtyPrice = FixedIncome::calculateBondPresentValue($flows, $discount);

        $accrued = $this->accruedInterest($bond, $currentTime);

        // The yield is solved from the curve-discounted price rather than assumed, so duration and convexity
        // are measured at the bond's own yield. Seeding the solver with the zero rate at the bond's maturity
        // puts it within a few basis points of the answer for anything but a deeply off-market coupon.
        $guess = $discount($bond->yearsToMaturity($currentTime));
        $ytm = FixedIncome::calculateYieldToMaturity($flows, $dirtyPrice, $guess);

        $macaulay = FixedIncome::calculateMacaulayDuration($flows, $ytm);

        return new BondValuationDTO(
            dirtyPrice: $dirtyPrice,
            cleanPrice: $dirtyPrice - $accrued,
            accruedInterest: $accrued,
            yieldToMaturity: $ytm,
            modifiedDuration: FixedIncome::calculateModifiedDuration($macaulay),
            convexity: FixedIncome::calculateConvexity($flows, $ytm),
        );
    }

    /**
     * The coupon rate that prices a new issue of this tenor at par, struck in the auction's eighths.
     *
     * Solved from the curve rather than set to a benchmark yield: par pricing requires the coupon to satisfy
     * face = c * sum(discount factors) + face * final discount factor, and the annuity of discount factors is
     * not the same thing as the single zero rate at the maturity unless the curve is flat.
     *
     * @param SovereignCurveDTO $curve      The fitted term structure.
     * @param float             $tenorYears Original maturity of the new issue.
     * @param float             $faceValue  Face value per bond.
     */
    public function parCouponRate(SovereignCurveDTO $curve, float $tenorYears, float $faceValue, float $creditSpread = 0.0): float
    {
        $period = 1.0 / FinancialConstants::BOND_COUPON_FREQUENCY;
        $totalCoupons = (int) round($tenorYears * FinancialConstants::BOND_COUPON_FREQUENCY);

        // A risky issue prices at par on a HIGHER coupon, which is the whole of what a credit spread costs
        // the borrower. Struck off the same discount function the issue will then be marked against, so a
        // fresh corporate bond opens at par rather than immediately at a discount to its own issue price.
        $spread = max(0.0, $creditSpread);

        $annuityFactor = 0.0;
        for ($k = 1; $k <= $totalCoupons; $k++) {
            $time = $k * $period;
            $annuityFactor += exp(-($this->discountZeroYield($curve, $time) + $spread) * $time);
        }

        $redemptionFactor = exp(-($this->discountZeroYield($curve, $tenorYears) + $spread) * $tenorYears);

        if ($annuityFactor <= 0.0) {
            return FinancialConstants::BOND_MIN_COUPON_RATE;
        }

        $couponPerPeriod = ($faceValue * (1.0 - $redemptionFactor)) / $annuityFactor;
        $annualRate = ($couponPerPeriod * FinancialConstants::BOND_COUPON_FREQUENCY) / $faceValue;

        $increment = FinancialConstants::BOND_COUPON_RATE_INCREMENT;
        $struck = round($annualRate / $increment) * $increment;

        return max(FinancialConstants::BOND_MIN_COUPON_RATE, $struck);
    }
}
