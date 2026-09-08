<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Authored cartography for the Aerie Autonomous District.
 *
 * Plot geometry is hand-drawn once and never derived from market data. Company fundamentals
 * decide what is *rendered* on a plot (facade height, condition, lighting), never where the
 * plot sits. Geography is strictly a presentation concern and must never feed the financial
 * simulation: contagion belongs to the macro and shock engines, not to street adjacency.
 */
class DistrictMap
{
    // --- Ward Registry ---
    /** Authored wards keyed by slug. Each ward renders as a single orthographic street elevation. */
    public const WARDS = [
        'glasswater-row' => [
            'name' => 'Glasswater Row',
            'sector' => 'Financials',
            'tagline' => 'The capital spine of the District, where Lakebird paper clears as reserve asset.',
            'viewbox_width' => 2882,
            'viewbox_height' => 1300,
            'ground_line' => 1100,
            'quay_depth' => 46,
        ],
    ];

    // --- Skyline Rendering Envelope ---
    /** Facade height in user units for a plot at or below the market capitalisation floor. */
    public const MIN_FACADE_HEIGHT = 90.0;
    /**
     * Facade height in user units for a plot at or above the market capitalisation ceiling.
     * Held below the ground line with headroom to spare so the tallest facade never reaches
     * the institution band (y 60-260) or the conduit airspace beneath it (y 260-400).
     */
    public const MAX_FACADE_HEIGHT = 700.0;
    /** Log10 market capitalisation floor (~$32B) mapped to MIN_FACADE_HEIGHT. */
    public const MARKET_CAP_LOG_FLOOR = 10.5;
    /** Log10 market capitalisation ceiling (~$3.2T) mapped to MAX_FACADE_HEIGHT. */
    public const MARKET_CAP_LOG_CEILING = 13.5;
    /** Normalised facade value the log floor/ceiling map to (edges approach but never hit the envelope ends). */
    public const MARKET_CAP_LOG_EDGE_TOLERANCE = 0.02;
    /** Vertical spacing in user units between rendered window bands on a facade. */
    public const FLOOR_HEIGHT = 46.0;

    // --- Condition Thresholds ---
    /** Credit rating rank at or above which a facade renders as sound (BBB and better). */
    public const INVESTMENT_GRADE_RANK = 4;

    // --- Land Registry Stress ---
    /** Fractional deviation of a property index from its 100.0 baseline that reads as a real-estate shock. */
    public const LAND_REGISTRY_PROPERTY_STRESS_DEVIATION = 0.15;

    // --- Institution Band Geometry ---
    /** Top y-coordinate of the institution structures on the far bank. */
    public const INSTITUTION_BAND_TOP = 60;
    /** Height in user units of an institution's structure. */
    public const INSTITUTION_BAND_HEIGHT = 140;
    /**
     * Y-coordinate conduits emanate from — below the structure, its name, and its two-line
     * readout, above the airspace. The ward canvas renders at ~1000-1150px wide in a typical
     * browser against a 2882-wide viewBox (~0.35-0.4x scale), so text sizes throughout this ward
     * run 1.5-2x larger than they would need to at 1:1 — see also FLOOR_HEIGHT-adjacent text
     * classes in templates/district/index.html.twig.
     */
    public const INSTITUTION_OUTLET_Y = 285;

    // --- Readout Units ---
    /** Field is a decimal fraction (e.g. 0.02) displayed as a percentage. */
    public const UNIT_PERCENT = 'pct';
    /** Field is an index around a 100.0 baseline, displayed as a plain number. */
    public const UNIT_INDEX = 'index';

