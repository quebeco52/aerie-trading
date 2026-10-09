<?php

declare(strict_types=1);

namespace App\Tests\Data\Macro;

use App\Data\Macro\OutputGapChannels;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\OutputGapProbe;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * The families are a second list of the drift's channels, and a second list goes stale.
 *
 * A channel added to App\Service\Macro\Subsystem\MacroAggregateSubsystem and not to a family would
 * vanish from the admin panel's table and from its chart while still moving the economy — the
 * decomposition would keep reconciling, because the probe's own arithmetic does not care how the
 * panel groups it. So the channels are taken from a real recorded window rather than written down
 * here, and the families are checked against what the engine actually produced.
 */
class OutputGapChannelsTest extends TestCase
{
    /**
     * Every channel one production tick records.
     *
     * @return list<string>
     */
    private function recordedChannels(): array
    {
        $probe = new OutputGapProbe();
        $probe->enable();
        $subsystem = new MacroAggregateSubsystem(new MathUtility(), $probe);

        $state = new MacroState();
        $state->inflation = MacroEngine::TARGET_INFLATION;
        $state->policyRate = MacroEngine::BASE_NATURAL_RATE + MacroEngine::TARGET_INFLATION;

        $subsystem->calculateOutputGap($state, 0.03, MacroEngine::BASE_NATURAL_RATE, MacroEngine::TARGET_INFLATION, 1.0 / 3600.0, 1.0);

        return array_keys($probe->snapshot()['current']['contributions']);
    }

    public function testEveryChannelTheEngineRecordsBelongsToExactlyOneFamily(): void
    {
        $grouped = [];
        foreach (OutputGapChannels::families() as $key => $family) {
            foreach ($family['channels'] as $channel) {
                $grouped[$channel] = ($grouped[$channel] ?? 0) + 1;
            }
        }

        foreach ($this->recordedChannels() as $channel) {
            $this->assertSame(
                1,
                $grouped[$channel] ?? 0,
                sprintf('%s is in %d families; every drift channel belongs to exactly one', $channel, $grouped[$channel] ?? 0)
            );
        }
    }

    public function testNoFamilyNamesAChannelTheEngineDoesNotRecord(): void
    {
        $recorded = $this->recordedChannels();

        foreach (OutputGapChannels::families() as $key => $family) {
            foreach ($family['channels'] as $channel) {
                $this->assertContains(
                    $channel,
                    $recorded,
                    sprintf('Family "%s" names %s, which no longer reaches the drift', $key, $channel)
                );
            }
        }
    }

    /**
     * Eight is the categorical palette's validated depth on the adjacent pairlist a stacked bar uses.
     * A ninth family would have to invent a hue, and an invented hue is the one thing the palette's
     * colourblind separation cannot be checked for.
     */
    public function testTheFamiliesFitTheValidatedPalette(): void
    {
        $families = OutputGapChannels::families();
        $colours = array_column($families, 'colour');

        $this->assertLessThanOrEqual(8, count($families));
        $this->assertSame($colours, array_unique($colours), 'Two families wearing one colour cannot be told apart');
        $this->assertNotContains(
            OutputGapChannels::RESIDUAL_COLOUR,
            $colours,
            'The residual is not a demand channel and must not wear a channel colour'
        );
    }

    public function testFamilyOfMapsEveryChannelBackToItsFamily(): void
    {
        $familyOf = OutputGapChannels::familyOf();

        foreach ($this->recordedChannels() as $channel) {
            $this->assertArrayHasKey($channel, $familyOf);
            $this->assertArrayHasKey($familyOf[$channel], OutputGapChannels::families());
        }
    }
}
