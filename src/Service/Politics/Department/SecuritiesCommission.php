<?php

declare(strict_types=1);

namespace App\Service\Politics\Department;

use App\Data\Politics\AerieCouncil;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\PoliticsState;

/**
 * The Securities Commission: a head the Council appoints for one fixed term, who sets the initial margin and how hard
 * enforcement leans on firms that steer reported earnings toward the Street's number. Each candidate holds a stance on
 * the markets, from light (-1) to strict (+1), drawn uniformly; a middle stance keeps the founding rules, and each
 * rule runs linearly to its record's end either way. The Council names the candidate nearest its median stance on the
 * markets; the head sitting at Year 1 keeps the founding rules, and a later head's take effect after a notice period
 * (App\DTO\GovernmentPolicyDTO::$initialMarginRequirement).
 */
final class SecuritiesCommission
{
    // --- Margin ---
    /** Lightest initial margin a head may set: 40% (Regulation T's lowest setting). */
    public const LIGHTEST_INITIAL_MARGIN = 0.40;
    /** Strictest initial margin a head may set: 80% (Regulation T, 1968). */
    public const STRICTEST_INITIAL_MARGIN = 0.80;

    // --- Enforcement ---
    /** Multiplier on a firm's propensity to manage earnings under the lightest enforcement: 1.5x. */
    public const LIGHTEST_EARNINGS_MANAGEMENT_SCALE = 1.5;
    /** The same at the strictest: 0.5x, the post-2002 fall in accrual management (Cohen, Dey & Lys 2008). */
    public const STRICTEST_EARNINGS_MANAGEMENT_SCALE = 0.5;

    // --- The Head ---
    /** Length of the head's single term, in years: the five most Council department heads serve. */
    public const HEAD_TERM_YEARS = 5.0;
    /** When the term of the head sitting at Year 1 ends, in years after Year 1 began. */
    public const OPENING_HEAD_TERM_END = 4.5;
    /** Notice, in years, before a new head's rules take effect: a quarter, so no account is called on arrival. */
    public const RULE_NOTICE_YEARS = 0.25;

    /**
     * One tick, after the Council's own: seats a head when the state has none and at each term's end.
     *
     * @param PoliticsState $state Advanced in place; its totalTime already this tick's.
     */
    public static function advance(PoliticsState $state, MathUtility $math): void
    {
        $time = $state->totalTime;
        $term = CouncilAppointments::termStart($time, self::OPENING_HEAD_TERM_END, self::HEAD_TERM_YEARS);
        if ($state->securitiesHeadName !== '' && abs($state->securitiesHeadTermStart - $term) < 1e-9) {
            return;
        }

        $opening = $state->securitiesHeadName === '';
        [$chosen, $state->securitiesHeadPassedOver] = CouncilAppointments::appoint(
            (int) $state->authoritySalt,
            'securities',
            $term,
            [CouncilAppointments::AXIS_MARKETS => CouncilAppointments::median($state->councilMarketsStances)],
            CouncilAppointments::reservedNames($state),
            $math,
        );
        if ($term < 0.0) {
            $chosen['name'] = AerieCouncil::OPENING_SECURITIES_HEAD;
            $chosen['markets'] = 0.0;
            $state->securitiesHeadPassedOver = [];
        }

        // The rules the outgoing head ran, which hold through the new head's notice; none for the founding ones.
        $state->securitiesPriorStance = $opening || $state->securitiesHeadTermStart < 0.0 ? null : $state->securitiesHeadStance;
        CouncilAppointments::retire($state, $state->securitiesHeadName);
        $state->securitiesHeadName = $chosen['name'];
        $state->securitiesHeadBirth = $chosen['birth'];
        $state->securitiesHeadTermStart = $term;
        $state->securitiesHeadStance = $chosen['markets'];
        if (!$opening) {
            $state->lastSecuritiesHeadAppointedAt = $time;
        }
    }

    /** The stance in force: the head's after their notice, else their predecessor's; null for the founding rules. */
    public static function stanceInForce(float $time, string $headName, float $termStart, float $stance, ?float $priorStance): ?float
    {
        if ($headName === '' || $termStart < 0.0) {
            return null;
        }

        return $time >= $termStart + self::RULE_NOTICE_YEARS ? $stance : $priorStance;
    }

    /** A stance on the markets drawn from a uniform: any regime on the record, each as likely. */
    public static function drawStance(float $uniform): float
    {
        return (2.0 * $uniform) - 1.0;
    }

    /** The initial margin a stance sets, or the founding one for none. */
    public static function initialMargin(?float $stance): float
    {
        return self::along($stance, self::LIGHTEST_INITIAL_MARGIN, FinancialConstants::INITIAL_MARGIN_REQUIREMENT, self::STRICTEST_INITIAL_MARGIN);
    }

    /**
     * The margins an account is held to under an initial margin. Maintenance on longs and shorts stays at its founding
     * level, since a rise would call positions bought under the old rule.
     *
     * @return array{initial: float, long: float, short: float}
     */
    public static function margins(float $initial): array
    {
        return ['initial' => $initial, 'long' => FinancialConstants::MAINTENANCE_MARGIN_LONG, 'short' => FinancialConstants::MAINTENANCE_MARGIN_SHORT];
    }

    /** The multiplier a stance puts on each firm's propensity to manage earnings; 1 for the founding rules. */
    public static function earningsManagementScale(?float $stance): float
    {
        return self::along($stance, self::LIGHTEST_EARNINGS_MANAGEMENT_SCALE, 1.0, self::STRICTEST_EARNINGS_MANAGEMENT_SCALE);
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

    /** A rule at a stance: its founding value at 0 or for none, linear out to its light (-1) and strict (+1) ends. */
    private static function along(?float $stance, float $light, float $founding, float $strict): float
    {
        if ($stance === null) {
            return $founding;
        }

        return $founding + (max(-1.0, min(1.0, $stance)) * ($stance < 0.0 ? $founding - $light : $strict - $founding));
    }
}
