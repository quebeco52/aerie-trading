<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\NarrativeEngine;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\SecurityProtectionBusinessModel;
use PHPUnit\Framework\TestCase;

class SecurityProtectionBusinessModelTest extends TestCase
{
    private SecurityProtectionBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new SecurityProtectionBusinessModel();
    }

    public function testTacticalFailureShockTriggersSecurityBreachEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('SEC');
        $stock->setBeta('1.0');

        $mathUtilityMock = $this->createMock(MathUtility::class);
        // govZ = 0.0, retainerZ = 0.0, expeditionaryZ = 0.0, eventZ = -3.0 (triggers tactical failure < -2.50)
        $mathUtilityMock->method('generatePersistentZ')
            ->willReturnOnConsecutiveCalls(0.0, 0.0, 0.0, -3.0);

        $macroState = new MacroStateDTO(
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

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.60,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock
        );

        $this->assertEquals(ShockEvent::SECURITY_BREACH, $result->eventType);
        $this->assertTrue($result->isPublicEvent);
    }

    public function testGeopoliticalConflictShockTriggersConflictEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('SEC');
        $stock->setBeta('1.0');

        $mathUtilityMock = $this->createMock(MathUtility::class);
        // govZ = 0.0, retainerZ = 0.0, expeditionaryZ = 0.0, eventZ = 3.0 (triggers conflict > 2.40)
        $mathUtilityMock->method('generatePersistentZ')
            ->willReturnOnConsecutiveCalls(0.0, 0.0, 0.0, 3.0);

        $macroState = new MacroStateDTO(
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

        $result = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.60,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock
        );

        $this->assertEquals(ShockEvent::GEOPOLITICAL_CONFLICT, $result->eventType);
        $this->assertTrue($result->isPublicEvent);
    }

    public function testGovernmentSpendingExpansionBoostsGovernmentContractRevenue(): void
    {
        $stock = new Stock();
        $stock->setTicker('GRIP');
        $stock->setBeta('1.0');

        $mathUtility = new MathUtility();

        $baselineMacro = new MacroStateDTO(governmentSpendingIndexEma: 100.0);
        $expansionMacro = new MacroStateDTO(governmentSpendingIndexEma: 150.0);

        $baselineResult = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            50.0,
            0.0,
            $baselineMacro,
            $mathUtility
        );

        $expansionResult = $this->model->computeActualFinancials(
            $stock,
            1000.0,
            0.50,
            50.0,
            0.0,
            $expansionMacro,
            $mathUtility
        );

        $this->assertGreaterThan(
            $baselineResult->streamRevenue['government_contracts'],
            $expansionResult->streamRevenue['government_contracts'],
            'Fiscal appropriations and government spending expansion must increase government contract revenue.'
        );
    }
}
