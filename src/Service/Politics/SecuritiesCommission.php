<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\Politics\AerieCouncil;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

/**
 * The Securities Commission as a department of the Council: a head the Council appoints for one fixed term, who sets
 * the rules of the Aerie Exchange that reach investors and listed firms. One stance on the markets, from light (-1) to
 * strict (+1), sets two of them: the margin an investor must put up to buy on credit, and how hard the Commission's
 * enforcement leans on firms that steer their reported earnings toward the Street's number. Each rule stands at its
 * founding value for a stance at the middle, so a head of the middle view keeps the District's rules, and runs out to
 * its record's ends either way.
 *
 * The initial margin runs over the record of Regulation T, from 40% (1937-45, 1949) to 80% (1968), short of the 100% of
 * 1946 that shut buying on credit; maintenance stays where it was founded, since it guards the broker's collateral and
 * a rise in it would call positions bought under the old rule. Enforcement scales each firm's own propensity to manage
 * earnings from half again as much to half as much, the size of the fall in accrual management after the US's 2002
 * reforms (Cohen, Dey & Lys 2008).
 *
 * The head sitting at Year 1 keeps the founding rules, and a new head's rules take effect a notice period after they
 * are seated, so no account is called the day a new head arrives
 * (App\DTO\GovernmentPolicyDTO::$initialMarginRequirement).
 */
final class SecuritiesCommission
{
    // --- Margin (Regulation T, 1934-1974) ---
    /** Lightest initial margin on the record: 40%, the Federal Reserve's lowest setting. */
    public const LIGHTEST_INITIAL_MARGIN = 0.40;
    /** Strictest initial margin a head may set: 80%, the 1968 setting, short of the 100% that stopped margin buying. */
    public const STRICTEST_INITIAL_MARGIN = 0.80;

    // --- Enforcement ---
    /** Multiplier on a firm's propensity to manage earnings under the lightest enforcement: 1.5x. */
    public const LIGHTEST_EARNINGS_MANAGEMENT_SCALE = 1.5;
    /** The same under the strictest enforcement: 0.5x, the post-2002 fall in accrual management. */
    public const STRICTEST_EARNINGS_MANAGEMENT_SCALE = 0.5;

    // --- The Head ---
    /**
     * Length of the head's single term, in years: the five years of the Council's other department heads
     * (SovereignReserveFund::HEAD_TERM_YEARS).
     */
    public const HEAD_TERM_YEARS = 5.0;
    /** When the term of the head sitting at Year 1 ends, in years after Year 1 began. */
    public const OPENING_HEAD_TERM_END = 4.5;
    /** Years between a head's seating and their rules taking effect: one quarter's notice. */
    public const RULE_NOTICE_YEARS = 0.25;

    /**
     * One tick, after the Council's own: the head seated on the first read of a state without one, and a new head at
     * each term's end, from a shortlist, the candidate nearest the Council's median stance on the markets.
     *
     * @param PoliticsState $state The politics, advanced in place; its totalTime already set to this tick's.
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

    /**
     * The stance whose rules are in force at a moment: the head's once their notice has run, else the head's before
     * them; null for the founding rules, which the head sitting at Year 1 keeps.
     */
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
     * The margins an account is held to under an initial margin: the initial one, and the founding maintenance on longs
     * and on shorts.
     *
     * @return array{initial: float, long: float, short: float}
     */
    public static function margins(float $initial): array
    {
        return ['initial' => $initial, 'long' => FinancialConstants::MAINTENANCE_MARGIN_LONG, 'short' => FinancialConstants::MAINTENANCE_MARGIN_SHORT];
    }

    /**
     * The multiplier a stance puts on each firm's propensity to manage earnings, or none (1) for the founding rules.
     */
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

    /**
     * A rule's value at a stance: its founding value at the middle (0) or for none, linear out to its light end (-1)
     * and its strict end (+1).
     */
    private static function along(?float $stance, float $light, float $founding, float $strict): float
    {
        if ($stance === null) {
            return $founding;
        }

        return $founding + (max(-1.0, min(1.0, $stance)) * ($stance < 0.0 ? $founding - $light : $strict - $founding));
    }
}
