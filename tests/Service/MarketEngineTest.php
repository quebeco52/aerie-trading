<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use App\Service\Market\MarketEngine;
use App\Service\Math\MathUtility;
use App\DTO\MarketPricingContext;
use App\DTO\MacroStateDTO;
use PHPUnit\Framework\MockObject\MockObject;

class MarketEngineTest extends TestCase
{
    private MathUtility|MockObject $mathUtilityMock;
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
        
        $this->assertEqualsWithDelta(0.1951, $result['next_volatility'], 0.001);
        
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
}