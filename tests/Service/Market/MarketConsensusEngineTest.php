<?php

declare(strict_types=1);

namespace App\Tests\Service\Market;

use App\DTO\ActualFinancialsDTO;
use App\DTO\ConsensusDTO;
use App\DTO\SectorCoverageProfile;
use App\Service\Market\MarketConsensusEngine;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MarketConsensusEngineTest extends TestCase
{
    private MathUtility|MockObject $mathUtilityMock;
    private MarketConsensusEngine $engine;

    protected function setUp(): void
    {
        $this->mathUtilityMock = $this->createMock(MathUtility::class);
        $this->engine = new MarketConsensusEngine();
    }

    public function testGenerateConsensusRoutine(): void
    {
        // Mock standard normal return of 0.0 -> zero analyst estimation error
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $actuals = new ActualFinancialsDTO(
            actualRevenue: 1100.0,
            actualVariableCosts: 440.0,
            clampedMargin: 0.40,
            ebit: 360.0,
            primaryShockZ: 1.0,
            observableShockZ: 0.10, // 10% positive observable shock
            eventType: null,
            eventContext: [],
            isPublicEvent: false
        );

        $coverage = new SectorCoverageProfile(
            baseVisibility: 0.50,
            errorStdDev: 0.05,
            minVisibility: 0.20
        );

        $consensus = $this->engine->generateConsensus($actuals, $coverage, 1000.0, $this->mathUtilityMock);

        $this->assertInstanceOf(ConsensusDTO::class, $consensus);
        $this->assertSame(0.50, $consensus->dynamicVisibility);
        // expectedRevenue * (1 + observableShockZ * dynamicVisibility) = 1000 * (1 + 0.10 * 0.50) = 1050.0
        $this->assertEqualsWithDelta(1050.0, $consensus->analystExpectedRevenue, 0.001);
        // analystExpectedVariableCosts = analystExpectedRevenue * clampedMargin = 1050 * 0.40 = 420.0
        $this->assertEqualsWithDelta(420.0, $consensus->analystExpectedVariableCosts, 0.001);
    }

    public function testGenerateConsensusClampingToMinVisibility(): void
    {
        // Mock extreme negative analyst error (-10.0 * 0.05 = -0.50)
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(-10.0);

        $actuals = new ActualFinancialsDTO(
            actualRevenue: 1000.0,
            actualVariableCosts: 300.0,
            clampedMargin: 0.30,
            ebit: 400.0,
            primaryShockZ: 0.0,
            observableShockZ: 0.20,
            eventType: null
        );

        $coverage = new SectorCoverageProfile(
            baseVisibility: 0.50,
            errorStdDev: 0.05,
            minVisibility: 0.25
        );

        $consensus = $this->engine->generateConsensus($actuals, $coverage, 1000.0, $this->mathUtilityMock);

        // baseVisibility (0.50) - 0.50 = 0.0, clamped to minVisibility (0.25)
        $this->assertSame(0.25, $consensus->dynamicVisibility);
        $this->assertEqualsWithDelta(1050.0, $consensus->analystExpectedRevenue, 0.001);
    }

    public function testGenerateConsensusClampingToMaxVisibility(): void
    {
        // Mock extreme positive analyst error (+10.0 * 0.05 = +0.50)
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(10.0);

        $actuals = new ActualFinancialsDTO(
            actualRevenue: 1000.0,
            actualVariableCosts: 300.0,
            clampedMargin: 0.30,
            ebit: 400.0,
            primaryShockZ: 0.0,
            observableShockZ: 0.10,
            eventType: null
        );

        $coverage = new SectorCoverageProfile(
            baseVisibility: 0.80,
            errorStdDev: 0.05,
            minVisibility: 0.20
        );

        $consensus = $this->engine->generateConsensus($actuals, $coverage, 1000.0, $this->mathUtilityMock);

        // baseVisibility (0.80) + 0.50 = 1.30, clamped to 1.0
        $this->assertSame(1.0, $consensus->dynamicVisibility);
        $this->assertEqualsWithDelta(1100.0, $consensus->analystExpectedRevenue, 0.001);
    }

    public function testGenerateConsensusEventConditionalForkWhenPublicEventIsTrue(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $actuals = new ActualFinancialsDTO(
            actualRevenue: 2000.0,
            actualVariableCosts: 400.0,
            clampedMargin: 0.20,
            ebit: 1000.0,
            primaryShockZ: 3.0,
            observableShockZ: 1.0,
            eventType: 'CLINICAL_TRIAL_SUCCESS',
            eventContext: [],
            isPublicEvent: true
        );

        // Biotech / ConsumerStaples style profile: routine visibility is low (0.10), but event visibility is high (0.90)
        $coverage = new SectorCoverageProfile(
            baseVisibility: 0.10,
            errorStdDev: 0.05,
            minVisibility: 0.0,
            eventBaseVisibility: 0.90,
            eventMinVisibility: 0.50
        );

        $consensus = $this->engine->generateConsensus($actuals, $coverage, 1000.0, $this->mathUtilityMock);

        $this->assertSame(0.90, $consensus->dynamicVisibility);
        // 1000 * (1 + 1.0 * 0.90) = 1900.0
        $this->assertEqualsWithDelta(1900.0, $consensus->analystExpectedRevenue, 0.001);
    }

    public function testGenerateConsensusUsesRoutineVisibilityWhenPublicEventIsFalse(): void
    {
        $this->mathUtilityMock->method('generateStandardNormal')->willReturn(0.0);

        $actuals = new ActualFinancialsDTO(
            actualRevenue: 1050.0,
            actualVariableCosts: 210.0,
            clampedMargin: 0.20,
            ebit: 600.0,
            primaryShockZ: 0.5,
            observableShockZ: 0.1,
            eventType: null,
            eventContext: [],
            isPublicEvent: false
        );

        $coverage = new SectorCoverageProfile(
            baseVisibility: 0.10,
            errorStdDev: 0.05,
            minVisibility: 0.0,
            eventBaseVisibility: 0.90,
            eventMinVisibility: 0.50
        );

        $consensus = $this->engine->generateConsensus($actuals, $coverage, 1000.0, $this->mathUtilityMock);

        $this->assertSame(0.10, $consensus->dynamicVisibility);
        // 1000 * (1 + 0.1 * 0.10) = 1010.0
        $this->assertEqualsWithDelta(1010.0, $consensus->analystExpectedRevenue, 0.001);
    }
}
