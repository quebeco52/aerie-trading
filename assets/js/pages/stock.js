import { setupChartDefaults } from '../utils/chart-config.js';
import { readPageData } from '../utils/page-data.js';
import { showLoading, showError, hideStatus } from '../utils/fetch-status.js';
import { initPriceChart, updateLivePricePoint, resizePriceChart, destroyPriceChart } from '../stock/price-chart.js';
import { initEtfChart, updateEtfPie, resizeEtfChart, destroyEtfChart } from '../stock/etf-chart.js';
import { updatePriceUI, updateMacroIndicators, resetPriceHistoryState } from '../stock/stats-updater.js';
import { renderEvents } from '../stock/events-feed.js';
import { updateMacroCharts, resizeMacroCharts, destroyMacroCharts, setMacroTimeframe } from '../stock/macro-charts.js';
import { updateFundamentalCharts, resizeFundamentalCharts, destroyFundamentalCharts } from '../stock/fundamental-charts.js';

let rawReports = [];
let currentContext = {};
let marketUpdateHandler = null;
let marketFrameHandler = null;
let tabChangeHandler = null;

function getAerieContext() {
    const d = readPageData('aerie-data');
    return {
        ticker: d.ticker || null,
        isEtf: !!d.isEtf,
        businessModel: d.businessModel || 'none',
        isFinancial: !!d.isFinancial,
        sharesOutstanding: d.sharesOutstanding || 1,
        currentPrice: d.currentPrice || 0,
        userQuantity: d.userQuantity || 0,
        eps: d.eps || 0,
        ticksPerYear: d.ticksPerYear || 54000,
        pieLabels: Array.isArray(d.pieLabels) ? d.pieLabels : [],
        pieData: Array.isArray(d.pieData) ? d.pieData : [],
        sharesMap: d.sharesMap || {}
    };
}

function initStockPage() {
    setupChartDefaults();
    currentContext = getAerieContext();
    const { ticker, isEtf, ticksPerYear, pieLabels, pieData } = currentContext;

    const container = document.getElementById('mainChartContainer');
    if (!container || !ticker) return;
    if (container.dataset.initialized) return;
    container.dataset.initialized = 'true';

    cleanupPageResources();

    // Initialize Price Chart (Lightweight Charts)
    initPriceChart(container, ticker, ticksPerYear);

    // Initialize ETF Doughnut if applicable
    if (isEtf) {
        initEtfChart('etfPieChart', pieLabels, pieData);
    }

    // Fetch reports
    let isAborted = false;

    /**
     * Loads a report series into its charts, and says so on the page while it is in flight or
     * when it fails. A non-2xx response is a failure here: `res.json()` alone would happily
     * parse an error document and hand the charts a shape they cannot draw.
     */
    async function loadReports({ url, statusId, label, draw }) {
        showLoading(statusId, `Loading ${label}…`);
        try {
            const res = await fetch(url);
            if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
            const data = await res.json();
            if (isAborted) return;

            rawReports = data;
            hideStatus(statusId);
            draw();
        } catch (err) {
            if (isAborted) return;
            console.error(`Failed to load ${label}:`, err);
            showError(statusId, `Could not load ${label}.`, () => loadReports({ url, statusId, label, draw }));
        }
    }

    if (!isEtf) {
        loadReports({
            url: `/api/fundamentals?ticker=${encodeURIComponent(ticker)}`,
            statusId: 'financialChartsStatus',
            label: 'fundamentals',
            draw: () => updateFundamentalCharts('12Y', rawReports, currentContext)
        });
    } else {
        loadReports({
            url: '/api/macro-reports',
            statusId: 'macroChartsStatus',
            label: 'macro series',
            draw: () => updateMacroCharts(rawReports)
        });
    }

    // Every tick goes to the price chart only: the live bar's high and low are accumulated
    // from each tick, so the chart is the one consumer that must see all of them.
    marketUpdateHandler = (event) => {
        const payload = event.detail;
        const stockUpdate = payload && payload.stocks ? payload.stocks.find(s => s.ticker === ticker) : null;
        if (!stockUpdate) return;
        try {
            updateLivePricePoint(parseFloat(stockUpdate.price), stockUpdate.volume);
        } catch (err) {
            console.error('Error updating live chart:', err);
        }
    };
    document.addEventListener('market:update', marketUpdateHandler);

    // Everything written to the page reads the coalesced frame (market-stream.js): latest
    // state per ticker, every event since the previous frame, at most every FRAME_INTERVAL_MS.
    marketFrameHandler = (event) => {
        const payload = event.detail;
        if (!payload) return;

        if (isEtf) {
            updateEtfPie(payload, currentContext.sharesMap);
        }

        const stockUpdate = payload.stocks ? payload.stocks.find(s => s.ticker === ticker) : null;
        if (stockUpdate) {
            try {
                updatePriceUI(parseFloat(stockUpdate.price), stockUpdate, currentContext);
            } catch (err) {
                console.error('Error updating price UI:', err);
            }
        }

        if (payload.events && payload.events.length > 0) {
            renderEvents(payload.events, ticker);
        }

        if (payload.macro || payload.economic_cycle) {
            updateMacroIndicators(payload);
        }
    };
    document.addEventListener('market:frame', marketFrameHandler);

    // Handle Tab Changes for Canvas Resizes
    tabChangeHandler = () => {
        setTimeout(() => {
            resizePriceChart();
            resizeEtfChart();
            resizeMacroCharts();
            resizeFundamentalCharts();
        }, 50);
    };
    document.addEventListener('tabs:changed', tabChangeHandler);
    window.addEventListener('resize', tabChangeHandler);

    setupExpandableCards();
    setupChartGridFilters('macro');
    setupChartGridFilters('financial');
    setupMacroTimeframeButtons();
    setupFinancialTimeframeButtons();

    // Turbo cleanup
    document.addEventListener('turbo:before-render', () => {
        isAborted = true;
        cleanupPageResources();
    }, { once: true });
}

