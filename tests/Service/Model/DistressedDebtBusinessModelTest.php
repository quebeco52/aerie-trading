<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroEngine;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\DistressedDebtBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DistressedDebtBusinessModelTest extends TestCase
{
    private DistressedDebtBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new DistressedDebtBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testCreditSpreadBlowoutSurgesTurnaroundRevenue(): void
    {
        $stock = new Stock();
        $stock->setTicker('VULT');
        $stock->setBeta('-1.3');

        // High credit spread blowout regime (500 bps = 0.05) & severe recession (-3% output gap)
        $crisisMacro = new MacroStateDTO(
            outputGapEma: -0.03,
            macroCreditSpread: 0.05,
            inflationEma: 0.02
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.40,
            fixedCosts: 10_000_000.0,
            baselineVol: 0.0,
            macroState: $crisisMacro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('restructuring_advisory', $result->streamRevenue);
        $this->assertArrayHasKey('turnaround_recovery', $result->streamRevenue);

        // Turnaround recovery revenue must surge significantly above the baseline 60M allocation
        $this->assertGreaterThan(60_000_000.0, $result->streamRevenue['turnaround_recovery']);
        $this->assertGreaterThan(100_000_000.0, $result->actualRevenue);
    }

    public function testBullMarketTightCreditSpreadsCreateDryPowderDrag(): void
    {
        $stock = new Stock();
        $stock->setTicker('VULT');
        $stock->setBeta('-1.3');

        // Bull market with tight credit spreads (100 bps) and high positive output gap (+3%)
        $bullMacro = new MacroStateDTO(
            outputGapEma: 0.03,
            macroCreditSpread: 0.01,
            inflationEma: 0.02
        );

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.40,
            fixedCosts: 10_000_000.0,
            baselineVol: 0.0,
            macroState: $bullMacro,
            mathUtility: $this->mathUtility
        );

        // Revenue should contract slightly due to dry powder holding drag
        $this->assertLessThan(60_000_000.0, $result->streamRevenue['turnaround_recovery']);
        $this->assertLessThan(100_000_000.0, $result->actualRevenue);
    }

    public function testRestructuringShockEventTriggeredDuringCrisis(): void
    {
        $stock = new Stock();
        $stock->setTicker('VULT');
        $stock->setBeta('-1.3');

        $crisisMacro = new MacroStateDTO(
            outputGapEma: -0.02,
            macroCreditSpread: 0.04
        );

        $mathStub = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ'])
            ->getMock();

        // High positive recovery shock Z = 2.0 (triggers event)
        $mathStub->method('generatePersistentZ')->willReturn(2.0);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 50_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 5_000_000.0,
            baselineVol: 0.20,
            macroState: $crisisMacro,
            mathUtility: $mathStub
        );

        $this->assertSame(ShockEvent::DISTRESSED_DEBT_RESTRUCTURING, $result->eventType);
        $this->assertTrue($result->isPublicEvent);
    }

    public function testEvaluateHoardingStatusThresholds(): void
    {
        $operatingBase = 100_000_000.0;
        $targetCash = 20_000_000.0;

        // 1. Normal cash ($30M treasury -> $10M excess <= 40M threshold)
        $normal = $this->model->evaluateHoardingStatus(30_000_000.0, $targetCash, $operatingBase, 0.0);
        $this->assertFalse($normal['is_hoarder']);
        $this->assertFalse($normal['is_mega_hoarder']);

        // 2. Hoarder cash ($70M treasury -> $50M excess > 40M threshold)
        $hoarder = $this->model->evaluateHoardingStatus(70_000_000.0, $targetCash, $operatingBase, 0.0);
        $this->assertTrue($hoarder['is_hoarder']);
        $this->assertFalse($hoarder['is_mega_hoarder']);

        // 3. Mega hoarder cash ($100M treasury -> $80M excess > 70M threshold)
        $mega = $this->model->evaluateHoardingStatus(100_000_000.0, $targetCash, $operatingBase, 0.0);
        $this->assertTrue($mega['is_hoarder']);
        $this->assertTrue($mega['is_mega_hoarder']);
    }

    public function testHighYieldCreditSpreadAndDefaultRateSurgeBoostRecoveryRevenue(): void
    {
        $stock = new Stock();
        $stock->setTicker('VULT');
        $stock->setBeta('-1.3');

        $calmMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: MacroEngine::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: MacroEngine::BASE_CREDIT_SPREAD,
            highYieldCreditSpreadEma: MacroEngine::BASE_CREDIT_SPREAD * MacroEngine::HY_BASE_SPREAD_MULTIPLIER, // Baseline HY
            corporateDefaultRateEma: 0.020   // Baseline 2.0%
        );

        $hySpikeMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            macroCreditSpread: 0.02,
            macroCreditSpreadEma: 0.02,
            highYieldCreditSpreadEma: 0.088, // Blowout to 880 bps (+400 bps)
            corporateDefaultRateEma: 0.060   // Surging to 6.0% (+200% shift)
        );

        $mathMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ'])
            ->getMock();
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $calmResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.40, 10_000_000.0, 0.0, $calmMacro, $mathMock);
        $spikeResult = $this->model->computeActualFinancials($stock, 100_000_000.0, 0.40, 10_000_000.0, 0.0, $hySpikeMacro, $mathMock);

        $this->assertGreaterThan(
            $calmResult->streamRevenue['turnaround_recovery'],
            $spikeResult->streamRevenue['turnaround_recovery'],
            'Surging high-yield credit spreads and corporate default rates must explode distressed debt recovery revenue.'
        );
    }

    public function testExtremeCrisisSurgeIsStrictlyBoundedBySaturationCeiling(): void
    {
        $stock = new Stock();
        $stock->setTicker('VULT');
        $stock->setBeta('-1.3');

        // Apocalyptic crisis: 25% default rate, 1500 bps HY spreads, 800 bps credit spreads, -5% GDP gap
        $apocalypseMacro = new MacroStateDTO(
            outputGapEma: -0.05,
            macroCreditSpread: 0.08,
            macroCreditSpreadEma: 0.08,
            highYieldCreditSpreadEma: 0.150,
            corporateDefaultRateEma: 0.250
        );

        $mathMock = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ'])
            ->getMock();
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $expectedRevenue = 100_000_000.0;
        $result = $this->model->computeActualFinancials($stock, $expectedRevenue, 0.40, 10_000_000.0, 0.0, $apocalypseMacro, $mathMock);

        // Total actual revenue is strictly bounded:
        // actualRevenue = expectedRevenue * (w_advisory + w_recovery * (1 + distressMultiplier))
        // Since w_advisory + w_recovery = 1.0 and distressMultiplier <= MAX_DISTRESS_REVENUE_EXPANSION (1.25),
        // actual revenue cannot exceed expectedRevenue * (1 + 1.25) = 225M under any crisis condition.
        $absoluteMaxAllowed = $expectedRevenue * (1.0 + DistressedDebtBusinessModel::MAX_DISTRESS_REVENUE_EXPANSION);
        $this->assertLessThanOrEqual($absoluteMaxAllowed, $result->actualRevenue);
        $this->assertLessThanOrEqual($absoluteMaxAllowed, $result->streamRevenue['turnaround_recovery']);
        $this->assertGreaterThan($expectedRevenue, $result->actualRevenue);
    }
}
