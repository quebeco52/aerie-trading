<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\Politics\AerieCouncil;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

/**
 * The Financial Regulator: a head the Council appoints for one fixed term, who sets the core equity (CET1) the
 * District's banks must hold against their risk-weighted assets, the most a mortgage may lend against a home's value,
 * and the countercyclical buffer the banks hold when credit is calm.
 *
 * Everyone the Council seats holds a stance on the banks, from light-touch (-1) to strict (+1), drawn uniformly. The
 * stance sets all three rules together: the requirement runs linearly from the lightest to the strictest, and the
 * mortgage cap and the neutral buffer tighten linearly from none at the stance the District opened under to their
 * strictest, so a head no stricter than Year 1's runs neither, as the head at Year 1 does.
 *
 * The Council names the candidate nearest its median stance on the banks (App\Service\Politics\CouncilAppointments).
 * A head's requirement takes effect as the countercyclical buffer's does under Basel III: a rise is phased in over the
 * year after it is announced, a cut applies at once. The banks hold the requirement in force
 * (App\DTO\GovernmentPolicyDTO::$bankCapitalRequirement). The cap and the buffer take effect from the day the head is
 * seated and the economy builds them in: the cap through household credit
 * (App\Service\Macro\Subsystem\CreditFiscalSubsystem::mortgageLtvCreditCut()), the buffer through its own phase-in.
 */
final class FinancialRegulator
{
    // --- The Stance's Range ---
    /** CET1 requirement of the lightest-touch head, countercyclical buffer aside (8%): Basel III's 4.5% minimum and 2.5% conservation buffer, and a 1% charge on the District's systemic banks. */
    public const LIGHTEST_REQUIREMENT = 0.08;
    /** CET1 requirement of the strictest head (13%), about where the strictest supervisors of the advanced economies hold their largest banks. */
    public const STRICTEST_REQUIREMENT = 0.13;
    /** Mortgage loan-to-value cap of the strictest head (80%): a fifth of a home's price from the buyer's own funds. */
    public const STRICTEST_LTV_CAP = 0.80;
    /** Neutral countercyclical buffer of the strictest head (2%), held while credit is calm so there is capital to release in a bust. */
    public const STRICTEST_NEUTRAL_BUFFER = 0.02;
    /** Requirement below which a head reads as light-touch: the lightest third of the range. */
    public const LIGHT_TOUCH_BELOW = self::LIGHTEST_REQUIREMENT + ((self::STRICTEST_REQUIREMENT - self::LIGHTEST_REQUIREMENT) / 3.0);
    /** Requirement above which a head reads as strict: the strictest third of the range. */
    public const STRICT_ABOVE = self::STRICTEST_REQUIREMENT - ((self::STRICTEST_REQUIREMENT - self::LIGHTEST_REQUIREMENT) / 3.0);

    // --- The Head ---
    /** Length of the head's single term, in years: the five-year, non-renewable term of the chair of the ECB's Supervisory Board (Regulation 1024/2013, Art. 26(3)), the commonest term among bank supervisors that have one. */
    public const HEAD_TERM_YEARS = 5.0;
    /** When the term of the head sitting at Year 1 ends, in years after Year 1 began. */
    public const OPENING_HEAD_TERM_END = 2.0;

    // --- The Requirement Taking Effect (Basel III countercyclical buffer) ---
    /** Years over which a rise in the requirement is phased in: a higher countercyclical buffer applies up to 12 months after it is announced, a lower one at once (BCBS 2010, Guidance for national authorities operating the countercyclical capital buffer). */
    public const PHASE_IN_YEARS = 1.0;

    /**
     * One tick, after the Council's own: the head seated on the first read of a state without one; a new head at each
     * term's end, from a shortlist, the candidate nearest the Council's median stance on the banks; and the requirement in
     * force moved toward the head's.
     *
     * @param PoliticsState $state The politics, advanced in place; its totalTime already set to this tick's.
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
                $math
            );
            self::seatHead($state, $chosen, $term);
            $state->requirementPhaseFrom = $state->bankCapitalRequirement;
            $state->requirementPhaseStart = $time;
            $state->lastRegulatorAppointedAt = $time;
        }

        $state->bankCapitalRequirement = self::requirementInForce(
            self::requirement($state->regulatorStance),
            $state->requirementPhaseFrom,
            $time - $state->requirementPhaseStart
        );
    }

    /**
     * The head as they stood at Year 1, or as they stand when a state that predates the Regulator is first read, chosen
     * from a shortlist as they would have been. The head sitting at Year 1, seated before it under their own name, runs
     * the requirement the banks opened under; a later head's requirement is taken as already in force.
     */
    private static function open(PoliticsState $state, float $term, MathUtility $math): void
    {
        [$chosen, $state->regulatorPassedOver] = CouncilAppointments::appoint(
            (int) $state->authoritySalt,
            'regulator',
            $term,
            [CouncilAppointments::AXIS_REGULATION => CouncilAppointments::median($state->councilRegulationStances)],
            CouncilAppointments::reservedNames($state),
            $math
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

    /** The cap a stance runs: none up to the opening stance, then tightening linearly from a cap no buyer reaches to the strictest. */
    public static function ltvCapForStance(float $stance): ?float
    {
        $strictness = self::strictnessPastOpening($stance);
        if ($strictness <= 0.0) {
            return null;
        }
        $looseCap = CreditFiscalSubsystem::NEW_PURCHASE_CLTV_EDGES[count(CreditFiscalSubsystem::NEW_PURCHASE_CLTV_EDGES) - 1];

        return $looseCap - ($strictness * ($looseCap - self::STRICTEST_LTV_CAP));
    }

    /** The positive neutral countercyclical buffer a stance holds, as a share of risk-weighted assets: none up to the opening stance. */
    public static function neutralBufferForStance(float $stance): float
    {
        return self::strictnessPastOpening($stance) * self::STRICTEST_NEUTRAL_BUFFER;
    }

    /** How far a stance stands from the District's opening one toward the strictest, 0 to 1; 0 at or below the opening. */
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

    /**
     * The requirement in force: a cut, or a rise once phased in, is the head's; part way through a rise's year it stands
     * that share of the way up from where it began.
     */
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

    /** @param array{name: string, birth: float, stance: float, regulation: float, fund: float, debt: float, competition: float, swing?: float} $person */
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
