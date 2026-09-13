<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\BondValuationDTO;
use App\DTO\SovereignCurveDTO;
use App\Entity\Bond;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

/**
 * Marks sovereign bonds against the live term structure.
 *
 * Every flow is discounted at the zero rate for its own settlement distance rather than at one blended
 * yield, so the shape of the curve reaches the price and not just its level: a steepening that leaves the
 * ten-year untouched still reprices a two-year and a thirty-year in opposite directions, which is the whole
 * point of having a curve rather than a rate.
 *
 * The zero rates come from MathUtility::calculateSovereignZeroYield, which is the same function
 * MonetaryPolicySubsystem publishes the benchmark points with. That is deliberate: a bond priced off a
 * second curve implementation would disagree with the quoted 10y by however much the two drifted apart, and
 * a disagreement between a quoted rate and the instrument that pays it is a risk-free trade.
 */
final class BondPricingEngine
{
    public function __construct(
        private readonly MathUtility $mathUtility,
    ) {}

    /**
     * Zero-coupon yield at an arbitrary tenor off a fitted curve.
     *
     * @param SovereignCurveDTO $curve The fitted term structure.
     * @param float             $tau   Maturity in years.
     */
    public function zeroYield(SovereignCurveDTO $curve, float $tau): float
    {
        return $this->mathUtility->calculateSovereignZeroYield(
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

        return $this->mathUtility->calculateAccruedInterest($bond->couponAmount(), $fractionOfPeriod, $period);
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
    public function value(Bond $bond, SovereignCurveDTO $curve, float $currentTime): BondValuationDTO
    {
        $face = (float) $bond->getFaceValue();
        $flows = $this->remainingCashFlows($bond, $currentTime);

        if ($flows === []) {
            return new BondValuationDTO($face, $face, 0.0, 0.0, 0.0, 0.0);
        }

        $dirtyPrice = $this->mathUtility->calculateBondPresentValue(
            $flows,
            fn (float $tau): float => $this->zeroYield($curve, $tau)
        );

        $accrued = $this->accruedInterest($bond, $currentTime);

        // The yield is solved from the curve-discounted price rather than assumed, so duration and convexity
        // are measured at the bond's own yield. Seeding the solver with the zero rate at the bond's maturity
        // puts it within a few basis points of the answer for anything but a deeply off-market coupon.
        $guess = $this->zeroYield($curve, $bond->yearsToMaturity($currentTime));
        $ytm = $this->mathUtility->calculateYieldToMaturity($flows, $dirtyPrice, $guess);

        $macaulay = $this->mathUtility->calculateMacaulayDuration($flows, $ytm);

        return new BondValuationDTO(
            dirtyPrice: $dirtyPrice,
            cleanPrice: $dirtyPrice - $accrued,
            accruedInterest: $accrued,
            yieldToMaturity: $ytm,
            modifiedDuration: $this->mathUtility->calculateModifiedDuration($macaulay),
            convexity: $this->mathUtility->calculateConvexity($flows, $ytm),
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
    public function parCouponRate(SovereignCurveDTO $curve, float $tenorYears, float $faceValue): float
    {
        $period = 1.0 / FinancialConstants::BOND_COUPON_FREQUENCY;
        $totalCoupons = (int) round($tenorYears * FinancialConstants::BOND_COUPON_FREQUENCY);

        $annuityFactor = 0.0;
        for ($k = 1; $k <= $totalCoupons; $k++) {
            $time = $k * $period;
            $annuityFactor += exp(-$this->zeroYield($curve, $time) * $time);
        }

        $redemptionFactor = exp(-$this->zeroYield($curve, $tenorYears) * $tenorYears);

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
