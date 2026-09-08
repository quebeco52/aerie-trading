<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Authored cartography for the Aerie Autonomous District: a single street, Glasswater Row,
 * fronted by whichever ~30 listed companies currently rank largest by market cap.
 *
 * The roster is never authored — App\Service\District\DistrictWardComposer recomputes it from
 * live Stock rows on every request, so a company entering or dropping out of the top tier moves
 * in or out of frontage with no code edit. `FRONTAGE_ORDER` only decides *where on the street* a
 * given tenant stands once it has already qualified; it does not gate qualification. Company
 * fundamentals decide what is *rendered* on a plot (facade height, condition, lighting), never
 * where the plot sits. Geography is strictly a presentation concern and must never feed the
 * financial simulation: contagion belongs to the macro and shock engines, not to street adjacency.
 */
class DistrictMap
{
    // --- The Street ---
    /** The only ward. Kept as a slug (rather than inlining "glasswater-row" at call sites) so a second street remains a one-line addition, not a rewrite. */
    public const WARD_SLUG = 'glasswater-row';
    /** Display name of the street. */
    public const WARD_NAME = 'Glasswater Row';
    /** Sub-headline shown under the street name. */
    public const WARD_TAGLINE = 'The capital spine of the District — frontage held by the District\'s largest houses by market capitalisation, reshuffled as fortunes rise and fall.';
    /**
     * How many of the District's largest listed companies (by live market cap) hold frontage.
     * See DistrictWardComposer::composeFrontage() — this is a live ranking, not a fixed roster.
     */
    public const STREET_ROSTER_SIZE = 30;

    /**
     * West-to-east ordering: a business model identifier's position here decides where a
     * qualifying tenant stands relative to tenants of other models (tenants of the same model
     * then order by market cap descending). Every business model actually carried by a company in
     * App\Data\InitialMarket::STOCKS must appear here — see
     * DistrictMapTest::testFrontageOrderCoversEveryBusinessModelInTheListedUniverse — because
     * unlike the roster itself, this list is authored: the street's character (capital markets
     * west, industry and consumer names fanning east) is composed, not ranked.
     */
    public const FRONTAGE_ORDER = [
        // Financials — the historical core of the row.
        'credit_services', 'commercial_bank', 'insurance', 'reinsurance', 'shadow_bank',
        'investment_bank', 'clearing_house', 'financial_data', 'asset_manager', 'hedge_fund',
        'brokerage', 'distressed_debt',
        // Industrials & Materials.
        'steel_manufacturing', 'specialty_industrial_machinery', 'commodity', 'chemical',
        'construction', 'defense_contractor', 'security_protection', 'waste_management',
        'railroad', 'shipping', 'logistics', 'tools_and_accessories', 'law_firm',
        // Consumer Discretionary & Staples.
        'consumer_staples', 'restaurant', 'apparel_manufacturing', 'auto_manufacturer',
        'internet_retail', 'resorts_casinos', 'luxury', 'advertising_agency', 'education',
        // Energy & Utilities.
        'utility',
        // Information Technology & Communication Services.
        'tech', 'computer_hardware', 'semiconductor', 'telecom',
        // Real Estate & Health Care.
        'reit', 'medical_care_facility', 'biotech',
        // Unclassified conglomerates trail east, matching the old row's "recovery houses" tail.
        'conglomerate',
    ];

