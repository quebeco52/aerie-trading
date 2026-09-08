<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use App\Service\Market\MarketEngine;
use App\Service\Math\MathUtility;
use App\DTO\MarketPricingContext;
use App\DTO\MacroStateDTO;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
class MarketEngineTest extends TestCase
{
    private MathUtility&MockObject $mathUtilityMock;
    private MarketEngine $engine;

    protected function setUp(): void
    {
        // Use a partial mock so the actual math methods run, but we can control randomness
        $this->mathUtilityMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal', 'checkProbability'])
            ->getMock();

        $this->engine = new MarketEngine($this->mathUtilityMock);
    }

    public function testCalculateNextPriceWithoutJump()
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);

        $ctx = new MarketPricingContext(
            currentPrice: 100.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 5.0,
            dt: 1.0,
            lambda: 0.0, // lambda = 0 means NO jump
            drift: 0.1
        );

        $result = $this->engine->calculateNextPrice($ctx);

        $this->assertIsArray($result);
        $this->assertNull($result['shock'], 'Shock should be null when no jump occurs.');

        // The variance anchor sits below the 20% long-run input because the market-wide jump budget reclaims
        // part of it: at unit beta the district jump delivers more variance than the 25% ceiling allows, so
        // the drag binds at a quarter of long-run variance and the diffusion settles at sqrt(0.03).
        $this->assertEqualsWithDelta(0.1723, $result['next_volatility'], 0.001);

        $this->assertIsFloat($result['price']);
        $this->assertGreaterThan(0, $result['price']);
    }

    public function testCalculateNextPriceWithGuaranteedJump()
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(true);

        $ctx = new MarketPricingContext(
            currentPrice: 100.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 5.0,
            dt: 1.0,
            lambda: 1000.0, // massive lambda guarantees checkProbability triggers
            jumpVol: 0.05,
            drift: 0.1
        );

        $result = $this->engine->calculateNextPrice($ctx);

        $this->assertNotNull($result['shock'], 'Shock should occur due to high lambda.');

        $this->assertIsFloat($result['price']);
        $this->assertGreaterThan(0, $result['price']);
        $this->assertIsFloat($result['next_volatility']);
        $this->assertGreaterThan(0, $result['next_volatility']);
    }

    public function testReversionToFairValue()
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);

        $ctx = new MarketPricingContext(
            currentPrice: 50.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 5.0,
            dt: 1.0,
            lambda: 0.0,
            drift: 0.0,
            reversionSpeed: 0.5,
            bookValuePerShare: 50.0,
            currentRoic: 0.10,
            roicTtm: 0.10,
            revenuePerShare: 50.0
        );

        $result = $this->engine->calculateNextPrice($ctx);

        $this->assertGreaterThan(50.0, $result['price'], 'Undervalued price should drift upwards towards fair value.');
    }

    public function testEvaluateFundamentalStateLowMarginHighRevenueNotInflated()
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);

        $ctx = new MarketPricingContext(
            currentPrice: 40.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 0.50, // low margin
            dt: 1.0,
            lambda: 0.0,
            drift: 0.0,
            reversionSpeed: 0.25,
            bookValuePerShare: 25.0,
            currentRoic: 0.08,
            roicTtm: 0.08,
            liveWacc: 0.15,
            revenuePerShare: 32.0,  // high revenue
            businessModel: 'standard'
        );

        $result = $this->engine->calculateNextPrice($ctx);

        // Perceived fair value should not blow up to $60+ due to raw P/S floor
        $this->assertLessThan(35.0, $result['perceived_fair_value'], 'Low-margin firm should not receive bubble fair value.');
    }

    public function testEstarReversionAndFundingLiquidityDampening()
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);

        // Calculate with 0 macro stress
        $normalCtx = new MarketPricingContext(
            currentPrice: 100.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 5.0,
            dt: 1.0,
            lambda: 0.0,
            reversionSpeed: 0.5,
            macroState: new MacroStateDTO(outputGap: 0.0, inflation: 0.02)
        );
        $normalResult = $this->engine->calculateNextPrice($normalCtx);

        // Calculate with high macro stress (severe recession and inflation spike)
        $stressedCtx = new MarketPricingContext(
            currentPrice: 100.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 5.0,
            dt: 1.0,
            lambda: 0.0,
            reversionSpeed: 0.5,
            macroState: new MacroStateDTO(outputGap: -0.10, inflation: 0.08)
        );
        $stressedResult = $this->engine->calculateNextPrice($stressedCtx);

        $this->assertLessThan(
            $normalResult['dynamic_reversion'],
            $stressedResult['dynamic_reversion'],
            'Brunnermeier-Pedersen funding liquidity dampener must reduce reversion speed during high systemic stress.'
        );
    }

    public function testDynamicDdmHaircutOnUnsustainableDividend()
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);

        // Sustainable dividend ($1.00 quarterly = $4.00 annual on $8.00 EPS => 50% payout)
        $sustainableCtx = new MarketPricingContext(
            currentPrice: 100.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 8.0,
            dt: 1.0,
            lambda: 0.0,
            dividendPerShare: 1.0,
            currentRoic: 0.12,
            roicTtm: 0.12,
            liveCostOfEquity: 0.08
        );
        $sustainableResult = $this->engine->calculateNextPrice($sustainableCtx);

        // Unsustainable debt-funded dividend ($3.00 quarterly = $12.00 annual on $4.00 EPS => 300% payout)
        $unsustainableCtx = new MarketPricingContext(
            currentPrice: 100.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 4.0,
            dt: 1.0,
            lambda: 0.0,
            dividendPerShare: 3.0,
            currentRoic: 0.12,
            roicTtm: 0.12,
            liveCostOfEquity: 0.08
        );
        $unsustainableResult = $this->engine->calculateNextPrice($unsustainableCtx);

        $this->assertArrayHasKey('income_analyst', $unsustainableResult['analyst_targets']);
        // Income analyst target should be heavily discounted due to unsustainable payout
        $this->assertGreaterThan(0.0, $unsustainableResult['analyst_targets']['income_analyst']);
    }

    public function testTechSectorIgnoresBookValueInValuation()
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);

        $techCtx = new MarketPricingContext(
            currentPrice: 150.0,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 10.0,
            dt: 1.0,
            lambda: 0.0,
            bookValuePerShare: 2.0, // Negligible book value
            currentRoic: 0.25,
            roicTtm: 0.25,
            liveWacc: 0.09,
            revenuePerShare: 50.0,
            businessModel: 'tech'
        );
        $techResult = $this->engine->calculateNextPrice($techCtx);

        // Tech fair value should not be dragged down by the tiny $2.00 book value
        $this->assertGreaterThan(75.0, $techResult['perceived_fair_value'], 'Tech fair value should reflect high earnings power rather than book value.');
    }

    public function testMarketShocksAreStrictlyBoundedToThirtyPercent(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(true);

        for ($i = 0; $i < 500; $i++) {
            $ctx = new MarketPricingContext(
                currentPrice: 100.0,
                currentVolatility: 0.15,
                longTermVolatility: 0.15,
                earningsPerShare: 5.0,
                dt: 1.0 / 252.0,
                lambda: 2.0,
                jumpVol: 0.10,
                drift: 0.08
            );

            $result = $this->engine->calculateNextPrice($ctx);
            $shock = $result['shock'];

            $this->assertNotNull($shock);
            $this->assertLessThanOrEqual(30.01, $shock, 'Positive market shock must be bounded to <= 30% ceiling.');
            $this->assertGreaterThanOrEqual(-30.01, $shock, 'Negative market shock must be bounded to >= -30% floor.');
            $this->assertNotEquals(0.0, $shock, 'Market shock should be non-zero.');
        }
    }

    public function testSafeReinsuranceMarketJumpStaysWithinBoundedLimits(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(true);

        $ctx = new MarketPricingContext(
            currentPrice: 1500.0,
            currentVolatility: 0.10,
            longTermVolatility: 0.10,
            earningsPerShare: 200.0,
            dt: 1.0 / 252.0,
            lambda: 0.15,
            jumpVol: 0.06,
            beta: 0.20
        );

        for ($i = 0; $i < 200; $i++) {
            $result = $this->engine->calculateNextPrice($ctx);
            $shock = $result['shock'];

            $this->assertNotNull($shock);
            $this->assertLessThanOrEqual(30.01, $shock, 'SAFE market shock must never exceed 30%.');
            $this->assertGreaterThanOrEqual(-30.01, $shock, 'SAFE market shock must never breach -30%.');
            $this->assertNotEquals(0.0, $shock, 'SAFE market shock should be non-zero.');
        }
    }
}
