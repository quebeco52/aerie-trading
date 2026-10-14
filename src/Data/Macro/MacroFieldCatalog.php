<?php

declare(strict_types=1);

namespace App\Data\Macro;

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
 * the names App\Data\District\DistrictMap's institution `fields`/`readouts` use, so a driver reading and
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

    /** Currency amount; printed compactly (2.4e13 → "24T"). */
    public const UNIT_LEVEL = 'level';

    // --- Field Catalog ---

    /**
     * Macro field name => [label, unit] for every input a business model declares it reads, which is every input a stream driver can name.
     *
     * @var array<string, array{label: string, unit: string}>
     */
    public const FIELDS = [
        'output_gap_ema' => ['label' => 'Output gap', 'unit' => self::UNIT_PERCENT],
        'output_gap_lag_3m' => ['label' => 'Output gap (3-month lag)', 'unit' => self::UNIT_PERCENT],
        'output_gap_lag_6m' => ['label' => 'Output gap (6-month lag)', 'unit' => self::UNIT_PERCENT],
        'output_gap_lag_9m' => ['label' => 'Output gap (9-month lag)', 'unit' => self::UNIT_PERCENT],
        'output_gap_lag_12m' => ['label' => 'Output gap (12-month lag)', 'unit' => self::UNIT_PERCENT],
        'output_gap_lag_15m' => ['label' => 'Output gap (15-month lag)', 'unit' => self::UNIT_PERCENT],
        'output_gap_lag_18m' => ['label' => 'Output gap (18-month lag)', 'unit' => self::UNIT_PERCENT],
        'inflation_ema' => ['label' => 'CPI inflation', 'unit' => self::UNIT_PERCENT],
        'policy_rate_ema' => ['label' => 'Policy rate', 'unit' => self::UNIT_PERCENT],
        'yield_2y_ema' => ['label' => '2Y yield', 'unit' => self::UNIT_PERCENT],
        'yield_10y_ema' => ['label' => '10Y yield', 'unit' => self::UNIT_PERCENT],
        'treasury_volatility' => ['label' => 'Treasury rate volatility', 'unit' => self::UNIT_BPS],
        'treasury_volatility_trend' => ['label' => 'Treasury rate volatility (trend)', 'unit' => self::UNIT_BPS],
        'macro_credit_spread_ema' => ['label' => 'IG credit spread', 'unit' => self::UNIT_BPS],
        'high_yield_credit_spread_ema' => ['label' => 'HY credit spread', 'unit' => self::UNIT_BPS],
        'interbank_liquidity_spread_ema' => ['label' => 'Interbank spread', 'unit' => self::UNIT_BPS],
        'retail_default_rate_ema' => ['label' => 'Retail default rate', 'unit' => self::UNIT_PERCENT],
        'household_debt_to_income_ema' => ['label' => 'Household debt / income', 'unit' => self::UNIT_PERCENT],
        'household_debt_service_ratio_ema' => ['label' => 'Debt service ratio', 'unit' => self::UNIT_PERCENT],
        'household_debt_service_gap' => ['label' => 'Debt service gap', 'unit' => self::UNIT_PERCENT],
        'credit_to_gdp_gap_ema' => ['label' => 'Credit gap', 'unit' => self::UNIT_PERCENT],
        'countercyclical_buffer_rate' => ['label' => 'Countercyclical buffer', 'unit' => self::UNIT_PERCENT],
        'bank_capital_requirement' => ['label' => 'Bank capital requirement', 'unit' => self::UNIT_PERCENT],
        'market_volatility_ema' => ['label' => 'Implied volatility', 'unit' => self::UNIT_PERCENT],
        'consumer_sentiment_index_ema' => ['label' => 'Consumer sentiment', 'unit' => self::UNIT_INDEX],
        'government_spending_index_ema' => ['label' => 'Government spending', 'unit' => self::UNIT_INDEX],
        'allied_defense_spending_index_ema' => ['label' => 'Allied defence spending', 'unit' => self::UNIT_INDEX],
        'reimbursement_rate_growth' => ['label' => 'Reimbursement update', 'unit' => self::UNIT_PERCENT],
        'system_deposit_beta_ema' => ['label' => 'Deposit beta', 'unit' => self::UNIT_PERCENT],
        'money_market_fund_share_ema' => ['label' => 'Money-market share', 'unit' => self::UNIT_PERCENT],
        'commercial_property_index_ema' => ['label' => 'Commercial property', 'unit' => self::UNIT_INDEX],
        'industrial_metals_index_ema' => ['label' => 'Industrial metals', 'unit' => self::UNIT_INDEX],
        'agricultural_commodity_index_ema' => ['label' => 'Agricultural commodities', 'unit' => self::UNIT_INDEX],
        'energy_price_index_ema' => ['label' => 'Energy prices', 'unit' => self::UNIT_INDEX],
        'natural_gas_price_index_ema' => ['label' => 'Natural gas', 'unit' => self::UNIT_INDEX],
        'gold_price_index_ema' => ['label' => 'Gold', 'unit' => self::UNIT_INDEX],
        'wholesale_power_price_index_ema' => ['label' => 'Wholesale power', 'unit' => self::UNIT_INDEX],
        'carbon_price' => ['label' => 'Carbon price ($/t)', 'unit' => self::UNIT_INDEX],
        'extraction_stringency' => ['label' => 'Extraction rules', 'unit' => self::UNIT_PERCENT],
        'stamp_duty_rate' => ['label' => 'Stamp duty (each side)', 'unit' => self::UNIT_PERCENT],
        'utility_rate_case_grant_share' => ['label' => 'Utility rate requests granted', 'unit' => self::UNIT_PERCENT],
        'catastrophe_loss_index_ema' => ['label' => 'Catastrophe losses', 'unit' => self::UNIT_INDEX],
        'freight_rate_index_ema' => ['label' => 'Freight rates', 'unit' => self::UNIT_INDEX],
        'exchange_rate_index_ema' => ['label' => 'Trade-weighted FX', 'unit' => self::UNIT_INDEX],
        'exchange_rate_deviation' => ['label' => 'Currency against fair value', 'unit' => self::UNIT_LEVEL],
        'foreign_output_gap_ema' => ['label' => 'Mainland output gap', 'unit' => self::UNIT_PERCENT],
        'foreign_equity_valuation' => ['label' => 'Mainland share valuations', 'unit' => self::UNIT_PERCENT],
        'foreign_policy_rate_ema' => ['label' => 'Mainland policy rate', 'unit' => self::UNIT_PERCENT],
        'global_demand_gap_ema' => ['label' => 'Global demand gap', 'unit' => self::UNIT_PERCENT],
        'sovereign_risk_spread_ema' => ['label' => 'Sovereign spread', 'unit' => self::UNIT_BPS],
        'primary_deficit_to_gdp' => ['label' => 'Primary deficit', 'unit' => self::UNIT_PERCENT],
        'yield_5y_ema' => ['label' => '5Y yield', 'unit' => self::UNIT_PERCENT],
        'yield_30y_ema' => ['label' => '30Y yield', 'unit' => self::UNIT_PERCENT],
        'tips_breakeven_ema' => ['label' => 'Expected inflation (10-year)', 'unit' => self::UNIT_PERCENT],
        'supercore_inflation_ema' => ['label' => 'Supercore inflation', 'unit' => self::UNIT_PERCENT],
        'producer_price_inflation_ema' => ['label' => 'Producer price inflation', 'unit' => self::UNIT_PERCENT],
        'perceived_neutral_rate' => ['label' => 'Perceived neutral rate', 'unit' => self::UNIT_PERCENT],
        'natural_rate_ema' => ['label' => 'Natural rate', 'unit' => self::UNIT_PERCENT],
        'ns_slope' => ['label' => 'Curve slope', 'unit' => self::UNIT_BPS],
        'ns_slope_ema' => ['label' => 'Curve slope (smoothed)', 'unit' => self::UNIT_BPS],
        'macro_credit_spread' => ['label' => 'IG credit spread (spot)', 'unit' => self::UNIT_BPS],
        'high_yield_credit_spread' => ['label' => 'HY credit spread (spot)', 'unit' => self::UNIT_BPS],
        'corporate_default_rate_ema' => ['label' => 'Corporate default rate', 'unit' => self::UNIT_PERCENT],
        'recession_probability_ema' => ['label' => 'Recession probability', 'unit' => self::UNIT_PERCENT],
        'sloos_tightening_index_ema' => ['label' => 'Lending standards (net tightening)', 'unit' => self::UNIT_PERCENT],
        'money_supply_growth_ema' => ['label' => 'Broad money growth', 'unit' => self::UNIT_PERCENT],
        'money_supply_growth_trend' => ['label' => 'Broad money growth trend', 'unit' => self::UNIT_PERCENT],
        'money_market_fund_share' => ['label' => 'Money-market share (spot)', 'unit' => self::UNIT_PERCENT],
        'qe_intensity' => ['label' => 'QE intensity', 'unit' => self::UNIT_PERCENT],
        'qe_active' => ['label' => 'QE programme', 'unit' => self::UNIT_PERCENT],
        'unemployment_rate_ema' => ['label' => 'Unemployment', 'unit' => self::UNIT_PERCENT],
        'nairu_ema' => ['label' => 'Natural unemployment', 'unit' => self::UNIT_PERCENT],
        'real_wage_gap' => ['label' => 'Real wage gap', 'unit' => self::UNIT_PERCENT],
        'capacity_utilization_rate_ema' => ['label' => 'Capacity utilisation', 'unit' => self::UNIT_PERCENT],
        'capital_stock_overhang_ema' => ['label' => 'Capital overhang', 'unit' => self::UNIT_PERCENT],
        'inventory_stock_gap_ema' => ['label' => 'Inventory gap', 'unit' => self::UNIT_PERCENT],
        'trade_balance_to_gdp_ema' => ['label' => 'Trade balance / GDP', 'unit' => self::UNIT_PERCENT],
        'manufacturing_pmi_ema' => ['label' => 'Manufacturing PMI', 'unit' => self::UNIT_INDEX],
        'manufacturing_pmi' => ['label' => 'Manufacturing PMI (spot)', 'unit' => self::UNIT_INDEX],
        'supply_chain_pressure_index_ema' => ['label' => 'Supply-chain pressure', 'unit' => self::UNIT_INDEX],
        'deal_activity_index_ema' => ['label' => 'Deal activity', 'unit' => self::UNIT_INDEX],
        'housing_starts_index_ema' => ['label' => 'Housing starts', 'unit' => self::UNIT_INDEX],
        'residential_property_index_ema' => ['label' => 'House prices', 'unit' => self::UNIT_INDEX],
        'residential_wealth_trend' => ['label' => 'House price trend', 'unit' => self::UNIT_INDEX],
        'nominal_gdp_index' => ['label' => 'Nominal GDP (opening = 1)', 'unit' => self::UNIT_INDEX],
        'potential_gdp_index' => ['label' => 'Potential output (opening = 1)', 'unit' => self::UNIT_INDEX],
        'gdp_deflator' => ['label' => 'GDP deflator (opening = 1)', 'unit' => self::UNIT_INDEX],
        'equity_wealth_ratio' => ['label' => 'Equity market wealth', 'unit' => self::UNIT_LEVEL],
        'sovereign_fund_to_gdp' => ['label' => 'Reserve Fund / GDP', 'unit' => self::UNIT_PERCENT],
        'energy_base_price' => ['label' => 'Energy base price', 'unit' => self::UNIT_INDEX],
        'energy_supply_ema' => ['label' => 'Energy supply', 'unit' => self::UNIT_INDEX],
        'energy_inventory_index_ema' => ['label' => 'Energy inventories', 'unit' => self::UNIT_INDEX],
        'energy_cost_push_lag' => ['label' => 'Energy cost pass-through', 'unit' => self::UNIT_PERCENT],
        'refining_crack_spread_ema' => ['label' => 'Crack spread ($/bbl)', 'unit' => self::UNIT_INDEX],
        'refining_crack_spread' => ['label' => 'Crack spread ($/bbl, spot)', 'unit' => self::UNIT_INDEX],
    ];

    /**
     * The name a field takes when its reading is negative, for a net balance whose minus sign would read as a double
     * negative ("net tightening −14%" is net easing of 14%).
     *
     * @var array<string, string>
     */
    public const NEGATIVE_LABELS = [
        'sloos_tightening_index_ema' => 'Lending standards (net easing)',
    ];

    /** The field's printable name; an uncatalogued field is named by its own key. */
    public static function labelFor(string $field): string
    {
        return self::FIELDS[$field]['label'] ?? ucfirst(str_replace('_', ' ', $field));
    }

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

    /**
     * A reading's name and value as a reader takes them: a field with a name for its negative side shows that name and
     * the size of the reading instead of a minus sign.
     *
     * @return array{0: string, 1: float}
     */
    public static function signedReading(string $field, float $value): array
    {
        if ($value < 0.0 && isset(self::NEGATIVE_LABELS[$field])) {
            return [self::NEGATIVE_LABELS[$field], -$value];
        }

        return [self::labelFor($field), $value];
    }

    /**
     * A filing's stream drivers with today's names: a macro driver named for its field through this catalog, readings
     * included, and an execution driver in sentence case. Filings keep the label they were struck with, so this is what
     * lets a renamed field read the same on old reports as on new ones.
     *
     * @param  list<array<string, mixed>> $drivers CorporateReport stream detail drivers
     * @return list<array<string, mixed>>
     */
    public static function relabelDrivers(array $drivers): array
    {
        return array_map(static function (array $driver): array {
            $field = $driver['readings'][0]['field'] ?? null;
            if (is_string($field) && isset(self::FIELDS[$field])) {
                $driver['label'] = self::FIELDS[$field]['label'];
                $driver['readings'] = array_map(
                    static fn (array $reading): array => isset(self::FIELDS[$reading['field'] ?? '']) ? ['label' => self::FIELDS[$reading['field']]['label']] + $reading : $reading,
                    $driver['readings']
                );
            } elseif (($driver['type'] ?? null) === 'momentum' && is_string($driver['label'] ?? null)) {
                $driver['label'] = ucfirst(mb_strtolower($driver['label']));
            }

            return $driver;
        }, $drivers);
    }
}
