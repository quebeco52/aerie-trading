<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
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
}
