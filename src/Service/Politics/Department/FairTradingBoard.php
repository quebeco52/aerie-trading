<?php

declare(strict_types=1);

namespace App\Service\Politics\Department;

use App\Data\Politics\AerieCouncil;
use App\Service\Math\MathUtility;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\PoliticsState;

/**
 * The Fair Trading Board: a head the Council appoints for one fixed term, who sets the screens of horizontal merger
 * review (App\Service\Corporate\MergerAndAcquisitionEngine::reviewScreens()), as an agency's own guidelines. Each
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

    // --- The Head ---
    /** Length of the head's single term, in years: the five most Council department heads serve. */
    public const HEAD_TERM_YEARS = 5.0;
    /** When the term of the head sitting at Year 1 ends, in years after Year 1 began. */
    public const OPENING_HEAD_TERM_END = 1.0;

    /**
     * One tick, after the Council's own: seats a head when the state has none and at each term's end.
     *
     * @param PoliticsState $state Advanced in place; its totalTime already this tick's.
     */
    public static function advance(PoliticsState $state, MathUtility $math): void
    {
        $time = $state->totalTime;
        $term = CouncilAppointments::termStart($time, self::OPENING_HEAD_TERM_END, self::HEAD_TERM_YEARS);
        if ($state->fairTradingHeadName !== '' && abs($state->fairTradingHeadTermStart - $term) < 1e-9) {
            return;
        }

        $opening = $state->fairTradingHeadName === '';
        [$chosen, $state->fairTradingHeadPassedOver] = CouncilAppointments::appoint(
            (int) $state->authoritySalt,
            'fair-trading',
            $term,
            [CouncilAppointments::AXIS_COMPETITION => CouncilAppointments::median($state->councilCompetitionStances)],
            CouncilAppointments::reservedNames($state),
            $math,
        );
        if ($term < 0.0) {
            $chosen['name'] = AerieCouncil::OPENING_FAIR_TRADING_HEAD;
            $chosen['competition'] = self::stance(self::FOUNDING_LENIENCY);
            $state->fairTradingHeadPassedOver = [];
        }

        $state->fairTradingPriorStance = $opening ? $chosen['competition'] : $state->fairTradingHeadStance;
        CouncilAppointments::retire($state, $state->fairTradingHeadName);
        $state->fairTradingHeadName = $chosen['name'];
        $state->fairTradingHeadBirth = $chosen['birth'];
        $state->fairTradingHeadTermStart = $term;
        $state->fairTradingHeadStance = $chosen['competition'];
        if (!$opening) {
            $state->lastFairTradingHeadAppointedAt = $time;
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

    /** When the head in office at a moment leaves. */
    public static function headTermEnd(float $time): float
    {
        return CouncilAppointments::termStart($time, self::OPENING_HEAD_TERM_END, self::HEAD_TERM_YEARS) + self::HEAD_TERM_YEARS;
    }
}
