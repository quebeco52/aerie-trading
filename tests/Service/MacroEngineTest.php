<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use App\Service\MacroEngine;
use App\Service\MathUtility;
use App\Data\SectorPE;
use App\Data\EconomicCycle;

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

    public function testGetLiveSectorsReturnsDefaultsWhenRedisIsEmpty()
    {
        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macro_sectors_live')
            ->willReturn(false);

        // Expect it to seed the Redis cache with the baselines
        $this->redisMock->expects($this->once())
            ->method('set')
            ->with('macro_sectors_live', json_encode(SectorPE::MACRO_SECTORS));

        $result = $this->engine->getLiveSectors();

        $this->assertEquals(SectorPE::MACRO_SECTORS, $result);
    }

    public function testGetLiveSectorsReturnsRedisData()
    {
        $fakeData = ['Information Technology' => 30.0, 'Financials' => 10.0];
        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macro_sectors_live')
            ->willReturn(json_encode($fakeData));

        // Should not attempt to set defaults
        $this->redisMock->expects($this->never())->method('set');

        $result = $this->engine->getLiveSectors();

        $this->assertEquals($fakeData, $result);
    }

    public function testUpdateSectorMultiplesRevertsToMeanWithoutVolatility()
    {
        // Set standard normal to 0.0 to completely isolate the mean reversion (gravity)
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        // Current PE is 20.0, but MACRO_SECTORS baseline for 'Information Technology' is 24.0.
        $initialSectors = ['Information Technology' => 20.0];
        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macro_sectors_live')
            ->willReturn(json_encode($initialSectors));

        $dt = 1.0; // 1 year time step
        $result = $this->engine->updateSectorMultiples($dt, EconomicCycle::EXPANSION);

        $newPE = $result['Information Technology'];

        // Expected math: log(20) + 2.0 * (log(24 * 1.15) - log(20)) * 1.0
        $expectedLogPe = log(20.0) + 2.0 * (log(24.0 * 1.15) - log(20.0));
        $expectedPe = exp($expectedLogPe);

        $this->assertEqualsWithDelta($expectedPe, $newPE, 0.0001);
        $this->assertGreaterThan(20.0, $newPE, 'PE should have drifted upwards towards the 27.6 baseline.');
    }

    public function testUpdateSectorMultiplesAppliesVolatilityDrift()
    {
        // Set Z to 1.0 to trigger an upward volatility shock
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(1.0);

        // Start exactly AT the target PE to isolate the volatility (gravity becomes 0)
        $baseline = SectorPE::MACRO_SECTORS['Financials']; // 14.0
        $targetPe = $baseline * 1.15; // 1.15 is the EXPANSION cycle modifier
        $initialSectors = ['Financials' => $targetPe];
        $this->redisMock->expects($this->once())
            ->method('get')
            ->with('macro_sectors_live')
            ->willReturn(json_encode($initialSectors));

        $dt = 1.0;
        $result = $this->engine->updateSectorMultiples($dt, EconomicCycle::EXPANSION);

        $newPE = $result['Financials'];

        // Volatility drift math: macroVol(0.20) * sqrt(1.0) * Z(1.0) = 0.20
        $expectedLogPe = log($targetPe) + 0.20;
        $expectedPe = exp($expectedLogPe);

        $this->assertEqualsWithDelta($expectedPe, $newPE, 0.0001);
        $this->assertGreaterThan($targetPe, $newPE, 'PE should spike due to positive Z score shock.');
    }
}