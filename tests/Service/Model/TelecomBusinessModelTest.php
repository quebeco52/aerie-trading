<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\TelecomBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TelecomBusinessModelTest extends TestCase
{
    private TelecomBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new TelecomBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testDualStreamRevenueDecomposition(): void
    {
        $stock = new Stock();
        $stock->setTicker('TEL');
        $stock->setBeta('0.8');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.45,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $macro,
            mathUtility: $this->mathUtility
        );

        $this->assertArrayHasKey('wireless_subscriptions', $result->streamRevenue);
        $this->assertArrayHasKey('equipment_sales', $result->streamRevenue);

        // Baseline: 85M subscriptions, 15M equipment
        $this->assertEqualsWithDelta(85_000_000.0, $result->streamRevenue['wireless_subscriptions'], 1.0);
        $this->assertEqualsWithDelta(15_000_000.0, $result->streamRevenue['equipment_sales'], 1.0);
        $this->assertEqualsWithDelta(100_000_000.0, $result->actualRevenue, 1.0);
    }

    public function testPriceWarFatTailEventTriggersMarginPenalty(): void
    {
        $stock = new Stock();
        $stock->setTicker('TEL');
        $stock->setBeta('0.8');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02);

        $mathStub = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ'])
            ->getMock();

        // stream sequence: wireless_subscriptions Z = 0.0, equipment_sales Z = 0.0, event Z = -2.5 (price war)
        $mathStub->method('generatePersistentZ')
            ->willReturnOnConsecutiveCalls(0.0, 0.0, -2.5);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.45,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $mathStub
        );

        $this->assertSame(ShockEvent::TELECOM_PRICE_WAR, $result->eventType);
        $this->assertTrue($result->isPublicEvent);
    }

    public function testSpectrumAuctionShockEvent(): void
    {
        $stock = new Stock();
        $stock->setTicker('TEL');
        $stock->setBeta('0.8');

        $macro = new MacroStateDTO(outputGapEma: 0.0, inflationEma: 0.02);

        $mathStub = $this->getMockBuilder(MathUtility::class)
            ->onlyMethods(['generatePersistentZ'])
            ->getMock();

        // stream sequence: wireless_subscriptions Z = 0.0, equipment_sales Z = 0.0, event Z = 2.5 (spectrum auction)
        $mathStub->method('generatePersistentZ')
            ->willReturnOnConsecutiveCalls(0.0, 0.0, 2.5);

        $result = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.45,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $macro,
            mathUtility: $mathStub
        );

        $this->assertSame(ShockEvent::SPECTRUM_AUCTION, $result->eventType);
        $this->assertTrue($result->isPublicEvent);
    }

    public function testRefinancingWallDragUnderElevated10yYield(): void
    {
        $stock = new Stock();
        $stock->setTicker('TEL');
        $stock->setBeta('1.0');

        // High 10y Treasury yield at 8% (800 bps >> 4% fallback)
        $highYieldMacro = new MacroStateDTO(yield10yEma: 0.08, inflationEma: 0.02);
        $normalYieldMacro = new MacroStateDTO(yield10yEma: 0.04, inflationEma: 0.02);

        $mathStub = $this->createStub(MathUtility::class);
        $mathStub->method('generatePersistentZ')->willReturn(0.0);

        $resultHigh = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.40,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $highYieldMacro,
            mathUtility: $mathStub
        );

        $resultNormal = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.40,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.0,
            macroState: $normalYieldMacro,
            mathUtility: $mathStub
        );

        // High 10Y yield refinancing wall increases cost ratio and lowers EBIT
        $this->assertGreaterThan($resultNormal->clampedMargin, $resultHigh->clampedMargin);
        $this->assertLessThan($resultNormal->ebit, $resultHigh->ebit);
    }
}