    // --- Street Canvas Geometry ---
    /**
     * Staves the frontage wraps across, like a line of music continued below. The whole roster on
     * one row put the canvas ~5,000 units wide against a ~1,150px column — 0.23px per unit, at
     * which every label and stroke on the page falls under its legibility floor. Wrapping to two
     * rows roughly halves the width and roughly doubles that scale.
     *
     * Deliberately *not* framed as near/far terraces: this is an orthographic elevation with no
     * perspective cue, and conduits render behind the upper row's buildings, so any depth claim
     * would contradict what is actually drawn. It is one street, continued on a second line.
     */
    public const ROW_COUNT = 2;
    /** Tenant count below which a single row is already legible and wrapping would only make the canvas tall and thin. */
    public const ROW_SPLIT_THRESHOLD = 8;
    /**
     * Baseline y-coordinate each row's facades stand on, upper row first. Spaced by
     * KERB_DEPTH + ROW_GAP + MAX_FACADE_HEIGHT so the tallest possible facade on the lower row
     * still clears the upper row's kerb — asserted in DistrictMapTest.
     */
    public const ROW_GROUND_LINES = [960.0, 1740.0];
    /** Clear sky in user units between one row's kerb and the tallest possible facade of the row below. */
    public const ROW_GAP = 60;
    /** Height in user units of the street's SVG viewBox. */
    public const VIEWBOX_HEIGHT = 1990;
    /**
     * Depth in user units of the kerb band below a ground line.
     *
     * Deep enough for three *stacked, centred* lines — ticker, price, change. They cannot share
     * lines: the narrowest plot is 100 units, and at a legible size "$326.91 -59.2%" alone runs
     * to ~218 units. Anything paired left-and-right on one line collides on every base-tier plot.
     */
    public const KERB_DEPTH = 100;

    // --- Frontage Layout ---
    /**
     * West gutter before the first plot on every row. Wider than the east margin because the
     * market-cap gridline labels are printed in it: the longest ("$100B") is 5 characters, and
     * Courier Prime's 0.6em advance at GRIDLINE_LABEL_SIZE needs ~96 units plus padding.
     * FRONTAGE_MARGIN still governs the east edge.
     */
    public const FRONTAGE_GUTTER = 120;
    /** East margin after the last plot on a terrace. */
    public const FRONTAGE_MARGIN = 60;
    /** Uniform gap in user units between adjacent plots, and between adjacent institutions. */
    public const FRONTAGE_GAP = 14;
    /** Plot width in user units keyed by systemic importance tier (Stock::getSystemicImportance()). */
    public const PLOT_WIDTH_BY_IMPORTANCE = [
        'titan' => 190,
        'systemic' => 130,
        'base' => 100,
        'none' => 100,
    ];

    // --- Institution Layout ---
    /**
     * Floor of an institution's derived width, however many share the street's frontage. Kept
     * narrow relative to a plot — an institution is a small publisher, not a tenant, and should
     * read as background structure the skyline stands in front of, not compete with it. Low
     * enough that on the two-row canvas the frontage always drives the width and
     * DistrictMapBuilder::resolveViewboxWidth() never becomes the binding constraint.
     */
    public const MIN_INSTITUTION_WIDTH = 180;
    /** Ceiling of an institution's derived width, however few share the street's frontage. */
    public const MAX_INSTITUTION_WIDTH = 380;

    // --- Skyline Rendering Envelope ---
    /** Facade height in user units for a plot at or below the market capitalisation floor. */
    public const MIN_FACADE_HEIGHT = 90.0;
    /**
     * Facade height in user units for a plot at or above the market capitalisation ceiling.
     * Sized so two rows share the canvas: a facade of this height on the upper row must still
     * clear CONDUIT_CORRIDOR_Y above it, and one on the lower row must clear the upper row's
     * kerb. Lower than the single-row value it replaces, but *taller on screen* — the canvas
     * narrowed by more than this shrank.
     */
    public const MAX_FACADE_HEIGHT = 620.0;
    /** Log10 market capitalisation floor (~$32B) mapped to MIN_FACADE_HEIGHT. */
    public const MARKET_CAP_LOG_FLOOR = 10.5;
    /** Log10 market capitalisation ceiling (~$3.2T) mapped to MAX_FACADE_HEIGHT. */
    public const MARKET_CAP_LOG_CEILING = 13.5;
    /** Normalised facade value the log floor/ceiling map to (edges approach but never hit the envelope ends). */
    public const MARKET_CAP_LOG_EDGE_TOLERANCE = 0.02;
    /**
     * Vertical spacing in user units between rendered window bands on a facade. The template lays
     * window rows out at exactly this pitch — they previously disagreed (52 here, 46 there),
     * which left every facade with a blank lower band growing with its height.
     */
    public const FLOOR_HEIGHT = 52.0;
    /** Inset from a facade's top edge to its first window band. */
    public const FIRST_FLOOR_INSET = 22.0;