    // --- Institution Registry ---
    /**
     * Abstract macro-publishing institutions on Glasswater Row's far bank — councils and
     * registries, not rival sovereigns to Lakebird. Each names the real `MacroStateDTO` fields
     * (see App\DTO\MacroStateDTO) it publishes; a field is listed here only where CONDUITS also
     * wires at least one tenant to it, so no institution exists as pure decoration. Geometry
     * (x, width) is authored the same as PLOTS — hand-placed once, never computed from data.
     *
     * `readouts` is the small subset of `fields` actually printed on the structure — a curated
     * pick of the one or two numbers a viewer would recognise at a glance, not every field the
     * institution publishes. Every readout field must also appear in that institution's `fields`.
     */
    public const INSTITUTIONS = [
        'rate-council' => [
            'label' => 'The Rate Council',
            'x' => 100,
            'width' => 440,
            'fields' => ['policy_rate_ema', 'yield_2y_ema', 'yield_5y_ema', 'yield_10y_ema', 'yield_30y_ema', 'ns_slope', 'ns_slope_ema', 'money_supply_growth_ema'],
            'readouts' => [
                ['field' => 'policy_rate_ema', 'label' => 'POLICY', 'unit' => self::UNIT_PERCENT],
                ['field' => 'yield_10y_ema', 'label' => '10Y', 'unit' => self::UNIT_PERCENT],
            ],
        ],
        'credit-registry' => [
            'label' => 'The Credit Registry',
            'x' => 650,
            'width' => 480,
            'fields' => ['macro_credit_spread', 'macro_credit_spread_ema', 'high_yield_credit_spread_ema', 'interbank_liquidity_spread_ema', 'corporate_default_rate_ema', 'retail_default_rate_ema', 'sloos_tightening_index_ema', 'recession_probability_ema'],
            'readouts' => [
                ['field' => 'macro_credit_spread_ema', 'label' => 'IG SPREAD', 'unit' => self::UNIT_PERCENT],
                ['field' => 'high_yield_credit_spread_ema', 'label' => 'HY SPREAD', 'unit' => self::UNIT_PERCENT],
            ],
        ],
        'exchange-floor' => [
            'label' => 'The Exchange Floor',
            'x' => 1240,
            'width' => 420,
            'fields' => ['market_volatility_ema', 'deal_activity_index_ema'],
            'readouts' => [
                ['field' => 'market_volatility_ema', 'label' => 'VOL', 'unit' => self::UNIT_PERCENT],
                ['field' => 'deal_activity_index_ema', 'label' => 'DEALS', 'unit' => self::UNIT_INDEX],
            ],
        ],
        'statistical-office' => [
            'label' => 'The Statistical Office',
            'x' => 1770,
            'width' => 460,
            'fields' => ['output_gap_ema', 'inflation_ema', 'unemployment_rate_ema', 'consumer_sentiment_index_ema'],
            'readouts' => [
                ['field' => 'output_gap_ema', 'label' => 'OUTPUT GAP', 'unit' => self::UNIT_PERCENT],
                ['field' => 'inflation_ema', 'label' => 'CPI', 'unit' => self::UNIT_PERCENT],
            ],
        ],
        'land-registry' => [
            'label' => 'The Land Registry',
            'x' => 2340,
            'width' => 420,
            'fields' => ['commercial_property_index_ema', 'residential_property_index_ema', 'housing_starts_index_ema'],
            'readouts' => [
                ['field' => 'commercial_property_index_ema', 'label' => 'CRE', 'unit' => self::UNIT_INDEX],
                ['field' => 'residential_property_index_ema', 'label' => 'RESI', 'unit' => self::UNIT_INDEX],
            ],
        ],
    ];

    // --- Conduit Topology ---
    /**
     * Business model identifier (App\Data\Sectors::INDUSTRY_METRICS['business_model']) to the
     * institution ids it draws a conduit from. Wired only where that model's own operating
     * physics — calculateSectorPhysics()/getMacroPhysics() in App\Service\Model\Sector\* —
     * genuinely reads one of the institution's fields; valuation-only reads shared by nearly
     * every model (equityRiskPremium, corporateTaxRate, policyRate feeding WACC alone) are
     * deliberately excluded so this stays a real topology rather than everything-to-everything.
     */
    public const CONDUITS = [
        'commercial_bank' => ['rate-council', 'credit-registry', 'statistical-office', 'land-registry'],
        // Overrides calculateSectorPhysics(), getMacroPhysics() and getTargetMetrics() in full —
        // unlike shadow_bank it never touches the property/housing indices it inherits the class
        // hierarchy from, so it does not carry the land-registry conduit its parent does.
        'credit_services' => ['rate-council', 'credit-registry', 'statistical-office'],
        'shadow_bank' => ['rate-council', 'credit-registry', 'statistical-office', 'land-registry'],
        'investment_bank' => ['rate-council', 'credit-registry', 'exchange-floor', 'statistical-office'],
        'brokerage' => ['rate-council', 'credit-registry', 'exchange-floor', 'statistical-office'],
        'clearing_house' => ['rate-council', 'credit-registry', 'exchange-floor', 'statistical-office'],
        'asset_manager' => ['rate-council', 'exchange-floor', 'statistical-office'],
        'private_equity' => ['rate-council', 'credit-registry', 'exchange-floor', 'statistical-office'],
        'hedge_fund' => ['rate-council', 'credit-registry', 'exchange-floor', 'statistical-office'],
        // Deliberately narrow: distressed_debt's own physics is almost entirely credit-spread
        // driven (see DistressedDebtBusinessModel::calculateSectorPhysics), so unlike the asset
        // manager it inherits from, it does not carry the rate-council/exchange-floor conduits.
        'distressed_debt' => ['credit-registry', 'statistical-office'],
        'insurance' => ['rate-council', 'exchange-floor', 'statistical-office', 'land-registry'],
        'reinsurance' => ['rate-council', 'exchange-floor', 'statistical-office', 'land-registry'],
        'retail_insurance' => ['rate-council', 'exchange-floor', 'statistical-office', 'land-registry'],
        'financial_data' => ['credit-registry', 'exchange-floor', 'statistical-office'],
    ];

