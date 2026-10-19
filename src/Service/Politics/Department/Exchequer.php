<?php

declare(strict_types=1);

namespace App\Service\Politics\Department;

use App\Data\Politics\AerieCouncil;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\MathUtility;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\PoliticsState;

/**
 * The Exchequer: a head the Council appoints for one fixed term, who sets the average maturity the District's debt is
 * issued toward; the Diet's budget is the Exchequer's to execute, its funding in bills or bonds the head's. Each
 * candidate holds a stance on the debt, from short (-1) to long (+1), drawn uniformly over the US Treasury's record.
 * The Council names the candidate nearest its median stance on the debt; the head sitting at Year 1 keeps the debt's
 * opening maturity, so the economy gets a target only from a head the Council chose
 * (App\DTO\GovernmentPolicyDTO::$debtAverageMaturity).
 */
final class Exchequer
{
    // --- The Treasury's Record ---
    /** Shortest average maturity of marketable debt on the record, in years: 48 months (US Treasury, 2008). */
    public const SHORTEST_AVERAGE_MATURITY = 4.0;
    /** Longest average maturity on the record, in years: 71 months (US Treasury, 2017). */
    public const LONGEST_AVERAGE_MATURITY = 71.0 / 12.0;

    // --- The Head ---
    /** Length of the head's single term, in years: the five most Council department heads serve. */
    public const HEAD_TERM_YEARS = 5.0;
    /** When the term of the head sitting at Year 1 ends, in years after Year 1 began. */
    public const OPENING_HEAD_TERM_END = 3.0;

    /**
     * One tick, after the Council's own: seats a head when the state has none and at each term's end.
     *
     * @param PoliticsState $state Advanced in place; its totalTime already this tick's.
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
