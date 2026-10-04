<?php

declare(strict_types=1);

namespace App\Service\Politics;

use App\Data\AerieCouncil;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Math\MathUtility;

/**
 * The Sovereign Reserve Fund as a department of the Council: a head the Council appoints for one fixed term, who sets the
 * fund's policy equity share. The fund itself (App\Service\Macro\Subsystem\SovereignFundSubsystem) invests by its
 * published rules at whatever mix the head sets.
 *
 * Everyone the Council seats holds a stance on the reserves, from cautious (-1) to bold (+1). A stance is the policy mix
 * its holder would run: the equity share of a large reserve fund run on an equity-and-bond reference portfolio, as this
 * one is, from the most cautious on record (GPIF's, 50%) to the boldest (CPP Investments', 85%), and each candidate is
 * drawn as one of those funds. Who sets the mix moves it: GPIF doubled its equities from 24% to 50% in 2014, and the Norwegian fund went
 * from 40% to 60% in 2007 and to 70% in 2017.
 *
 * The Council names the candidate nearest its median stance on the reserves (App\Service\Politics\CouncilAppointments).
 * The head sitting at Year 1 keeps the mix the fund opened with, so the economy is handed a share only once a head the
 * Council has chosen takes office (App\DTO\GovernmentPolicyDTO::$reserveFundEquityShare).
 */
final class SovereignReserveFund
{
    // --- The Policy Mixes on Record (fund reports, 2024 policy) ---
    /** Equity shares of the seven large reserve funds run on an equity-and-bond policy or reference portfolio, as this fund is, in ascending order: GPIF (25 home + 25 foreign), PSP, Norway's GPFN, GIC, Norway's GPFG, NZ Super, CPP Investments. Funds steered by multi-asset allocations against pension liabilities (CalPERS, ABP, the AP funds) are left out. */
    public const OBSERVED_EQUITY_SHARES = [0.50, 0.59, 0.60, 0.65, 0.70, 0.80, 0.85];
    /** Equity share below which a mix reads as cautious: the lower tercile's edge, between PSP (59%) and the GPFN (60%). */
    public const CAUTIOUS_BELOW = 0.595;
    /** Equity share above which a mix reads as bold: the upper tercile's edge, between GIC (65%) and the GPFG (70%). */
    public const BOLD_ABOVE = 0.675;

    // --- The Head ---
    /** Length of the head's single term, in years: the fixed five-year term of NBIM's chief executive (Central Bank Act 2019, s. 2-13) and of GPIF's Board of Governors (GPIF Act, art. 8). */
    public const HEAD_TERM_YEARS = 5.0;
    /** When the term of the head sitting at Year 1 ends, in years after Year 1 began. */
    public const OPENING_HEAD_TERM_END = 4.0;

    /**
     * One tick, after the Council's own: the head seated on the first read of a state without one, and a new head at each
     * term's end, from a shortlist, the candidate nearest the Council's median stance on the reserves.
     *
     * @param PoliticsState $state The politics, advanced in place; its totalTime already set to this tick's.
     */
    public static function advance(PoliticsState $state, MathUtility $math): void
    {
        $time = $state->totalTime;
        $term = CouncilAppointments::termStart($time, self::OPENING_HEAD_TERM_END, self::HEAD_TERM_YEARS);
        if ($state->fundHeadName !== '' && abs($state->fundHeadTermStart - $term) < 1e-9) {
            return;
        }

        $opening = $state->fundHeadName === '';
        [$chosen, $state->fundHeadPassedOver] = CouncilAppointments::appoint(
            (int) $state->authoritySalt,
            'fund',
            $term,
            [CouncilAppointments::AXIS_FUND => CouncilAppointments::median($state->councilFundStances)],
            CouncilAppointments::sittingNames($state),
            $math
        );
        if ($term < 0.0) {
            $chosen['name'] = AerieCouncil::OPENING_FUND_HEAD;
            $chosen['fund'] = self::stance(SovereignFundSubsystem::OPENING_POLICY_EQUITY_SHARE);
            $state->fundHeadPassedOver = [];
        }

        $state->fundHeadName = $chosen['name'];
        $state->fundHeadBirth = $chosen['birth'];
        $state->fundHeadTermStart = $term;
        $state->fundHeadStance = $chosen['fund'];
        if (!$opening) {
            $state->lastFundHeadAppointedAt = $time;
        }
    }

    /** A stance on the reserves drawn from a uniform: one of the policy mixes on record, each as likely. */
    public static function drawStance(float $uniform): float
    {
        $count = count(self::OBSERVED_EQUITY_SHARES);

        return self::stance(self::OBSERVED_EQUITY_SHARES[min($count - 1, (int) floor($uniform * $count))]);
    }

    /** The stance an equity share stands for: -1 at the most cautious mix on record, +1 at the boldest, linear between. */
    public static function stance(float $equityShare): float
    {
        return (2.0 * ($equityShare - self::mostCautious()) / (self::boldest() - self::mostCautious())) - 1.0;
    }

    /** The policy equity share a stance stands for. */
    public static function equityShare(float $stance): float
    {
        return self::mostCautious() + ((($stance + 1.0) / 2.0) * (self::boldest() - self::mostCautious()));
    }

    /** How an equity share reads: 'cautious', 'balanced' or 'bold'. */
    public static function stanceName(float $equityShare): string
    {
        return $equityShare < self::CAUTIOUS_BELOW ? 'cautious' : ($equityShare > self::BOLD_ABOVE ? 'bold' : 'balanced');
    }

    /** When the head in office at a moment leaves. */
    public static function headTermEnd(float $time): float
    {
        return CouncilAppointments::termStart($time, self::OPENING_HEAD_TERM_END, self::HEAD_TERM_YEARS) + self::HEAD_TERM_YEARS;
    }

    /** The most cautious mix on record. */
    public static function mostCautious(): float
    {
        return self::OBSERVED_EQUITY_SHARES[0];
    }

    /** The boldest mix on record. */
    public static function boldest(): float
    {
        return self::OBSERVED_EQUITY_SHARES[count(self::OBSERVED_EQUITY_SHARES) - 1];
    }
}
