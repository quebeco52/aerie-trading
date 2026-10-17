<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Politics\AerieDiet;

/**
 * How the government pages draw and name the Diet: one colour and a short name a party, and each policy question's
 * name and ends.
 */
final class DietStyle
{
    // --- Party Colours ---
    /**
     * One colour per party, shared by the hemicycle, the compass and the tables, no two hues close enough to confuse.
     */
    public const PARTY_COLORS = [
        AerieDiet::CIVIC => '#dc2626',
        AerieDiet::VANGUARD => '#2563eb',
        AerieDiet::IRON_HARBOR => '#78716c',
        AerieDiet::EXCHANGE => '#0891b2',
        AerieDiet::CHARTISTS => '#7c3aed',
        AerieDiet::COMMON_LOT => '#d97706',
        AerieDiet::TIDELINE => '#16a34a',
        AerieDiet::NEW_HORIZON => '#ec4899',
    ];

    // --- Party Labels ---
    /** Short names for the drawings and narrow columns, where the full names would crowd each other. */
    public const PARTY_LABELS = [
        AerieDiet::CIVIC => 'Civic',
        AerieDiet::VANGUARD => 'Vanguard',
        AerieDiet::IRON_HARBOR => 'Iron Harbor',
        AerieDiet::EXCHANGE => 'Exchange',
        AerieDiet::CHARTISTS => 'Chartists',
        AerieDiet::COMMON_LOT => 'Common Lot',
        AerieDiet::TIDELINE => 'Tideline',
        AerieDiet::NEW_HORIZON => 'New Horizon',
    ];

    // --- Policy Questions ---
    /** The policy questions as the pages name them: the axis, and its two ends. */
    public const AXIS_NAMES = [
        AerieDiet::AXIS_STATE => ['name' => 'Size of the state', 'low' => 'Small state', 'high' => 'Big state'],
        AerieDiet::AXIS_OPENNESS => ['name' => 'Openness', 'low' => 'Closed', 'high' => 'Open'],
        AerieDiet::AXIS_COUNCIL => ['name' => 'The Council', 'low' => 'Populist', 'high' => 'Technocratic'],
        AerieDiet::AXIS_ENVIRONMENT => ['name' => 'The environment', 'low' => 'Growth first', 'high' => 'Environment first'],
    ];
}
