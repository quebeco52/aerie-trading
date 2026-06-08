<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use App\Service\MacroEngine;
use App\Service\MathUtility;
use App\Data\SectorPE;
use App\Data\EconomicCycle;

#[AllowMockObjectsWithoutExpectations]
class MacroEngineTest extends TestCase
{
    private MathUtility|MockObject $mathUtilityMock;
    private \Redis|MockObject $redisMock;
    private MacroEngine $engine;

    protected function setUp(): void
    {
        // Use a partial mock so the actual math methods run, but we can control randomness
        $this->mathUtilityMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generateStandardNormal', 'checkProbability'])
            ->getMock();
        $this->redisMock = $this->createMock(\Redis::class);

        // Bypass the constructor to avoid making an actual Redis connection
        $reflection = new \ReflectionClass(MacroEngine::class);
        $this->engine = $reflection->newInstanceWithoutConstructor();

        // Inject our mocked Redis and MathUtility via reflection
        $redisProperty = $reflection->getProperty('redis');
        $redisProperty->setValue($this->engine, $this->redisMock);

        $mathProperty = $reflection->getProperty('mathUtility');
        $mathProperty->setValue($this->engine, $this->mathUtilityMock);
    }

    public function testMacroEngineCanBeInstantiated()
    {
        $this->assertInstanceOf(MacroEngine::class, $this->engine);
    }

    public function testUpdateMacroStateInitializesFromEmptyRedis()
    {
        // Simulate an empty Redis cache
        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(false);

        // Expect the engine to save the new state to Redis
        $this->redisMock->expects($this->once())
            ->method('set')
            ->with('macroeconomic_state', $this->callback(fn($val) => is_string($val)));

        // Neutral shocks
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $result = $this->engine->updateMacroState(1.0);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('inflation', $result);
        $this->assertArrayHasKey('output_gap', $result);
        $this->assertArrayHasKey('policy_rate', $result);
        $this->assertArrayHasKey('yield_10y', $result);
        
        // Starting in a 0.02 boom pulls the initial target rate up, raising the policy rate from 0.02 to ~0.04
        $this->assertEqualsWithDelta(0.04, $result['policy_rate'], 0.01);
        $this->assertEqualsWithDelta(0.02, $result['inflation'], 0.01);
    }

    public function testUpdateMacroStateWithExistingStateAndRecessionShock()
    {
        $existingState = [
            'inflation' => 0.02,
            'output_gap' => 0.00,
            'policy_rate' => 0.04,
            'inflation_ema' => 0.02,
            'output_gap_ema' => 0.00,
            'policy_rate_ema' => 0.04,
            'corporate_tax_rate' => 0.21,
            'nominal_gdp_index' => 1.0,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($existingState));

        // Force a severe negative shock to output gap and inflation
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(-2.0);

        $result = $this->engine->updateMacroState(0.25); // Advance by 1 quarter

        $this->assertIsArray($result);
        
        // Output gap should drop below 0 due to the negative shock
        $this->assertLessThan(0.0, $result['output_gap'], 'Recession shock should drive output gap negative.');
        
        // Because output gap is negative, ERP should increase
        $this->assertGreaterThan(MacroEngine::BASE_EQUITY_RISK_PREMIUM, $result['equity_risk_premium'], 'ERP should rise during a recession.');
    }
    
    public function testUpdateMacroStateDuringSevereInflationBoom()
    {
        $existingState = [
            'inflation' => 0.08, // Massive 8% inflation
            'output_gap' => 0.05, // 5% positive output gap (overheated)
            'policy_rate' => 0.05, 
            'inflation_ema' => 0.07,
            'output_gap_ema' => 0.04,
            'policy_rate_ema' => 0.05,
            'corporate_tax_rate' => 0.21,
            'nominal_gdp_index' => 1.10,
        ];

        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macroeconomic_state')
            ->willReturn(json_encode($existingState));
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(1.0);

        // Advance by a smaller time step (0.05) so the economy has time to hike taxes
        $result = $this->engine->updateMacroState(0.05);

        $this->assertIsArray($result);
        
        // Central bank should aggressively hike target rates
        $this->assertGreaterThan(0.05, $result['target_rate'], 'Central Bank should aggressively hike rates during an inflationary boom.');
        
        // Corporate tax rate should increase to cool the economy
        $this->assertGreaterThan(MacroEngine::BASE_CORPORATE_TAX_RATE, $result['corporate_tax_rate'], 'Fiscal policy should hike taxes to cool an overheated economy.');
        
        // ERP should drop due to complacency in a boom
        $this->assertLessThan(MacroEngine::BASE_EQUITY_RISK_PREMIUM, $result['equity_risk_premium'], 'ERP should drop during an economic boom due to market complacency.');
    }
}