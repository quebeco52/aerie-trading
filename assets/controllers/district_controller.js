import { Controller } from '@hotwired/stimulus';
import { formatLarge, formatCurrency, formatPercent } from '../js/utils/formatters.js';

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
        case 'index_deviation': return Math.abs(value - 100.0) / 100.0 >= rule.value;
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
 * The `d` for one conduit, from an institution's outlet down to a tenant's roofline. Mirrors the
 * two forms the Twig template renders on first paint — keep the two in step, the way the logistic
 * facade curve already is.
 *
 * The upper row takes a symmetric S. The lower row cannot: that curve's horizontal traverse sits
 * at the midpoint between outlet and target, which for a lower-row tenant lands in the middle of
 * the upper row's buildings and would show only through the few clear units between them. So it
 * traverses a corridor above every possible upper-row roofline first, then drops straight down.
 */
function conduitPath(sx, sy, tx, ty, row, corridorY) {
    if (row === 0) {
        const mid = sy + (ty - sy) / 2;
        return `M ${sx} ${sy} C ${sx} ${mid}, ${tx} ${mid}, ${tx} ${ty}`;
    }

    return `M ${sx} ${sy} C ${sx} ${sy + 20}, ${tx} ${corridorY - 20}, ${tx} ${corridorY} L ${tx} ${ty}`;
}

