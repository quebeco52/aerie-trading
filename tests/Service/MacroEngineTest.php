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

    public function testMacroEngineCanBeInstantiated()
    {
        $this->assertInstanceOf(MacroEngine::class, $this->engine);
    }
}