function cleanupPageResources() {
    resetPriceHistoryState();
    destroyPriceChart();
    destroyEtfChart();
    destroyMacroCharts();
    destroyFundamentalCharts();

    if (marketUpdateHandler) {
        document.removeEventListener('market:update', marketUpdateHandler);
        marketUpdateHandler = null;
    }
    if (marketFrameHandler) {
        document.removeEventListener('market:frame', marketFrameHandler);
        marketFrameHandler = null;
    }
    if (tabChangeHandler) {
        document.removeEventListener('tabs:changed', tabChangeHandler);
        window.removeEventListener('resize', tabChangeHandler);
        tabChangeHandler = null;
    }
}

/**
 * Both chart grids carry a category bar, a text filter and per-card expand buttons, and all
 * three decide whether a card is shown. One implementation, driven by this config; visibility
 * runs through the `hidden` class throughout, never an inline `style.display`.
 */
const CHART_GRIDS = {
    macro: {
        gridId: 'macroChartsGrid',
        categoryAttribute: 'macroCategory',
        buttonClass: 'macro-cat-btn',
        searchInputId: 'macroIndicatorSearch',
        resize: resizeMacroCharts
    },
    financial: {
        gridId: 'financialChartsGrid',
        categoryAttribute: 'financialCategory',
        buttonClass: 'financial-cat-btn',
        searchInputId: 'financialIndicatorSearch',
        resize: resizeFundamentalCharts
    }
};

/** Category-button styling, kept in one place so the two states cannot drift apart. */
const CAT_BTN_BASE = 'px-3 py-1.5 text-xs font-bold rounded-lg transition-all whitespace-nowrap cursor-pointer';
const CAT_BTN_ACTIVE = 'bg-primary text-on-primary shadow-md shadow-primary/20';
const CAT_BTN_IDLE = 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface';

/** Each grid's live filter state, and the function that re-applies it. */
const gridFilters = {};

