<?php

namespace App\Tests\Service;

use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

class MathUtilityTest extends TestCase
{
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->mathUtility = new MathUtility();
    }

    public function testCalculateCorrelatedGBMWithNoMovement(): void
    {
        // If there is no drift, no volatility, and no shocks, the price should remain exactly the same.
        $price = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: 100.0,
            currentVolatility: 0.0,
            drift: 0.0,
            gravityDrift: 0.0,
            dt: 1.0,
            beta: 1.0,
            marketVol: 0.15,
            marketZ: 0.0,
            w1: 0.0
        );

        $this->assertEquals(100.0, $price, 'Price should remain unchanged with 0 parameters.');
    }

    public function testCalculateCorrelatedGBMPureDrift(): void
    {
        // With exactly 10% drift over 1 year (dt = 1.0) and no volatility,
        // the geometric expectation is CurrentPrice * exp(Drift).
        // 100 * exp(0.10) ≈ 110.517
        $price = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: 100.0,
            currentVolatility: 0.0,
            drift: 0.10,
            gravityDrift: 0.0,
            dt: 1.0,
            beta: 1.0,
            marketVol: 0.15,
            marketZ: 0.0,
            w1: 0.0
        );

        $this->assertEqualsWithDelta(110.517, $price, 0.001, 'Price should match pure geometric drift.');
    }

    public function testCalculateCorrelatedGBMSystematicMarketShock(): void
    {
        // A positive market shock (marketZ = 1.0) with a 1.0 beta should push the price up.
        $price = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: 100.0,
            currentVolatility: 0.20,
            drift: 0.0,
            gravityDrift: 0.0,
            dt: 1.0,
            beta: 1.0,
            marketVol: 0.20,
            marketZ: 1.0, // +1 Standard Deviation Market Shock
            w1: 0.0       // No idiosyncratic shock
        );

        $this->assertGreaterThan(100.0, $price, 'Positive market shock should increase the price.');
    }

    public function testCalculateCorrelatedGBMIdiosyncraticStockShock(): void
    {
        // A negative idiosyncratic shock (w1 = -1.0) on a 0 beta stock should push the price down
        // independently of the broader market.
        $price = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: 100.0,
            currentVolatility: 0.20,
            drift: 0.0,
            gravityDrift: 0.0,
            dt: 1.0,
            beta: 0.0,    // 0 correlation to the market
            marketVol: 0.20,
            marketZ: 1.0, // Positive market shock (should be ignored due to 0 beta)
            w1: -1.0      // -1 Standard Deviation Individual Shock
        );

        $this->assertLessThan(100.0, $price, 'Negative idiosyncratic shock should decrease the price.');
    }

    public function testCalculateCorrelatedGBMCorrelationCappingPreventsNaN(): void
    {
        // Without the `max(-0.99, min(0.99, ...))` logic, an extreme beta and market vol
        // would cause $impliedRho to exceed 1.0. 
        // This would cause `sqrt(1 - (rho * rho))` to attempt to calculate the square root 
        // of a negative number, returning `NAN` and breaking the engine.
        $price = $this->mathUtility->calculateCorrelatedGBM(
            currentPrice: 100.0,
            currentVolatility: 0.05,
            drift: 0.05,
            gravityDrift: 0.0,
            dt: 1.0,
            beta: 5.0,        // Massive Beta
            marketVol: 0.80,  // Massive Market Volatility -> Uncapped Rho would be 80.0!
            marketZ: 0.0,
            w1: 1.0
        );

        $this->assertFalse(is_nan($price), 'Price returned NAN! The correlation cap failed.');
        $this->assertGreaterThan(0, $price, 'Price should be a valid positive float.');
    }

    public function testCalculateIntrinsicFairValuePEStandardValuation(): void
    {
        // COE = 10%, ROIC = 15%, Growth = 3%
        // b = 0.03 / 0.15 = 0.20 -> Payout Ratio = 0.80
        // Denominator = 0.10 - 0.03 = 0.07 -> PE = 0.80 / 0.07 ≈ 11.42857
        $pe = $this->mathUtility->calculateIntrinsicFairValuePE(0.10, 0.15, 0.03);

        $this->assertEqualsWithDelta(11.42857, $pe, 0.001, 'Standard Gordon Growth PE calculation failed.');
    }

    public function testCalculateIntrinsicFairValuePEClampsToMinWhenValueDestroying(): void
    {
        // COE = 10%, ROIC = 2%, Growth = 5%
        // ROIC < Growth -> b > 1.0 -> Capped at 1.0 -> Payout Ratio = 0.0 -> PE = 0.0 -> Clamped to MIN_INTRINSIC_PE
        $pe = $this->mathUtility->calculateIntrinsicFairValuePE(0.10, 0.02, 0.05);

        $this->assertEqualsWithDelta(FinancialConstants::MIN_INTRINSIC_PE, $pe, 0.0001, 'Value-destroying growth must clamp to MIN_INTRINSIC_PE.');
    }

    public function testCalculateIntrinsicFairValuePEConstrainsGrowthBelowCOE(): void
    {
        // COE = 8%, ROIC = 20%, Growth = 15% (Growth >= COE)
        // Growth constrained to 8% - 0.5% = 7.5%
        // b = 0.075 / 0.20 = 0.375 -> Payout Ratio = 0.625 -> PE = 0.625 / 0.005 = 125.0 -> Clamped to MAX_INTRINSIC_PE
        $pe = $this->mathUtility->calculateIntrinsicFairValuePE(0.08, 0.20, 0.15);

        $this->assertEqualsWithDelta(FinancialConstants::MAX_INTRINSIC_PE, $pe, 0.0001, 'Growth >= COE must constrain growth and clamp to MAX_INTRINSIC_PE.');
    }

    public function testCalculateDcfMultiplier(): void
    {
        // WACC = 8%, Growth = 2% -> Spread = 6% -> Multiplier = 1.02 / 0.06 = 17.0
        $multiplier = $this->mathUtility->calculateDcfMultiplier(0.08, 0.02);

        $this->assertEqualsWithDelta(17.0, $multiplier, 0.001, 'DCF terminal multiplier calculation failed.');
    }

    public function testCalculateDividendDiscountModel(): void
    {
        // Dividend = 2.0, COE = 10%, Growth = 5% -> Denominator = 0.05 -> Fair Value = 2.0 / 0.05 = 40.0
        $fairValue = $this->mathUtility->calculateDividendDiscountModel(2.0, 0.10, 0.05);

        $this->assertEqualsWithDelta(40.0, $fairValue, 0.001, 'DDM fair value calculation failed.');
    }

    public function testCalculateIntrinsicFairValuePEWithExtremeDistressAndNegativeGrowth(): void
    {
        // Extreme negative growth (-20%) and extreme low COE (1%) should be floored
        // to MIN_COST_OF_EQUITY (0.04) and MIN_PERPETUAL_GROWTH_RATE (-0.05)
        $pe = $this->mathUtility->calculateIntrinsicFairValuePE(0.01, 0.10, -0.20);

        $this->assertGreaterThanOrEqual(FinancialConstants::MIN_INTRINSIC_PE, $pe);
        $this->assertLessThanOrEqual(FinancialConstants::MAX_INTRINSIC_PE, $pe);
    }

    public function testCalculateDcfMultiplierAbsoluteBounds(): void
    {
        // WACC below 4% should be floored to MIN_COST_OF_EQUITY (0.04)
        $multiplier = $this->mathUtility->calculateDcfMultiplier(0.02, 0.02);

        $this->assertGreaterThanOrEqual(1.0, $multiplier);
        $this->assertLessThanOrEqual(FinancialConstants::MAX_DCF_MULTIPLIER, $multiplier);
    }
}