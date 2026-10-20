<?php

declare(strict_types=1);

namespace App\Service\Politics\Department;

/**
 * The Sovereign Reserve Fund: a head the Council appoints for one fixed term, who sets the policy equity share the fund
 * (App\Service\Macro\Subsystem\SovereignFundSubsystem) invests at. Each candidate holds a stance on the reserves, from
 * cautious (-1) to bold (+1), drawn as one of the large reserve funds' policy mixes on record. The Council names the
 * candidate nearest its median stance on the reserves; the head sitting at Year 1 keeps the fund's opening mix, so the
 * economy gets a share only from a head the Council chose (App\DTO\GovernmentPolicyDTO::$reserveFundEquityShare).
 */
final class SovereignReserveFund
{
    // --- The Policy Mixes on Record ---
    /**
     * Policy equity shares of seven large reserve funds run on an equity-and-bond reference portfolio, as this one is,
     * ascending from GPIF to CPP Investments (fund reports, 2024).
     */
    public const OBSERVED_EQUITY_SHARES = [0.50, 0.59, 0.60, 0.65, 0.70, 0.80, 0.85];
    /** Equity share below which a mix reads as cautious: 59.5%, the edge of the lowest third of the mixes on record. */
    public const CAUTIOUS_BELOW = 0.595;
    /** Equity share above which a mix reads as bold: 67.5%, the edge of the highest third of the mixes on record. */
    public const BOLD_ABOVE = 0.675;

    /** A stance on the reserves drawn from a uniform: one of the policy mixes on record, each as likely. */
    public static function drawStance(float $uniform): float
    {
        $count = count(self::OBSERVED_EQUITY_SHARES);

        return self::stance(self::OBSERVED_EQUITY_SHARES[min($count - 1, (int) floor($uniform * $count))]);
    }

    /** The stance a share stands for: -1 at the most cautious mix on record, +1 at the boldest, linear between. */
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
