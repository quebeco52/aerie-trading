<?php

declare(strict_types=1);

namespace App\Service\Politics\Department;

use App\Data\Politics\AerieCouncil;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\PoliticsState;

/**
 * The Financial Regulator: a head the Council appoints for one fixed term, who sets the banks' CET1 requirement
 * (App\DTO\GovernmentPolicyDTO::$bankCapitalRequirement), a mortgage loan-to-value cap and a neutral countercyclical
 * buffer. Each candidate holds a stance on the banks, from light-touch (-1) to strict (+1), drawn uniformly; the
 * requirement runs linearly over it, and the cap and buffer tighten from none at the District's opening stance. The
 * Council names the candidate nearest its median stance on the banks. A rise in the requirement phases in over a year
 * and a cut applies at once (Basel III); the cap and buffer apply from seating, and the economy builds the buffer up.
 */
final class FinancialRegulator
{
    // --- The Stance's Range ---
    /** Lightest head's CET1 requirement, buffer aside: 8% (Basel III's 4.5% + 2.5% conservation, 1% systemic). */
    public const LIGHTEST_REQUIREMENT = 0.08;
    /** Strictest head's CET1 requirement: 13%, near what the strictest supervisors ask of their largest banks. */
    public const STRICTEST_REQUIREMENT = 0.13;
    /** Strictest head's mortgage loan-to-value cap: 80%, a fifth of a home's price from the buyer's own funds. */
    public const STRICTEST_LTV_CAP = 0.80;
    /** Strictest head's neutral countercyclical buffer: 2% of risk-weighted assets, released in a bust. */
    public const STRICTEST_NEUTRAL_BUFFER = 0.02;
    /** Requirement below which a head reads as light-touch: the lightest third of the range. */
    public const LIGHT_TOUCH_BELOW = self::LIGHTEST_REQUIREMENT + ((self::STRICTEST_REQUIREMENT - self::LIGHTEST_REQUIREMENT) / 3.0);
    /** Requirement above which a head reads as strict: the strictest third of the range. */
    public const STRICT_ABOVE = self::STRICTEST_REQUIREMENT - ((self::STRICTEST_REQUIREMENT - self::LIGHTEST_REQUIREMENT) / 3.0);

    // --- The Head ---
    /** Length of the head's single term, in years: five, non-renewable (the ECB Supervisory Board chair's). */
    public const HEAD_TERM_YEARS = 5.0;
    /** When the term of the head sitting at Year 1 ends, in years after Year 1 began. */
    public const OPENING_HEAD_TERM_END = 2.0;

    // --- Phase-In ---
    /** Years over which a rise in the requirement phases in; a cut applies at once (BCBS 2010 buffer guidance). */
    public const PHASE_IN_YEARS = 1.0;

    /**
     * One tick, after the Council's own: seats a head when the state has none and at each term's end, then moves the
     * requirement in force toward the head's.
     *
     * @param PoliticsState $state Advanced in place; its totalTime already this tick's.
     */
    public static function advance(PoliticsState $state, MathUtility $math): void
    {
        $time = $state->totalTime;
        $term = CouncilAppointments::termStart($time, self::OPENING_HEAD_TERM_END, self::HEAD_TERM_YEARS);

        if ($state->regulatorName === '') {
            self::open($state, $term, $math);
        } elseif (abs($state->regulatorTermStart - $term) > 1e-9) {
            [$chosen, $state->regulatorPassedOver] = CouncilAppointments::appoint(
                (int) $state->authoritySalt,
                'regulator',
                $term,
                [CouncilAppointments::AXIS_REGULATION => CouncilAppointments::median($state->councilRegulationStances)],
                CouncilAppointments::reservedNames($state),
                $math,
            );
            self::seatHead($state, $chosen, $term);
            $state->requirementPhaseFrom = $state->bankCapitalRequirement;
            $state->requirementPhaseStart = $time;
            $state->lastRegulatorAppointedAt = $time;
        }

        $state->bankCapitalRequirement = self::requirementInForce(
            self::requirement($state->regulatorStance),
            $state->requirementPhaseFrom,
            $time - $state->requirementPhaseStart,
        );
    }

