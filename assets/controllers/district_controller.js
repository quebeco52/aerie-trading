import { Controller } from '@hotwired/stimulus';
import { formatLarge, formatCurrency, formatPercent } from '../js/utils/formatters.js';

/** SVG namespace — segment sparklines are built element by element, not parsed from markup. */
const SVG_NS = 'http://www.w3.org/2000/svg';

/** Segment sparkline box, in CSS pixels. Sized to sit inline in a 10px metric line. */
const SPARKLINE_WIDTH = 34;
const SPARKLINE_HEIGHT = 10;

/** Drivers printed per segment. The feed ranks them strongest-first, so this keeps the head. */
const MAX_DRIVERS_PER_STREAM = 3;

/** Pips in a driver's strength meter — the bands EarningsReportSubscriber grades a driver into. */
const DRIVER_STRENGTH_PIPS = 3;

/**
 * What kind of driver a row is, as a text tag. Emoji were unreadable here: the panel is set in
 * IBM Plex Mono, which has no colour-emoji fallback in this stack, so they rendered as tofu.
 */
const DRIVER_TYPE_TAGS = { macro: 'macro', momentum: 'ops', company: 'co' };

/**
 * Mix-bar palette. A segment's colour comes from its rank in the mix, so the swatch on its row
 * and its band in the 100% bar always match. Ordered largest-share first, so the dominant segment
 * is always the primary colour.
 */
const STREAM_COLORS = ['#7dd8e8', '#4edea3', '#f0b866', '#c39ae0', '#ff9f9a', '#8aa6d6', '#8f8f8f'];

/** Formats a growth rate, or the "n/m" a filing prints where there is no comparison period. */
function formatGrowth(value) {
    return (value === null || value === undefined) ? 'n/m' : formatPercent(value, 1, false, true);
}

/**
 * Formats one macro reading in the unit its catalogue entry declares. Mirrors the units in
 * App\Data\MacroFieldCatalog — spreads in basis points, rates as percentages, indices as levels.
 */
function formatReading(reading) {
    const value = Number(reading.value);
    if (!Number.isFinite(value)) return '—';
    if (reading.unit === 'bps') return `${Math.round(value * 10000)} bps`;
    if (reading.unit === 'pct') return `${(value * 100).toFixed(2)}%`;
    return value.toFixed(1);
}

const IMPORTANCE_LABELS = {
    titan: 'Titan — systemically irreplaceable',
    systemic: 'Systemically important',
    base: 'Baseline constituent',
    none: 'Unclassified',
};

/**
 * Evaluates one stress rule against a live macro snapshot. Mirrors
 * App\Service\District\DistrictStressEvaluator::ruleTriggered() exactly, but the rules
 * themselves are never duplicated in this file — they ship from
 * App\Data\DistrictMap::INSTITUTIONS[*]['stress_rules'] as the `institutions` Stimulus value, so
 * PHP stays the only place a threshold is written down. Field names match payload.macro
 * (App\DTO\MacroStateDTO::toArray()).
 */
function ruleTriggered(rule, macro) {
    const value = macro[rule.field];
    if (typeof value !== 'number' || !Number.isFinite(value)) return false;

    switch (rule.op) {
        case 'gte': return value >= rule.value;
        case 'lte': return value <= rule.value;
        case 'lt': return value < rule.value;
        case 'index_drop': return (100.0 - value) / 100.0 >= rule.value;
        default: return false;
    }
}

/** True if any rule in the (possibly empty) list is triggered — an institution with no rules never stresses. */
function anyRuleTriggered(rules, macro) {
    return Array.isArray(rules) && rules.some(rule => ruleTriggered(rule, macro));
}

/** Tooltip card size in viewBox units — must match the <rect class="tooltip-bg"> in the template. */
const TOOLTIP_WIDTH = 520;
const TOOLTIP_HEIGHT = 210;

/** Clips a company name to the tooltip's width; SVG text has no wrapping of its own. */
function truncate(text, maxLength) {
    return text.length > maxLength ? text.slice(0, maxLength - 1) + '…' : text;
}

/**
 * The `d` for one conduit: from an institution's outlet down to its lane, along the lane, and
 * down onto the tenant's roofline. Mirrors the path the Twig template renders on first paint —
 * keep the two in step, the way the facade height mapping already is. Only the last drop's end
 * (ty) ever changes on a live tick; the outlet, lane and drop x are fixed by the canvas.
 */
function conduitPath(sx, sy, tx, ty, laneY) {
    return `M ${sx} ${sy} V ${laneY} H ${tx} V ${ty}`;
}

/** Formats one readout value to match the server-rendered `number_format` precision exactly. */
function formatReadoutValue(rawValue, unit) {
    const value = Number.isFinite(rawValue) ? rawValue : 0;
    return unit === 'pct' ? (value * 100).toFixed(2) + '%' : value.toFixed(1);
}

/**
 * Share of a facade's windows to light for a return against its baseline. Mirrors
 * App\Service\District\DistrictMapBuilder::calculateLitShare() exactly — floor + (atBaseline −
 * floor) × ratio, clamped to [floor, 1], a non-positive baseline read as earning it exactly —
 * with the two figures shipped as the `lighting` value rather than restated here.
 */
function litShareFor(returnOnCapital, baselineReturn, lighting) {
    const ratio = baselineReturn > 0 ? returnOnCapital / baselineReturn : 1;
    const share = lighting.floor + (lighting.atBaseline - lighting.floor) * ratio;
    return Math.max(lighting.floor, Math.min(1, share));
}

/**
 * A facade's condition from solvency and credit standing. Mirrors
 * App\Service\District\DistrictMapBuilder::determineCondition(): the rating ladder and the
 * investment-grade cut both ship as the `condition` value, so no rank is written down here.
 */
function conditionFor(isBankrupt, rating, condition) {
    if (isBankrupt) return 'ruin';
    const ranks = condition.ratingRanks || {};
    const rank = ranks[rating] !== undefined ? ranks[rating] : ranks.BBB;
    return rank < condition.investmentGradeRank ? 'distressed' : 'sound';
}

