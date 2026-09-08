import { Controller } from '@hotwired/stimulus';
import { formatLarge, formatCurrency, formatPercent } from '../js/utils/formatters.js';

const IMPORTANCE_LABELS = {
    titan: 'Titan — systemically irreplaceable',
    systemic: 'Systemically important',
    base: 'Baseline constituent',
    none: 'Unclassified',
};

/**
 * Institution stress predicates, mirrored from App\Service\District\DistrictStressEvaluator so
 * conduits can react between full page loads without a round trip. Each threshold is the exact
 * constant that PHP class cites as its source — see that class for why no new one is invented
 * here. Field names match payload.macro (App\DTO\MacroStateDTO::toArray()).
 */
const STRESS_PREDICATES = {
    'rate-council': macro =>
        macro.inversion_duration >= 0.75 // MacroEngine::SYSTEMIC_INVERSION_ALARM_YEARS
        || macro.policy_rate_ema <= 0.015, // MacroEngine::ZLB_PROXIMITY_THRESHOLD
    'credit-registry': macro =>
        macro.interbank_liquidity_spread_ema >= 0.0100 // MacroEngine::SYSTEMIC_LIQUIDITY_FREEZE_SPREAD
        || macro.high_yield_credit_spread_ema >= 0.1000 // MacroEngine::SYSTEMIC_CREDIT_SEIZURE_SPREAD
        || macro.recession_probability_ema >= 0.50, // MacroEngine::SYSTEMIC_RECESSION_DECLARE_PROBABILITY
    'exchange-floor': macro =>
        macro.market_volatility_ema >= 0.30, // ClearingHouseBusinessModel::VIX_EXTREME_THRESHOLD
    'statistical-office': macro =>
        macro.inflation_ema >= 0.035 // MacroEngine::CB_INFLATION_PANIC_THRESHOLD
        || macro.output_gap_ema <= -0.010 // MacroEngine::SYSTEMIC_RECESSION_DECLARE_GAP
        || macro.unemployment_rate_ema >= 0.050, // MacroEngine::EVANS_RULE_UNEMPLOYMENT
    'land-registry': macro =>
        Math.abs(macro.commercial_property_index_ema - 100.0) / 100.0 >= 0.15 // DistrictMap::LAND_REGISTRY_PROPERTY_STRESS_DEVIATION
        || Math.abs(macro.residential_property_index_ema - 100.0) / 100.0 >= 0.15,
};

/**
 * The readout fields printed on each institution, mirrored from
 * App\Data\DistrictMap::INSTITUTIONS[*]['readouts'] in the same order the server rendered their
 * <tspan> values in — index-matched, not looked up by name, so this must stay in lockstep with
 * that PHP array. 'pct' fields are decimal fractions (multiply by 100); 'index' fields already
 * sit around a 100.0 baseline and print as-is.
 */