function setupChartGridFilters(key) {
    const config = CHART_GRIDS[key];
    const grid = document.getElementById(config.gridId);
    if (!grid) return;

    const state = { category: 'all', search: '' };

    const apply = () => {
        const query = state.search.toLowerCase().trim();
        grid.querySelectorAll('.chart-card').forEach(card => {
            // An expanded card hides its siblings outright and owns the grid until collapsed.
            if (card.dataset.soloHidden === 'true') return;

            const category = card.dataset[config.categoryAttribute] || '';
            const matchesCategory = state.category === 'all' || category === state.category;
            const matchesSearch = !query || (card.textContent || '').toLowerCase().includes(query);
            card.classList.toggle('hidden', !(matchesCategory && matchesSearch));
        });
        setTimeout(config.resize, 50);
    };

    gridFilters[config.gridId] = apply;

    // The category bar sits outside the grid element, so this is a document-wide lookup; the
    // button class is already specific to one grid.
    const catButtons = document.querySelectorAll(`.${config.buttonClass}`);
    catButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            state.category = btn.dataset.category || 'all';
            catButtons.forEach(b => {
                const isActive = b === btn;
                b.className = `${config.buttonClass} ${CAT_BTN_BASE} ${isActive ? CAT_BTN_ACTIVE : CAT_BTN_IDLE}`;
                b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
            apply();
        });
    });

    const searchInput = document.getElementById(config.searchInputId);
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            state.search = e.target.value;
            apply();
        });
    }
}

function setupFinancialTimeframeButtons() {
    document.querySelectorAll('[data-financial-timeframe]').forEach(btn => {
        btn.addEventListener('click', () => {
            updateFundamentalCharts(btn.dataset.financialTimeframe, rawReports, currentContext);
            document.querySelectorAll('[data-financial-timeframe]').forEach(b => {
                b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
            });
        });
    });
}

function setupMacroTimeframeButtons() {
    document.querySelectorAll('.macro-range-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const tf = btn.dataset.macroTimeframe;
            if (tf) setMacroTimeframe(tf);
        });
    });
}

function setupExpandableCards() {
    document.querySelectorAll('.expand-chart-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const card = this.closest('.chart-card');
            const grid = card?.closest('.grid');
            if (!card || !grid) return;

            const icon = this.querySelector('.expand-icon');
            const canvasContainer = card.querySelector('.chart-canvas-container');
            const siblings = [...grid.querySelectorAll('.chart-card')].filter(c => c !== card);
            const isExpanded = card.classList.contains('md:col-span-2') || card.classList.contains('lg:col-span-2');

            if (isExpanded) {
                card.classList.remove('md:col-span-2', 'lg:col-span-2');
                canvasContainer?.classList.remove('h-96', 'md:h-[500px]');
                if (icon) icon.textContent = 'open_in_full';
                this.setAttribute('aria-expanded', 'false');

                siblings.forEach(c => { delete c.dataset.soloHidden; });
                // Hand the grid back to its filter, which knows which siblings belong on screen.
                const reapply = gridFilters[grid.id];
                if (reapply) {
                    reapply();
                } else {
                    siblings.forEach(c => c.classList.remove('hidden'));
                }
            } else {
                card.classList.add('md:col-span-2', 'lg:col-span-2');
                canvasContainer?.classList.add('h-96', 'md:h-[500px]');
                if (icon) icon.textContent = 'close_fullscreen';
                this.setAttribute('aria-expanded', 'true');

                siblings.forEach(c => {
                    c.dataset.soloHidden = 'true';
                    c.classList.add('hidden');
                });
            }

            setTimeout(() => {
                window.dispatchEvent(new Event('resize'));
                resizeMacroCharts();
                resizeFundamentalCharts();
            }, 60);
        });
    });
}

/* Bound to `turbo:load` only. It fires on first load as well as on every Turbo navigation,
   and it is the load-bearing path: on a repeat visit this module is already in the module
   registry and its top level never runs again, so a direct call here would fire only on
   the very first evaluation and be pure duplication on that one. */
document.addEventListener('turbo:load', initStockPage);

