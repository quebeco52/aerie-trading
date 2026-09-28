<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Families the output gap's drift channels are read in, and the colour each family wears.
 *
 * App\Service\Macro\Recorder\OutputGapProbe records a couple of dozen channels, and that many rows sorted by
 * size is a list rather than a reading: the eye has no way to tell that `crisisDeleveragingDrag`
 * and `lendingStandardsDrag` are the same story told twice, or that the largest single line and the
 * third largest are both the government. Grouping is what makes the panel answer "what kind of
 * thing is moving the economy" before it answers "which variable".
 *
 * Eight families, because the categorical palette below validates eight slots against a dark
 * surface on the adjacent pairlist that a stacked bar uses — worst adjacent CVD ΔE 8.4, worst
 * normal-vision ΔE 19.3, every slot over 3:1 on the surface. A ninth family would have to invent a
 * hue, so a new channel joins an existing family instead.
 *
 * The residual is deliberately NOT a family. Diffusion is not a demand channel and colouring it
 * like one would claim an economic story it does not have, so it carries the neutral and stacks
 * beside them.
 *
 * App\Tests\Data\OutputGapChannelsTest fails if a channel the probe records is in no family or in
 * two, so the panel cannot silently drop a channel the economy is using.
 */
final class OutputGapChannels
{
    // --- Categorical Palette (validated dark steps) ---

    /** Neutral for the diffusion residual: outside the categorical order, because it is not a channel. */
    public const RESIDUAL_COLOUR = '#8c8b80';

    /**
     * Family key => label, the channels it holds, and its categorical slot.
     *
     * Slot order is the palette's own order and is the colourblind-safety mechanism rather than a
     * cosmetic choice, so families are declared in it and never re-sorted into it.
     *
     * @var array<string, array{label: string, colour: string, channels: list<string>}>
     */
    private const FAMILIES = [
        'monetary' => [
            'label' => 'Monetary',
            'colour' => '#3987e5',
            'channels' => ['monetaryDrag'],
        ],
        'fiscal' => [
            'label' => 'Fiscal',
            'colour' => '#d95926',
            'channels' => ['fiscalStimulus', 'fundStabilisation', 'automaticStabiliser'],
        ],
        'disturbance' => [
            'label' => 'Demand disturbance',
            'colour' => '#199e70',
            'channels' => ['demandShock', 'demandDisaster', 'disasterCompensator'],
        ],
        'credit' => [
            'label' => 'Credit',
            'colour' => '#c98500',
            'channels' => [
                'creditFrictionDrag',
                'premiumDrag',
                'premiumCompensator',
                'crisisDeleveragingDrag',
                'crisisCompensator',
                'lendingStandardsDrag',
                'householdDeleveragingDrag',
            ],
        ],
        'wealth' => [
            'label' => 'Wealth effects',
            'colour' => '#d55181',
            'channels' => ['housingWealthEffect', 'equityWealthEffect'],
        ],
        'supply' => [
            'label' => 'Supply shocks',
            'colour' => '#008300',
            'channels' => ['energySupplyDrag', 'freightSupplyDrag', 'catastropheSupplyDrag', 'productivitySupply'],
        ],
        'external' => [
            'label' => 'External demand',
            'colour' => '#9085e9',
            'channels' => ['netExportDrag'],
        ],
        'capacity' => [
            'label' => 'Capacity & inventory',
            'colour' => '#e66767',
            'channels' => [
                'momentum',
                'cubicConstraint',
                'capitalDrag',
                'inventoryDrag',
                'policyUncertaintyDrag',
            ],
        ],
    ];

    /**
     * The families, in palette order, as the panel and the chart both read them.
     *
     * @return array<string, array{label: string, colour: string, channels: list<string>}>
     */
    public static function families(): array
    {
        return self::FAMILIES;
    }

    /**
     * The validated categorical slots, in palette order, for the panel's other stacked charts (inflation, the
     * policy target). Those charts draw their series from these slots in order, never cycled.
     *
     * @return list<string>
     */
    public static function palette(): array
    {
        return array_values(array_column(self::FAMILIES, 'colour'));
    }

    /**
     * Channel name => the family key that holds it.
     *
     * @return array<string, string>
     */
    public static function familyOf(): array
    {
        $map = [];
        foreach (self::FAMILIES as $key => $family) {
            foreach ($family['channels'] as $channel) {
                $map[$channel] = $key;
            }
        }

        return $map;
    }
}
