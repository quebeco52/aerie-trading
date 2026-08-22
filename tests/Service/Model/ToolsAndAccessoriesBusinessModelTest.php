<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ToolsAndAccessoriesBusinessModel;
use PHPUnit\Framework\TestCase;

class ToolsAndAccessoriesBusinessModelTest extends TestCase
{
    private ToolsAndAccessoriesBusinessModel $model;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->model = new ToolsAndAccessoriesBusinessModel();
        $this->mathUtility = new MathUtility();
    }

    public function testDualStreamEmissionAndFxMetalsSensitivity(): void
    {
        $stock = new Stock();
        $stock->setTicker('SWK');
        $stock->setBeta('1.1');

        $baseMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            exchangeRateIndexEma: 100.0,
            industrialMetalsIndexEma: 100.0,
            consumerSentimentIndexEma: 100.0
        );

        $shockMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            exchangeRateIndexEma: 120.0, // Strong currency export headwind
            industrialMetalsIndexEma: 140.0, // Raw steel/carbide cost drag
            consumerSentimentIndexEma: 100.0
        );

        $mathMock = $this->createMock(MathUtility::class);
        $mathMock->method('generatePersistentZ')->willReturn(0.0);

        $baseResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $baseMacro,
            mathUtility: $mathMock
        );

        $shockResult = $this->model->computeActualFinancials(
            $stock,
            expectedRevenue: 100_000_000.0,
            realizedVariableMargin: 0.35,
            fixedCosts: 20_000_000.0,
            baselineVol: 0.10,
            macroState: $shockMacro,
            mathUtility: $mathMock
        );

        $this->assertArrayHasKey('commercial', $baseResult->streamRevenue);
        $this->assertArrayHasKey('consumer', $baseResult->streamRevenue);

        // Commercial and consumer revenue drop under strong dollar export headwind
        $this->assertLessThan(
            $baseResult->streamRevenue['commercial'],
            $shockResult->streamRevenue['commercial']
        );
        $this->assertLessThan(
            $baseResult->streamRevenue['consumer'],
            $shockResult->streamRevenue['consumer']
        );

        // Variable cost margin expands (clampedMargin increases) under metals cost drag
        $this->assertGreaterThan(
            $baseResult->clampedMargin,
            $shockResult->clampedMargin
        );
    }
}
