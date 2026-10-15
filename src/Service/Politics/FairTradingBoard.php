<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\Politics\AerieCouncil;
use App\Service\Math\MathUtility;

/**
 * The Fair Trading Board as a department of the Council: a head the Council appoints for one fixed term, who sets the
 * screens horizontal merger review applies (App\Service\Corporate\MergerAndAcquisitionEngine::reviewScreens()). The
 * screens are the Board's own guidelines, as merger guidelines are an agency's and not a statute's.
 *
 * Everyone the Council seats holds a stance on competition, from light (-1) to strict (+1). A stance is where its holder
 * would set the screens on the record of the US agencies' guidelines: the 2010 ones at the light end (a 200-point delta,
 * a 2,500 line, no share presumption), the 2023 ones at the strict end (100 points, 1,800, a 30% share). A light Board
 * lets firms buy more of their markets; a strict one caps a deal at less.
 *
 * The Council names the candidate nearest its median stance on competition (App\Service\Politics\CouncilAppointments).
 * The head sitting at Year 1 holds the screens the District opened with.
 */
final class FairTradingBoard
{
    // --- The Guidelines ---
    /** Where merger review stood at Year 1, between the 2023 guidelines (0) and the 2010 ones (1): where the founding Vanguard cabinet left it, its home of 0.3 on the Council axis between the Common Lot's -0.8 and the Chartists' 0.8 (0.6875). */
    public const FOUNDING_LENIENCY = 0.6875;

    // --- The Head ---
    /** Length of the head's single term, in years: the five years of the Council's other department heads (SovereignReserveFund::HEAD_TERM_YEARS). */
    public const HEAD_TERM_YEARS = 5.0;
    /** When the term of the head sitting at Year 1 ends, in years after Year 1 began. */
    public const OPENING_HEAD_TERM_END = 1.0;

    /**
     * One tick, after the Council's own: the head seated on the first read of a state without one, and a new head at each
     * term's end, from a shortlist, the candidate nearest the Council's median stance on competition.
     *
     * @param PoliticsState $state The politics, advanced in place; its totalTime already set to this tick's.
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
            $math
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

    /** The stance a leniency stands for: -1 at the 2010 guidelines (leniency 1), +1 at the 2023 ones (leniency 0), linear between. */
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