/** The `points` of a sparkline for a series, scaled into a box; a flat series runs along its middle. */
function sparklinePoints(series, left, top, width, height) {
    if (series.length < 2) return '';
    const min = Math.min(...series);
    const max = Math.max(...series);
    const range = max - min;
    const stepX = width / (series.length - 1);
    return series.map((value, i) => {
        const x = left + i * stepX;
        const y = range > 0 ? top + height - ((value - min) / range) * height : top + height / 2;
        return `${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(' ');
}

const EVENT_COLORS = {
    primary: '#adc6ff',
    secondary: '#4edea3',
    tertiary: '#ffb3ad',
    amber: '#f5b955',
    purple: '#c9a6ff',
    cyan: '#7dd8e8',
    red500: '#f36a6a',
};

/**
 * A live event over the WebSocket is only ever `{type, ticker, description, change_percent}` —
 * no id, no timestamp (App\Service\Event\MarketEventPublisher::publish()). This mirrors just the
 * *outer* routing table of App\Service\Event\EventPresenter::present() — which category a type
 * belongs to, and (for the two categories whose class is a sign, not the type itself) which side
 * of that sign it's on. It deliberately does not reimplement that class's prose parsing
 * (EPS beat/miss thresholds, upgrade/downgrade keyword matching, dividend/buyback pill
 * extraction) — a flare is a momentary cue, not the record. The precise, fully-parsed badge for
 * the same event is what DistrictEventFeed backfills from the database on the next full page
 * load, and is what a click on the building always shows for its older history.
 */
function categorizeEvent(type, changePercent) {
    const t = (type || '').toUpperCase();
    const cp = Number.isFinite(changePercent) ? changePercent : null;

    if (t === 'EARNINGS') {
        const color = cp === null || cp === 0 ? EVENT_COLORS.primary : (cp > 0 ? EVENT_COLORS.secondary : EVENT_COLORS.tertiary);
        const icon = cp > 0 ? 'trending_up' : (cp < 0 ? 'trending_down' : 'equalizer');
        return { category: 'earnings', color, icon, badge: 'EARNINGS' };
    }
    if (t === 'SHOCK') {
        const positive = cp === null || cp >= 0;
        return { category: 'shock', color: positive ? EVENT_COLORS.amber : EVENT_COLORS.tertiary, icon: 'bolt', badge: 'MARKET SHOCK' };
    }
    if (t === 'SPLIT' || t === 'REVERSE_SPLIT' || t === 'REVSPLIT') {
        const reverse = t !== 'SPLIT';
        return { category: 'split', color: EVENT_COLORS.purple, icon: 'call_split', badge: reverse ? 'REVERSE SPLIT' : 'STOCK SPLIT' };
    }
    if (t === 'RATING_UPGRADE') {
        return { category: 'debt', color: EVENT_COLORS.secondary, icon: 'credit_score', badge: 'RATING UPGRADE' };
    }
    if (t === 'RATING_DOWNGRADE') {
        return { category: 'debt', color: EVENT_COLORS.tertiary, icon: 'warning', badge: 'RATING DOWNGRADE' };
    }
    if (t === 'DEBT') {
        return { category: 'debt', color: EVENT_COLORS.tertiary, icon: 'warning', badge: 'CREDIT RATING' };
    }
    if (t === 'ACQUISITION' || t === 'MERGER' || t === 'DIVESTITURE') {
        return { category: 'mna', color: EVENT_COLORS.cyan, icon: 'domain_add', badge: t };
    }
    if (t === 'BANKRUPTCY') {
        return { category: 'bankruptcy', color: EVENT_COLORS.red500, icon: 'gavel', badge: 'BANKRUPTCY' };
    }
    if (t === 'DISTRICT') {
        // Roster change at the quarterly reconstitution — see App\Service\Event\EventPresenter::presentDistrict().
        return { category: 'district', color: EVENT_COLORS.primary, icon: 'location_city', badge: 'RECONSTITUTION' };
    }
    return { category: 'general', color: EVENT_COLORS.primary, icon: 'campaign', badge: t || 'EVENT' };
}

/** Client-clock timestamp, matching how assets/js/stock/events-feed.js stamps live events that carry none of their own. */
function nowStamp() {
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}`;
}

/**
 * Drives the district ward elevation: plot and institution selection, conduit highlighting, and
 * live facade/conduit/stress updates. Geometry stays a view concern — nothing computed here is
 * fed back to the market.
 */
export default class extends Controller {
    static targets = [
        'plot', 'label', 'rank', 'kerbPrice', 'kerbChange',
        'institution', 'conduit', 'institutionReadout', 'readoutSpark', 'svg', 'summaryStress',
        'summaryReconstitution', 'reconstitutionNotice',
        'flare', 'badge', 'badgeCount', 'kerbLight',
        'tooltip', 'tooltipTicker', 'tooltipRank', 'tooltipName', 'tooltipMeta',
        'tooltipPrice', 'tooltipChange', 'tooltipCap',
        'empty', 'detail', 'detailTicker', 'detailPlot', 'detailName', 'detailIndustry',
        'detailPrice', 'detailChange', 'detailMcap', 'detailRating', 'detailRoc',
        'detailImportance', 'detailRank', 'detailBlurb', 'detailLink', 'detailEvents', 'noEvents',
        'detailRevenueMix', 'detailRevenueTotal', 'noRevenueMix', 'detailRevenuePeriod',
        'detailRevenueHeadline', 'detailRevenueQoq', 'detailRevenueYoy', 'detailRevenueMeta',
        'detailRevenueMixBar', 'detailRevenueFootnote',
        'institutionDetail', 'institutionName', 'institutionStatus', 'institutionReadings',
        'institutionFeeds', 'institutionFeedCount',
        'sectorChip', 'sectorRun',
        'conduitModeBtn', 'pennant', 'detailPositionWrap', 'detailPosition', 'tooltipPosition',
        'quickTradeForm', 'quickTradeTickerInput', 'quickTradeHolding', 'quickTradeEstimate', 'quickTradeQuantity', 'quickTradeSubmit',
    ];

    static values = {
        shares: Object, envelope: Object, events: Object, revenueMix: Object, institutions: Array,
        /** Lit-share floor and at-baseline share — DistrictMap::WINDOW_LIT_SHARE_FLOOR / _AT_BASELINE. */
        lighting: Object,
        /** Credit rating ladder and the investment-grade cut — CreditRatingAgency::RATING_RANKS, DistrictMap::INVESTMENT_GRADE_RANK. */
        condition: Object,
        /** Wall-clock seconds one simulated month lasts — App\Service\District\DistrictEventFeed::badgeWindowSeconds(). */
        eventBadgeWindowSeconds: Number,
        /**
         * Open positions keyed by ticker — App\Service\District\DistrictHoldingsFeed. `quantity` is
         * ALWAYS the unsigned size; `isShort` carries the direction. `averageCost` is the weighted
         * average from CostBasisCalculator (average proceeds for a short), or null.
         */
        userHoldings: Object,
        /** Cash balance, for the quick-trade ticket's "Max" and estimate. */
        userCash: Number,
        /** Whether the account may short and cover — decides which ticket actions are rendered. */
        marginEnabled: Boolean,
        /** Roof pennant geometry — App\Data\DistrictMap::POSITION_PENNANT_*; the Twig draws from the same figures. */
        positionPennant: Object,
        /** Reconstitution calendar — App\Service\District\DistrictRoster::schedule(): nextTick, ticksPerYear… */
        reconstitution: Object,
    };

    /** Maximum recent events kept per ticker client-side, oldest dropped first. */
    static MAX_EVENTS_PER_TICKER = 8;

    /**
     * How often the badges are re-counted so an event ages out of its window while the page
     * stays open. Coarse on purpose: a badge is a "something happened here lately" cue, and
     * nothing else on the street changes on this clock.
     */
    static BADGE_REFRESH_MS = 5000;

    /** How long a rank plate shows the direction it just moved in. */
    static RANK_MOVE_MS = 1600;

    /**
     * How often an institution's readings are sampled for its sparklines, and how many samples
     * each keeps. Macro arrives on every tick; sampling once a second keeps a minute of history
     * per strip, which is enough to see a reading's direction without the strip becoming noise.
     */
    static READOUT_SAMPLE_MS = 1000;
    static READOUT_HISTORY_LENGTH = 60;

    /** Zoom multiplier bounds — 1 is the fit-to-width state (natural container width). */
    static MIN_ZOOM = 1;
    static MAX_ZOOM = 4;
    static ZOOM_STEP = 1.25;

    /** sessionStorage key carrying selection and plot positions across a reconstitution reload. */
    static RECONSTITUTION_STORAGE_KEY = 'district:reconstitution';
    /** How long the evicted facades fade before the frame is reloaded. */
    static EVICTION_FADE_MS = 700;
    /** Slide duration for survivors settling into their new plots after the reload. */
    static RECONSTITUTION_SLIDE_MS = 900;
    /** How long the "street reconstituted" notice stays up. */
    static RECONSTITUTION_NOTICE_MS = 12000;
    /** A carried-over reconstitution older than this is stale (a reload that was not ours) and is ignored. */
    static RECONSTITUTION_CARRY_MAX_MS = 15000;

    connect() {
        this.previousPrices = {};
        this.tickTimers = {};
        this.flareTimers = {};
        this.badgeTimers = {};
        this.plotsByTicker = new Map();
        this.conduitsByBuilding = new Map();
        this.conduitsByInstitution = new Map();
        this.flaresByTicker = new Map();
        this.badgesByTicker = new Map();
        this.badgeCountsByTicker = new Map();
        this.currentSelectedTicker = null;
        this.activeSector = null;

        // Recent-history backfill from App\Service\District\DistrictEventFeed, newest first —
        // read from <script type="application/json"> if present to avoid HTML-attribute bloat,
        // falling back to Stimulus values.
        const eventsDataEl = document.getElementById('district-events-data');
        const initialEvents = eventsDataEl ? JSON.parse(eventsDataEl.textContent || '{}') : (this.eventsValue || {});
        this.eventsByTicker = new Map(Object.entries(initialEvents));

        // Latest-quarter revenue mix from App\Service\District\DistrictRevenueFeed. This is a
        // snapshot, not a stream — it only changes once a simulated quarter, so unlike prices and
        // events it is never touched by onMarketUpdate()/flushPending(), only re-read on select().
        this.revenueMixByTicker = new Map(Object.entries(this.revenueMixValue || {}));

        // The market feed publishes at up to 50 Hz. Writing SVG attributes on every tick would
        // mean ~850 DOM writes/second for this ward alone, so ticks are coalesced here and the
        // DOM is only touched once per animation frame — see onMarketUpdate() / flushPending().
        this.pendingStockUpdates = new Map();
        this.pendingMacro = null;
        this.pendingEvents = [];
        this.frameHandle = null;

        this.rankMoveTimers = {};
        // Each window's lighting priority, parsed once — a live tick compares ~50 of them per
        // facade against the new lit share (see relightWindows()).
        this.windowsByTicker = new Map();
        this.plotTargets.forEach(plot => {
            const ticker = plot.dataset.ticker;
            if (ticker) {
                this.plotsByTicker.set(ticker, plot);
                this.previousPrices[ticker] = parseFloat(plot.dataset.price) || 0;
                this.windowsByTicker.set(ticker, Array.from(plot.querySelectorAll('.window')).map(el => ({
                    el,
                    key: parseFloat(el.dataset.key),
                })));
            }
        });

        this.kerbLightByTicker = new Map();
        this.kerbLightTargets.forEach(light => this.kerbLightByTicker.set(light.dataset.lightFor, light));

        // Sparkline strips keyed by macro field, and the sampled history behind each.
        this.sparksByField = new Map();
        this.readoutSparkTargets.forEach(spark => this.indexBy(this.sparksByField, spark.dataset.sparkField, spark));
        this.readingHistory = new Map();
        this.lastReadingSampleAt = 0;

        this.conduitTargets.forEach(conduit => {
            this.indexBy(this.conduitsByBuilding, conduit.dataset.conduitBuilding, conduit);
            this.indexBy(this.conduitsByInstitution, conduit.dataset.conduitInstitution, conduit);
        });

        this.flareTargets.forEach(flare => this.flaresByTicker.set(flare.dataset.flareFor, flare));
        this.badgeTargets.forEach(badge => this.badgesByTicker.set(badge.dataset.badgeFor, badge));

        // Kerb plates are rewritten on every tick, so index them once rather than searching the
        // DOM 30 times per animation frame.
        this.kerbPriceByTicker = new Map();
        this.kerbPriceTargets.forEach(node => this.kerbPriceByTicker.set(node.dataset.priceFor, node));
        this.kerbChangeByTicker = new Map();
        this.kerbChangeTargets.forEach(node => this.kerbChangeByTicker.set(node.dataset.changeFor, node));
        this.badgeCountTargets.forEach(count => {
            const badge = count.closest('[data-district-target="badge"]');
            if (badge) this.badgeCountsByTicker.set(badge.dataset.badgeFor, count);
        });

        this.readoutValueNodesByInstitution = new Map();
        this.institutionReadoutTargets.forEach(readout => {
            const institutionId = readout.dataset.institutionReadoutFor;
            this.readoutValueNodesByInstitution.set(institutionId, readout.querySelectorAll('.readout-value'));
        });

        // The single source for stress rules and readout config, shipped straight from
        // App\Data\DistrictMap::INSTITUTIONS by the controller — see ruleTriggered() above and
        // updateReadout() below for why this replaced two hand-mirrored constants.
        this.institutionsById = new Map((this.institutionsValue || []).map(i => [i.id, i]));

        this.zoomFactor = 1;
        this.conduitMode = 'all';

        this.userHoldings = this.userHoldingsValue || {};
        this.userCash = Number(this.userCashValue) || 0;

        this.pennantsByTicker = new Map();
        this.pennantTargets.forEach(pen => this.pennantsByTicker.set(pen.dataset.pennantFor, pen));

        this.onSubmitEnd = () => {
            if (this.hasQuickTradeSubmitTarget) {
                this.quickTradeSubmitTarget.disabled = false;
                this.quickTradeSubmitTarget.classList.remove('opacity-50', 'pointer-events-none');
                const span = this.quickTradeSubmitTarget.querySelector('span:last-child');
                if (span) span.textContent = 'Execute Order';
            }
        };
        document.addEventListener('turbo:submit-end', this.onSubmitEnd);

        this.onMarketUpdate = this.onMarketUpdate.bind(this);
        this.flushPending = this.flushPending.bind(this);
        document.addEventListener('market:update', this.onMarketUpdate);

        this.onVisibilityChange = this.onVisibilityChange.bind(this);
        document.addEventListener('visibilitychange', this.onVisibilityChange);

        this.reconstituting = false;
        this.settleReconstitution();

        // The server counted each badge at render time; from here on the client owns the count,
        // so events fall out of the window on schedule rather than only on the next page load.
        this.refreshBadges = this.refreshBadges.bind(this);
        this.badgeRefreshHandle = setInterval(this.refreshBadges, this.constructor.BADGE_REFRESH_MS);
    }

    /** Appends `value` to the array at `map[key]`, creating it on first use. */
    indexBy(map, key, value) {
        if (!key) return;
        if (!map.has(key)) map.set(key, []);
        map.get(key).push(value);
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
        document.removeEventListener('market:update', this.onMarketUpdate);
        document.removeEventListener('turbo:submit-end', this.onSubmitEnd);
        Object.values(this.tickTimers).forEach(clearTimeout);
        Object.values(this.rankMoveTimers).forEach(clearTimeout);
        Object.values(this.flareTimers).forEach(clearTimeout);
        Object.values(this.badgeTimers).forEach(clearTimeout);
        clearInterval(this.badgeRefreshHandle);
        clearTimeout(this.reconstitutionNoticeTimer);

        if (this.frameHandle !== null) {
            cancelAnimationFrame(this.frameHandle);
            this.frameHandle = null;
        }
    }

    /** Flushes any queued market ticks when the user switches back to this tab. */
    onVisibilityChange() {
        if (!document.hidden && this.frameHandle === null) {
            if (this.pendingStockUpdates.size > 0 || this.pendingMacro || this.pendingEvents.length > 0) {
                this.frameHandle = requestAnimationFrame(this.flushPending);
            }
        }
    }

    select(event) {
        const plot = event.currentTarget;
        if (!plot.dataset.ticker) return;

        // The SVG itself listens for clicks to deselect; a click that landed on a building is
        // already handled here.
        event.stopPropagation();

        this.clearSelection();
        plot.setAttribute('data-selected', 'true');
        this.markKerbSelected(plot.dataset.ticker, true);

        this.highlightConduits(this.conduitsByBuilding.get(plot.dataset.ticker) || []);

        this.institutionDetailTarget.classList.add('hidden');
        this.emptyTarget.classList.add('hidden');
        this.detailTarget.classList.remove('hidden');

        this.detailTickerTarget.innerText = plot.dataset.ticker;
        this.detailPlotTarget.innerText = plot.dataset.sector || '';
        this.detailNameTarget.innerText = plot.dataset.name || '';
        this.detailIndustryTarget.innerText = plot.dataset.industry || '';
        this.detailRatingTarget.innerText = plot.dataset.rating || '—';
        this.detailRankTarget.innerText = `#${plot.dataset.rank}`;
        this.detailImportanceTarget.innerText = IMPORTANCE_LABELS[plot.dataset.importance] || 'Unclassified';
        this.detailBlurbTarget.innerText = plot.dataset.blurb || '';
        this.detailLinkTarget.href = `/stock/${plot.dataset.ticker}`;

        this.renderFigures(plot);

        this.currentSelectedTicker = plot.dataset.ticker;
        this.renderEventsList(plot.dataset.ticker);
        this.renderRevenueMix(plot.dataset.ticker);

        const holding = this.userHoldings[plot.dataset.ticker];
        this.renderPositionDetail(plot.dataset.ticker, holding, parseFloat(plot.dataset.price) || 0);
        this.setupQuickTrade(plot.dataset.ticker, holding, parseFloat(plot.dataset.price) || 0);
    }

    /** Drops any current selection (and sector filter) and returns the panel to its resting copy. */
    deselect() {
        this.clearSelection();
        this.highlightConduits([]);
        this.applySectorFilter(null);
        this.currentSelectedTicker = null;

        this.detailTarget.classList.add('hidden');
        this.institutionDetailTarget.classList.add('hidden');
        this.emptyTarget.classList.remove('hidden');

        if (this.hasQuickTradeTickerInputTarget) {
            this.quickTradeTickerInputTarget.value = '';
        }
    }

    clearSelection() {
        this.plotTargets.forEach(p => p.removeAttribute('data-selected'));
        this.institutionTargets.forEach(i => i.removeAttribute('data-selected'));
        this.labelTargets.forEach(l => l.removeAttribute('data-selected'));
        this.rankTargets.forEach(r => r.removeAttribute('data-selected'));
    }

    /** The kerb plate is two separate text nodes, so selection has to light both. */
    markKerbSelected(ticker, selected) {
        const label = this.labelTargets.find(l => l.dataset.labelFor === ticker);
        const rank = this.rankTargets.find(r => r.dataset.rankFor === ticker);
        [label, rank].forEach(node => {
            if (node) node.setAttribute('data-selected', selected ? 'true' : 'false');
        });
    }

    /**
     * Shows the hover card for a building. Reading the street should not require clicking — a
     * base plot's click target is only ~45px wide even at the two-row scale.
     */
    showTooltip(event) {
        const plot = event.currentTarget;
        const tooltip = this.tooltipTarget;
        const change = parseFloat(plot.dataset.change);
        const hasChange = Number.isFinite(change);

        this.tooltipTickerTarget.textContent = plot.dataset.ticker;
        this.tooltipRankTarget.textContent = `RANK #${plot.dataset.rank}`;
        this.tooltipNameTarget.textContent = truncate(plot.dataset.name || '', 30);
        this.tooltipMetaTarget.textContent = `${plot.dataset.sector || ''} · ${plot.dataset.rating || '—'}`;
        this.tooltipPriceTarget.textContent = formatCurrency(parseFloat(plot.dataset.price) || 0);
        this.tooltipCapTarget.textContent = 'CAP ' + formatLarge(parseFloat(plot.dataset.mcap) || 0, '$');

        this.tooltipChangeTarget.textContent = hasChange ? formatPercent(change, 2, false, true) : '—';
        this.tooltipChangeTarget.setAttribute(
            'data-direction',
            hasChange ? (change > 0 ? 'up' : (change < 0 ? 'down' : 'flat')) : 'flat',
        );

        this.positionTooltip(plot);

        if (this.hasTooltipPositionTarget) {
            const holding = this.userHoldings[plot.dataset.ticker];
            if (holding) {
                const type = holding.isShort ? 'SHORT' : 'LONG';
                const shares = Number(holding.quantity).toLocaleString();
                this.tooltipPositionTarget.textContent = `YOU HOLD: ${type} ${shares} SHS`;
                this.tooltipPositionTarget.setAttribute('fill', holding.isShort ? '#ffd9a0' : '#4edea3');
            } else {
                this.tooltipPositionTarget.textContent = '';
            }
        }

        tooltip.setAttribute('data-visible', 'true');

        this.hoverConduits(this.conduitsByBuilding.get(plot.dataset.ticker) || []);
    }

    hideTooltip() {
        this.tooltipTarget.setAttribute('data-visible', 'false');
        if (this.hasTooltipPositionTarget) {
            this.tooltipPositionTarget.textContent = '';
        }
        this.hoverConduits([]);
    }

    /** Lights every conduit an institution feeds while the pointer rests on it. */
    hoverInstitution(event) {
        this.hoverConduits(this.conduitsByInstitution.get(event.currentTarget.dataset.institution) || []);
    }

    unhoverInstitution() {
        this.hoverConduits([]);
    }

    /**
     * Marks exactly the given conduits as hovered. Separate from the click highlight so moving
     * the pointer away never clears a selection.
     */
    hoverConduits(conduits) {
        this.conduitTargets.forEach(c => c.removeAttribute('data-hover'));
        conduits.forEach(c => c.setAttribute('data-hover', 'true'));
    }

    /** A legend chip toggles its sector as the street's filter; the same chip again clears it. */
    toggleSector(event) {
        const sector = event.currentTarget.dataset.sector || null;
        this.applySectorFilter(this.activeSector === sector ? null : sector);
    }

    /**
     * Dims every tenant outside the given sector — facade, kerb plate and bracket alike — or
     * clears the dimming when given null. Pure presentation: nothing is hidden, so hover, click
     * and live ticks keep working on a dimmed building.
     */
    applySectorFilter(sector) {
        this.activeSector = sector;

        const mark = (node, nodeSector) => {
            if (sector !== null && nodeSector !== sector) {
                node.setAttribute('data-dimmed', 'true');
            } else {
                node.removeAttribute('data-dimmed');
            }
        };

        this.plotTargets.forEach(plot => mark(plot, plot.dataset.sector));
        this.sectorRunTargets.forEach(run => mark(run, run.dataset.sector));

        const sectorByTicker = new Map();
        this.plotTargets.forEach(plot => sectorByTicker.set(plot.dataset.ticker, plot.dataset.sector));
        this.labelTargets.forEach(node => mark(node, sectorByTicker.get(node.dataset.labelFor)));
        this.rankTargets.forEach(node => mark(node, sectorByTicker.get(node.dataset.rankFor)));
        this.kerbPriceTargets.forEach(node => mark(node, sectorByTicker.get(node.dataset.priceFor)));
        this.kerbChangeTargets.forEach(node => mark(node, sectorByTicker.get(node.dataset.changeFor)));
        this.kerbLightTargets.forEach(node => mark(node, sectorByTicker.get(node.dataset.lightFor)));

        this.sectorChipTargets.forEach(chip => {
            const active = chip.dataset.sector === sector;
            chip.setAttribute('aria-pressed', active ? 'true' : 'false');
            chip.setAttribute('data-active', active ? 'true' : 'false');
            chip.setAttribute('data-muted', sector !== null && !active ? 'true' : 'false');
        });
    }

    /**
     * Places the card above the hovered building, flipping it inside the canvas at either edge so
     * it is never clipped by the viewBox.
     */
    positionTooltip(plot) {
        const facade = plot.querySelector('.facade');
        if (!facade) return;

        const width = TOOLTIP_WIDTH;
        const height = TOOLTIP_HEIGHT;
        const plotX = parseFloat(facade.getAttribute('x'));
        const plotWidth = parseFloat(facade.getAttribute('width'));
        const plotY = parseFloat(facade.getAttribute('y'));

        const [, , canvasWidth] = this.svgTarget.getAttribute('viewBox').split(' ').map(Number);

        let x = plotX + plotWidth / 2 - width / 2;
        x = Math.max(8, Math.min(canvasWidth - width - 8, x));

        // Prefer above the roofline; drop below it when the building is tall enough to run out of sky.
        let y = plotY - height - 24;
        if (y < 8) y = plotY + 24;

        this.tooltipTarget.setAttribute('transform', `translate(${x} ${y})`);
    }

    /** Selects an institution: highlights every conduit it feeds, without touching the building panel. */
    selectInstitution(event) {
        const institution = event.currentTarget;
        const institutionId = institution.dataset.institution;
        if (!institutionId) return;

        event.stopPropagation();
        this.currentSelectedTicker = null;

        this.clearSelection();
        institution.setAttribute('data-selected', 'true');

        const fed = this.conduitsByInstitution.get(institutionId) || [];
        this.highlightConduits(fed);

        this.emptyTarget.classList.add('hidden');
        this.detailTarget.classList.add('hidden');
        this.institutionDetailTarget.classList.remove('hidden');

        const config = this.institutionsById.get(institutionId);
        const stressed = institution.getAttribute('data-stressed') === 'true';

        this.institutionNameTarget.textContent = config ? config.label : institutionId;
        this.institutionStatusTarget.textContent = stressed ? 'Stressed' : 'Calm';
        this.institutionStatusTarget.className = 'text-3xs font-mono uppercase tracking-wider px-1.5 py-0.5 rounded border '
            + (stressed
                ? 'bg-tertiary/10 text-tertiary border-tertiary/30'
                : 'bg-secondary/10 text-secondary border-secondary/30');

        this.renderInstitutionReadings(config);
        this.renderInstitutionFeeds(fed);
    }

    /** Prints the institution's published readings from the last macro snapshot it was given. */
    renderInstitutionReadings(config) {
        const container = this.institutionReadingsTarget;
        container.replaceChildren();

        const readouts = config && Array.isArray(config.readouts) ? config.readouts : [];
        if (readouts.length === 0) {
            return;
        }

        readouts.forEach(readout => {
            const wrap = document.createElement('div');

            const label = document.createElement('dt');
            label.className = 'text-on-surface-variant/60 uppercase tracking-wider text-3xs';
            label.textContent = readout.label;

            const value = document.createElement('dd');
            value.className = 'text-on-surface tabular-nums';
            // The rendered SVG readout is the live one — read it back rather than keeping a
            // second copy of the macro snapshot in sync.
            const node = this.element.querySelector(`.readout-value[data-readout-field="${readout.field}"]`);
            value.textContent = node ? node.textContent : '—';

            wrap.append(label, value);
            container.appendChild(wrap);
        });
    }

    /** Lists the tenants this institution feeds, as ticker chips. */
    renderInstitutionFeeds(conduits) {
        const container = this.institutionFeedsTarget;
        container.replaceChildren();

        const tickers = [...new Set(conduits.map(c => c.dataset.conduitBuilding))].sort();
        this.institutionFeedCountTarget.textContent = `(${tickers.length})`;

        tickers.forEach(ticker => {
            const chip = document.createElement('span');
            chip.className = 'px-1.5 py-0.5 rounded bg-surface-container text-on-surface-variant text-3xs font-mono border border-outline-variant/15';
            chip.textContent = ticker;
            container.appendChild(chip);
        });
    }

    /** Marks exactly the given conduits as highlighted, clearing any previous highlight. */
    highlightConduits(conduits) {
        this.conduitTargets.forEach(c => c.removeAttribute('data-highlighted'));
        conduits.forEach(c => c.setAttribute('data-highlighted', 'true'));
    }

    /**
     * Segmented toolbar: 'all', 'stressed', 'focused' or 'muted'. The mode is one attribute on the
     * canvas and the rest is CSS — "focused" reads the same `data-highlighted` (selection) and
     * `data-hover` marks the conduits already carry, so it needs no state of its own to keep in step.
     */
    setConduitMode(event) {
        const mode = event.currentTarget.dataset.mode;
        if (!mode || !this.hasSvgTarget) return;

        this.conduitMode = mode;
        this.conduitModeBtnTargets.forEach(btn => {
            btn.setAttribute('data-active', btn.dataset.mode === mode ? 'true' : 'false');
        });

        if (mode === 'all') {
            this.svgTarget.removeAttribute('data-conduit-mode');
        } else {
            this.svgTarget.setAttribute('data-conduit-mode', mode);
        }
    }

    /** Formats and displays position details (shares, avg cost, unrealised P&L) in the sidebar. */
    renderPositionDetail(ticker, holding, currentPrice) {
        if (!this.hasDetailPositionTarget) return;

        if (!holding) {
            this.detailPositionTarget.textContent = 'None';
            this.detailPositionTarget.className = 'text-on-surface-variant/50 tabular-nums font-normal';
            return;
        }

        const qty = holding.quantity;
        const avgCost = Number(holding.averageCost) || 0;
        const isShort = Boolean(holding.isShort);
        const positionType = isShort ? 'Short' : 'Long';

        let costText = '';
        let pnlText = '';
        let pnlClass = 'text-on-surface';

        // A short's basis is its average proceeds, so its gain runs the other way: down from the basis.
        if (avgCost > 0) {
            costText = ` @ ${formatCurrency(avgCost)}`;
            if (currentPrice > 0) {
                const pnlPerShare = isShort ? (avgCost - currentPrice) : (currentPrice - avgCost);
                const totalPnl = pnlPerShare * qty;
                const pnlPct = (pnlPerShare / avgCost) * 100;
                const sign = totalPnl >= 0 ? '+' : '';
                pnlText = ` (${sign}${formatCurrency(totalPnl)}, ${sign}${pnlPct.toFixed(1)}%)`;
                pnlClass = totalPnl >= 0 ? 'text-secondary' : 'text-tertiary';
            }
        }

        this.detailPositionTarget.textContent = `${positionType} ${qty.toLocaleString()} shs${costText}${pnlText}`;
        this.detailPositionTarget.className = `tabular-nums font-bold ${pnlClass}`;
    }

    /** Initialises the quick-trade ticket for the selected constituent. */
    setupQuickTrade(ticker, holding, currentPrice) {
        if (!this.hasQuickTradeFormTarget) return;

        if (this.hasQuickTradeTickerInputTarget) {
            this.quickTradeTickerInputTarget.value = ticker;
        }

        if (this.hasQuickTradeHoldingTarget) {
            if (holding) {
                const isShort = Boolean(holding.isShort);
                this.quickTradeHoldingTarget.textContent = `${isShort ? 'Short' : 'Long'} ${holding.quantity.toLocaleString()} shs`;
                this.quickTradeHoldingTarget.className = `text-3xs font-mono font-semibold ${isShort ? 'text-amber-400' : 'text-secondary'}`;
            } else {
                this.quickTradeHoldingTarget.textContent = '0 shs';
                this.quickTradeHoldingTarget.className = 'text-3xs font-mono font-semibold text-on-surface-variant/50';
            }
        }

        this.updateQuickTradeEstimate();
    }

    onTradeActionChange() {
        this.updateQuickTradeEstimate();
    }

    onQuantityInput() {
        this.updateQuickTradeEstimate();
    }

    setQuickQuantity(event) {
        const qty = parseInt(event.currentTarget.dataset.quantity, 10);
        if (this.hasQuickTradeQuantityTarget && qty > 0) {
            this.quickTradeQuantityTarget.value = qty;
            this.updateQuickTradeEstimate();
        }
    }

    setQuickQuantityMax() {
        if (!this.hasQuickTradeQuantityTarget || !this.currentSelectedTicker) return;
        const plot = this.plotsByTicker.get(this.currentSelectedTicker);
        const price = plot ? (parseFloat(plot.dataset.price) || 0) : 0;
        const holding = this.userHoldings[this.currentSelectedTicker];

        const form = this.hasQuickTradeFormTarget ? this.quickTradeFormTarget : null;
        const actionInput = form ? form.querySelector('input[name="action"]:checked') : null;
        const action = actionInput ? actionInput.value : 'BUY';

        let maxQty = 1;
        if (action === 'BUY' || action === 'SHORT') {
            maxQty = price > 0 ? Math.floor(this.userCash / price) : 0;
        } else if (action === 'SELL') {
            maxQty = (holding && !holding.isShort) ? holding.quantity : 0;
        } else if (action === 'COVER') {
            maxQty = (holding && holding.isShort) ? holding.quantity : 0;
        }

        this.quickTradeQuantityTarget.value = Math.max(1, maxQty);
        this.updateQuickTradeEstimate();
    }

    onQuickTradeSubmit() {
        if (this.hasQuickTradeSubmitTarget) {
            this.quickTradeSubmitTarget.disabled = true;
            this.quickTradeSubmitTarget.classList.add('opacity-50', 'pointer-events-none');
            const span = this.quickTradeSubmitTarget.querySelector('span:last-child');
            if (span) span.textContent = 'Executing...';
        }
    }

    updateQuickTradeEstimate() {
        if (!this.hasQuickTradeEstimateTarget || !this.currentSelectedTicker) return;
        const plot = this.plotsByTicker.get(this.currentSelectedTicker);
        const price = plot ? (parseFloat(plot.dataset.price) || 0) : 0;
        const qty = this.hasQuickTradeQuantityTarget ? (parseInt(this.quickTradeQuantityTarget.value, 10) || 0) : 0;
        const total = price * qty;
        this.quickTradeEstimateTarget.textContent = `Est: ${formatCurrency(total)}`;
    }

    /** Rewrites the countdown tile from the live tick count: simulated days to the next reconstitution. */
    renderReconstitutionCountdown(tick) {
        if (!this.hasSummaryReconstitutionTarget) return;
        const schedule = this.reconstitutionValue || {};
        const ticksPerYear = Number(schedule.ticksPerYear) || 0;
        const nextTick = Number(schedule.nextTick) || 0;
        if (ticksPerYear <= 0) return;

        const days = Math.max(0, Math.ceil((nextTick - tick) / ticksPerYear * 365));
        this.summaryReconstitutionTarget.textContent = days === 0 ? 'now' : `in ${days}d`;

        // The boundary has passed but no announcement reached us — the announcement is one
        // 20ms message, and the market stream drops its socket while the tab is hidden. The
        // roster on the server has changed regardless, so reload anyway; without the names
        // the notice just says the street was reconstituted and the slide still works.
        if (tick >= nextTick && !this.reconstituting) {
            this.reconstitute({ promoted: [], evicted: [] });
        }
    }

    /**
     * The feed announced a reconstitution. Evicted facades fade out first; then the selection and
     * every plot's position are stashed for settleReconstitution() and the frame reloads itself
     * from the server, which is the only party that knows what a newcomer looks like.
     */
    reconstitute(district) {
        if (this.reconstituting) return;
        const frame = this.element.closest('turbo-frame');
        if (!frame) return;
        this.reconstituting = true;

        const evicted = Array.isArray(district.evicted) ? district.evicted : [];
        const promoted = Array.isArray(district.promoted) ? district.promoted : [];

        const positions = {};
        this.plotsByTicker.forEach((plot, ticker) => {
            const facade = plot.querySelector('.facade');
            if (!facade) return;
            positions[ticker] = {
                x: parseFloat(facade.getAttribute('x')) || 0,
                y: parseFloat(facade.getAttribute('y')) || 0,
            };
        });

        try {
            sessionStorage.setItem(this.constructor.RECONSTITUTION_STORAGE_KEY, JSON.stringify({
                at: Date.now(),
                selected: this.currentSelectedTicker,
                positions,
                promoted,
                evicted,
            }));
        } catch {
            // Storage unavailable: the reload still happens, it just cannot animate or reselect.
        }

        evicted.forEach(ticker => {
            const plot = this.plotsByTicker.get(ticker);
            if (!plot) return;
            plot.style.transition = `opacity ${this.constructor.EVICTION_FADE_MS}ms ease-out`;
            plot.style.opacity = '0';
        });

        // Turbo 7's FrameElement.reload() re-assigns the frame's own `src`, and a frame rendered
        // inline with the page has none — reload() would set null to null and do nothing. The
        // first reconstitution therefore points the frame at this page's URL, which triggers the
        // fetch; the attribute survives the render, so every later one can plain reload().
        const delay = evicted.some(t => this.plotsByTicker.has(t)) ? this.constructor.EVICTION_FADE_MS : 0;
        setTimeout(() => {
            if (frame.src) {
                frame.reload();
            } else {
                frame.src = window.location.href;
            }
        }, delay);
    }

    /**
     * Runs on connect(). If this render is the far side of a reconstitution reload, survivors
     * slide from where they stood to where they stand now (FLIP: start at the old offset, then
     * transition to none), newcomers fade in, the previous selection is restored, and the notice
     * says who moved. A stale or absent stash means an ordinary page load and nothing happens.
     */
    settleReconstitution() {
        let carried = null;
        try {
            const raw = sessionStorage.getItem(this.constructor.RECONSTITUTION_STORAGE_KEY);
            sessionStorage.removeItem(this.constructor.RECONSTITUTION_STORAGE_KEY);
            carried = raw ? JSON.parse(raw) : null;
        } catch {
            carried = null;
        }
        if (!carried || !Number.isFinite(carried.at)) return;
        if (Date.now() - carried.at > this.constructor.RECONSTITUTION_CARRY_MAX_MS) return;

        const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const slideMs = this.constructor.RECONSTITUTION_SLIDE_MS;
        const positions = carried.positions || {};
        const promoted = new Set(Array.isArray(carried.promoted) ? carried.promoted : []);

        if (!reduceMotion) {
            const movers = [];
            this.plotsByTicker.forEach((plot, ticker) => {
                const facade = plot.querySelector('.facade');
                if (!facade) return;

                if (promoted.has(ticker) || !positions[ticker]) {
                    plot.style.opacity = '0';
                    movers.push({ plot, dx: 0, dy: 0, fadeIn: true });
                    return;
                }

                const dx = positions[ticker].x - (parseFloat(facade.getAttribute('x')) || 0);
                const dy = positions[ticker].y - (parseFloat(facade.getAttribute('y')) || 0);
                if (dx === 0 && dy === 0) return;
                plot.style.transform = `translate(${dx}px, ${dy}px)`;
                movers.push({ plot, dx, dy, fadeIn: false });
            });

            // Two frames apart so the browser paints the "first" state before transitioning to the "last".
            requestAnimationFrame(() => requestAnimationFrame(() => {
                movers.forEach(({ plot, fadeIn }) => {
                    plot.style.transition = `transform ${slideMs}ms cubic-bezier(0.22, 1, 0.36, 1), opacity ${slideMs}ms ease-out`;
                    plot.style.transform = '';
                    if (fadeIn) plot.style.opacity = '';
                });
                setTimeout(() => movers.forEach(({ plot }) => { plot.style.transition = ''; }), slideMs + 50);
            }));
        }

        if (carried.selected && this.plotsByTicker.has(carried.selected)) {
            const plot = this.plotsByTicker.get(carried.selected);
            this.select({ currentTarget: plot, stopPropagation() {} });
        }

        if (this.hasReconstitutionNoticeTarget) {
            const evicted = Array.isArray(carried.evicted) ? carried.evicted : [];
            const parts = ['Street reconstituted'];
            if (promoted.size > 0) parts.push(`promoted ${Array.from(promoted).join(', ')}`);
            if (evicted.length > 0) parts.push(`evicted ${evicted.join(', ')}`);
            if (promoted.size === 0 && evicted.length === 0) parts.push('no change to the roster');
            this.reconstitutionNoticeTarget.textContent = parts.join(' · ');
            this.reconstitutionNoticeTarget.hidden = false;
            this.reconstitutionNoticeTimer = setTimeout(() => {
                if (this.hasReconstitutionNoticeTarget) this.reconstitutionNoticeTarget.hidden = true;
            }, this.constructor.RECONSTITUTION_NOTICE_MS);
        }
    }

    zoomIn() {
        this.setZoomFactor(this.zoomFactor * this.constructor.ZOOM_STEP);
    }

    zoomOut() {
        this.setZoomFactor(this.zoomFactor / this.constructor.ZOOM_STEP);
    }

    /**
     * Restores the natural fit-to-container width — the whole derived street frontage visible
     * at once, same as first paint.
     */
    fitToWidth() {
        this.setZoomFactor(1);
    }

    /**
     * Sets the SVG's rendered pixel width as a multiple of its natural (fit-to-width) width.
     * The viewBox itself never changes — only the element's CSS width — so the browser does the
     * scaling and the existing horizontal scrollbar becomes the pan mechanism at any zoom level.
     */
    setZoomFactor(factor) {
        const { MIN_ZOOM, MAX_ZOOM } = this.constructor;
        this.zoomFactor = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, factor));

        if (this.zoomFactor === 1) {
            this.svgTarget.style.width = '';
            return;
        }

        const naturalWidth = this.svgTarget.parentElement.clientWidth;
        this.svgTarget.style.width = Math.round(naturalWidth * this.zoomFactor) + 'px';
    }

    /** Writes the numeric fields of the panel from a plot's current dataset. */
    renderFigures(plot) {
        const price = parseFloat(plot.dataset.price) || 0;
        const mcap = parseFloat(plot.dataset.mcap) || 0;
        const roc = parseFloat(plot.dataset.roc) || 0;
        const baselineRoc = parseFloat(plot.dataset.baselineRoc);

        this.detailPriceTarget.innerText = formatCurrency(price);
        this.detailMcapTarget.innerText = formatLarge(mcap, '$');
        // The same ratio that lights the windows, printed as the two figures behind it.
        this.detailRocTarget.innerText = Number.isFinite(baselineRoc) && baselineRoc > 0
            ? `${formatPercent(roc)} vs ${formatPercent(baselineRoc)}`
            : formatPercent(roc);

        const change = parseFloat(plot.dataset.change);
        if (Number.isFinite(change)) {
            this.detailChangeTarget.innerText = formatPercent(change, 2, false, true);
            this.detailChangeTarget.className = 'tabular-nums ' + (change >= 0 ? 'text-secondary' : 'text-tertiary');
        } else {
            this.detailChangeTarget.innerText = '—';
            this.detailChangeTarget.className = 'tabular-nums text-on-surface-variant/50';
        }
    }

    /** Rebuilds the "Recent Events" list in the side panel for the given ticker. */
    renderEventsList(ticker) {
        const events = this.eventsByTicker.get(ticker) || [];

        this.detailEventsTarget.querySelectorAll('.district-event-card').forEach(card => card.remove());

        if (events.length === 0) {
            this.noEventsTarget.hidden = false;
            return;
        }

        this.noEventsTarget.hidden = true;
        events.forEach(evt => this.detailEventsTarget.appendChild(this.buildEventCard(evt)));
    }

    /** Builds one compact event card. Accepts both server-presented events (badgeClass/iconClass,
     *  Tailwind tokens from EventPresenter) and live-appended ones (plain `color`, from
     *  categorizeEvent()) — the two are rendered identically except for how colour is applied. */
    buildEventCard(evt) {
        const card = document.createElement('div');
        card.className = 'district-event-card flex items-start gap-2 p-2 rounded-lg bg-surface-container-lowest/80 border border-outline-variant/15';

        const iconWrap = document.createElement('div');
        iconWrap.className = 'w-5 h-5 rounded flex items-center justify-center shrink-0 mt-0.5';
        const icon = document.createElement('span');
        icon.className = 'material-symbols-outlined text-xs';
        icon.textContent = evt.icon || 'campaign';
        iconWrap.appendChild(icon);
        if (evt.iconClass) {
            iconWrap.className += ' ' + evt.iconClass;
        } else {
            iconWrap.style.backgroundColor = (evt.color || '#adc6ff') + '26';
            icon.style.color = evt.color || '#adc6ff';
        }
        card.appendChild(iconWrap);

        const body = document.createElement('div');
        body.className = 'min-w-0 flex-1 space-y-1';

        const header = document.createElement('div');
        header.className = 'flex items-center justify-between gap-2';

        const badge = document.createElement('span');
        badge.className = 'inline-flex items-center rounded px-1.5 py-0.5 text-4xs font-bold font-mono uppercase tracking-wider border';
        badge.textContent = evt.badge || evt.type || 'EVENT';
        if (evt.badgeClass) {
            badge.className += ' ' + evt.badgeClass;
        } else {
            badge.style.color = evt.color || '#adc6ff';
            badge.style.borderColor = (evt.color || '#adc6ff') + '55';
            badge.style.backgroundColor = (evt.color || '#adc6ff') + '1a';
        }
        header.appendChild(badge);

        const time = document.createElement('span');
        time.className = 'text-4xs font-mono text-on-surface-variant/50 shrink-0';
        time.textContent = evt.recordedAt || '';
        header.appendChild(time);
        body.appendChild(header);

        const headline = document.createElement('p');
        headline.className = 'text-2xs text-on-surface leading-snug';
        headline.textContent = evt.headline || '';
        body.appendChild(headline);

        card.appendChild(body);
        return card;
    }

    /**
     * Rebuilds the segment-revenue note for the given ticker from App\Service\District\DistrictRevenueFeed.
     *
     * Laid out the way a filing's segment note is: the period and the headline total first, with
     * the sequential and year-on-year moves beside it, then the mix as a single 100% bar, then a
     * row per segment. Every growth figure that has no comparison quarter prints "n/m" rather
     * than a zero, which is what the feed's nulls mean.
     */
    renderRevenueMix(ticker) {
        const mix = this.revenueMixByTicker.get(ticker);
        const streams = mix && Array.isArray(mix.streams) ? mix.streams : [];

        this.detailRevenueMixTarget.querySelectorAll('.district-stream-row').forEach(row => row.remove());
        this.detailRevenueMixBarTarget.replaceChildren();

        if (streams.length === 0) {
            this.noRevenueMixTarget.hidden = false;
            this.detailRevenueHeadlineTarget.classList.add('hidden');
            this.detailRevenueFootnoteTarget.hidden = true;
            this.detailRevenuePeriodTarget.textContent = '';
            this.detailRevenueTotalTarget.textContent = '';
            return;
        }

        this.noRevenueMixTarget.hidden = true;
        this.detailRevenueHeadlineTarget.classList.remove('hidden');
        this.detailRevenueFootnoteTarget.hidden = false;

        this.detailRevenuePeriodTarget.textContent = mix.periodLabel || '';
        this.detailRevenueTotalTarget.textContent = formatLarge(mix.totalRevenue, '$');
        this.applyGrowth(this.detailRevenueQoqTarget, 'QoQ', mix.totalQoq);
        this.applyGrowth(this.detailRevenueYoyTarget, 'YoY', mix.totalYoy);
        this.detailRevenueMetaTarget.textContent = this.buildRevenueMeta(mix);

        // One 100% bar for the whole mix — the segment-mix idiom, and the only place a segment's
        // colour is assigned; the rows below read their swatch from the same index.
        streams.forEach((stream, index) => {
            const share = stream.share || 0;
            if (share <= 0) return;
            const segment = document.createElement('div');
            segment.style.width = Math.min(100, share * 100) + '%';
            segment.style.backgroundColor = STREAM_COLORS[index % STREAM_COLORS.length];
            segment.title = `${stream.label} — ${formatPercent(share, 1)}`;
            this.detailRevenueMixBarTarget.appendChild(segment);
        });

        streams.forEach((stream, index) => {
            this.detailRevenueMixTarget.appendChild(this.buildStreamRow(stream, index));
        });
    }

    /** Writes one signed growth figure into a node, greying it out where the period is "n/m". */
    applyGrowth(node, label, value) {
        const isMeaningful = value !== null && value !== undefined;
        node.textContent = `${label} ${formatGrowth(value)}`;
        node.className = 'text-2xs font-mono tabular-nums ' + (
            !isMeaningful ? 'text-on-surface-variant/40' : (value >= 0 ? 'text-secondary' : 'text-tertiary')
        );
    }

    /** The secondary line under the headline: TTM revenue and how concentrated the mix is. */
    buildRevenueMeta(mix) {
        const parts = [];

        if (mix.ttmRevenue !== null && mix.ttmRevenue !== undefined) {
            parts.push('TTM ' + formatLarge(mix.ttmRevenue, '$'));
        }

        if (mix.concentration) {
            // HHI is conventionally quoted on the 0–10,000 scale, not as a decimal fraction.
            parts.push(`HHI ${Math.round(mix.concentration.hhi * 10000).toLocaleString()}`);
            parts.push(`${mix.concentration.effectiveStreams.toFixed(1)} effective segments`);
        }

        parts.push(`${mix.quartersOnFile} qtr${mix.quartersOnFile === 1 ? '' : 's'} on file`);

        return parts.join('  ·  ');
    }

    /**
     * Builds one segment row: the reported dollars and share, then the trend, both growth rates,
     * what the segment contributed to the firm's own growth, and how its share of the mix moved.
     *
     * Contribution is the figure that makes the row honest — a segment holding 5% of revenue that
     * grows 40% contributes 2 points, and printing only its own rate implied it carried the
     * quarter. The contributions across these rows sum to the headline QoQ above them.
     */
    buildStreamRow(stream, index) {
        const row = document.createElement('div');
        row.className = 'district-stream-row';

        const header = document.createElement('div');
        header.className = 'flex items-baseline justify-between gap-2 text-2xs mb-1';

        const name = document.createElement('span');
        name.className = 'flex items-center gap-1.5 min-w-0';
        const swatch = document.createElement('span');
        swatch.className = 'w-1.5 h-1.5 rounded-sm shrink-0';
        swatch.style.backgroundColor = STREAM_COLORS[index % STREAM_COLORS.length];
        const label = document.createElement('span');
        label.className = 'font-semibold text-on-surface truncate';
        label.textContent = stream.label || stream.key || '';
        name.append(swatch, label);

        const figures = document.createElement('span');
        figures.className = 'font-mono tabular-nums shrink-0 ' + ((stream.revenue || 0) < 0 ? 'text-tertiary' : 'text-on-surface');
        const revenue = stream.revenue || 0;
        // A negative segment — a trading loss — is bracketed, as it is in a filing, not clamped away.
        figures.textContent = (revenue < 0 ? `(${formatLarge(Math.abs(revenue), '$')})` : formatLarge(revenue, '$'))
            + '  ' + formatPercent(stream.share || 0, 1);

        header.append(name, figures);
        row.appendChild(header);

        // Two fixed lines rather than one wrapping row: growth rates above, attribution below.
        // Letting all five figures share a wrapping flex row orphaned whichever one ran out of
        // width onto a line of its own, which read as though it belonged to the next segment.
        const growth = document.createElement('div');
        growth.className = 'flex items-center gap-2 text-3xs font-mono mb-0.5 pl-3';
        growth.appendChild(this.buildSparkline(stream.history));
        growth.appendChild(this.buildMetric('QoQ', formatGrowth(stream.qoqDelta), stream.qoqDelta));
        growth.appendChild(this.buildMetric('YoY', formatGrowth(stream.yoyDelta), stream.yoyDelta));
        row.appendChild(growth);

        const attribution = document.createElement('div');
        attribution.className = 'flex items-center gap-2 text-3xs font-mono mb-1.5 pl-3';

        if (stream.contribution !== null && stream.contribution !== undefined) {
            const contribution = stream.contribution;
            attribution.appendChild(this.buildMetric(
                '',
                `${contribution >= 0 ? '+' : '−'}${Math.abs(contribution * 100).toFixed(2)}pp of growth`,
                contribution,
            ));
        }

        if (stream.shareShiftBps !== null && stream.shareShiftBps !== undefined) {
            const shift = stream.shareShiftBps;
            attribution.appendChild(this.buildMetric(
                '',
                `${shift >= 0 ? '+' : '−'}${Math.abs(shift).toFixed(0)} bps mix`,
                shift,
            ));
        }

        if (attribution.childElementCount > 0) {
            row.appendChild(attribution);
        }

        if (stream.event) {
            const eventLine = document.createElement('div');
            eventLine.className = 'pl-3 mb-1';
            const eventBadge = document.createElement('span');
            eventBadge.className = 'text-3xs font-bold uppercase tracking-wider text-amber-400 bg-amber-950/40 px-1.5 py-0.5 rounded border border-amber-500/30';
            eventBadge.textContent = stream.event;
            eventLine.appendChild(eventBadge);
            row.appendChild(eventLine);
        }

        if (Array.isArray(stream.drivers) && stream.drivers.length > 0) {
            row.appendChild(this.buildDriverList(stream.drivers));
        }

        return row;
    }

    /**
     * A driver's direction and strength, drawn rather than typed.
     *
     * The pips are elements with an explicit size, not box-drawing characters: the panel is set in
     * IBM Plex Mono, which carries no glyph for them, so a typed meter rendered as tofu boxes.
     */
    buildStrengthMeter(strength, isPositive) {
        const meter = document.createElement('span');
        meter.className = 'flex items-center gap-[2px] shrink-0 ' + (isPositive ? 'text-secondary' : 'text-tertiary');

        const pips = Math.max(1, Math.min(DRIVER_STRENGTH_PIPS, strength || 1));
        meter.title = (isPositive ? 'Tailwind' : 'Headwind') + ` (${pips}/${DRIVER_STRENGTH_PIPS})`;

        const arrow = document.createElement('span');
        arrow.className = 'text-4xs leading-none mr-0.5';
        arrow.textContent = isPositive ? '\u25B2' : '\u25BC';
        meter.appendChild(arrow);

        for (let index = 0; index < DRIVER_STRENGTH_PIPS; index++) {
            const pip = document.createElement('span');
            pip.style.width = '3px';
            pip.style.height = '8px';
            pip.style.borderRadius = '1px';
            pip.style.backgroundColor = 'currentColor';
            pip.style.opacity = index < pips ? '1' : '0.2';
            meter.appendChild(pip);
        }

        return meter;
    }

    /** One labelled figure in a segment row's metric line, coloured by sign and greyed when "n/m". */
    buildMetric(label, text, value) {
        const node = document.createElement('span');
        const isMeaningful = value !== null && value !== undefined;
        node.className = 'tabular-nums ' + (
            !isMeaningful ? 'text-on-surface-variant/40' : (value >= 0 ? 'text-secondary' : 'text-tertiary')
        );
        node.textContent = label ? `${label} ${text}` : text;
        return node;
    }

    /**
     * Five-quarter revenue trend for one segment, oldest-first. Quarters the segment did not exist
     * in arrive as null and break the line rather than being drawn as a zero.
     */
    buildSparkline(history) {
        const svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('width', String(SPARKLINE_WIDTH));
        svg.setAttribute('height', String(SPARKLINE_HEIGHT));
        svg.setAttribute('viewBox', `0 0 ${SPARKLINE_WIDTH} ${SPARKLINE_HEIGHT}`);
        svg.classList.add('shrink-0', 'opacity-70');

        const series = Array.isArray(history) ? history : [];
        const points = series
            .map((value, index) => ({ value, index }))
            .filter(point => point.value !== null && point.value !== undefined);

        if (points.length < 2) return svg;

        const values = points.map(point => point.value);
        const min = Math.min(...values);
        const max = Math.max(...values);
        // A flat series has no range to scale against — draw it down the middle rather than
        // dividing by zero and sending it off the top of the box.
        const range = max - min;
        const stepX = SPARKLINE_WIDTH / Math.max(1, series.length - 1);

        const path = points.map((point, i) => {
            const x = point.index * stepX;
            const y = range > 0
                ? SPARKLINE_HEIGHT - ((point.value - min) / range) * SPARKLINE_HEIGHT
                : SPARKLINE_HEIGHT / 2;
            return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
        }).join(' ');

        const line = document.createElementNS(SVG_NS, 'path');
        line.setAttribute('d', path);
        line.setAttribute('fill', 'none');
        line.setAttribute('stroke', 'currentColor');
        line.setAttribute('stroke-width', '1');
        line.classList.add(values[values.length - 1] >= values[0] ? 'text-secondary' : 'text-tertiary');
        svg.appendChild(line);

        return svg;
    }

    /**
     * The driver block: what moved the segment, and the macro level it was struck at.
     *
     * A driver's `impact` is a raw model coefficient with no unit — it is not a share of revenue
     * and does not reconcile against the QoQ figure above it, so it is never printed as a number.
     * What prints is its direction, a coarse strength, and the observed readings
     * App\Data\MacroFieldCatalog resolved for it — the same macro variables the street's
     * institutions publish, in the same units, so the two always agree.
     */
    buildDriverList(drivers) {
        const list = document.createElement('div');
        list.className = 'space-y-1 pl-3 mb-3 border-l border-outline-variant/25';

        drivers.slice(0, MAX_DRIVERS_PER_STREAM).forEach(driver => {
            const isPositive = (driver.direction ?? 0) >= 0;
            const entry = document.createElement('div');
            entry.className = 'pl-2';

            const head = document.createElement('div');
            head.className = 'flex items-baseline justify-between gap-2 text-3xs';

            const driverLabel = document.createElement('span');
            driverLabel.className = 'min-w-0 flex items-baseline gap-1.5';
            const typeTag = document.createElement('span');
            typeTag.className = 'text-4xs uppercase tracking-wider text-on-surface-variant/35 shrink-0';
            typeTag.textContent = DRIVER_TYPE_TAGS[driver.type] || DRIVER_TYPE_TAGS.company;
            const driverName = document.createElement('span');
            driverName.className = 'text-on-surface-variant/70';
            driverName.textContent = driver.label || '';
            driverLabel.append(typeTag, driverName);
            head.appendChild(driverLabel);

            head.appendChild(this.buildStrengthMeter(driver.strength, isPositive));
            entry.appendChild(head);

            const readings = Array.isArray(driver.readings) ? driver.readings : [];
            if (readings.length > 0) {
                const readingLine = document.createElement('div');
                readingLine.className = 'text-3xs font-mono tabular-nums text-on-surface-variant/45';
                readingLine.textContent = readings.map(r => `${r.label} ${formatReading(r)}`).join('  ·  ');
                entry.appendChild(readingLine);
            } else if (driver.type === 'momentum' && driver.z !== undefined) {
                // Sigma is how a standardised deviation is quoted; a bare "Z=" is model notation.
                const momentumLine = document.createElement('div');
                momentumLine.className = 'text-3xs font-mono tabular-nums text-on-surface-variant/45';
                const z = Number(driver.z);
                momentumLine.textContent = `Operating momentum ${z >= 0 ? '+' : '−'}${Math.abs(z).toFixed(1)}σ `
                    + (z >= 0 ? 'above trend' : 'below trend');
                entry.appendChild(momentumLine);
            }

            list.appendChild(entry);
        });

        return list;
    }

    onMarketUpdate(event) {
        const payload = event.detail;
        if (!payload) return;

        // Queue only; DOM writes happen in flushPending(). The Map naturally coalesces several
        // ticks landing in the same frame down to the latest state per ticker. The LBI index
        // entry carries `is_etf: true` and none of this ward's fields (no market_cap, no
        // is_bankrupt) — it never holds a plot here, so it is skipped explicitly rather than
        // relying only on the plotsByTicker miss.
        if (Array.isArray(payload.stocks)) {
            payload.stocks.forEach(update => {
                if (update.is_etf || !this.plotsByTicker.has(update.ticker)) return;
                this.pendingStockUpdates.set(update.ticker, update);
            });
        }

        if (payload.macro) {
            this.pendingMacro = payload.macro;
        }

        if (Number.isFinite(payload.tick)) {
            this.renderReconstitutionCountdown(payload.tick);
        }

        // A new roster was taken on this tick. The street is server-rendered, so the frame is
        // reloaded rather than re-laid-out here; everything up to the reload is choreography.
        if (payload.district && typeof payload.district === 'object') {
            this.reconstitute(payload.district);
        }

        // Unlike stock ticks, events are discrete occurrences rather than a replaceable state —
        // several can land on different buildings in the same frame and all of them must fire,
        // so they are appended rather than coalesced by ticker.
        if (Array.isArray(payload.events)) {
            payload.events.forEach(evt => {
                if (this.plotsByTicker.has(evt.ticker)) this.pendingEvents.push(evt);
            });
        }

        if (!document.hidden && this.frameHandle === null) {
            this.frameHandle = requestAnimationFrame(this.flushPending);
        }
    }

    /** Applies every tick coalesced since the last animation frame in a single DOM pass. */
    flushPending() {
        this.frameHandle = null;

        this.pendingStockUpdates.forEach((update, ticker) => {
            const plot = this.plotsByTicker.get(ticker);
            if (plot) this.applyUpdate(plot, update);
        });
        if (this.pendingStockUpdates.size > 0) {
            this.rerank();
        }
        this.pendingStockUpdates.clear();

        if (this.pendingMacro) {
            this.applyStress(this.pendingMacro);
            this.pendingMacro = null;
        }

        if (this.pendingEvents.length > 0) {
            this.pendingEvents.forEach(evt => this.applyEvent(evt));
            this.pendingEvents = [];
        }
    }

    /** Fires a flare, updates the badge, and prepends a live event to a building's history. */
    applyEvent(evt) {
        const ticker = evt.ticker;
        const style = categorizeEvent(evt.type, parseFloat(evt.change_percent));

        const flare = this.flaresByTicker.get(ticker);
        if (flare) {
            flare.style.fill = style.color;
            flare.setAttribute('data-flash', 'true');
            clearTimeout(this.flareTimers[ticker]);
            this.flareTimers[ticker] = setTimeout(() => flare.removeAttribute('data-flash'), 900);
        }

        const entry = {
            type: evt.type,
            category: style.category,
            color: style.color,
            icon: style.icon,
            badge: style.badge,
            headline: evt.description || '',
            changePercent: Number.isFinite(parseFloat(evt.change_percent)) ? parseFloat(evt.change_percent) : null,
            recordedAt: nowStamp(),
            recordedAtTs: Date.now() / 1000,
        };

        const history = this.eventsByTicker.get(ticker) || [];
        history.unshift(entry);
        history.length = Math.min(history.length, this.constructor.MAX_EVENTS_PER_TICKER);
        this.eventsByTicker.set(ticker, history);

        this.updateBadge(ticker);

        const badge = this.badgesByTicker.get(ticker);
        if (badge) {
            badge.setAttribute('data-flash', 'true');
            clearTimeout(this.badgeTimers[ticker]);
            this.badgeTimers[ticker] = setTimeout(() => badge.removeAttribute('data-flash'), 500);
        }

        if (this.currentSelectedTicker === ticker) {
            this.renderEventsList(ticker);
        }
    }

    /** Events for a ticker that still fall inside the badge window, oldest already dropped. */
    countRecentEvents(ticker) {
        const windowOpensAt = Date.now() / 1000 - (this.eventBadgeWindowSecondsValue || 0);
        const events = this.eventsByTicker.get(ticker) || [];

        return events.filter(evt => Number.isFinite(evt.recordedAtTs) && evt.recordedAtTs >= windowOpensAt).length;
    }

    /** Rewrites one building's badge from its current in-window count. */
    updateBadge(ticker) {
        const badge = this.badgesByTicker.get(ticker);
        const badgeCount = this.badgeCountsByTicker.get(ticker);
        if (!badge || !badgeCount) return;

        const count = this.countRecentEvents(ticker);
        badge.dataset.count = String(count);
        badgeCount.textContent = String(count);
    }

    /** Re-counts every badge so events age out of the window while the page stays open. */
    refreshBadges() {
        this.badgesByTicker.forEach((badge, ticker) => this.updateBadge(ticker));
    }

    /**
     * Re-ranks the street from live caps and rewrites the plates that moved. The server's rank
     * is the tenant's position in the whole listed universe; on the client only the roster is
     * known, so this is a ranking among the tenants on the street — the same figures at load,
     * since the roster is exactly the top of that universe, and only ever off by a company that
     * overtook one from below the cut, which the next page load corrects.
     */
    rerank() {
        const order = Array.from(this.plotsByTicker.values())
            .map(plot => ({ plot, cap: parseFloat(plot.dataset.mcap) || 0 }))
            .sort((a, b) => b.cap - a.cap);

        order.forEach(({ plot }, index) => {
            const rank = index + 1;
            const previous = parseInt(plot.dataset.rank, 10);
            if (rank === previous) return;

            plot.dataset.rank = String(rank);
            const ticker = plot.dataset.ticker;
            const plate = plot.querySelector('.plot-rank');
            if (plate) {
                plate.textContent = `#${rank}`;
                plate.setAttribute('data-rank-move', rank < previous ? 'up' : 'down');
                clearTimeout(this.rankMoveTimers[ticker]);
                this.rankMoveTimers[ticker] = setTimeout(() => plate.removeAttribute('data-rank-move'), this.constructor.RANK_MOVE_MS);
            }

            if (this.currentSelectedTicker === ticker) {
                this.detailRankTarget.innerText = `#${rank}`;
            }
        });
    }

    /** Recomputes institution stress from a live macro snapshot and toggles matching conduits. */
    applyStress(macro) {
        this.sampleReadings(macro);
        let stressedCount = 0;

        this.institutionTargets.forEach(institution => {
            const institutionId = institution.dataset.institution;
            const config = this.institutionsById.get(institutionId);
            if (config) {
                const isStressed = anyRuleTriggered(config.stressRules, macro);
                if (isStressed) stressedCount++;

                const stressed = isStressed ? 'true' : 'false';
                institution.setAttribute('data-stressed', stressed);

                (this.conduitsByInstitution.get(institutionId) || []).forEach(conduit => {
                    conduit.setAttribute('data-stressed', stressed);
                });
            }

            this.updateReadout(institutionId, macro);
        });

        // Keep the summary tile honest — it is a server-rendered count of the same verdict.
        if (this.hasSummaryStressTarget) {
            this.summaryStressTarget.textContent = `${stressedCount}/${this.institutionTargets.length} stressed`;
            this.summaryStressTarget.className = 'text-sm font-bold font-mono tabular-nums mt-1 '
                + (stressedCount > 0 ? 'text-tertiary' : 'text-on-surface');
        }
    }

    /**
     * Appends one sample per sparkline field, at most every READOUT_SAMPLE_MS, and redraws the
     * strips. Only the fields an institution actually prints are kept — the snapshot carries
     * far more than that.
     */
    sampleReadings(macro) {
        const now = Date.now();
        if (now - this.lastReadingSampleAt < this.constructor.READOUT_SAMPLE_MS) return;
        this.lastReadingSampleAt = now;

        this.sparksByField.forEach((sparks, field) => {
            const value = macro[field];
            if (typeof value !== 'number' || !Number.isFinite(value)) return;

            const history = this.readingHistory.get(field) || [];
            history.push(value);
            if (history.length > this.constructor.READOUT_HISTORY_LENGTH) history.shift();
            this.readingHistory.set(field, history);

            sparks.forEach(spark => {
                spark.setAttribute('points', sparklinePoints(
                    history,
                    parseFloat(spark.dataset.sparkLeft),
                    parseFloat(spark.dataset.sparkTop),
                    parseFloat(spark.dataset.sparkWidth),
                    parseFloat(spark.dataset.sparkHeight),
                ));
            });
        });
    }

    /** Rewrites an institution's printed values from a live macro snapshot, matched by field name. */
    updateReadout(institutionId, macro) {
        const config = this.institutionsById.get(institutionId);
        const valueNodes = this.readoutValueNodesByInstitution.get(institutionId);
        if (!config || !valueNodes) return;

        config.readouts.forEach(readout => {
            const node = Array.from(valueNodes).find(n => n.dataset.readoutField === readout.field);
            if (node) node.textContent = formatReadoutValue(macro[readout.field], readout.unit);
        });
    }

    /** Re-renders a single facade against a live tick. */
    applyUpdate(plot, update) {
        const ticker = update.ticker;
        const newPrice = parseFloat(update.price);
        if (!Number.isFinite(newPrice)) return;

        const shares = this.sharesValue[ticker] || 0;
        const marketCap = update.market_cap !== undefined ? update.market_cap : newPrice * shares;

        plot.dataset.price = newPrice;
        plot.dataset.mcap = marketCap;

        this.applyCondition(plot, update);
        this.relightWindows(plot, update);
        this.resizeFacade(plot, update.is_bankrupt ? 0 : marketCap);
        this.flashTick(plot, ticker, newPrice);
        this.updateKerbPlate(ticker, newPrice);

        if (plot.getAttribute('data-selected') === 'true') {
            this.renderFigures(plot);
            this.detailRatingTarget.innerText = plot.dataset.rating || '—';
            const holding = this.userHoldings[ticker];
            this.renderPositionDetail(ticker, holding, newPrice);
            this.updateQuickTradeEstimate();
        }
    }

    /**
     * Recolours the masonry from a live tick's solvency and rating — the same verdict the server
     * drew on first paint (see conditionFor()). A downgrade to junk used to wait for a reload.
     */
    applyCondition(plot, update) {
        if (typeof update.credit_rating === 'string' && update.credit_rating !== '') {
            plot.dataset.rating = update.credit_rating;
        }
        plot.dataset.condition = conditionFor(Boolean(update.is_bankrupt), plot.dataset.rating, this.conditionValue || {});
    }

    /**
     * Relights a facade from a live tick's return on capital. The tick carries both ROIC and ROE;
     * which one lights this tenant was decided server-side (data-return-field), and each window's
     * priority came down with the markup, so this is only the comparison the server made —
     * see DistrictMapBuilder::lightWindows(). Touches only the windows whose state flips.
     */
    relightWindows(plot, update) {
        const current = parseFloat(update[plot.dataset.returnField]);
        if (!Number.isFinite(current)) return;

        const baseline = parseFloat(plot.dataset.baselineRoc);
        const share = litShareFor(current, Number.isFinite(baseline) ? baseline : 0, this.lightingValue || {});
        plot.dataset.roc = current;
        if (share === parseFloat(plot.dataset.litShare)) return;
        plot.dataset.litShare = share;

        (this.windowsByTicker.get(plot.dataset.ticker) || []).forEach(({ el, key }) => {
            const lit = key < share ? 'true' : 'false';
            if (el.dataset.lit !== lit) el.dataset.lit = lit;
        });
    }

    /**
     * Rewrites a kerb plate's price on a live tick. The change % beside it is left alone: it is
     * measured against the oldest price in the ticker's Redis buffer (see DistrictPriceChangeFeed)
     * and the client has no access to that baseline, so recomputing it here would quietly invent
     * a different number from the one the server rendered.
     */
    updateKerbPlate(ticker, price) {
        const priceNode = this.kerbPriceByTicker.get(ticker);
        if (priceNode) priceNode.textContent = formatCurrency(price);
    }

    /**
     * Applies the height envelope to a facade: linear in log10 across the window the server
     * fitted to this roster, clamped at both ends. Must mirror
     * DistrictMapBuilder::calculateFacadeHeight() and App\DTO\DistrictHeightEnvelope::normalise()
     * exactly or a facade jumps on the first live tick after load.
     */
    resizeFacade(plot, marketCap) {
        const env = this.envelopeValue;
        const logCap = Math.log10(Math.max(marketCap, 1));
        const normalised = Math.max(0, Math.min(1, (logCap - env.logFloor) / (env.logCeiling - env.logFloor)));
        const height = env.minHeight + normalised * (env.maxHeight - env.minHeight);
        // Each row stands on its own ground line, carried per plot — reading one shared value
        // here would drop every lower-row facade onto the upper row on the first live tick.
        const y = parseFloat(plot.dataset.groundLine) - height;

        const facade = plot.querySelector('.facade');
        if (!facade) return;

        const currentHeight = parseFloat(facade.getAttribute('height')) || 0;
        const currentY = parseFloat(facade.getAttribute('y')) || 0;
        // Sub-pixel threshold: skip rewriting SVG attributes if the height change is negligible (< 0.5 units)
        if (Math.abs(height - currentHeight) < 0.5 && Math.abs(y - currentY) < 0.5) {
            return;
        }

        const roofline = plot.querySelector('.roofline');
        const windows = plot.querySelector('.plot-windows');

        facade.setAttribute('y', y);
        facade.setAttribute('height', height);
        if (windows) {
            windows.setAttribute('transform', `translate(${facade.getAttribute('x')} ${y})`);
        }
        if (roofline) {
            roofline.setAttribute('y', y - 7);
        }

        // Conduits terminate at the roofline, so they must be redrawn every time it moves —
        // outlet, lane and drop x are fixed by the canvas, only ty changes.
        const ty = y - 7;
        (this.conduitsByBuilding.get(plot.dataset.ticker) || []).forEach(conduit => {
            conduit.setAttribute('d', conduitPath(
                parseFloat(conduit.dataset.sx),
                parseFloat(conduit.dataset.sy),
                parseFloat(conduit.dataset.tx),
                ty,
                parseFloat(conduit.dataset.laneY),
            ));
        });

        // The rank, badge, flare, beacon and roof furniture all ride the roofline, so they move
        // with it.
        const flare = this.flaresByTicker.get(plot.dataset.ticker);
        if (flare) flare.setAttribute('cy', y - 34);

        const beacon = plot.querySelector('.beacon');
        if (beacon) beacon.setAttribute('cy', y - 20);

        const furniture = plot.querySelector('.roof-furniture');
        if (furniture) furniture.setAttribute('y', y - 7 - parseFloat(furniture.getAttribute('height')));

        const rank = plot.querySelector('.plot-rank');
        if (rank) rank.setAttribute('y', y - 14);

        const badge = this.badgesByTicker.get(plot.dataset.ticker);
        if (badge) {
            const circle = badge.querySelector('circle');
            const count = badge.querySelector('text');
            if (circle) circle.setAttribute('cy', y - 18);
            if (count) count.setAttribute('y', y - 18);
        }

        if (facade) {
            this.movePennant(plot.dataset.ticker, parseFloat(facade.getAttribute('x')) || 0, y);
        }
    }

    /**
     * Rides the position pennant along with its roofline. Mirrors the Twig: pole at
     * `inset` from the west edge, foot on the rank plate (roof − 14), flag `height` tall with
     * a 6-unit clearance above the plate, flying `width` eastward.
     */
    movePennant(ticker, roofX, roofY) {
        const pennant = this.pennantsByTicker.get(ticker);
        if (!pennant) return;

        const geometry = this.positionPennantValue || {};
        const width = Number(geometry.width) || 0;
        const height = Number(geometry.height) || 0;
        const poleX = roofX + (Number(geometry.inset) || 0);
        const poleFoot = roofY - 14;
        const poleTop = poleFoot - height - 6;

        const line = pennant.querySelector('line');
        const poly = pennant.querySelector('polygon');
        const circle = pennant.querySelector('circle');
        if (line) {
            line.setAttribute('x1', String(poleX));
            line.setAttribute('x2', String(poleX));
            line.setAttribute('y1', String(poleFoot));
            line.setAttribute('y2', String(poleTop));
        }
        if (poly) {
            poly.setAttribute('points', `${poleX},${poleTop} ${poleX + width},${poleTop + height / 2} ${poleX},${poleTop + height}`);
        }
        if (circle) {
            circle.setAttribute('cx', String(poleX));
            circle.setAttribute('cy', String(poleTop));
        }
    }

    /** Lights the roofline green or red for a moment on a price change. */
    flashTick(plot, ticker, newPrice) {
        const oldPrice = this.previousPrices[ticker];
        this.previousPrices[ticker] = newPrice;
        if (oldPrice === undefined || newPrice === oldPrice) return;

        plot.setAttribute('data-tick', newPrice > oldPrice ? 'up' : 'down');

        clearTimeout(this.tickTimers[ticker]);
        this.tickTimers[ticker] = setTimeout(() => plot.removeAttribute('data-tick'), 700);
    }
}
