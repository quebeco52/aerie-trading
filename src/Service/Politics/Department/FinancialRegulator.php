<?php

declare(strict_types=1);

namespace App\Service\Politics\Department;

use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\FinancialConstants;
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

    // --- Phase-In ---
    /** Years over which a rise in the requirement phases in; a cut applies at once (BCBS 2010 buffer guidance). */
    public const PHASE_IN_YEARS = 1.0;

    /**
     * One tick, after the Council's own: seats a head when the post is vacant and at each term's end (Office::fill()),
     * with the cap and buffer their stance runs, then moves the requirement in force toward the head's. A rise phases
     * in from the seating; the head seated on a state's first read has theirs in force already.
     *
     * @param PoliticsState $state Advanced in place; its totalTime already this tick's.
     */
    public static function advance(PoliticsState $state): void
    {
        $time = $state->totalTime;
        $seated = Office::Regulator->fill($state);
        $head = $state->head(Office::Regulator);
        if ($seated !== null) {
            $state->regulatorLtvCap = self::ltvCapForStance($head['stance']);
            $state->regulatorNeutralBuffer = self::neutralBufferForStance($head['stance']);
            [, $outgoing] = $seated;
            $opening = $outgoing['name'] === '';
            $state->requirementPhaseFrom = $opening ? self::requirement($head['stance']) : $state->bankCapitalRequirement;
            $state->requirementPhaseStart = $opening ? $head['termStart'] : $time;
        }

        $state->bankCapitalRequirement = self::requirementInForce(
            self::requirement($head['stance']),
            $state->requirementPhaseFrom,
            $time - $state->requirementPhaseStart,
        );
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
}