    // --- Market Capitalisation Gridlines ---
    /**
     * Reference capitalisations drawn as horizontal rules behind each row, so facade height reads
     * as a scale rather than only a relative impression. Keyed by printed label because a float
     * is not a safe array key. Each line's y is
     * `groundLine - DistrictMapBuilder::calculateFacadeHeight($cap)`, reusing the very logistic
     * the facades use, so a rule can never disagree with the buildings beside it — and because a
     * cap maps to a *height*, every row carries its own set at its own y.
     *
     * Four lines, not more: above ~$2T the log compression puts successive round numbers within a
     * few units of each other ($3T and $5T land 45 units apart), which would crowd rather than
     * inform.
     */
    public const MARKET_CAP_GRIDLINES = [
        '$100B' => 1.0e11,
        '$500B' => 5.0e11,
        '$1T' => 1.0e12,
        '$2T' => 2.0e12,
    ];
    /** Font size in user units of a gridline's gutter label — see FRONTAGE_GUTTER for the width this implies. */
    public const GRIDLINE_LABEL_SIZE = 32;

    // --- Sector Palette ---
    /**
     * Facade, stroke and lit-window colours per App\Data\Sectors::MACRO_SECTORS name.
     *
     * The building mass stays dark — these are silhouettes at night against a #060e20-#131b2e
     * sky — and it is the *lit windows* that carry the sector hue. Colouring the whole facade
     * would turn the skyline into a bar chart; lighting the windows keeps it a street while still
     * making sector legible at a glance, and it rescues windows that previously rendered as a
     * uniform #adc6ff at 0.30 opacity, i.e. barely visible at all.
     *
     * Condition (see determineCondition()) layers on top and takes priority: a distressed or
     * ruined tenant loses its sector hue, because solvency is the more urgent fact.
     */
    public const SECTOR_PALETTE = [
        'Financials'             => ['facade' => '#1b2439', 'stroke' => '#46536f', 'window' => '#adc6ff'],
        'Information Technology' => ['facade' => '#152436', 'stroke' => '#3d6076', 'window' => '#7dd8e8'],
        'Health Care'            => ['facade' => '#152a2f', 'stroke' => '#3a6a68', 'window' => '#6ee7d3'],
        'Consumer Discretionary' => ['facade' => '#26203a', 'stroke' => '#5b4a80', 'window' => '#c9a6ff'],
        'Consumer Staples'       => ['facade' => '#1d2a24', 'stroke' => '#456b52', 'window' => '#8fe0a6'],
        'Industrials'            => ['facade' => '#2a2a33', 'stroke' => '#61616f', 'window' => '#cfd4e0'],
        'Real Estate'            => ['facade' => '#2b2632', 'stroke' => '#6a5a72', 'window' => '#e0b3e8'],
        'Energy'                 => ['facade' => '#2e2620', 'stroke' => '#75593d', 'window' => '#f5b955'],
        'Materials'              => ['facade' => '#2b2422', 'stroke' => '#6d5148', 'window' => '#e09b7d'],
        'Utilities'              => ['facade' => '#1f2733', 'stroke' => '#4d6079', 'window' => '#9fc4e8'],
        'Communication Services' => ['facade' => '#2a1f2e', 'stroke' => '#6b4a6b', 'window' => '#f0a6d0'],
    ];
    /** Facade, stroke and window colours for a tenant whose sector has no palette entry. */
    public const SECTOR_PALETTE_FALLBACK = ['facade' => '#1b2439', 'stroke' => '#424754', 'window' => '#adc6ff'];

