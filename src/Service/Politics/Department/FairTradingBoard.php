<?php

declare(strict_types=1);

namespace App\Service\Politics\Department;

use App\Service\Politics\PoliticsState;

/**
 * The Fair Trading Board: a head the Council appoints for one fixed term, who sets the screens of horizontal merger
 * review (App\Service\Corporate\Deal\MergerReview::reviewScreens()), as an agency's own guidelines. Each
 * candidate holds a stance on competition, from light (-1) to strict (+1), drawn uniformly between the US agencies'
 * 2010 guidelines (light) and their 2023 ones (strict). The Council names the candidate nearest its median stance on
 * competition; the head sitting at Year 1 keeps the screens the District opened with.
 */
final class FairTradingBoard
{
    // --- The Guidelines ---
    /**
     * Leniency of merger review at Year 1, from the 2023 guidelines (0) to the 2010 ones (1): the founding Vanguard
     * cabinet's 0.3 on the axis from the Common Lot (-0.8) to the Chartists (0.8).
     */
    public const FOUNDING_LENIENCY = 0.6875;

    /**
     * One tick, after the Council's own: seats a head when the post is vacant and at each term's end (Office::fill()),
     * and keeps the stance of the head before them, whose screens theirs replaced (at the opening, their own).
     *
     * @param PoliticsState $state Advanced in place; its totalTime already this tick's.
     */
    public static function advance(PoliticsState $state): void
    {
        $seated = Office::FairTradingHead->fill($state);
        if ($seated !== null) {
            [, $outgoing] = $seated;
            $state->fairTradingPriorStance = $outgoing['name'] === '' ? $state->head(Office::FairTradingHead)['stance'] : $outgoing['stance'];
        }
    }

    /** A stance on competition drawn from a uniform: any screens between the two sets of guidelines, each as likely. */
    public static function drawStance(float $uniform): float
    {
        return (2.0 * $uniform) - 1.0;
    }

    /** The stance a leniency stands for: -1 at the 2010 guidelines (1), +1 at the 2023 ones (0), linear between. */
    public static function stance(float $leniency): float
    {
        return 1.0 - (2.0 * $leniency);
    }

    /** Where a stance sets merger review between the 2023 guidelines (0) and the 2010 ones (1). */
    public static function leniency(float $stance): float
    {
        return (1.0 - $stance) / 2.0;
    }

    /** How a stance reads: 'light', 'balanced' or 'strict', by thirds of the record. */
    public static function stanceName(float $stance): string
    {
        return $stance < -1.0 / 3.0 ? 'light' : ($stance > 1.0 / 3.0 ? 'strict' : 'balanced');
    }
}
