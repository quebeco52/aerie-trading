<?php

namespace App\Data;

/**
 * The canonical names of the District's public bodies and market institutions. Pages, the Council's department list,
 * the district map and the "Source:" line under each chart all read their names from here, so a body is never called
 * two things on two pages. tests/Twig/TemplateStyleTest rejects a source line naming anything not listed.
 */
final class Institutions
{
    // --- Public bodies ---
    public const AERIE_COUNCIL = 'Aerie Council';
    /** Puts forward the candidates for each Council seat; its members are named by the District's bar, judges, universities and the Aerie Exchange. */
    public const COUNCIL_APPOINTMENT_BOARD = 'Council Appointment Board';
    public const DIET = 'Diet';
    /** The central bank: sets the policy rate. Never the Rate Council or the Fed. */
    public const MONETARY_AUTHORITY = 'Monetary Authority';
    public const FINANCIAL_REGULATOR = 'Financial Regulator';
    /** Runs the budget the Diet passes and the District's debt, as the Exchequer does in Jersey and Ireland. */
    public const EXCHEQUER = 'Exchequer';
    public const SOVEREIGN_RESERVE_FUND = 'Sovereign Reserve Fund';
    public const TRADE_MIGRATION_OFFICE = 'Trade & Migration Office';
    public const STATISTICAL_OFFICE = 'Statistical Office';
    public const CREDIT_REGISTRY = 'Credit Registry';
    public const LAND_REGISTRY = 'Land Registry';
    public const FREIGHT_AUTHORITY = 'Freight Authority';
    public const MANUFACTORY_BOARD = 'Manufactory Board';
    public const WORKS_MINISTRY = 'Works Ministry';

    // --- Markets ---
    /** The securities market the site belongs to. Never the Lakebird Exchange. */
    public const AERIE_EXCHANGE = 'Aerie Exchange';
    public const COMMODITY_EXCHANGE = 'Commodity Exchange';

    // --- Market data ---
    /** Tickbird Data Systems' research desk, which writes the long-form company profiles for terminal subscribers. */
    public const TICKBIRD_RESEARCH = 'Tickbird Research';

    /**
     * Every name a page may print as a publisher, keyed by the id templates pass to `source()`.
     *
     * @var array<string, string>
     */
    public const PUBLISHERS = [
        'aerie-council' => self::AERIE_COUNCIL,
        'monetary-authority' => self::MONETARY_AUTHORITY,
        'financial-regulator' => self::FINANCIAL_REGULATOR,
        'exchequer' => self::EXCHEQUER,
        'sovereign-reserve-fund' => self::SOVEREIGN_RESERVE_FUND,
        'trade-migration-office' => self::TRADE_MIGRATION_OFFICE,
        'statistical-office' => self::STATISTICAL_OFFICE,
        'credit-registry' => self::CREDIT_REGISTRY,
        'land-registry' => self::LAND_REGISTRY,
        'freight-authority' => self::FREIGHT_AUTHORITY,
        'manufactory-board' => self::MANUFACTORY_BOARD,
        'works-ministry' => self::WORKS_MINISTRY,
        'aerie-exchange' => self::AERIE_EXCHANGE,
        'commodity-exchange' => self::COMMODITY_EXCHANGE,
        'tickbird-research' => self::TICKBIRD_RESEARCH,
    ];
}