    // --- Glasswater Row Frontage ---
    /**
     * Street frontage ordered west to east: consumer credit at the western end, the sovereign
     * core (Lakebird and Central Clearing) mid-row, capital markets trailing east toward the
     * recovery houses. A null ticker is a vacant lot held for procedural listings.
     */
    public const PLOTS = [
        'GW-01' => ['ward' => 'glasswater-row', 'x' => 60,   'width' => 100, 'ticker' => 'STRK'],
        'GW-02' => ['ward' => 'glasswater-row', 'x' => 174,  'width' => 130, 'ticker' => 'TALN'],
        'GW-03' => ['ward' => 'glasswater-row', 'x' => 318,  'width' => 100, 'ticker' => 'RIVR'],
        'GW-04' => ['ward' => 'glasswater-row', 'x' => 432,  'width' => 100, 'ticker' => 'DOVE'],
        'GW-05' => ['ward' => 'glasswater-row', 'x' => 546,  'width' => 90,  'ticker' => null],
        'GW-06' => ['ward' => 'glasswater-row', 'x' => 650,  'width' => 190, 'ticker' => 'SAFE'],
        'GW-07' => ['ward' => 'glasswater-row', 'x' => 854,  'width' => 130, 'ticker' => 'POOL'],
        'GW-08' => ['ward' => 'glasswater-row', 'x' => 998,  'width' => 130, 'ticker' => 'KING'],
        'GW-09' => ['ward' => 'glasswater-row', 'x' => 1142, 'width' => 190, 'ticker' => 'LAKE'],
        'GW-10' => ['ward' => 'glasswater-row', 'x' => 1346, 'width' => 190, 'ticker' => 'ACC'],
        'GW-11' => ['ward' => 'glasswater-row', 'x' => 1550, 'width' => 130, 'ticker' => 'PERE'],
        'GW-12' => ['ward' => 'glasswater-row', 'x' => 1694, 'width' => 130, 'ticker' => 'CORV'],
        'GW-13' => ['ward' => 'glasswater-row', 'x' => 1838, 'width' => 130, 'ticker' => 'SHRK'],
        'GW-14' => ['ward' => 'glasswater-row', 'x' => 1982, 'width' => 130, 'ticker' => 'OWLS'],
        'GW-15' => ['ward' => 'glasswater-row', 'x' => 2126, 'width' => 130, 'ticker' => 'CROW'],
        'GW-16' => ['ward' => 'glasswater-row', 'x' => 2270, 'width' => 190, 'ticker' => 'SWAN'],
        'GW-17' => ['ward' => 'glasswater-row', 'x' => 2474, 'width' => 100, 'ticker' => 'ROOK'],
        'GW-18' => ['ward' => 'glasswater-row', 'x' => 2588, 'width' => 130, 'ticker' => 'VULT'],
        'GW-19' => ['ward' => 'glasswater-row', 'x' => 2732, 'width' => 90,  'ticker' => null],
    ];

    /**
     * Returns the authored plots belonging to a ward, preserving west-to-east authoring order.
     *
     * @return array<string, array{ward: string, x: int, width: int, ticker: string|null}>
     */
    public static function plotsForWard(string $wardSlug): array
    {
        return array_filter(self::PLOTS, static fn(array $plot): bool => $plot['ward'] === $wardSlug);
    }

    /**
     * Returns every ticker holding a plot in the given ward.
     *
     * @return list<string>
     */
    public static function tickersForWard(string $wardSlug): array
    {
        $tickers = [];
        foreach (self::plotsForWard($wardSlug) as $plot) {
            if ($plot['ticker'] !== null) {
                $tickers[] = $plot['ticker'];
            }
        }

        return $tickers;
    }

    /**
     * Returns the institution ids a business model draws a conduit from, or an empty list for a
     * model with no registered topology (e.g. a non-financial model with no frontage on any
     * macro-conduit ward).
     *
     * @return list<string>
     */
    public static function conduitsForBusinessModel(string $businessModel): array
    {
        return self::CONDUITS[$businessModel] ?? [];
    }
}
