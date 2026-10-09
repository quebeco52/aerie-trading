<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\Company\Sectors;
use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\MacroDriverAttribution;
use App\Service\Math\MathUtility;
use App\Service\Model\BusinessModelInterface;
use App\Tests\Support\StockBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The report's stream drivers are measured by each model's own stream physics, re-run with one input neutral on the
 * quarter's own draws (StandardOperatingPhysicsTrait::measureStreamMacroEffects). That is only a measurement if the
 * re-run reproduces the quarter exactly wherever the input does not reach, which is what these pin for every model.
 */
class StreamMacroAttributionTest extends TestCase
{
    private const EXPECTED_REVENUE = 10_000_000_000.0;
    private const VARIABLE_MARGIN = 0.60;
    private const FIXED_COSTS = 2_000_000_000.0;
    private const BASELINE_VOL = 0.25;

    /** @return iterable<string, array{string, string}> */
    public static function modelProvider(): iterable
    {
        foreach (array_keys(Sectors::BUSINESS_MODELS) as $identifier) {
            $industry = 'General';
            foreach (Sectors::INDUSTRY_METRICS as $candidate => $metrics) {
                if ($metrics['business_model'] === $identifier) {
                    $industry = $candidate;
                    break;
                }
            }
            yield $identifier => [$identifier, $industry];
        }
    }

    private function firm(string $industry): Stock
    {
        $stock = StockBuilder::create('ATTR')
            ->withPrice(80.0)
            ->withSharesOutstanding(1_000_000_000)
            ->withTotalEquity(50_000_000_000.0)
            ->withWholesaleDebt(10_000_000_000.0)
            ->withCorporateTreasury(5_000_000_000.0)
            ->withVolatility(self::BASELINE_VOL)
            ->build()
            ->setIndustry($industry);
        $stock->setTotalRevenue('40000000000');
        $stock->setOperatingMargin('0.15');
        $stock->setFixedCostRatio(0.35);

        return $stock;
    }

    /** A quarter well away from steady state, so every channel a model has is open. */
    private function stressedMacro(): MacroStateDTO
    {
        return new MacroStateDTO(
            outputGapEma: 0.03,
            inflationEma: 0.035,
            yield2yEma: 0.035,
            yield10yEma: 0.045,
            macroCreditSpreadEma: 0.03,
            marketVolatilityEma: 0.35,
            energyPriceIndexEma: 130.0,
        );
    }

    /**
     * @return array{0: Stock, 1: ActualFinancialsDTO, 2: array{uniforms: list<float>, spare: ?float}}
     */
    private function runQuarter(BusinessModelInterface $model, Stock $stock, MacroStateDTO $macroState): array
    {
        mt_srand(17);
        $math = new MathUtility();
        $basis = clone $stock;

        $math->beginDrawLog();
        $actual = $model->computeActualFinancials($stock, self::EXPECTED_REVENUE, self::VARIABLE_MARGIN, self::FIXED_COSTS, self::BASELINE_VOL, $macroState, $math);

        return [$basis, $actual, $math->endDrawLog()];
    }

    /** An input the model does not read cannot move any of its streams: the re-run reproduces the quarter exactly. */
    #[DataProvider('modelProvider')]
    public function testTheReplayReproducesTheQuarterWhereTheInputDoesNotReach(string $identifier, string $industry): void
    {
        $model = Sectors::getBusinessModelStrategy($identifier);
        $macroState = $this->stressedMacro();
        [$basis, $actual] = $this->runQuarter($model, $this->firm($industry), $macroState);
        [, , $draws] = $this->runQuarter($model, $this->firm($industry), $macroState);

        $unread = array_values(array_diff(['primary_deficit_to_gdp', 'sovereign_risk_spread_ema', 'foreign_policy_rate_ema'], $model->getOperatingMacroFields()))[0];
        $state = MacroStateDTO::fromArray(array_replace($macroState->toArray(), [$unread => 0.05]));

        $effects = $model->measureStreamMacroEffects($basis, $draws, [$unread => [$state, self::EXPECTED_REVENUE]], self::VARIABLE_MARGIN, self::FIXED_COSTS, self::BASELINE_VOL, $actual->streamRevenue);

        foreach ($actual->streamRevenue as $stream => $revenue) {
            if ($revenue > 0.0) {
                $this->assertSame(0.0, $effects[$stream][$unread] ?? null, "{$identifier}/{$stream} moved under an input it does not read.");
            }
        }
    }

    /** Every input the model declares is measured on the streams it actually reports, as a finite share. */
    #[DataProvider('modelProvider')]
    public function testEveryDeclaredInputIsMeasuredOnTheModelsOwnStreams(string $identifier, string $industry): void
    {
        $model = Sectors::getBusinessModelStrategy($identifier);
        $macroState = $this->stressedMacro();
        [$basis, $actual, $draws] = $this->runQuarter($model, $this->firm($industry), $macroState);

        $counterfactuals = [];
        foreach (MacroDriverAttribution::counterfactualStates($model, $macroState) as $field => $state) {
            $counterfactuals[$field] = [$state, self::EXPECTED_REVENUE];
        }

        $effects = $model->measureStreamMacroEffects($basis, $draws, $counterfactuals, self::VARIABLE_MARGIN, self::FIXED_COSTS, self::BASELINE_VOL, $actual->streamRevenue);
        // A model none of whose inputs the quarter moves has nothing to attribute.
        $this->assertSame($counterfactuals === [], $effects === [] && $counterfactuals === []);

        foreach ($effects as $stream => $byField) {
            $this->assertArrayHasKey($stream, $actual->streamRevenue, "{$identifier} measured a stream it does not report.");
            foreach ($byField as $field => $effect) {
                $this->assertContains($field, $model->getOperatingMacroFields());
                $this->assertTrue(is_finite($effect), "{$identifier}/{$stream}/{$field} is not finite.");
            }
        }
    }

    /** A channel the model applies to one stream shows on that stream and no other: Tech's ad budgets follow the cycle. */
    public function testAdvertisingMovesWithTheCycleAndSubscriptionsDoNot(): void
    {
        $model = Sectors::getBusinessModelStrategy('tech');
        $macroState = $this->stressedMacro();
        [$basis, $actual, $draws] = $this->runQuarter($model, $this->firm('Software - Infrastructure'), $macroState);
        $neutral = MacroStateDTO::fromArray(array_replace($macroState->toArray(), ['output_gap_ema' => 0.0]));

        $effects = $model->measureStreamMacroEffects($basis, $draws, ['output_gap_ema' => [$neutral, self::EXPECTED_REVENUE]], self::VARIABLE_MARGIN, self::FIXED_COSTS, self::BASELINE_VOL, $actual->streamRevenue);

        $this->assertGreaterThan(0.0, $effects['advertising']['output_gap_ema']);
        $this->assertSame(0.0, $effects['subscription']['output_gap_ema']);
    }
}
