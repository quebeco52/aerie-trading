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

    /** Only the inputs the model reads, and only those the quarter has moved, get a counterfactual; each moves just its own input. */
    public function testEachCounterfactualNeutralisesOneMovedInput(): void
    {
        $model = Sectors::getBusinessModelStrategy('tech');
        $macroState = new MacroStateDTO(outputGapEma: 0.03);

        $states = MacroDriverAttribution::counterfactualStates($model, $macroState);

        $this->assertArrayHasKey('output_gap_ema', $states);
        $this->assertSame([], array_diff(array_keys($states), $model->getOperatingMacroFields()));

        $actual = $macroState->toArray();
        $changed = array_keys(array_diff_assoc(array_map('serialize', $states['output_gap_ema']->toArray()), array_map('serialize', $actual)));
        $this->assertSame(['output_gap_ema'], $changed);
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
