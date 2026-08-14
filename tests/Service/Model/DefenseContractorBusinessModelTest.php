<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\DefenseContractorBusinessModel;
use PHPUnit\Framework\TestCase;

class DefenseContractorBusinessModelTest extends TestCase
{
    public function testProgramExecutionElasticityImprovesVariableMargin(): void
    {
        $model = new DefenseContractorBusinessModel();
        $stock = new Stock();
        $stock->setTicker('GRIP');
        $stock->setBeta('0.7');

        $mathUtilityMock = $this->createMock(MathUtility::class);
        // domesticZ = 2.0 (strong sovereign execution), fmsZ = 0.0, eventZ = 0.0
        $mathUtilityMock->method('generatePersistentZ')
            ->willReturnOnConsecutiveCalls(2.0, 0.0, 0.0);

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

        $result = $model->computeActualFinancials(
            $stock,
            1000.0,
            0.65,
            50.0,
            0.15,
            $macroState,
            $mathUtilityMock
        );

        // Strong contract performance reduces cost overruns -> actual variable costs lower than baseline 65%
        $this->assertLessThan(1000.0 * 0.65, $result->actualVariableCosts);
    }

    public function testClassifiedToolingAndNextGenPlatformReinvestment(): void
    {
        $model = new DefenseContractorBusinessModel();

        // R = 0.5 -> Classified tooling tech debt
        $stock = new Stock();
        $stock->setOperatingMargin('0.14');
        $model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $decayed = (float) $stock->getOperatingMargin();
        $this->assertLessThan(0.14, $decayed);
        $this->assertGreaterThanOrEqual(DefenseContractorBusinessModel::MIN_OPERATING_MARGIN_FLOOR, $decayed);

        // R = 1.5 -> Next-gen defense platform modernization
        $stock->setOperatingMargin('0.14');
        $model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $expanded = (float) $stock->getOperatingMargin();
        $this->assertGreaterThan(0.14, $expanded);
        $this->assertLessThanOrEqual(DefenseContractorBusinessModel::MAX_OPERATING_MARGIN_CEILING, $expanded);
    }
}
