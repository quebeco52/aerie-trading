<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Service\Corporate\MacroDriverAttribution;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

class MacroDriverAttributionTest extends TestCase
{
    /** A mean-reverting input is neutral at the steady state the economy opens in. */
    public function testAStationaryInputIsNeutralAtTheSteadyState(): void
    {
        $actual = (new MacroStateDTO(outputGapEma: 0.03))->toArray();

        $this->assertSame((new MacroStateDTO())->toArray()['output_gap_ema'], MacroDriverAttribution::neutralReading('output_gap_ema', $actual));
    }

    /** A trending level is neutral at its trend, or every year of growth would read as the business cycle. */
    public function testATrendingLevelIsNeutralAtItsTrend(): void
    {
        $actual = (new MacroStateDTO(nominalGdpIndex: 1.8, potentialGdpIndex: 1.75, equityWealthRatio: 2.4e13, equityWealthTrend: 2.3e13))->toArray();

        $this->assertSame(1.75, MacroDriverAttribution::neutralReading('nominal_gdp_index', $actual));
        $this->assertSame(2.3e13, MacroDriverAttribution::neutralReading('equity_wealth_ratio', $actual));
        $this->assertNull(MacroDriverAttribution::neutralReading('equity_wealth_trend', $actual), 'A trend is a reference, never a driver.');
    }

    /** The IG spread is neutral at the decade trend its readers measure it against, not the base it is built from. */
    public function testTheCreditSpreadIsNeutralAtItsTrend(): void
    {
        $actual = (new MacroStateDTO(macroCreditSpread: 0.025, macroCreditSpreadEma: 0.024, macroCreditSpreadTrend: 0.018))->toArray();

        $this->assertSame(0.018, MacroDriverAttribution::neutralReading('macro_credit_spread', $actual));
        $this->assertSame(0.018, MacroDriverAttribution::neutralReading('macro_credit_spread_ema', $actual));
    }

    /**
     * Read beside its spot, the smoothed IG spread is the level and the spot the move: the level's counterfactual takes
     * the spot with it, and the move's holds the spot at the level. A model reading only one of the two measures it
     * against the trend.
     */
    public function testTheSmoothedSpreadAndItsSpotSplitIntoLevelAndMove(): void
    {
        $actual = (new MacroStateDTO(macroCreditSpread: 0.029, macroCreditSpreadEma: 0.025, macroCreditSpreadTrend: 0.015))->toArray();
        $both = ['macro_credit_spread', 'macro_credit_spread_ema'];

        $level = MacroDriverAttribution::counterfactualReadings('macro_credit_spread_ema', $actual, $both) ?? [];
        $this->assertSame(['macro_credit_spread_ema', 'macro_credit_spread'], array_keys($level));
        $this->assertEqualsWithDelta(0.015, $level['macro_credit_spread_ema'] ?? null, 1e-15);
        $this->assertEqualsWithDelta(0.019, $level['macro_credit_spread'] ?? null, 1e-15, 'The spot keeps its 40 bps over the smoothed spread.');

        $this->assertSame(['macro_credit_spread' => 0.025], MacroDriverAttribution::counterfactualReadings('macro_credit_spread', $actual, $both));
        $this->assertSame(['macro_credit_spread' => 0.015], MacroDriverAttribution::counterfactualReadings('macro_credit_spread', $actual, ['macro_credit_spread']));
        $this->assertSame(['macro_credit_spread_ema' => 0.015], MacroDriverAttribution::counterfactualReadings('macro_credit_spread_ema', $actual, ['macro_credit_spread_ema']));

        // A spread that has not moved off its level has no move to attribute.
        $plateau = (new MacroStateDTO(macroCreditSpread: 0.025, macroCreditSpreadEma: 0.025, macroCreditSpreadTrend: 0.015))->toArray();
        $this->assertNull(MacroDriverAttribution::counterfactualReadings('macro_credit_spread', $plateau, $both));
    }

    /** The HY and interbank spreads are neutral at their decade trends, not the bases they are built from. */
    public function testTheHighYieldAndInterbankSpreadsAreNeutralAtTheirTrends(): void
    {
        $actual = (new MacroStateDTO(
            interbankLiquiditySpreadEma: 0.004,
            interbankLiquiditySpreadTrend: 0.0026,
            highYieldCreditSpreadEma: 0.07,
            highYieldCreditSpreadTrend: 0.05,
        ))->toArray();

        $this->assertSame(0.0026, MacroDriverAttribution::neutralReading('interbank_liquidity_spread_ema', $actual));
        $this->assertSame(0.05, MacroDriverAttribution::neutralReading('high_yield_credit_spread_ema', $actual));
    }

    /**
     * Only the inputs the model reads, and only those the quarter has moved, get a counterfactual; each moves just its
     * own input.
     */
    public function testEachCounterfactualNeutralisesOneMovedInput(): void
    {
        $model = Sectors::getBusinessModelStrategy('tech');
        $macroState = new MacroStateDTO(outputGapEma: 0.03);

        $states = MacroDriverAttribution::counterfactualStates($model, $macroState);

        $this->assertArrayHasKey('output_gap_lag_6m', $states);
        $this->assertSame([], array_diff(array_keys($states), $model->getOperatingMacroFields()));

        $actual = $macroState->toArray();
        $changed = array_keys(array_diff_assoc(array_map('serialize', $states['output_gap_lag_6m']->toArray()), array_map('serialize', $actual)));
        $this->assertSame(['output_gap_lag_6m'], $changed);
    }

    /** A replay hands out the recorded draws and leaves the simulation's own generator where it was. */
    public function testAReplayReproducesTheDrawsWithoutTouchingTheGlobalGenerator(): void
    {
        mt_srand(99);
        $math = new MathUtility();
        $math->beginDrawLog();
        $drawn = [$math->generateStandardNormal(), $math->generateStandardNormal(), $math->generateUniform()];
        $log = $math->endDrawLog();

        $next = mt_rand();
        mt_srand(99);
        for ($i = 0; $i < count($log['uniforms']); $i++) {
            mt_rand();
        }

        $replay = MathUtility::replaying($log);
        $this->assertSame($drawn, [$replay->generateStandardNormal(), $replay->generateStandardNormal(), $replay->generateUniform()]);
        $this->assertSame($next, mt_rand(), 'The replay consumed the global generator.');
        $this->assertSame(MathUtility::REPLAY_EXHAUSTED_DRAW, $replay->generateUniform());
    }
}
