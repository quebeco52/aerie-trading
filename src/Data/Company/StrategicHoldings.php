<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * The District's own strategic stakes in listed companies: held off the float, never traded, and kept outside the
 * Sovereign Reserve Fund's portfolio.
 *
 * This is how sovereigns hold market infrastructure. Singapore holds about 23% of its exchange through SEL Holdings,
 * a special-purpose company owned by Temasek, for MAS's Financial Sector Development Fund, apart from GIC's reserve
 * portfolio. Norway holds 67% of Equinor through a ministry rather than the GPFG and routes the dividends into the
 * fund. So a stake here is not a sleeve of SovereignFundSubsystem and never enters its weights, bands or ownership
 * ceiling; only its cash reaches the fund.
 *
 * A stake is a share of shares outstanding. The District tenders into buybacks and takes up issues pro rata, as the
 * Norwegian state keeps its 67% of Equinor through the company's buybacks, so the percentage never drifts; StockTracker
 * settles that participation in cash beside the dividends.
 * AnchorHoldings::closelyHeldShare subtracts it from the float, so the stake and the float are one fact.
 */
final class StrategicHoldings
{
    // --- Clearinghouse Stake (Singapore: SEL Holdings in SGX) ---
    /** The District's stake in its central counterparty, as a share of shares outstanding (SEL Holdings holds about 23% of SGX). */
    public const CLEARINGHOUSE_STAKE = 0.23;

    /**
     * Held ticker => the District's share of its shares outstanding.
     *
     * @var array<string, float>
     */
    public const STAKES = [
        'ACC' => self::CLEARINGHOUSE_STAKE, // Aerie Central Clearing: the District's CCP
    ];

    /** The District's share of a company's shares outstanding; zero for every company it holds no stake in. */
    public static function stake(string $ticker): float
    {
        return self::STAKES[$ticker] ?? 0.0;
    }
}
