<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\NarrativeEngine;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\InternetRetailBusinessModel;
use PHPUnit\Framework\TestCase;

class InternetRetailBusinessModelTest extends TestCase
{
    private InternetRetailBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new InternetRetailBusinessModel();
    }

    private function createMacroStateDTO(): MacroStateDTO
    {
        return new MacroStateDTO(
            outputGap: 0.0,
            outputGapEma: 0.0,
            unemploymentRate: 0.04,
            unemploymentRateEma: 0.04,
            energyPriceIndex: 100.0,
            energyPriceIndexEma: 100.0,
            energyPriceShock: 0.0,
            consumerSentimentIndex: 100.0,
            consumerSentimentIndexEma: 100.0,
            inflation: 0.02,
            inflationEma: 0.02,
            policyRate: 0.04,
            policyRateEma: 0.04,
            targetRate: 0.04,
            yield2y: 0.04,
            yield2yEma: 0.04,
            yield5y: 0.04,
            yield5yEma: 0.04,
            yield10y: 0.04,
            yield10yEma: 0.04,
            yield30y: 0.04,
            yield30yEma: 0.04,
            marketVolatility: 0.15,
            marketVolatilityEma: 0.15,
            marketZ: 0.0,
            corporateTaxRate: 0.21,
            equityRiskPremium: 0.05,
            macroCreditSpread: 0.015,
            macroCreditSpreadEma: 0.015,
            qeActive: false,
            qeIntensity: 0.0,
            inversionDuration: 0.0,
            nsLevel: 0.04,
            nsSlope: 0.0,
            nsSlopeEma: 0.0,
            nsCurvature: 0.0,
            potentialGdpIndex: 1.0,
            nominalGdpIndex: 1.0,
        );
    }

    public function testWarehouseLaborStrikeShockTriggersLaborStrikeEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('AMZN_MOCK');
        $stock->setBeta('1.0');

        $mathUtilityMock = $this->createMock(MathUtility::class);
        // fpZ = 0.0, tpZ = 0.0, adsZ = 0.0, eventZ = -2.30 (< WAREHOUSE_STRIKE_Z_SCORE -2.20)
        $mathUtilityMock->method('generatePersistentZ')
            ->willReturnOnConsecutiveCalls(0.0, 0.0, 0.0, -2.30);

        $macroState = $this->createMacroStateDTO();

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.60,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock
        );

        $this->assertSame(ShockEvent::LABOR_STRIKE, $result->eventType);
        $this->assertTrue($result->isPublicEvent);
    }

    public function testAntitrustFineShockTriggersRegulatoryFineEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('AMZN_MOCK');
        $stock->setBeta('1.0');

        $mathUtilityMock = $this->createMock(MathUtility::class);
        // fpZ = 0.0, tpZ = 0.0, adsZ = 0.0, eventZ = -2.70 (< ANTITRUST_FINE_Z_SCORE -2.60)
        $mathUtilityMock->method('generatePersistentZ')
            ->willReturnOnConsecutiveCalls(0.0, 0.0, 0.0, -2.70);

        $macroState = $this->createMacroStateDTO();

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.60,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock
        );

        $this->assertSame(ShockEvent::REGULATORY_FINE, $result->eventType);
        $this->assertTrue($result->isPublicEvent);
        $this->assertLessThan(1000.0, $result->actualRevenue);
    }

    public function testViralHolidaySurgeShockTriggersViralGrowthEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('AMZN_MOCK');
        $stock->setBeta('1.0');

        $mathUtilityMock = $this->createMock(MathUtility::class);
        // fpZ = 0.0, tpZ = 0.0, adsZ = 0.0, eventZ = 2.50 (> VIRAL_HOLIDAY_SURGE_Z 2.40)
        $mathUtilityMock->method('generatePersistentZ')
            ->willReturnOnConsecutiveCalls(0.0, 0.0, 0.0, 2.50);

        $macroState = $this->createMacroStateDTO();

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.60,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock
        );

        $this->assertSame(ShockEvent::VIRAL_GROWTH, $result->eventType);
        $this->assertTrue($result->isPublicEvent);
        $this->assertGreaterThan(1000.0, $result->actualRevenue);
    }

    public function testNarrativeEngineGeneratesLoreForLaborStrike(): void
    {
        $narrativeEngine = new NarrativeEngine();

        $lore = $narrativeEngine->generateLore(ShockEvent::LABOR_STRIKE);
        $this->assertNotEmpty($lore);
        $this->assertNotSame('Experienced an unexpected market event.', $lore);
    }
}
