<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Display metadata for the macro observables that revenue-stream attribution names as drivers.
 *
 * App\EventSubscriber\EarningsReportSubscriber tags every driver it writes with the
 * MacroStateDTO fields the driver was computed from (the `fields` key), and this catalog turns
 * one of those field names into a printable reading: a short label and the unit the value should
 * be rendered in. That is what lets a driver row show the observed macro level a filing was
 * struck under — "Output Gap −0.80%" — instead of a bare model coefficient that reconciles to
 * nothing the reader can check.
 *
 * Field names are the snake_case keys App\DTO\MacroStateDTO::toArray() publishes, which are also
 * the names App\Data\DistrictMap's institution `fields`/`readouts` use, so a driver reading and
 * the district institution publishing that same variable always print the same number.
 */
final class MacroFieldCatalog
{
    // --- Reading Units ---

    /** Rate or ratio held as a decimal fraction; printed as a percentage (0.0425 → "4.25%"). */
    public const UNIT_PERCENT = 'pct';

    /** Spread held as a decimal fraction; printed in basis points (0.0125 → "125 bps"). */
    public const UNIT_BPS = 'bps';

    /** Diffusion or price index on a 100.0 base; printed as a level (104.23 → "104.2"). */
    public const UNIT_INDEX = 'index';

    // --- Field Catalog ---

    /**
     * Macro field name => [label, unit] for every observable named by stream driver attribution.
     *
     * @var array<string, array{label: string, unit: string}>
     */
    public const FIELDS = [
        'output_gap_ema' => ['label' => 'Output Gap', 'unit' => self::UNIT_PERCENT],
        'inflation_ema' => ['label' => 'CPI Inflation', 'unit' => self::UNIT_PERCENT],
        'policy_rate_ema' => ['label' => 'Policy Rate', 'unit' => self::UNIT_PERCENT],
        'yield_2y_ema' => ['label' => '2Y Yield', 'unit' => self::UNIT_PERCENT],
        'yield_10y_ema' => ['label' => '10Y Yield', 'unit' => self::UNIT_PERCENT],
        'macro_credit_spread_ema' => ['label' => 'IG Credit Spread', 'unit' => self::UNIT_BPS],
        'interbank_liquidity_spread_ema' => ['label' => 'Interbank Spread', 'unit' => self::UNIT_BPS],
        'retail_default_rate_ema' => ['label' => 'Retail Default Rate', 'unit' => self::UNIT_PERCENT],
        'market_volatility_ema' => ['label' => 'Implied Volatility', 'unit' => self::UNIT_PERCENT],
        'consumer_sentiment_index_ema' => ['label' => 'Consumer Sentiment', 'unit' => self::UNIT_INDEX],
        'government_spending_index_ema' => ['label' => 'Government Spending', 'unit' => self::UNIT_INDEX],
        'commercial_property_index_ema' => ['label' => 'Commercial Property', 'unit' => self::UNIT_INDEX],
        'industrial_metals_index_ema' => ['label' => 'Industrial Metals', 'unit' => self::UNIT_INDEX],
        'agricultural_commodity_index_ema' => ['label' => 'Agricultural Commodities', 'unit' => self::UNIT_INDEX],
        'energy_price_index_ema' => ['label' => 'Energy Prices', 'unit' => self::UNIT_INDEX],
        'freight_rate_index_ema' => ['label' => 'Freight Rates', 'unit' => self::UNIT_INDEX],
        'exchange_rate_index_ema' => ['label' => 'Trade-Weighted FX', 'unit' => self::UNIT_INDEX],
    ];

    /**
     * Resolves one field to a printable reading, or null when the field is not catalogued or the
     * snapshot does not carry it.
     *
     * @param  array<string, mixed> $macroSnapshot MacroStateDTO::toArray()
     * @return array{field: string, label: string, unit: string, value: float}|null
     */
    public static function readingFor(string $field, array $macroSnapshot): ?array
    {
        if (!isset(self::FIELDS[$field]) || !isset($macroSnapshot[$field]) || !is_numeric($macroSnapshot[$field])) {
            return null;
        }

        return [
            'field' => $field,
            'label' => self::FIELDS[$field]['label'],
            'unit' => self::FIELDS[$field]['unit'],
            'value' => round((float) $macroSnapshot[$field], 6),
        ];
    }
}
