<?php

declare(strict_types=1);

namespace App\Service\Corporate\Deal;

/**
 * The approval gates on the size of a deal: the Fair Trading Board's horizontal merger review, which caps the share of
 * its market an acquirer may buy, and, for a firm its capital ratio governs, the prudential regulator's capital test,
 * which caps the price at the deal that keeps it well capitalized. Pure functions; AcquisitionEngine reads the market
 * and the balance sheet they are applied to.
 */
final class MergerReview
{
    // --- Horizontal Merger Review (2023 Merger Guidelines §2.1; 2010 Horizontal Merger Guidelines §5.3) ---
    /**
     * HHI increase above which a merger significantly increases concentration under the 2023 guidelines (100 points).
     */
    public const MERGER_REVIEW_HHI_DELTA = 0.0100;
    /** Post-merger HHI above which the market is highly concentrated under the 2023 guidelines (1,800 points). */
    public const MERGER_REVIEW_CONCENTRATED_HHI = 0.1800;
    /**
     * Merged market share above which a deal that also passes the HHI delta is presumed illegal under the 2023
     * guidelines (Philadelphia National Bank, 1963).
     */
    public const MERGER_REVIEW_SHARE_CEILING = 0.30;
    /**
     * HHI increase a merger into a highly concentrated market must pass to be presumed to enhance market power under
     * the 2010 guidelines (200 points).
     */
    public const MERGER_REVIEW_LENIENT_HHI_DELTA = 0.0200;
    /**
     * Post-merger HHI above which the market is highly concentrated under the 2010 guidelines (2,500 points); they
     * carry no market-share presumption.
     */
    public const MERGER_REVIEW_LENIENT_CONCENTRATED_HHI = 0.2500;

    /**
     * The screens horizontal merger review applies, between the 2023 guidelines (leniency 0: 1,800 points, a
     * 100-point delta, and a 30% share presumption) and the 2010 guidelines (leniency 1: 2,500 points, a
     * 200-point delta, no share presumption), where the Fair Trading Board's head sets it
     * (App\Service\Politics\Department\FairTradingBoard::leniency()).
     *
     * @return array{delta: float, concentrated: float, shareCeiling: float} The HHI delta, the highly concentrated
     *         line, and the merged share presumed illegal (1.0: none).
     */
    public static function reviewScreens(float $leniency): array
    {
        $weight = max(0.0, min(1.0, $leniency));

        return [
            'delta' => self::MERGER_REVIEW_HHI_DELTA + ($weight * (self::MERGER_REVIEW_LENIENT_HHI_DELTA - self::MERGER_REVIEW_HHI_DELTA)),
            'concentrated' => self::MERGER_REVIEW_CONCENTRATED_HHI + ($weight * (self::MERGER_REVIEW_LENIENT_CONCENTRATED_HHI - self::MERGER_REVIEW_CONCENTRATED_HHI)),
            'shareCeiling' => self::MERGER_REVIEW_SHARE_CEILING + ($weight * (1.0 - self::MERGER_REVIEW_SHARE_CEILING)),
        ];
    }

    /**
     * The largest share of its market an acquirer can buy and still clear horizontal merger review
     * (reviewScreens()). A deal is presumed to lessen competition when it raises the HHI by more than the
     * delta and either leaves the market highly concentrated or creates a firm above the share ceiling. The
     * target comes out of the competitive fringe, so buying share t at share s raises the HHI by 2st and
     * leaves it at H + t² + 2st. Clearance is the union of two intervals from zero: the delta safe harbour
     * t ≤ Δ/2s, and t ≤ min(ceiling − s, √(s² + line − H) − s) while both screens hold.
     */
    public static function maxClearedTargetShare(float $acquirerShare, float $herfindahl, float $leniency = 0.0): float
    {
        $screens = self::reviewScreens($leniency);
        $share = max(0.0, $acquirerShare);
        $safeHarbour = $share > 0.0 ? $screens['delta'] / (2.0 * $share) : 1.0;
        $belowConcentration = sqrt(($share * $share) + max(0.0, $screens['concentrated'] - $herfindahl)) - $share;
        $belowScreens = min($screens['shareCeiling'] - $share, $belowConcentration);

        return min(1.0, max(0.0, $safeHarbour, $belowScreens));
    }

    /**
     * The largest deal that keeps a capital ratio out of the grey zone once the goodwill is deducted: a bank must stay
     * well capitalized to have a merger approved (Bank Merger Act; Regulation Y) and an insurer needs its regulator's
     * Form A approval. Goodwill is linear in the price P, and so are the leases the acquired revenue brings onto the
     * balance sheet (IFRS 16), so the bound is closed-form. Paid in cash, the deal swaps cash for identifiable assets
     * and goodwill, taking the goodwill off tangible capital and tangible assets alike; the part funded by new debt
     * adds that debt to the assets as well. C and A are tangible capital and assets, f the floor, g and ℓ the goodwill
     * and lease each dollar of price brings, and U the cash paid before anything is borrowed; nothing when the ratio
     * already sits at the floor.
     */
    public static function capitalClearedPrice(float $capital, float $assets, float $floor, float $goodwillPerDollar, float $leasePerDollar, float $usableCash): float
    {
        $headroom = $capital - ($floor * $assets);
        if ($headroom <= 0.0) {
            return 0.0;
        }

        // Cash-funded: (C − gP) / (A − gP + ℓP) ≥ f.
        $cashFundedRate = ($goodwillPerDollar * (1.0 - $floor)) + ($floor * $leasePerDollar);
        $cashFunded = $cashFundedRate > 0.0 ? $headroom / $cashFundedRate : INF;
        if ($cashFunded <= $usableCash) {
            return $cashFunded;
        }

        // Past the usable cash the rest is borrowed: (C − gP) / (A − gP + ℓP + P − U) ≥ f.
        return ($capital - ($floor * ($assets - $usableCash)))
            / max(1e-9, $goodwillPerDollar + ($floor * (1.0 - $goodwillPerDollar + $leasePerDollar)));
    }
}
