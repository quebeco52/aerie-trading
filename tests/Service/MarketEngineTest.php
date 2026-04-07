<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use App\Service\MarketEngine;
use App\Service\MathUtility;
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

        $currentPrice = 100.0;
        $currentVolatility = 0.2;
        $longTermVolatility = 0.2;
        $earningsPerShare = 5.0;
        $targetPE = 20.0; // Fair value = 100
        $dt = 1.0;

        // Using PHP 8 named arguments ensures changes to signature orders do not break tests
        $result = $this->engine->calculateNextPrice(
            currentPrice: $currentPrice,
            currentVolatility: $currentVolatility,
            longTermVolatility: $longTermVolatility,
            earningsPerShare: $earningsPerShare,
            targetPE: $targetPE,
            dt: $dt,
            lambda: 0.0, // lambda = 0 means NO jump
            drift: 0.1
        );

        $this->assertIsArray($result);
        $this->assertNull($result['shock'], 'Shock should be null when no jump occurs.');
        
        // The variance math: dv = kappa * (long - current) * dt + volOfVol * current * w2
        // Since current == long, and w2 == 0, dv = 0. Volatility remains unchanged.
        $this->assertEquals($currentVolatility, $result['next_volatility']);
        
        // The price math:
        // fairValue = 100
        // logFairValue = log(100), logCurrent = log(100) -> gravityDrift = 0
        // CAPM drift = 0.020 (risk-free) + 0.1 * 1.0 (beta) = 0.12
        // gbmExponent = (0.12 + 0 - 0.5 * 0.04) * 1.0 = 0.10
        // price = 100 * exp(0.10)
        $expectedPrice = 100.0 * exp(0.10);
        $this->assertEqualsWithDelta($expectedPrice, $result['price'], 0.0001);
    }

    public function testCalculateNextPriceWithGuaranteedJump()
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(true);

        $currentPrice = 100.0;

        // Force a jump by setting lambda very high
        $result = $this->engine->calculateNextPrice(
            currentPrice: $currentPrice,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 5.0,
            targetPE: 20.0,
            dt: 1.0,
            lambda: 1000.0, // massive lambda guarantees mt_rand check triggers
            jumpMean: 0.05,
            jumpVol: 0.0, // no noise
            drift: 0.1
        );

        $this->assertNotNull($result['shock'], 'Shock should occur due to high lambda.');
        
        $expectedGbmPrice = 100.0 * exp(0.10); // Baseline drift from previous test
        $expectedJumpPrice = $expectedGbmPrice * exp(0.05);

        $this->assertEqualsWithDelta($expectedJumpPrice, $result['price'], 0.0001);
        $this->assertEqualsWithDelta((exp(0.05) - 1) * 100, $result['shock'], 0.0001);
        
        // Volatility increases by abs(jumpExponent) * 1.5
        $this->assertEqualsWithDelta(0.275, $result['next_volatility'], 0.0001);
    }
    
    public function testReversionToFairValue()
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);
        $this->mathUtilityMock->method('checkProbability')->willReturn(false);

        // Undervalued stock
        $currentPrice = 50.0;
        $reversionSpeed = 0.5;
        
        // Lambda 0.0 isolates the jump, drift 0.0 isolates normal growth
        $result = $this->engine->calculateNextPrice(
            currentPrice: $currentPrice,
            currentVolatility: 0.2,
            longTermVolatility: 0.2,
            earningsPerShare: 5.0,
            targetPE: 20.0,
            dt: 1.0,
            lambda: 0.0,
            drift: 0.0,
            reversionSpeed: $reversionSpeed
        );

        // gravityDrift = 0.5 * (log(100) - log(50)) = 0.5 * log(2)
        // CAPM drift = 0.020 + (0.0 * 1.0) = 0.020
        // gbmExponent = (0.020 + gravityDrift - 0.02) * 1.0 = gravityDrift
        $expectedPrice = 50.0 * exp(0.5 * log(2));
        
        $this->assertEqualsWithDelta($expectedPrice, $result['price'], 0.0001);
        $this->assertGreaterThan($currentPrice, $result['price'], 'Undervalued price should drift upwards towards fair value.');
    }
}