const INSTITUTION_READOUTS = {
    'rate-council': [
        { field: 'policy_rate_ema', unit: 'pct' },
        { field: 'yield_10y_ema', unit: 'pct' },
    ],
    'credit-registry': [
        { field: 'macro_credit_spread_ema', unit: 'pct' },
        { field: 'high_yield_credit_spread_ema', unit: 'pct' },
    ],
    'exchange-floor': [
        { field: 'market_volatility_ema', unit: 'pct' },
        { field: 'deal_activity_index_ema', unit: 'index' },
    ],
    'statistical-office': [
        { field: 'output_gap_ema', unit: 'pct' },
        { field: 'inflation_ema', unit: 'pct' },
    ],
    'land-registry': [
        { field: 'commercial_property_index_ema', unit: 'index' },
        { field: 'residential_property_index_ema', unit: 'index' },
    ],
};

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
        'plot', 'facade', 'roofline', 'windows', 'label',
        'institution', 'conduit', 'institutionReadout',
        'flare', 'badge', 'badgeCount',
        'empty', 'detail', 'detailTicker', 'detailPlot', 'detailName', 'detailIndustry',
        'detailPrice', 'detailMcap', 'detailRating', 'detailRoc', 'detailImportance',
        'detailBlurb', 'detailLink', 'detailEvents', 'noEvents',
        'detailRevenueMix', 'detailRevenueTotal', 'noRevenueMix',
    ];

    static values = { shares: Object, envelope: Object, events: Object, revenueMix: Object };

    /** Maximum recent events kept per ticker client-side, oldest dropped first. */
    static MAX_EVENTS_PER_TICKER = 8;

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
        this.badgeCountTargets.forEach(count => {
            const badge = count.closest('[data-district-target="badge"]');
            if (badge) this.badgeCountsByTicker.set(badge.dataset.badgeFor, count);
        });

        this.readoutValueNodesByInstitution = new Map();
        this.institutionReadoutTargets.forEach(readout => {
            const institutionId = readout.dataset.institutionReadoutFor;
            this.readoutValueNodesByInstitution.set(institutionId, readout.querySelectorAll('.readout-value'));
        });

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

        this.plotTargets.forEach(p => p.removeAttribute('data-selected'));
        this.labelTargets.forEach(l => l.removeAttribute('data-selected'));
        this.institutionTargets.forEach(i => i.removeAttribute('data-selected'));
        plot.setAttribute('data-selected', 'true');

        const label = this.labelTargets.find(l => l.dataset.labelFor === plot.dataset.ticker);
        if (label) label.setAttribute('data-selected', 'true');

        this.highlightConduits(this.conduitsByBuilding.get(plot.dataset.ticker) || []);

        this.emptyTarget.classList.add('hidden');
        this.detailTarget.classList.remove('hidden');

        this.detailTickerTarget.innerText = plot.dataset.ticker;
        this.detailPlotTarget.innerText = `Plot ${plot.dataset.plot}`;
        this.detailNameTarget.innerText = plot.dataset.name || '';
        this.detailIndustryTarget.innerText = plot.dataset.industry || '';
        this.detailRatingTarget.innerText = plot.dataset.rating || '—';
        this.detailImportanceTarget.innerText = IMPORTANCE_LABELS[plot.dataset.importance] || 'Unclassified';
        this.detailBlurbTarget.innerText = plot.dataset.blurb || '';
        this.detailLinkTarget.href = `/stock/${plot.dataset.ticker}`;

        this.renderFigures(plot);

        this.currentSelectedTicker = plot.dataset.ticker;
        this.renderEventsList(plot.dataset.ticker);
        this.renderRevenueMix(plot.dataset.ticker);
    }

    /** Selects an institution: highlights every conduit it feeds, without touching the building panel. */
    selectInstitution(event) {
        const institution = event.currentTarget;
        const institutionId = institution.dataset.institution;
        if (!institutionId) return;

        this.currentSelectedTicker = null;

        this.plotTargets.forEach(p => p.removeAttribute('data-selected'));
        this.labelTargets.forEach(l => l.removeAttribute('data-selected'));
        this.institutionTargets.forEach(i => i.removeAttribute('data-selected'));
        institution.setAttribute('data-selected', 'true');

        this.highlightConduits(this.conduitsByInstitution.get(institutionId) || []);

        this.emptyTarget.classList.remove('hidden');
        this.detailTarget.classList.add('hidden');
    }

    /** Marks exactly the given conduits as highlighted, clearing any previous highlight. */
    highlightConduits(conduits) {
        this.conduitTargets.forEach(c => c.removeAttribute('data-highlighted'));
        conduits.forEach(c => c.setAttribute('data-highlighted', 'true'));
    }

    /** Writes the numeric fields of the panel from a plot's current dataset. */
    renderFigures(plot) {
        const price = parseFloat(plot.dataset.price) || 0;
        const mcap = parseFloat(plot.dataset.mcap) || 0;
        const roc = parseFloat(plot.dataset.roc) || 0;

        this.detailPriceTarget.innerText = formatCurrency(price);
        this.detailMcapTarget.innerText = '$' + formatLarge(mcap);
        this.detailRocTarget.innerText = formatPercent(roc);
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
        this.institutionTargets.forEach(institution => {
            const institutionId = institution.dataset.institution;
            const predicate = STRESS_PREDICATES[institutionId];
            if (predicate) {
                const stressed = predicate(macro) ? 'true' : 'false';
                institution.setAttribute('data-stressed', stressed);

                (this.conduitsByInstitution.get(institutionId) || []).forEach(conduit => {
                    conduit.setAttribute('data-stressed', stressed);
                });
            }

            this.updateReadout(institutionId, macro);
        });
    }

    /** Rewrites an institution's printed values from a live macro snapshot. */
    updateReadout(institutionId, macro) {
        const config = INSTITUTION_READOUTS[institutionId];
        const valueNodes = this.readoutValueNodesByInstitution.get(institutionId);
        if (!config || !valueNodes) return;

        config.forEach((readout, index) => {
            const node = valueNodes[index];
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

        if (plot.getAttribute('data-selected') === 'true') {
            this.renderFigures(plot);
            this.detailRatingTarget.innerText = plot.dataset.rating || '—';
        }
    }

    /**
     * Applies the log-scale height envelope to a facade. Mirrors DistrictMapBuilder so the
     * skyline keeps rising and falling between full page loads.
     */
    resizeFacade(plot, marketCap) {
        const env = this.envelopeValue;
        const logCap = Math.log10(Math.max(marketCap, 1));
        const span = env.logCeiling - env.logFloor;
        const normalised = Math.max(0, Math.min(1, (logCap - env.logFloor) / span));
        const height = env.minHeight + normalised * (env.maxHeight - env.minHeight);
        const y = env.groundLine - height;

        const facade = plot.querySelector('.facade');
        const roofline = plot.querySelector('.roofline');
        const windows = plot.querySelector('.plot-windows');

        if (facade) {
            facade.setAttribute('y', y);
            facade.setAttribute('height', height);
        }
        if (roofline) {
            roofline.setAttribute('y', y - 7);
        }
        if (windows) {
            windows.setAttribute('transform', `translate(${plot.querySelector('.facade').getAttribute('x')} ${y})`);
        }

        // Conduits terminate at the roofline, so they must be redrawn every time it moves —
        // their source end (sx, sy) is fixed (institutions don't move), only ty changes.
        const ty = y - 7;
        (this.conduitsByBuilding.get(plot.dataset.ticker) || []).forEach(conduit => {
            const sx = parseFloat(conduit.dataset.sx);
            const sy = parseFloat(conduit.dataset.sy);
            const tx = parseFloat(conduit.dataset.tx);
            conduit.setAttribute('d', `M ${sx} ${sy} C ${sx} ${sy + (ty - sy) / 2}, ${tx} ${ty - (ty - sy) / 2}, ${tx} ${ty}`);
        });

        // The flare sits just above the roofline; it is invisible almost all the time, but must
        // still track a resized facade so it bursts from the right spot whenever it next fires.
        const flare = this.flaresByTicker.get(plot.dataset.ticker);
        if (flare) {
            flare.setAttribute('cy', y - 30);
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
