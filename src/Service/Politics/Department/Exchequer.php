<?php

declare(strict_types=1);

namespace App\Service\Politics\Department;

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
}
