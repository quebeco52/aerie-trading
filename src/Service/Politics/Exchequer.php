<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\Politics\AerieCouncil;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\MathUtility;

/**
 * The Exchequer as a department of the Council: a head the Council appoints for one fixed term, who sets the average
 * maturity the District's debt is issued toward. The budget the Diet passes is the Exchequer's to execute; how it is
 * funded, in bills or in bonds, is the head's.
 *
 * Everyone the Council seats holds a stance on the debt, from short (-1) to long (+1). A stance is the average maturity
 * its holder would steer the marketable debt to, on the US Treasury's record: from the 48 months of 2008, when bills
 * funded the crisis, to the 71 months of 2017, after the longest extension in its modern history. A short book is
 * cheaper while the curve slopes up and costs more when rates rise; a long one locks its rate in and leaves the market
 * more duration to hold.
 *
 * The Council names the candidate nearest its median stance on the debt (App\Service\Politics\CouncilAppointments).
 * The head sitting at Year 1 keeps the maturity the debt opened with, so the economy is handed a target only once a
 * head the Council has chosen takes office (App\DTO\GovernmentPolicyDTO::$debtAverageMaturity).
 */
final class Exchequer
{
    // --- The Treasury's Record (US Treasury, weighted average maturity of marketable debt) ---
    /** Shortest average maturity on the record, in years: 48 months, in 2008. */
    public const SHORTEST_AVERAGE_MATURITY = 4.0;
    /** Longest average maturity on the record, in years: 71 months, in 2017, near the modern high after the 2009-2016 extension. */
    public const LONGEST_AVERAGE_MATURITY = 71.0 / 12.0;

    // --- The Head ---
    /** Length of the head's single term, in years: the five years of the Council's other department heads (SovereignReserveFund::HEAD_TERM_YEARS). */
    public const HEAD_TERM_YEARS = 5.0;
    /** When the term of the head sitting at Year 1 ends, in years after Year 1 began. */
    public const OPENING_HEAD_TERM_END = 3.0;

    /**
     * One tick, after the Council's own: the head seated on the first read of a state without one, and a new head at each
     * term's end, from a shortlist, the candidate nearest the Council's median stance on the debt.
     *
     * @param PoliticsState $state The politics, advanced in place; its totalTime already set to this tick's.
     */
    public static function advance(PoliticsState $state, MathUtility $math): void
    {
        $time = $state->totalTime;
        $term = CouncilAppointments::termStart($time, self::OPENING_HEAD_TERM_END, self::HEAD_TERM_YEARS);
        if ($state->exchequerHeadName !== '' && abs($state->exchequerHeadTermStart - $term) < 1e-9) {
            return;
        }

        $opening = $state->exchequerHeadName === '';
        [$chosen, $state->exchequerHeadPassedOver] = CouncilAppointments::appoint(
            (int) $state->authoritySalt,
            'exchequer',
            $term,
            [CouncilAppointments::AXIS_DEBT => CouncilAppointments::median($state->councilDebtStances)],
            CouncilAppointments::reservedNames($state),
            $math,
        );
        if ($term < 0.0) {
            $chosen['name'] = AerieCouncil::OPENING_EXCHEQUER_HEAD;
            $chosen['debt'] = self::stance(CreditFiscalSubsystem::FOUNDING_DEBT_AVERAGE_MATURITY);
            $state->exchequerHeadPassedOver = [];
        }

        CouncilAppointments::retire($state, $state->exchequerHeadName);
        $state->exchequerHeadName = $chosen['name'];
        $state->exchequerHeadBirth = $chosen['birth'];
        $state->exchequerHeadTermStart = $term;
        $state->exchequerHeadStance = $chosen['debt'];
        if (!$opening) {
            $state->lastExchequerHeadAppointedAt = $time;
        }
    }

    /** A stance on the debt drawn from a uniform: any average maturity on the record, each as likely. */
    public static function drawStance(float $uniform): float
    {
        return (2.0 * $uniform) - 1.0;
    }

    /** The stance an average maturity stands for: -1 at the shortest on record, +1 at the longest, linear between. */
    public static function stance(float $averageMaturity): float
    {
        return (2.0 * ($averageMaturity - self::SHORTEST_AVERAGE_MATURITY) / (self::LONGEST_AVERAGE_MATURITY - self::SHORTEST_AVERAGE_MATURITY)) - 1.0;
    }

    /** The average maturity, in years, a stance stands for. */
    public static function averageMaturity(float $stance): float
    {
        return self::SHORTEST_AVERAGE_MATURITY + ((($stance + 1.0) / 2.0) * (self::LONGEST_AVERAGE_MATURITY - self::SHORTEST_AVERAGE_MATURITY));
    }

    /** How an average maturity reads: 'short', 'balanced' or 'long', by thirds of the record. */
    public static function stanceName(float $averageMaturity): string
    {
        $stance = self::stance($averageMaturity);

        return $stance < -1.0 / 3.0 ? 'short' : ($stance > 1.0 / 3.0 ? 'long' : 'balanced');
    }

    /** When the head in office at a moment leaves. */
    public static function headTermEnd(float $time): float
    {
        return CouncilAppointments::termStart($time, self::OPENING_HEAD_TERM_END, self::HEAD_TERM_YEARS) + self::HEAD_TERM_YEARS;
    }
}