    // --- Condition Thresholds ---
    /** Credit rating rank at or above which a facade renders as sound (BBB and better). */
    public const INVESTMENT_GRADE_RANK = 4;

    // --- Land Registry Stress ---
    /** Fractional deviation of a property index from its 100.0 baseline that reads as a real-estate shock. */
    public const LAND_REGISTRY_PROPERTY_STRESS_DEVIATION = 0.15;

    // --- Institution Band Geometry ---
    /** Top y-coordinate of the institution structures on the far bank. */
    public const INSTITUTION_BAND_TOP = 40;
    /**
     * Height in user units of an institution's structure — deliberately modest next to a plot's
     * facade (up to MAX_FACADE_HEIGHT). An institution is a small publisher on the skyline, not
     * a tenant competing with it for visual weight.
     */
    public const INSTITUTION_BAND_HEIGHT = 90;
    /**
     * Y-coordinate conduits emanate from — below the structure, its name, and its two-line
     * readout. The street's viewBox is almost always rendered well under 1:1 scale in a browser,
     * so text throughout this street is sized well above what it would need at 1:1.
     */
    public const INSTITUTION_OUTLET_Y = 250;
    /**
     * Floor of the band conduits traverse horizontally in before dropping to their tenant.
     *
     * Only the lower row needs it, and it is what makes a two-row street drawable at all: the
     * single symmetric-S curve the upper row uses puts its horizontal traverse at the midpoint
     * between outlet and target, which for a lower-row tenant lands in the middle of the upper
     * row's buildings. It would cross six to ten facades, visible only through the 14-unit gaps
     * between them — of which the rooflines' 5-unit overhangs leave 4 — and read as speckle
     * rather than as a line. Traversing above every possible upper-row roofline instead keeps the
     * run continuous, and the vertical drop that follows is occluded by at most the one building
     * standing in front of it, which is the depth cue rather than a defect.
     *
     * Held clear of the tallest possible upper-row roofline; asserted in DistrictMapTest.
     */
    public const CONDUIT_CORRIDOR_Y = 310;

    // --- Readout Units ---
    /** Field is a decimal fraction (e.g. 0.02) displayed as a percentage. */
    public const UNIT_PERCENT = 'pct';
    /** Field is an index around a 100.0 baseline, displayed as a plain number. */
    public const UNIT_INDEX = 'index';

    // --- Stress Rule Operators ---
    /** Stressed when the field is at or above `value`. */
    public const OP_GTE = 'gte';
    /** Stressed when the field is at or below `value`. */
    public const OP_LTE = 'lte';
    /**
     * Stressed when the field is strictly below `value`. Distinct from OP_LTE for a threshold
     * that is itself a field's neutral resting value (e.g. MacroEngine::PMI_BASELINE) — OP_LTE
     * would misfire at that exact baseline, since "at or below" includes "at".
     */
    public const OP_LT = 'lt';
    /** Stressed when the field's fractional deviation from a 100.0 baseline is at or above `value`. */
    public const OP_INDEX_DEVIATION = 'index_deviation';

    // --- Conduit Derivation ---
    /**
     * MacroStateDTO fields read by a large majority of business models' operating physics (via
     * App\Service\Model\Strategy\OperatingStrategyInterface::getOperatingMacroFields()) —
     * output_gap_ema (93%), inflation_ema (72%), tips_breakeven_ema (63%) and
     * exchange_rate_index_ema (63%) of the 46 concrete models, measured against every model's
     * fully-inherited declaration. Excluded from conduit derivation only (they remain published
     * `fields` and printable readouts) so a field nearly every model shares does not wire every
     * tenant to the same institution — the same principle CONDUITS already applied by hand to
     * equityRiskPremium/corporateTaxRate/policyRate feeding WACC alone.
     * See App\Service\District\DistrictConduitResolver.
     */
    public const UBIQUITOUS_MACRO_FIELDS = [
        'output_gap_ema',
        'inflation_ema',
        'exchange_rate_index_ema',
        'tips_breakeven_ema',
    ];