    /**
     * Seats the head on the first read of a state. The head sitting at Year 1 runs, under their own name, the
     * requirement the banks opened under; a later head's, for a state that predates the Regulator, is already in force.
     */
    private static function open(PoliticsState $state, float $term, MathUtility $math): void
    {
        [$chosen, $state->regulatorPassedOver] = CouncilAppointments::appoint(
            (int) $state->authoritySalt,
            'regulator',
            $term,
            [CouncilAppointments::AXIS_REGULATION => CouncilAppointments::median($state->councilRegulationStances)],
            CouncilAppointments::reservedNames($state),
            $math,
        );
        if ($term < 0.0) {
            $chosen['name'] = AerieCouncil::OPENING_REGULATOR;
            $chosen['regulation'] = self::stance(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT);
            $state->regulatorPassedOver = [];
        }
        self::seatHead($state, $chosen, $term);

        $state->requirementPhaseFrom = self::requirement($state->regulatorStance);
        $state->requirementPhaseStart = $term;
    }

    /** A stance on the banks drawn from a uniform, every stance between light-touch and strict as likely. */
    public static function drawStance(float $uniform): float
    {
        return (2.0 * $uniform) - 1.0;
    }

    /** The stance a requirement stands for: -1 at the lightest, +1 at the strictest, linear between. */
    public static function stance(float $requirement): float
    {
        return (2.0 * ($requirement - self::lightest()) / (self::strictest() - self::lightest())) - 1.0;
    }

    /** The mortgage loan-to-value cap the head in office runs, as a share of the property's value; null for none. */
    public static function ltvCap(PoliticsState $state): ?float
    {
        return $state->regulatorLtvCap;
    }

    /** The cap a stance runs: none to the opening stance, then linear from one no buyer reaches to the strictest. */
    public static function ltvCapForStance(float $stance): ?float
    {
        $strictness = self::strictnessPastOpening($stance);
        if ($strictness <= 0.0) {
            return null;
        }
        $looseCap = CreditFiscalSubsystem::NEW_PURCHASE_CLTV_EDGES[count(CreditFiscalSubsystem::NEW_PURCHASE_CLTV_EDGES) - 1];

        return $looseCap - ($strictness * ($looseCap - self::STRICTEST_LTV_CAP));
    }

    /** The neutral buffer a stance holds, as a share of risk-weighted assets; none up to the opening stance. */
    public static function neutralBufferForStance(float $stance): float
    {
        return self::strictnessPastOpening($stance) * self::STRICTEST_NEUTRAL_BUFFER;
    }

    /** How far a stance stands past the District's opening one toward the strictest, 0 to 1. */
    private static function strictnessPastOpening(float $stance): float
    {
        $opening = self::stance(FinancialConstants::OPENING_BANK_CAPITAL_REQUIREMENT);

        return max(0.0, min(1.0, ($stance - $opening) / (1.0 - $opening)));
    }

    /** The requirement a stance stands for. */
    public static function requirement(float $stance): float
    {
        return self::lightest() + ((($stance + 1.0) / 2.0) * (self::strictest() - self::lightest()));
    }

    /** The requirement in force: the head's after a cut or a phase-in; part way through a rise, that share of it. */
    public static function requirementInForce(float $target, float $from, float $sincePhaseStart): float
    {
        if ($target <= $from) {
            return $target;
        }

        return $from + (($target - $from) * max(0.0, min(1.0, $sincePhaseStart / self::PHASE_IN_YEARS)));
    }

    /** How a requirement reads: 'light' (light-touch), 'middle' or 'strict'. */
    public static function stanceName(float $requirement): string
    {
        return $requirement < self::LIGHT_TOUCH_BELOW ? 'light' : ($requirement > self::STRICT_ABOVE ? 'strict' : 'middle');
    }

    /** When the head in office at a moment leaves. */
    public static function headTermEnd(float $time): float
    {
        return CouncilAppointments::termStart($time, self::OPENING_HEAD_TERM_END, self::HEAD_TERM_YEARS) + self::HEAD_TERM_YEARS;
    }

    /** The lightest requirement a head may set. */
    public static function lightest(): float
    {
        return self::LIGHTEST_REQUIREMENT;
    }

    /** The strictest requirement a head may set. */
    public static function strictest(): float
    {
        return self::STRICTEST_REQUIREMENT;
    }

    /**
     * @param array{name: string, birth: float, stance: float, regulation: float, fund: float, debt: float, competition: float, markets: float, swing?: float} $person
     */
    private static function seatHead(PoliticsState $state, array $person, float $termStart): void
    {
        CouncilAppointments::retire($state, $state->regulatorName);
        $state->regulatorName = $person['name'];
        $state->regulatorBirth = $person['birth'];
        $state->regulatorTermStart = $termStart;
        $state->regulatorStance = $person['regulation'];
        $state->regulatorLtvCap = self::ltvCapForStance($person['regulation']);
        $state->regulatorNeutralBuffer = self::neutralBufferForStance($person['regulation']);
    }
}
