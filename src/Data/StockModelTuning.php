<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Centralized registry for company-specific business model tuning overrides.
 *
 * While industry sector models define universal economic physics, specific firms within
 * an industry often operate distinct business models (e.g., pure quantitative market-making vs.
 * M&A advisory syndicates). This class provides strictly typed, version-controlled overrides.
 */
class StockModelTuning
{
    // --- Model Parameter Overrides by Ticker ---
    /**
     * Ticker-keyed parameter tuning map.
     *
     * @var array<string, array<string, float>>
     */
    public const OVERRIDES = [
        // --- Peregrine Prime Securities (PERE) ---
        // PERE functions as the ultimate quantitative institutional market maker and derivatives options writer
        // (Citadel Securities / Jane Street archetype). It generates 100% of its revenue from Sales & Trading
        // and volatility arbitrage, and 0% from M&A advisory.
        'PERE' => [
            'advisory_revenue_weight' => 0.00,
            'trading_revenue_weight'  => 1.00,
            'vix_arbitrage_scalar'    => 1.80,
        ],

        // --- Kingfisher Capital (KING) ---
        // Pure-play M&A advisory syndicate and capital markets boutique (Lazard / Evercore archetype).
        // Hyper-sensitive to corporate deal pipelines and GDP booms; experiences severe drawdowns when M&A stalls.
        'KING' => [
            'advisory_revenue_weight' => 0.75,
            'trading_revenue_weight'  => 0.25,
            'vix_arbitrage_scalar'    => 1.20,
        ],
    ];

    /**
     * Retrieves a tuned parameter for a given stock ticker, or falls back to the baseline default.
     */
    public static function get(string $ticker, string $parameterKey, float $default): float
    {
        return self::OVERRIDES[$ticker][$parameterKey] ?? $default;
    }

    /**
     * Checks if a stock ticker has any model tuning overrides.
     */
    public static function hasOverrides(string $ticker): bool
    {
        return isset(self::OVERRIDES[$ticker]);
    }

    /**
     * Returns all tuning overrides for a specific ticker.
     *
     * @return array<string, float>
     */
    public static function getOverridesForTicker(string $ticker): array
    {
        return self::OVERRIDES[$ticker] ?? [];
    }

    /**
     * Resolves a complete parameter map by merging baseline defaults with any company-specific tuning overrides.
     *
     * @param array<string, float> $defaults
     * @return array<string, float>
     */
    public static function resolve(string $ticker, array $defaults): array
    {
        if (!isset(self::OVERRIDES[$ticker])) {
            return $defaults;
        }

        return array_merge($defaults, self::OVERRIDES[$ticker]);
    }
}
