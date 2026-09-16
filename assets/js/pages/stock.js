import { setupChartDefaults } from '../utils/chart-config.js';
import { readPageData } from '../utils/page-data.js';
import { showLoading, showError, hideStatus } from '../utils/fetch-status.js';
import { setupChartGridFilters, setupExpandableCards } from '../utils/chart-grid.js';
import { initPriceChart, updateLivePricePoint, resizePriceChart, destroyPriceChart } from '../stock/price-chart.js';
import { initEtfChart, updateEtfPie, resizeEtfChart, destroyEtfChart } from '../stock/etf-chart.js';
import { updatePriceUI, resetPriceHistoryState } from '../stock/stats-updater.js';
import { renderEvents } from '../stock/events-feed.js';
import { updateConstituentRows } from '../stock/index-table.js';
import { updateFundamentalCharts, resizeFundamentalCharts, destroyFundamentalCharts } from '../stock/fundamental-charts.js';
import { onPageLoad } from '../utils/page-init.js';

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

    // A fund has no reports of its own; the economy it tracks has its own page now (pages/economy.js).
    if (!isEtf) {
        loadReports({
            url: `/api/fundamentals?ticker=${encodeURIComponent(ticker)}`,
            statusId: 'financialChartsStatus',
            label: 'fundamentals',
            draw: () => updateFundamentalCharts('12Y', rawReports, currentContext)
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
            updateConstituentRows(payload, currentContext.sharesMap);
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
    };
    document.addEventListener('market:frame', marketFrameHandler);

    // Handle Tab Changes for Canvas Resizes
    tabChangeHandler = () => {
        setTimeout(() => {
            resizePriceChart();
            resizeEtfChart();
            resizeFundamentalCharts();
        }, 50);
    };
    document.addEventListener('tabs:changed', tabChangeHandler);
    window.addEventListener('resize', tabChangeHandler);

    setupExpandableCards(resizeFundamentalCharts);
    setupChartGridFilters({
        gridId: 'financialChartsGrid',
        categoryAttribute: 'financialCategory',
        buttonClass: 'financial-cat-btn',
        searchInputId: 'financialIndicatorSearch',
        resize: resizeFundamentalCharts
    });
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

/* `onPageLoad`, not a bare `turbo:load` listener: on a Turbo navigation this module is
   fetched asynchronously and can evaluate after that page's `turbo:load` has already
   fired. See utils/page-init.js. */
onPageLoad(initStockPage);

