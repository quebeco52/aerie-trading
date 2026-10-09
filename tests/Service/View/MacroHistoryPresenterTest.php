<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Data\Macro\OutputGapChannels;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\OutputGapProbe;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Math\MathUtility;
use App\Service\View\MacroHistoryPresenter;
use PHPUnit\Framework\TestCase;

class MacroHistoryPresenterTest extends TestCase
{
    /**
     * A window the engine itself closed over a few production ticks.
     *
     * @return array<string, mixed>
     */
    private function recordedWindow(): array
    {
        $probe = new OutputGapProbe();
        $probe->enable();
        $subsystem = new MacroAggregateSubsystem(new MathUtility(), $probe);

        $state = new MacroState();
        $state->inflation = MacroEngine::TARGET_INFLATION;
        $state->policyRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;

        $gap = 0.01;
        for ($tick = 0; $tick < 3; ++$tick) {
            $gap = $subsystem->calculateOutputGap($state, $gap, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 1.0 / 3600.0, 1.0);
        }
        $probe->rollWindow();

        return $probe->snapshot()['previous'];
    }

    public function testEveryFamilyHasAPageLabelAndTheResidualIsLast(): void
    {
        $this->assertSame(
            [...array_keys(OutputGapChannels::families()), 'other'],
            array_keys(MacroHistoryPresenter::GAP_GROUP_LABELS)
        );
    }

    public function testTheGroupsSumToTheQuartersChangeInTheGap(): void
    {
        $window = $this->recordedWindow();
        $breakdown = (new MacroHistoryPresenter())->gapBreakdown($window);

        $this->assertNotNull($breakdown);
        $this->assertSame(array_keys(MacroHistoryPresenter::GAP_GROUP_LABELS), array_keys($breakdown));
        $this->assertEqualsWithDelta($window['change'], array_sum($breakdown), 1e-12);
    }

    public function testRowsLoseTheInternalColumnsAndGainTheirQuarter(): void
    {
        $rows = (new MacroHistoryPresenter())->present([
            [
                'total_time' => '13.250000',
                'inflation_ema' => '0.0210',
                'gap_channels' => json_encode($this->recordedWindow()),
                'quarter_diagnostics' => '{}',
                'config_fingerprint' => 'abc',
                'ticks_per_year' => 3600,
            ],
            ['total_time' => '13.000000', 'gap_channels' => null],
            ['total_time' => null],
        ]);

        foreach (['gap_channels', 'quarter_diagnostics', 'config_fingerprint', 'ticks_per_year'] as $column) {
            $this->assertArrayNotHasKey($column, $rows[0]);
        }
        $this->assertSame('0.0210', $rows[0]['inflation_ema']);
        $this->assertIsArray($rows[0]['gap_breakdown']);

        // A row is written as its quarter closes: 13.25 closes Year 14's first quarter, 13.0 Year 13's last.
        $this->assertSame('Year 14 Q1', $rows[0]['quarter_label']);
        $this->assertSame('Year 13 Q4', $rows[1]['quarter_label']);
        $this->assertNull($rows[1]['gap_breakdown']);
        $this->assertNull($rows[2]['quarter_label']);
    }
}