    // --- Institution Registry ---
    /**
     * Abstract macro-publishing institutions — councils, registries and exchanges, not rival
     * sovereigns to Lakebird. Each names the real `MacroStateDTO` fields (see App\DTO\MacroStateDTO)
     * it publishes. An institution renders on a ward only where at least one of that ward's
     * tenants draws a conduit from it (App\Service\District\DistrictConduitResolver), so no
     * institution exists as pure decoration and none needs a `ward` key of its own.
     *
     * `readouts` is the small subset of `fields` actually printed on the structure — a curated
     * pick of the one or two numbers a viewer would recognise at a glance, not every field the
     * institution publishes. Every readout field must also appear in that institution's `fields`.
     *
     * `stress_rules` is an optional OR-list of conditions (see the OP_* constants) evaluated by
     * App\Service\District\DistrictStressEvaluator and shipped to the client verbatim so the two
     * runtimes never drift. Every threshold must reuse a constant the simulation itself already
     * treats as a distress signal (a MacroEngine systemic-event constant, or a business model's
     * own named crisis constant) — see that class's docblock. Where no such constant exists for
     * an institution's fields, `stress_rules` is simply omitted: the district may only raise an
     * alarm the simulation itself raises, so that institution's conduits highlight on click but
     * never render as stressed. The one exception predating this rule is the Land Registry's
     * property-index deviation, defined locally as LAND_REGISTRY_PROPERTY_STRESS_DEVIATION
     * because no such constant exists anywhere else in the simulation.
     */
    public const INSTITUTIONS = [
        'rate-council' => [
            'label' => 'The Rate Council',
            'short_label' => 'RATES',
            'fields' => ['policy_rate_ema', 'yield_2y_ema', 'yield_5y_ema', 'yield_10y_ema', 'yield_30y_ema', 'ns_slope', 'ns_slope_ema', 'money_supply_growth_ema'],
            'readouts' => [
                ['field' => 'policy_rate_ema', 'label' => 'POLICY', 'unit' => self::UNIT_PERCENT],
                ['field' => 'yield_10y_ema', 'label' => '10Y', 'unit' => self::UNIT_PERCENT],
            ],
            'stress_rules' => [
                // MacroEngine::SYSTEMIC_INVERSION_ALARM_YEARS — a yield curve inversion that has persisted long enough to count as a systemic alarm.
                ['field' => 'inversion_duration', 'op' => self::OP_GTE, 'value' => 0.75],
                // MacroEngine::ZLB_PROXIMITY_THRESHOLD — policy rate pinned near the zero lower bound.
                ['field' => 'policy_rate_ema', 'op' => self::OP_LTE, 'value' => 0.015],
            ],
        ],
        'credit-registry' => [
            'label' => 'The Credit Registry',
            'short_label' => 'CREDIT',
            'fields' => ['macro_credit_spread', 'macro_credit_spread_ema', 'high_yield_credit_spread_ema', 'interbank_liquidity_spread_ema', 'corporate_default_rate_ema', 'retail_default_rate_ema', 'sloos_tightening_index_ema', 'recession_probability_ema'],
            'readouts' => [
                ['field' => 'macro_credit_spread_ema', 'label' => 'IG', 'unit' => self::UNIT_PERCENT],
                ['field' => 'high_yield_credit_spread_ema', 'label' => 'HY', 'unit' => self::UNIT_PERCENT],
            ],
            'stress_rules' => [
                // MacroEngine::SYSTEMIC_LIQUIDITY_FREEZE_SPREAD — an interbank funding freeze.
                ['field' => 'interbank_liquidity_spread_ema', 'op' => self::OP_GTE, 'value' => 0.0100],
                // MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD — a high-yield credit seizure.
                ['field' => 'high_yield_credit_spread_ema', 'op' => self::OP_GTE, 'value' => 0.1000],
                // MacroEngine::SYSTEMIC_RECESSION_DECLARE_PROBABILITY — a declared recession probability.
                ['field' => 'recession_probability_ema', 'op' => self::OP_GTE, 'value' => 0.50],
            ],
        ],
        'exchange-floor' => [
            'label' => 'The Exchange Floor',
            'short_label' => 'EXCHANGE',
            'fields' => ['market_volatility_ema', 'deal_activity_index_ema'],
            'readouts' => [
                ['field' => 'market_volatility_ema', 'label' => 'VOL', 'unit' => self::UNIT_PERCENT],
                ['field' => 'deal_activity_index_ema', 'label' => 'DEALS', 'unit' => self::UNIT_INDEX],
            ],
            'stress_rules' => [
                // ClearingHouseBusinessModel::VIX_EXTREME_THRESHOLD — the exact volatility level that class itself treats as a panic regime.
                ['field' => 'market_volatility_ema', 'op' => self::OP_GTE, 'value' => 0.30],
            ],
        ],
        'statistical-office' => [
            'label' => 'The Statistical Office',
            'short_label' => 'STATISTICS',
            'fields' => ['output_gap_ema', 'inflation_ema', 'unemployment_rate_ema', 'consumer_sentiment_index_ema'],
            'readouts' => [
                ['field' => 'output_gap_ema', 'label' => 'GAP', 'unit' => self::UNIT_PERCENT],
                ['field' => 'inflation_ema', 'label' => 'CPI', 'unit' => self::UNIT_PERCENT],
            ],
            'stress_rules' => [
                // MacroEngine::CB_INFLATION_PANIC_THRESHOLD — inflation past the central bank's own panic threshold.
                ['field' => 'inflation_ema', 'op' => self::OP_GTE, 'value' => 0.035],
                // MacroEngine::SYSTEMIC_RECESSION_DECLARE_GAP — a recessionary output gap.
                ['field' => 'output_gap_ema', 'op' => self::OP_LTE, 'value' => -0.010],
                // MacroEngine::EVANS_RULE_UNEMPLOYMENT — unemployment past the Evans Rule level.
                ['field' => 'unemployment_rate_ema', 'op' => self::OP_GTE, 'value' => 0.050],
            ],
        ],
        'land-registry' => [
            'label' => 'The Land Registry',
            'short_label' => 'LAND',
            'fields' => ['commercial_property_index_ema', 'residential_property_index_ema', 'housing_starts_index_ema'],
            'readouts' => [
                ['field' => 'commercial_property_index_ema', 'label' => 'CRE', 'unit' => self::UNIT_INDEX],
                ['field' => 'residential_property_index_ema', 'label' => 'RESI', 'unit' => self::UNIT_INDEX],
            ],
            'stress_rules' => [
                // DistrictMap::LAND_REGISTRY_PROPERTY_STRESS_DEVIATION — the one threshold this district
                // invents rather than borrows, because no such constant exists anywhere in the simulation.
                ['field' => 'commercial_property_index_ema', 'op' => self::OP_INDEX_DEVIATION, 'value' => self::LAND_REGISTRY_PROPERTY_STRESS_DEVIATION],
                ['field' => 'residential_property_index_ema', 'op' => self::OP_INDEX_DEVIATION, 'value' => self::LAND_REGISTRY_PROPERTY_STRESS_DEVIATION],
            ],
        ],
        'commodity-exchange' => [
            'label' => 'The Commodity Exchange',
            'short_label' => 'COMMODITY',
            'fields' => ['energy_cost_push_lag', 'industrial_metals_index_ema', 'agricultural_commodity_index_ema', 'refining_crack_spread_ema'],
            'readouts' => [
                ['field' => 'industrial_metals_index_ema', 'label' => 'METALS', 'unit' => self::UNIT_INDEX],
                ['field' => 'agricultural_commodity_index_ema', 'label' => 'AGRI', 'unit' => self::UNIT_INDEX],
            ],
            'stress_rules' => [
                // ChemicalBusinessModel::SEVERE_ENERGY_INFLATION_THRESHOLD — the exact energy cost-push
                // level that class itself treats as a severe inflation shock.
                ['field' => 'energy_cost_push_lag', 'op' => self::OP_GTE, 'value' => 0.30],
            ],
        ],
        'freight-authority' => [
            'label' => 'The Freight Authority',
            'short_label' => 'FREIGHT',
            'fields' => ['freight_rate_index_ema', 'supply_chain_pressure_index_ema', 'trade_balance_to_gdp_ema'],
            'readouts' => [
                ['field' => 'freight_rate_index_ema', 'label' => 'FREIGHT', 'unit' => self::UNIT_INDEX],
                ['field' => 'supply_chain_pressure_index_ema', 'label' => 'GSCPI', 'unit' => self::UNIT_INDEX],
            ],
            // No stress_rules: no MacroEngine or business-model constant treats any of this
            // institution's fields as a distress signal. Its conduits still exist and highlight
            // on click; it simply never renders stressed. See this class's docblock.
        ],
        'manufactory-board' => [
            'label' => 'The Manufactory Board',
            'short_label' => 'MANUFACTORY',
            'fields' => ['producer_price_inflation_ema', 'manufacturing_pmi_ema', 'capacity_utilization_rate_ema'],
            'readouts' => [
                ['field' => 'manufacturing_pmi_ema', 'label' => 'PMI', 'unit' => self::UNIT_INDEX],
                ['field' => 'producer_price_inflation_ema', 'label' => 'PPI', 'unit' => self::UNIT_PERCENT],
            ],
            'stress_rules' => [
                // MacroEngine::PMI_BASELINE — the ISM contraction line: a real-world standard boundary, not an
                // invented number. Strictly-below (OP_LT, not OP_LTE) because 50.0 is also every model's
                // resting default for this field — OP_LTE would stress at rest.
                ['field' => 'manufacturing_pmi_ema', 'op' => self::OP_LT, 'value' => 50.0],
            ],
        ],
        'works-ministry' => [
            'label' => 'The Works Ministry',
            'short_label' => 'WORKS',
            'fields' => ['government_spending_index_ema'],
            'readouts' => [
                ['field' => 'government_spending_index_ema', 'label' => 'SPEND', 'unit' => self::UNIT_INDEX],
            ],
            // No stress_rules — GOVT_SPENDING_BASELINE is a baseline, not a distress level, and no
            // other constant in the simulation treats government spending as a crisis signal.
        ],
    ];

    /** Returns the plot width in user units for a given systemic importance tier. */
    public static function plotWidthForImportance(?string $systemicImportance): int
    {
        return self::PLOT_WIDTH_BY_IMPORTANCE[$systemicImportance ?? 'none'] ?? self::PLOT_WIDTH_BY_IMPORTANCE['none'];
    }

    /**
     * Returns the facade, stroke and lit-window colours for a macro sector, falling back for a
     * sector with no palette entry rather than rendering an untinted hole in the street.
     *
     * @return array{facade: string, stroke: string, window: string}
     */
    public static function paletteForSector(?string $sector): array
    {
        return self::SECTOR_PALETTE[$sector ?? ''] ?? self::SECTOR_PALETTE_FALLBACK;
    }

    /** Returns the ground line for a row index, clamped to the last row for an out-of-range index. */
    public static function groundLineForRow(int $row): float
    {
        return self::ROW_GROUND_LINES[$row] ?? self::ROW_GROUND_LINES[count(self::ROW_GROUND_LINES) - 1];
    }
}
