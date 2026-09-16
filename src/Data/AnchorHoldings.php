<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The listed anchor stakes a permanent-capital sphere holds in other companies on this exchange.
 *
 * Named holdings valued off the market, not a dial: a sphere whose NAV is a number cannot fall when the
 * market it holds falls. Declared as a CONVICTION (see AnchorStake) rather than a percentage, so adding a
 * holding is one word and the class retunes in one place.
 *
 * A tier resolves to a fraction of the held company's MARKET CAP. Split-proof by construction, and the
 * archetype's own behaviour — a sphere defends its ownership percentage. Unmodelled: it sitting a buyback
 * or an issue out, which here reads as tendering or subscribing pro rata.
 *
 * Ownership here and the holding's `public_float` are one fact; the float is derived from this, never typed
 * beside it. Guarded in AnchorHoldingsTest.
 */
final class AnchorHoldings
{
    // --- Configuration Bounds ---
    /** Least capital employed a sphere must keep OUTSIDE its portfolio. The subsidiaries are the residual, so a stake list that swallows the book stops their stream being drawn at all. */
    public const MIN_CONSOLIDATED_SHARE = 0.20;

    /** Least tradable float a listing must keep once every anchor block is subtracted, or nobody can trade it. */
    public const MIN_TRADABLE_FLOAT = 0.05;

    /**
     * Holder ticker => held ticker => how firmly it is held.
     *
     * @var array<string, array<string, AnchorStake>>
     */
    public const STAKES = [
        // --- Breakwater Trust (BRKW) ---
        // The engineering, transport, grid and civic-utility pillars its charter exists to steward.
        'BRKW' => [
            'ALBT' => AnchorStake::Anchor,  // Deepwater berths: the closest thing the sphere has to a subsidiary
            'CBIL' => AnchorStake::Control,   // Precision toolmakers, the oldest holding in the sphere
            'ALCA' => AnchorStake::Control,   // Specialty industrial machinery, the engineering champion of the roster
            'KSTL' => AnchorStake::Anchor,   // Rail corridors, held for the right of way rather than the rolling stock
            'BIRD' => AnchorStake::Anchor,   // Regulated electric: the municipal concession the Trust was chartered around
            'ERNE' => AnchorStake::Anchor,   // Communication equipment, the grid's nervous system
            'SNDR' => AnchorStake::Control, // Industrial machinery, a seat and nomination rights
            'RIVE' => AnchorStake::Anchor, // Industrial machinery, the smaller of the two machinery holdings
            'EIDR' => AnchorStake::Anchor, // Vehicle manufacturing, the cyclical edge of the portfolio
            'NUTH' => AnchorStake::Minority, // Building products, the materials half of the infrastructure programme
            'BUZT' => AnchorStake::Minority, // Heavy construction machinery, cyclical and held through the cycle
            'IBIS' => AnchorStake::Minority, //

        ],
    ];

    /**
     * The stakes a holder declares as fractions; empty for the firms that hold none, which is nearly all.
     *
     * @return array<string, float>
     */
    public static function forHolder(string $ticker): array
    {
        return array_map(
            static fn(AnchorStake $stake): float => $stake->fraction(),
            self::STAKES[$ticker] ?? []
        );
    }

    /**
     * The same holdings as the convictions they were declared at, for a page that names the kind of
     * holding rather than only the size of it.
     *
     * @return array<string, AnchorStake>
     */
    public static function tiersForHolder(string $ticker): array
    {
        return self::STAKES[$ticker] ?? [];
    }

    /** Fraction of a company locked away by anchor holders, and so absent from its free float. */
    public static function closelyHeldShare(string $ticker): float
    {
        $held = 0.0;

        foreach (self::STAKES as $stakes) {
            $held += isset($stakes[$ticker]) ? $stakes[$ticker]->fraction() : 0.0;
        }

        return $held;
    }

    /**
     * A holding's tradable float: what its seed row declares, less whatever the anchors have locked away.
     *
     * Derived, because two numbers that must agree and live in different files do not stay agreeing.
     * The seed row still means "float before any anchor block", which keeps IBHI and CNDR at the 0.45
     * LAKE and SWAN hold — those two are not spheres.
     */
    public static function tradableFloat(string $ticker, float $declaredFloat): float
    {
        return max(0.0, min(1.0, $declaredFloat) - self::closelyHeldShare($ticker));
    }

    /** Share of capital employed that is NOT the portfolio, and so is the subsidiaries. Checked against MIN_CONSOLIDATED_SHARE by whoever has the board priced. */
    public static function consolidatedShare(float $portfolioValue, float $investedCapital): float
    {
        if ($investedCapital <= 0.0) {
            return 0.0;
        }

        return max(0.0, $investedCapital - max(0.0, $portfolioValue)) / $investedCapital;
    }
}