/** Formats one readout value to match the server-rendered `number_format` precision exactly. */
function formatReadoutValue(rawValue, unit) {
    const value = Number.isFinite(rawValue) ? rawValue : 0;
    return unit === 'pct' ? (value * 100).toFixed(2) + '%' : value.toFixed(1);
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
        'institution', 'conduit', 'institutionReadout', 'svg', 'summaryStress',
        'flare', 'badge', 'badgeCount',
        'tooltip', 'tooltipTicker', 'tooltipRank', 'tooltipName', 'tooltipMeta',
        'tooltipPrice', 'tooltipChange', 'tooltipCap',
        'empty', 'detail', 'detailTicker', 'detailPlot', 'detailName', 'detailIndustry',
        'detailPrice', 'detailChange', 'detailMcap', 'detailRating', 'detailRoc',
        'detailImportance', 'detailRank', 'detailBlurb', 'detailLink', 'detailEvents', 'noEvents',
        'detailRevenueMix', 'detailRevenueTotal', 'noRevenueMix',
        'institutionDetail', 'institutionName', 'institutionStatus', 'institutionReadings',
        'institutionFeeds', 'institutionFeedCount',
    ];

    static values = { shares: Object, envelope: Object, events: Object, revenueMix: Object, institutions: Array };

    /** Maximum recent events kept per ticker client-side, oldest dropped first. */
    static MAX_EVENTS_PER_TICKER = 8;

    /** Zoom multiplier bounds — 1 is the fit-to-width state (natural container width). */
    static MIN_ZOOM = 1;
    static MAX_ZOOM = 4;
    static ZOOM_STEP = 1.25;

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

        // Recent-history backfill from App\Service\District\DistrictEventFeed, newest first —
        // the same App\Service\Event\EventPresenter output the stock page's own event feed uses.
        // Live events (see applyEvent()) are unshifted onto the same per-ticker arrays.
        this.eventsByTicker = new Map(Object.entries(this.eventsValue || {}));

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

        this.plotTargets.forEach(plot => {
            const ticker = plot.dataset.ticker;
            if (ticker) {
                this.plotsByTicker.set(ticker, plot);
                this.previousPrices[ticker] = parseFloat(plot.dataset.price) || 0;
            }
        });

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

        this.onMarketUpdate = this.onMarketUpdate.bind(this);
        this.flushPending = this.flushPending.bind(this);
        document.addEventListener('market:update', this.onMarketUpdate);
    }

    /** Appends `value` to the array at `map[key]`, creating it on first use. */
    indexBy(map, key, value) {
        if (!key) return;
        if (!map.has(key)) map.set(key, []);
        map.get(key).push(value);
    }

    disconnect() {
        document.removeEventListener('market:update', this.onMarketUpdate);
        Object.values(this.tickTimers).forEach(clearTimeout);
        Object.values(this.flareTimers).forEach(clearTimeout);
        Object.values(this.badgeTimers).forEach(clearTimeout);

        if (this.frameHandle !== null) {
            cancelAnimationFrame(this.frameHandle);
            this.frameHandle = null;
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
    }

    /** Drops any current selection and returns the panel to its resting copy. */
    deselect() {
        this.clearSelection();
        this.highlightConduits([]);
        this.currentSelectedTicker = null;

        this.detailTarget.classList.add('hidden');
        this.institutionDetailTarget.classList.add('hidden');
        this.emptyTarget.classList.remove('hidden');
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
        this.tooltipCapTarget.textContent = 'CAP $' + formatLarge(parseFloat(plot.dataset.mcap) || 0);

        this.tooltipChangeTarget.textContent = hasChange ? formatPercent(change, 2, false, true) : '—';
        this.tooltipChangeTarget.setAttribute(
            'data-direction',
            hasChange ? (change > 0 ? 'up' : (change < 0 ? 'down' : 'flat')) : 'flat',
        );

        this.positionTooltip(plot);
        tooltip.setAttribute('data-visible', 'true');
    }

    hideTooltip() {
        this.tooltipTarget.setAttribute('data-visible', 'false');
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
        this.institutionStatusTarget.className = 'text-[10px] font-mono uppercase tracking-wider px-1.5 py-0.5 rounded border '
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
            label.className = 'text-on-surface-variant/60 uppercase tracking-wider text-[10px]';
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
            chip.className = 'px-1.5 py-0.5 rounded bg-surface-container text-on-surface-variant text-[10px] font-mono border border-outline-variant/15';
            chip.textContent = ticker;
            container.appendChild(chip);
        });
    }

    /** Marks exactly the given conduits as highlighted, clearing any previous highlight. */
    highlightConduits(conduits) {
        this.conduitTargets.forEach(c => c.removeAttribute('data-highlighted'));
        conduits.forEach(c => c.setAttribute('data-highlighted', 'true'));
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

        this.detailPriceTarget.innerText = formatCurrency(price);
        this.detailMcapTarget.innerText = '$' + formatLarge(mcap);
        this.detailRocTarget.innerText = formatPercent(roc);

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
        badge.className = 'inline-flex items-center rounded px-1.5 py-0.5 text-[9px] font-bold font-mono uppercase tracking-wider border';
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
        time.className = 'text-[9px] font-mono text-on-surface-variant/50 shrink-0';
        time.textContent = evt.recordedAt || '';
        header.appendChild(time);
        body.appendChild(header);

        const headline = document.createElement('p');
        headline.className = 'text-[11px] text-on-surface leading-snug';
        headline.textContent = evt.headline || '';
        body.appendChild(headline);

        card.appendChild(body);
        return card;
    }

    /** Rebuilds the "Revenue Mix" list for the given ticker from the latest quarter's report. */
    renderRevenueMix(ticker) {
        const mix = this.revenueMixByTicker.get(ticker);
        const streams = mix && Array.isArray(mix.streams) ? mix.streams : [];

        this.detailRevenueMixTarget.querySelectorAll('.district-stream-row').forEach(row => row.remove());

        if (streams.length === 0) {
            this.noRevenueMixTarget.hidden = false;
            this.detailRevenueTotalTarget.textContent = '';
            return;
        }

        this.noRevenueMixTarget.hidden = true;
        this.detailRevenueTotalTarget.textContent = '$' + formatLarge(mix.totalRevenue);
        streams.forEach(stream => this.detailRevenueMixTarget.appendChild(this.buildStreamRow(stream)));
    }

    /**
     * Builds one stream row — share bar, QoQ move, and named drivers. The driver icon/colour/
     * value conventions (🏛 macro vs 📈 momentum, ±X.X% vs Z-score) are copied verbatim from
     * assets/controllers/sankey_controller.js's own tooltip, which renders this exact same
     * App\Service\Event\EarningsReportSubscriber::buildStreamDetails() data — one vocabulary for
     * the same numbers everywhere they appear, not a second one invented for the district.
     */
    buildStreamRow(stream) {
        const row = document.createElement('div');
        row.className = 'district-stream-row';

        const header = document.createElement('div');
        header.className = 'flex items-baseline justify-between gap-2 text-[11px] mb-1';
        const label = document.createElement('span');
        label.className = 'font-semibold text-on-surface';
        label.textContent = stream.label || stream.key || '';
        const share = document.createElement('span');
        share.className = 'font-mono text-on-surface-variant/70';
        share.textContent = formatPercent(stream.share || 0, 1);
        header.append(label, share);
        row.appendChild(header);

        const barTrack = document.createElement('div');
        barTrack.className = 'h-1.5 rounded-full bg-surface-container-high overflow-hidden mb-1.5';
        const barFill = document.createElement('div');
        barFill.className = 'h-full rounded-full bg-primary/70';
        barFill.style.width = Math.max(0, Math.min(100, (stream.share || 0) * 100)) + '%';
        barTrack.appendChild(barFill);
        row.appendChild(barTrack);

        const meta = document.createElement('div');
        meta.className = 'flex items-center gap-2 flex-wrap mb-1';

        const delta = document.createElement('span');
        const deltaValue = stream.qoqDelta || 0;
        delta.className = 'text-[10px] font-mono font-semibold ' + (deltaValue >= 0 ? 'text-secondary' : 'text-tertiary');
        delta.textContent = 'QoQ ' + formatPercent(deltaValue, 1, false, true);
        meta.appendChild(delta);

        if (stream.event) {
            const eventBadge = document.createElement('span');
            eventBadge.className = 'text-[10px] font-bold text-amber-400 bg-amber-950/40 px-1.5 py-0.5 rounded border border-amber-500/30';
            eventBadge.textContent = '⚡ ' + stream.event;
            meta.appendChild(eventBadge);
        }
        row.appendChild(meta);

        if (Array.isArray(stream.drivers) && stream.drivers.length > 0) {
            const driverList = document.createElement('div');
            driverList.className = 'space-y-0.5 mb-3';

            stream.drivers.forEach(driver => {
                const impact = driver.impact || 0;
                const isPositive = impact >= 0;
                const driverRow = document.createElement('div');
                driverRow.className = 'flex items-center justify-between gap-2 text-[10px]';

                const driverLabel = document.createElement('span');
                driverLabel.className = 'text-on-surface-variant/70';
                driverLabel.textContent = (driver.type === 'macro' ? '🏛 ' : '📈 ') + (driver.label || '');
                driverRow.appendChild(driverLabel);

                const driverValue = document.createElement('span');
                driverValue.className = 'font-mono font-bold shrink-0 ' + (isPositive ? 'text-secondary' : 'text-tertiary');
                driverValue.textContent = driver.type === 'momentum'
                    ? `(Z=${driver.z !== undefined ? driver.z : 0})`
                    : formatPercent(impact, 1, false, true);
                driverRow.appendChild(driverValue);

                driverList.appendChild(driverRow);
            });
            row.appendChild(driverList);
        } else {
            row.className += ' mb-3';
        }

        return row;
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

        // Unlike stock ticks, events are discrete occurrences rather than a replaceable state —
        // several can land on different buildings in the same frame and all of them must fire,
        // so they are appended rather than coalesced by ticker.
        if (Array.isArray(payload.events)) {
            payload.events.forEach(evt => {
                if (this.plotsByTicker.has(evt.ticker)) this.pendingEvents.push(evt);
            });
        }

        if (this.frameHandle === null) {
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

        const badge = this.badgesByTicker.get(ticker);
        const badgeCount = this.badgeCountsByTicker.get(ticker);
        if (badge && badgeCount) {
            const count = (parseInt(badge.dataset.count, 10) || 0) + 1;
            badge.dataset.count = String(count);
            badgeCount.textContent = String(count);
            badge.setAttribute('data-flash', 'true');
            clearTimeout(this.badgeTimers[ticker]);
            this.badgeTimers[ticker] = setTimeout(() => badge.removeAttribute('data-flash'), 500);
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
        };

        const history = this.eventsByTicker.get(ticker) || [];
        history.unshift(entry);
        history.length = Math.min(history.length, this.constructor.MAX_EVENTS_PER_TICKER);
        this.eventsByTicker.set(ticker, history);

        if (this.currentSelectedTicker === ticker) {
            this.renderEventsList(ticker);
        }
    }

    /** Recomputes institution stress from a live macro snapshot and toggles matching conduits. */
    applyStress(macro) {
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

        if (update.is_bankrupt) {
            plot.dataset.condition = 'ruin';
        }

        this.resizeFacade(plot, update.is_bankrupt ? 0 : marketCap);
        this.flashTick(plot, ticker, newPrice);
        this.updateKerbPlate(ticker, newPrice);

        if (plot.getAttribute('data-selected') === 'true') {
            this.renderFigures(plot);
        }
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
     * Applies the log-scale height envelope to a facade. Mirrors
     * DistrictMapBuilder::calculateFacadeHeight()'s logistic curve exactly — the two used to
     * disagree (this was a plain clamped linear map), so a facade would visibly jump in height
     * on the very first live tick after page load; see MathUtility::logisticUnitInterval().
     */
    resizeFacade(plot, marketCap) {
        const env = this.envelopeValue;
        const logCap = Math.log10(Math.max(marketCap, 1));
        const midpoint = (env.logCeiling + env.logFloor) / 2;
        const halfSpan = (env.logCeiling - env.logFloor) / 2;
        const steepness = Math.log((1 - env.edgeTolerance) / env.edgeTolerance) / halfSpan;
        const normalised = 1 / (1 + Math.exp(-steepness * (logCap - midpoint)));
        const height = env.minHeight + normalised * (env.maxHeight - env.minHeight);
        // Each row stands on its own ground line, carried per plot — reading one shared value
        // here would drop every lower-row facade onto the upper row on the first live tick.
        const y = parseFloat(plot.dataset.groundLine) - height;

        const facade = plot.querySelector('.facade');
        const roofline = plot.querySelector('.roofline');
        const windows = plot.querySelector('.plot-windows');

        if (facade) {
            facade.setAttribute('y', y);
            facade.setAttribute('height', height);
            if (windows) {
                windows.setAttribute('transform', `translate(${facade.getAttribute('x')} ${y})`);
            }
        }
        if (roofline) {
            roofline.setAttribute('y', y - 7);
        }

        // Conduits terminate at the roofline, so they must be redrawn every time it moves —
        // their source end (sx, sy) is fixed (institutions don't move), only ty changes.
        const ty = y - 7;
        (this.conduitsByBuilding.get(plot.dataset.ticker) || []).forEach(conduit => {
            conduit.setAttribute('d', conduitPath(
                parseFloat(conduit.dataset.sx),
                parseFloat(conduit.dataset.sy),
                parseFloat(conduit.dataset.tx),
                ty,
                parseInt(conduit.dataset.conduitRow, 10) || 0,
                env.corridorY,
            ));
        });

        // The rank, badge and flare all ride the roofline, so they move with it.
        const flare = this.flaresByTicker.get(plot.dataset.ticker);
        if (flare) flare.setAttribute('cy', y - 34);

        const rank = plot.querySelector('.plot-rank');
        if (rank) rank.setAttribute('y', y - 14);

        const badge = this.badgesByTicker.get(plot.dataset.ticker);
        if (badge) {
            const circle = badge.querySelector('circle');
            const count = badge.querySelector('text');
            if (circle) circle.setAttribute('cy', y - 18);
            if (count) count.setAttribute('y', y - 18);
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
