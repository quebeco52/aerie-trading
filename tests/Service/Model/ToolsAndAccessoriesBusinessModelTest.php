<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\ToolsAndAccessoriesBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ToolsAndAccessoriesBusinessModelTest extends TestCase
{
    private ToolsAndAccessoriesBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new ToolsAndAccessoriesBusinessModel();
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

        $mathMock = $this->createStub(MathUtility::class);
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

    /** @return iterable<string, array{string, float}> */
    public static function cyclicalities(): iterable
    {
        yield 'Crossbill, tuned to maintenance demand' => ['CBIL', 0.55];
        yield 'the sector default' => ['XTLS', ToolsAndAccessoriesBusinessModel::OPERATING_CYCLICALITY];
    }

    /**
     * The gap reaches each stream once, at the firm's cyclicality. It used to arrive three times: a root shift at
     * half cyclicality, the commercial stream at (0.90 + pricing power) x cyclicality, and the consumer stream again
     * through confidence, which carries SENTIMENT_GAP_LOADING points of gap: ~1.4x the gap for Crossbill, whose
     * tuning means 0.55x.
     */
    #[DataProvider('cyclicalities')]
    public function testTheGapReachesEachStreamOnceAtTheFirmsCyclicality(string $ticker, float $cyclicality): void
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setBeta('1.0');
        $quiet = $this->createStub(MathUtility::class);
        $expectedRevenue = 100_000_000.0;

        // Each gap comes with the confidence it implies, so the sentiment residual is nil and only the gap moves.
        $stateAt = fn (float $gap): MacroStateDTO => new MacroStateDTO(
            outputGapEma: $gap,
            consumerSentimentIndexEma: MacroEngine::SENTIMENT_TREND_LEVEL + (MacroEngine::SENTIMENT_GAP_LOADING * $gap),
            exchangeRateIndexEma: 100.0,
        );
        $response = function (float $gap) use ($stock, $quiet, $expectedRevenue, $stateAt): float {
            $root = $this->model->getMacroPhysics($stock, $stateAt($gap))['macro_demand_shift'];
            $streams = $this->model->computeActualFinancials($stock, $expectedRevenue, 0.35, 20_000_000.0, 0.10, $stateAt($gap), $quiet)->actualRevenue;

            return $root + ($streams / $expectedRevenue) - 1.0;
        };

        $gap = -0.03;
        $this->assertEqualsWithDelta($gap * $cyclicality, $response($gap) - $response(0.0), 1e-9);

        // The sticky cost base reads the same volume through the sector shift, the root none of it.
        $this->assertEqualsWithDelta(0.0, $this->model->getMacroPhysics($stock, $stateAt($gap))['macro_demand_shift'], 1e-12);
        $this->assertEqualsWithDelta(
            $gap * $cyclicality,
            $this->model->resolveSectorActivityShift($stock, $stateAt($gap)) - $this->model->resolveSectorActivityShift($stock, $stateAt(0.0)),
            1e-9
        );
    }

    /** Confidence beyond what the gap explains moves the prosumer stream alone, at its own sensitivity. */
    public function testConfidenceBeyondTheGapMovesOnlyTheConsumerStream(): void
    {
        $stock = new Stock();
        $stock->setTicker('XTLS');
        $stock->setBeta('1.0');
        $quiet = $this->createStub(MathUtility::class);

        $run = fn (float $sentiment) => $this->model->computeActualFinancials(
            $stock,
            100_000_000.0,
            0.35,
            20_000_000.0,
            0.10,
            new MacroStateDTO(outputGapEma: 0.0, consumerSentimentIndexEma: $sentiment, exchangeRateIndexEma: 100.0),
            $quiet
        );
        $trend = $run(MacroEngine::SENTIMENT_TREND_LEVEL);
        $gloomy = $run(MacroEngine::SENTIMENT_TREND_LEVEL - 10.0);

        $this->assertEqualsWithDelta($trend->streamRevenue['commercial'], $gloomy->streamRevenue['commercial'], 1e-6);
        $this->assertEqualsWithDelta(
            -0.10 * ToolsAndAccessoriesBusinessModel::CONSUMER_SENTIMENT_SENSITIVITY * ToolsAndAccessoriesBusinessModel::OPERATING_CYCLICALITY,
            ($gloomy->streamRevenue['consumer'] / $trend->streamRevenue['consumer']) - 1.0,
            1e-9
        );
    }
}
