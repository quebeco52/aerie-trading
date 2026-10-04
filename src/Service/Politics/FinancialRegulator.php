<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\AerieCouncil;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

/**
 * The Financial Regulator: a head the Council appoints for one fixed term, who sets the core equity (CET1) the
 * District's banks must hold against their risk-weighted assets.
 *
 * Everyone the Council seats holds a stance on the banks, from light-touch (-1) to strict (+1). A stance is the regime
 * its holder would run: the CET1 requirement, countercyclical buffer aside, from the lightest the 17 largest banks of the
 * advanced economies faced at end-2024 (Luxembourg's, 8.43%) to the strictest (Norway's, 13.15%), and each candidate is
 * drawn as one of those 17 regimes. No record classifies bank supervisors as the press classifies central bankers, so
 * the regimes themselves are the record: which one a head runs is who heads the regulator, as the US requirement on its
 * largest banks moved with each Vice Chair for Supervision (a 19% rise proposed in 2023, half of it re-proposed in 2024,
 * none adopted).
 *
 * The Council names the candidate nearest its median stance on the banks (App\Service\Politics\CouncilAppointments).
 * A head's requirement takes effect as the countercyclical buffer's does under Basel III: a rise is phased in over the
 * year after it is announced, a cut applies at once. The banks hold the requirement in force
 * (App\DTO\GovernmentPolicyDTO::$bankCapitalRequirement).
 */
final class FinancialRegulator
{
    // --- The Regimes on Record (Pillar 3 disclosures, end-2024) ---
    /** CET1 requirements, countercyclical buffer aside, on the largest bank of each of 17 advanced economies at end-2024, in ascending order: Luxembourg, Japan, Singapore, Australia, Hong Kong, France, Ireland, the Netherlands, Switzerland, the UK, Germany, Canada, the US, Denmark, Sweden, Iceland, Norway. Japan's, Singapore's, Australia's and Hong Kong's leave out an unpublished Pillar 2 add-on. */
    public const OBSERVED_REQUIREMENTS = [0.0843, 0.0850, 0.0900, 0.0925, 0.0950, 0.0966, 0.0982, 0.0993, 0.1000, 0.1046, 0.1071, 0.1150, 0.1230, 0.1255, 0.1260, 0.1290, 0.1315];
    /** Requirement below which a regime reads as light-touch: midway across the gap between the financial centres of Asia and Luxembourg (8.43-9.50%) and the large euro-area banks (9.66% and up). */
    public const LIGHT_TOUCH_BELOW = 0.0958;
    /** Requirement above which a regime reads as strict: midway across the widest gap on record, between Canada's 11.50% and the US's 12.30%, past which only the US and the Nordic regimes stand. */
    public const STRICT_ABOVE = 0.1190;

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
                CouncilAppointments::sittingNames($state),
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
            CouncilAppointments::sittingNames($state),
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

    /** A stance on the banks drawn from a uniform: one of the 17 regimes on record, each as likely. */
    public static function drawStance(float $uniform): float
    {
        $count = count(self::OBSERVED_REQUIREMENTS);

        return self::stance(self::OBSERVED_REQUIREMENTS[min($count - 1, (int) floor($uniform * $count))]);
    }

    /** The stance a requirement stands for: -1 at the lightest regime on record, +1 at the strictest, linear between. */
    public static function stance(float $requirement): float
    {
        return (2.0 * ($requirement - self::lightest()) / (self::strictest() - self::lightest())) - 1.0;
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

    /** The lightest regime on record. */
    public static function lightest(): float
    {
        return self::OBSERVED_REQUIREMENTS[0];
    }

    /** The strictest regime on record. */
    public static function strictest(): float
    {
        return self::OBSERVED_REQUIREMENTS[count(self::OBSERVED_REQUIREMENTS) - 1];
    }

    /** @param array{name: string, birth: float, regulation: float} $person */
    private static function seatHead(PoliticsState $state, array $person, float $termStart): void
    {
        $state->regulatorName = $person['name'];
        $state->regulatorBirth = $person['birth'];
        $state->regulatorTermStart = $termStart;
        $state->regulatorStance = $person['regulation'];
    }
}
