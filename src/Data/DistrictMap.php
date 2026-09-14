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
     * How many of the District's largest listed companies (by market cap) hold frontage. Ranked
     * by DistrictWardComposer::composeFrontage() at each reconstitution and frozen in between —
     * see DistrictRoster. No company is ever authored on or off the street.
     */
    public const STREET_ROSTER_SIZE = 30;

    // --- Reconstitution ---
    /**
     * How many times a simulated year the roster is re-ranked; four is the quarterly cadence most
     * equity indices reconstitute on. Between reconstitutions the street is fixed: a company that
     * goes bankrupt keeps its plot as a defunct shell, and a riser waits its turn below the cut.
     */
    public const RECONSTITUTIONS_PER_YEAR = 4;

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
        'credit_services', 'commercial_bank', 'insurance', 'retail_insurance', 'reinsurance', 'shadow_bank',
        'investment_bank', 'clearing_house', 'financial_data', 'asset_manager', 'hedge_fund',
        'brokerage', 'distressed_debt',
        // Industrials & Materials.
        'steel_manufacturing', 'specialty_industrial_machinery', 'commodity', 'chemical',
        'construction', 'defense_contractor', 'security_protection', 'waste_management',
        'railroad', 'shipping', 'logistics', 'tools_and_accessories', 'heavy_manufacturing', 'law_firm',
        // Consumer Discretionary & Staples.
        'consumer_staples', 'restaurant', 'apparel_manufacturing', 'auto_manufacturer',
        'internet_retail', 'resorts_casinos', 'luxury', 'advertising_agency', 'education',
        // Energy & Utilities.
        'utility',
        // Information Technology & Communication Services.
        'tech', 'computer_hardware', 'semiconductor', 'communication_equipment', 'telecom',
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
     * Clear sky in user units between one row's kerb (or the conduit lane band) and the tallest
     * facade of the row below, allowing for everything that rides above a roofline: the rank
     * label, the titan beacon, the event badge and the roof furniture (ROOF_FURNITURE_HEIGHT) —
     * see DistrictMapTest::testRowSpacingLeavesRoomForTheRoofFurniture.
     *
     * Every row's ground line, and with it the viewBox height, is derived per request by
     * App\Service\District\DistrictMapBuilder::resolveCanvas(): each row is given exactly the
     * sky its own tallest facade needs plus the height a headroom-sized cap move
     * (MARKET_CAP_LOG_HEADROOM) would add, capped at MAX_FACADE_HEIGHT. Spacing every row for the
     * theoretical maximum instead left the lower row's sky mostly empty on any real roster. A
     * facade can only outgrow its row's clearance if its cap climbs past the envelope's headroom
     * within one page session, and even then it clamps at MAX_FACADE_HEIGHT.
     */
    public const ROW_GAP = 60;
    /**
     * Depth in user units of the kerb band below a ground line.
     *
     * Deep enough for three *stacked, centred* lines — ticker, price, change — plus the sector
     * bracket beneath them (SECTOR_BRACKET_RULE_OFFSET / SECTOR_BRACKET_LABEL_OFFSET). The three
     * cannot share lines: the narrowest plot is 100 units, and at a legible size "$326.91 -59.2%"
     * alone runs to ~218 units. Anything paired left-and-right on one line collides on every
     * base-tier plot.
     */
    public const KERB_DEPTH = 130;
    /** Air in user units below the lowest kerb, so its bottom rule is not clipped by the canvas edge. */
    public const CANVAS_BOTTOM_MARGIN = 8;
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
     * Facade height in user units for a plot at or above the market capitalisation ceiling, and
     * the most sky any row is ever given (see resolveCanvas()). Lower than the single-row value
     * it replaced, but *taller on screen* — the two-row canvas narrowed by more than this shrank.
     */
    public const MAX_FACADE_HEIGHT = 620.0;
    /**
     * Log10 padding added on both sides of the roster's own capitalisation range to form the
     * height window (0.15 ≈ ×1.4). The window is derived from the tenants actually on the street
     * on every request — a fixed window centred on one figure squashed whichever end of the
     * roster it was not centred on (ranks 12, 22 and 24 once rendered at the same height) — and
     * the padding keeps the largest tenant off the ceiling and the smallest off the floor, so a
     * live tick in either direction still has somewhere to go before the clamp.
     */
    public const MARKET_CAP_LOG_HEADROOM = 0.15;
    /**
     * Narrowest height window in log10 units (one decade). A street whose tenants all share one
     * cap, or a single-tenant street, would otherwise blow a fractional difference up to the full
     * envelope; the window is centred on the roster's midpoint and widened to this instead.
     */
    public const MARKET_CAP_LOG_MIN_SPAN = 1.0;
    /** Log10 floor of the window drawn for an empty street (~$100B), so the gutter still carries a scale. */
    public const MARKET_CAP_LOG_EMPTY_FLOOR = 11.0;
    /**
     * Vertical spacing in user units between rendered window bands on a facade. The template lays
     * window rows out at exactly this pitch — they previously disagreed (52 here, 46 there),
     * which left every facade with a blank lower band growing with its height.
     */
    public const FLOOR_HEIGHT = 52.0;
    /** Inset from a facade's top edge to its first window band. */
    public const FIRST_FLOOR_INSET = 22.0;

    // --- Windows ---
    /** Horizontal distance in user units between adjacent window columns. */
    public const WINDOW_PITCH = 34;
    /** Width in user units of one window. */
    public const WINDOW_WIDTH = 20;
    /** Height in user units of one window. */
    public const WINDOW_HEIGHT = 22;
    /** Facade width reserved for the outer walls; the columns that fit in the remainder are centred. */
    public const WINDOW_WALL_ALLOWANCE = 20;
    /**
     * Share of a facade's windows lit when the tenant earns exactly its baseline return on
     * capital (ROIC, or ROE for financials). Lit share is linear in the ratio of current to
     * baseline return — floor + (this − floor) × ratio, clamped to [floor, 1] — so a tenant at
     * twice its baseline is fully lit and one at half is between. Presentation only: the ratio
     * is already what the info panel prints, this just makes it legible from the street.
     */
    public const WINDOW_LIT_SHARE_AT_BASELINE = 0.6;
    /** Fewest windows lit however poor the return — a dark facade still has to read as occupied, and a ruin is styled separately. */
    public const WINDOW_LIT_SHARE_FLOOR = 0.2;
    /**
     * Decimal places a window's lighting priority is rounded to before it is compared with the
     * lit share. The priority ships to the client on every window so a live tick can relight the
     * facade without the client owning a hash function; rounding on both sides to the same
     * precision is what guarantees the two runtimes light exactly the same windows.
     */
    public const WINDOW_KEY_PRECISION = 4;
    /**
     * Share of a facade's windows that flicker slowly while lit. A static grid of lit windows
     * reads as a diagram; a few drifting ones read as a city. Which windows flicker, and where
     * in the cycle each one starts, is a second stable hash of the window's address, so the
     * street never changes its mind between renders.
     */
    public const WINDOW_TWINKLE_SHARE = 0.12;
    /** Seconds one flicker cycle lasts. Slow, so it never competes with the roofline tick flashes. */
    public const WINDOW_TWINKLE_PERIOD_SECONDS = 5.5;

    // --- Roof Furniture ---
    /**
     * One rooftop glyph per tenant, so a row of same-height facades still tells a bank from a
     * refinery at a glance. Resolved by industry first (ROOF_FURNITURE_BY_INDUSTRY) and then by
     * business model (ROOF_FURNITURE), so an oil explorer and a refiner — one accounting model,
     * two trades — dress differently while every model still has a glyph. Both are authored,
     * like FRONTAGE_ORDER; DistrictMapTest keeps them complete. Each value names a
     * `<symbol id="roof-…">` the street template defines (ROOF_FURNITURE_SYMBOLS).
     *
     * The glyphs are drawn to survive the street's rendered scale (~0.45px per unit, so a
     * ROOF_FURNITURE_WIDTH box is ~14px): filled silhouettes with one bold feature each,
     * chosen from the pictograms people already read on signage.
     */
    public const ROOF_FURNITURE_BY_INDUSTRY = [
        'Oil & Gas E&P' => 'derrick', 'Oil & Gas Equipment & Services' => 'derrick', 'Oil & Gas Integrated' => 'derrick',
        'Oil & Gas Midstream' => 'flare_stack', 'Oil & Gas Refining & Marketing' => 'flare_stack',
        'Aluminum' => 'ingot', 'Copper' => 'ingot', 'Gold' => 'ingot',
        'Utilities - Regulated Water' => 'drop', 'Utilities - Regulated Gas' => 'flare_stack',
        'Beverages - Brewers' => 'bottle', 'Beverages - Non-Alcoholic' => 'bottle', 'Beverages - Wineries & Distilleries' => 'bottle',
        'Insurance Brokers' => 'umbrella',
    ];
    /** Business-model fallback for any industry ROOF_FURNITURE_BY_INDUSTRY does not single out. */
    public const ROOF_FURNITURE = [
        // Capital houses: a coin.
        'credit_services' => 'coin', 'commercial_bank' => 'coin', 'shadow_bank' => 'coin',
        'investment_bank' => 'coin', 'clearing_house' => 'coin', 'asset_manager' => 'coin',
        'hedge_fund' => 'coin', 'brokerage' => 'coin', 'distressed_debt' => 'coin', 'private_equity' => 'coin',
        // Underwriters: an umbrella. Market data: a bar chart.
        'insurance' => 'umbrella', 'reinsurance' => 'umbrella', 'retail_insurance' => 'umbrella',
        'financial_data' => 'chart',
        // Process industry: a factory; commodities: a derrick; chemistry: a flask.
        'steel_manufacturing' => 'factory', 'specialty_industrial_machinery' => 'factory',
        'tools_and_accessories' => 'factory', 'heavy_manufacturing' => 'factory',
        'commodity' => 'derrick', 'chemical' => 'flask',
        // Movers and builders.
        'construction' => 'crane', 'shipping' => 'anchor', 'logistics' => 'truck',
        'railroad' => 'train', 'waste_management' => 'bin',
        // Guardians and the professions.
        'defense_contractor' => 'shield', 'security_protection' => 'lock',
        'law_firm' => 'scales', 'education' => 'cap', 'advertising_agency' => 'megaphone',
        // Consumer houses.
        'consumer_staples' => 'cart', 'restaurant' => 'fork', 'apparel_manufacturing' => 'shirt',
        'auto_manufacturer' => 'car', 'internet_retail' => 'bag', 'resorts_casinos' => 'dice',
        'luxury' => 'gem',
        // Power, silicon and signal.
        'utility' => 'bolt', 'tech' => 'cloud', 'computer_hardware' => 'chip',
        'semiconductor' => 'chip', 'communication_equipment' => 'signal', 'telecom' => 'signal',
        // Care, cures, property and the conglomerates.
        'medical_care_facility' => 'cross', 'biotech' => 'pill', 'reit' => 'house',
        'conglomerate' => 'blocks',
    ];
    /** Every symbol the template must define; every furniture value must be one of these. */
    public const ROOF_FURNITURE_SYMBOLS = [
        'coin', 'umbrella', 'chart', 'derrick', 'flare_stack', 'ingot', 'factory', 'flask', 'bolt', 'drop',
        'crane', 'anchor', 'truck', 'train', 'bin', 'shield', 'lock', 'cart', 'bottle', 'fork', 'shirt',
        'car', 'bag', 'gem', 'dice', 'megaphone', 'cap', 'scales', 'chip', 'cloud', 'signal', 'cross',
        'pill', 'house', 'blocks',
    ];
    /** Symbol for a tenant neither map dressed — defensive only, the tests forbid the case for listed companies. */
    public const ROOF_FURNITURE_DEFAULT = 'blocks';
    /** Width in user units of a furniture symbol's box. As wide as the band between the rank plate and the badge on the narrowest roof allows. */
    public const ROOF_FURNITURE_WIDTH = 30;
    /** Height in user units of a furniture symbol's box; its base sits on the roofline. */
    public const ROOF_FURNITURE_HEIGHT = 36;
    /**
     * Distance from a plot's east edge to the furniture's east edge. Right-anchored rather than
     * centred: the rank plate rides the roof's west end (~47 units for "#30") and the badge its
     * east end (r=15, 6 in from the edge), and on a 100-unit roof only the band between them is
     * free. 22 clears the badge on every tier and leaves 48 units for the rank on the narrowest.
     */
    public const ROOF_FURNITURE_EAST_MARGIN = 22;

    // --- Kerb Lights ---
    /**
     * A pool of light on the pavement under each frontage plate, in the tenant's sector colour,
     * as if cast by a street lamp on the kerb. Ties the plate to the building above it and
     * softens the hard kerb band. Depth in user units the pool reaches below the ground line.
     */
    public const KERB_LIGHT_DEPTH = 46;
    /** Peak opacity of a kerb light at the ground line; it fades to nothing at KERB_LIGHT_DEPTH. */
    public const KERB_LIGHT_OPACITY = 0.16;

    // --- Terrace Embankment ---
    /** Height in user units of the retaining wall drawn under the upper row's kerb, inside ROW_GAP. */
    public const TERRACE_WALL_HEIGHT = 14;

    // --- Portfolio Position Pennant ---
    /** Flag length in user units, from the pole eastward; the pole stands POSITION_PENNANT_INSET in from the west edge. */
    public const POSITION_PENNANT_WIDTH = 18;
    /** Flag height in user units; the pole rises this far again above the rank plate so the flag clears it. */
    public const POSITION_PENNANT_HEIGHT = 12;
    /** Pole x offset in user units from the plot's west edge. */
    public const POSITION_PENNANT_INSET = 6;

    // --- Market Capitalisation Gridlines ---
    /**
     * Mantissas of the reference capitalisations drawn as horizontal rules behind each row, so
     * facade height reads as a scale rather than only a relative impression. The rules are
     * generated per request as every 1-2-5 step of a decade that falls inside the derived height
     * window (see MARKET_CAP_LOG_HEADROOM), then thinned to GRIDLINE_MIN_SPACING. Each line's y is
     * `groundLine - DistrictMapBuilder::calculateFacadeHeight($cap)`, reusing the very mapping
     * the facades use, so a rule can never disagree with the buildings beside it — and because a
     * cap maps to a *height*, every row carries its own set at its own y.
     */
    public const GRIDLINE_MANTISSAS = [1.0, 2.0, 5.0];
    /**
     * Least vertical distance in user units between two rules on the same row. A rule crowded
     * against its neighbour informs less than no rule at all: labels are printed at
     * GRIDLINE_LABEL_SIZE, so consecutive rules must clear that with some air.
     */
    public const GRIDLINE_MIN_SPACING = 44.0;
    /** Font size in user units of a gridline's gutter label — see FRONTAGE_GUTTER for the width this implies. */
    public const GRIDLINE_LABEL_SIZE = 32;

    // --- Sector Brackets ---
    /**
     * A rule in the sector's window colour drawn along the kerb under every run of adjacent
     * same-sector plots, with the sector's name beneath it where the run is wide enough to carry
     * it. FRONTAGE_ORDER already seats same-model tenants together; the bracket makes that
     * composition legible from the street rather than only from the legend.
     */
    /** Distance below a ground line of the bracket rule. Below the change line (88) and its descenders. */
    public const SECTOR_BRACKET_RULE_OFFSET = 104;
    /** Baseline of the bracket label below a ground line. */
    public const SECTOR_BRACKET_LABEL_OFFSET = 124;
    /** Font size in user units of the bracket label. */
    public const SECTOR_BRACKET_LABEL_SIZE = 20;
    /** Advance width of the label face as a fraction of its size (Courier Prime is 0.6em), used to decide whether a run can carry its name. */
    public const SECTOR_BRACKET_LABEL_ADVANCE = 0.6;
    /** Air on each side of a bracket label within its run. */
    public const SECTOR_BRACKET_LABEL_PADDING = 8;

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

    // --- Event Badges ---
    /**
     * Span of simulated time, in years, a building's event badge counts over (one simulated
     * month — the same window the kerb's change figure is measured across, see
     * App\Service\Market\PriceChangeFeed). The badge used to print the whole capped backfill,
     * so once a tenant had six lifetime events it read "6" for good and carried nothing.
     */
    public const EVENT_BADGE_WINDOW_YEARS = 1.0 / 12.0;

    // --- Land Registry Stress ---
    /** Fractional fall of a property index below its 100.0 baseline that reads as a real-estate shock. */
    public const LAND_REGISTRY_PROPERTY_STRESS_DROP = 0.15;

    // --- Institution Band Geometry ---
    /** Top y-coordinate of the institution structures on the far bank. */
    public const INSTITUTION_BAND_TOP = 40;
    /**
     * Height in user units of an institution's structure — deliberately modest next to a plot's
     * facade (up to MAX_FACADE_HEIGHT). An institution is a small publisher on the skyline, not
     * a tenant competing with it for visual weight.
     */
    public const INSTITUTION_BAND_HEIGHT = 90;
    /** Baseline of an institution's name below the bottom of its structure. */
    public const INSTITUTION_LABEL_OFFSET = 28;
    /** Baseline of the first readout line below the bottom of the structure. */
    public const INSTITUTION_READOUT_TOP_OFFSET = 58;
    /** Vertical distance between readout baselines — a line of text plus the sparkline strip under it. */
    public const INSTITUTION_READOUT_PITCH = 44;
    /** Most readouts an institution may print; the outlet below is placed for exactly this many. */
    public const INSTITUTION_READOUT_LINES = 2;
    /**
     * Each readout carries a sparkline of its recent readings beneath the printed value, so a
     * viewer can see whether a variable is heading toward its stress threshold rather than only
     * that it crossed. Drawn client-side from the ticks the page has seen; empty on first paint.
     */
    /** Width in user units of a readout's sparkline strip. */
    public const INSTITUTION_SPARKLINE_WIDTH = 140;
    /** Height in user units of a readout's sparkline strip. */
    public const INSTITUTION_SPARKLINE_HEIGHT = 12;
    /** Air between a readout's baseline and the top of its sparkline strip. */
    public const INSTITUTION_SPARKLINE_GAP = 8;
    /**
     * Y-coordinate conduits emanate from — below the structure, its name, and its readouts with
     * their sparklines (DistrictMapTest pins that arithmetic). The street's viewBox is almost
     * always rendered well under 1:1 scale in a browser, so text throughout this street is sized
     * well above what it would need at 1:1.
     */
    public const INSTITUTION_OUTLET_Y = 270;

    // --- Conduit Lanes ---
    /**
     * Conduits are routed orthogonally, the way a utility map draws service lines: each
     * institution owns one horizontal lane in a band below the outlets, drops from its outlet
     * into that lane, runs along it, and drops again onto each tenant's roofline. The symmetric
     * S-curves this replaced fanned ~100 crossing curves across the sky; with lanes, a stressed
     * institution reads as one red bus with drops, and the band is what makes a two-row street
     * drawable at all — lower-row drops fall through the upper row's gaps and behind its kerb.
     * Sized for one lane per rendered institution; the band's depth is derived from that count.
     */
    /** Vertical distance in user units between adjacent lanes — a 3-unit dashed stroke needs this much to read as separate lines. */
    public const CONDUIT_LANE_PITCH = 14;
    /** Distance from INSTITUTION_OUTLET_Y to the first lane. */
    public const CONDUIT_LANE_TOP_INSET = 14;
    /**
     * Inset from a plot's edges within which its drops are spread. A tenant wired to several
     * institutions takes one drop per conduit, spaced evenly across the roof; without the inset
     * the outermost drops would land on the roofline's overhang.
     */
    public const CONDUIT_DROP_INSET = 18;

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
    /**
     * Stressed when the field has fallen at least `value` (a fraction) below its 100.0 baseline.
     * Downside only: a property index running hot is a boom, and the street's legend promises a
     * conduit reddens when its variable turns *adverse* for the tenants it feeds. It replaced a
     * symmetric deviation test that painted a +20% commercial-property rally as distress.
     */
    public const OP_INDEX_DROP = 'index_drop';

    // --- Conduit Derivation ---
    /**
     * MacroStateDTO fields read by a large majority of business models' operating physics (via
     * App\Service\Model\Strategy\OperatingStrategyInterface::getOperatingMacroFields()) —
     * output_gap_ema (96%), tips_breakeven_ema (68%), exchange_rate_index_ema (66%),
     * energy_cost_push_lag (62%) and producer_price_inflation_ema (62%) of the 47 concrete models,
     * measured against each model's declaration of the fields its own operating code reads
     * (BusinessModelMacroFieldDeclarationTest keeps those declarations honest). Excluded from
     * conduit derivation only (they remain published `fields` and printable readouts) so a field
     * nearly every model shares does not wire every tenant to the same institution — the same
     * principle CONDUITS already applied by hand to equityRiskPremium/corporateTaxRate/policyRate
     * feeding WACC alone.
     *
     * The last two joined when the input-cost basket reached Biotech and REIT: energy and wholesale
     * goods are what every producing firm buys, so leaving them in wired 62% of tenants to the
     * commodity exchange and the manufactory board respectively. What still distinguishes a tenant
     * there is genuine metals, agricultural or crack-spread exposure, which is what those conduits
     * now draw on.
     * See App\Service\District\DistrictConduitResolver.
     */
    public const UBIQUITOUS_MACRO_FIELDS = [
        'output_gap_ema',
        'exchange_rate_index_ema',
        'tips_breakeven_ema',
        'energy_cost_push_lag',
        'producer_price_inflation_ema',
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
     * property-index fall, defined locally as LAND_REGISTRY_PROPERTY_STRESS_DROP
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
                // DistrictMap::LAND_REGISTRY_PROPERTY_STRESS_DROP — the one threshold this district
                // invents rather than borrows, because no such constant exists anywhere in the simulation.
                ['field' => 'commercial_property_index_ema', 'op' => self::OP_INDEX_DROP, 'value' => self::LAND_REGISTRY_PROPERTY_STRESS_DROP],
                ['field' => 'residential_property_index_ema', 'op' => self::OP_INDEX_DROP, 'value' => self::LAND_REGISTRY_PROPERTY_STRESS_DROP],
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
}
