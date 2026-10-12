import { setupChartDefaults } from '../utils/chart-config.js';
import { showLoading, showError, hideStatus } from '../utils/fetch-status.js';
import { setupChartGridFilters, setupExpandableCards } from '../utils/chart-grid.js';
import { updateEconomyVitals } from '../economy/macro-vitals.js';
import { updateMacroCharts, resizeMacroCharts, destroyMacroCharts, setMacroTimeframe } from '../stock/macro-charts.js';
import { onPageLoad } from '../utils/page-init.js';

let rawReports = [];
let marketFrameHandler = null;
let resizeHandler = null;
let refreshTimer = null;

/**
 * Wait after the live clock enters a new quarter before refetching the history, so the ticker has written the
 * closed quarter's report row by the time the request reaches the database.
 */
const QUARTER_CLOSE_REFRESH_DELAY_MS = 2000;

function initEconomyPage() {
    const grid = document.getElementById('macroChartsGrid');
    if (!grid) return;
    if (grid.dataset.initialized) return;
    grid.dataset.initialized = 'true';

    setupChartDefaults();
    cleanupPageResources();

    let isAborted = false;

    /**
     * Loads the quarterly macro series into the charts, and says so on the page while it is in flight
     * or when it fails. A non-2xx response is a failure here: `res.json()` alone would happily parse an
     * error document and hand the charts a shape they cannot draw.
     */
    async function loadReports() {
        showLoading('macroChartsStatus', 'Loading macro series…');
        try {
            const res = await fetch('/api/macro-reports');
            if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
            const data = await res.json();
            if (isAborted) return;

            rawReports = data;
            hideStatus('macroChartsStatus');
            updateMacroCharts(rawReports);
        } catch (err) {
            if (isAborted) return;
            console.error('Failed to load macro series:', err);
            showError('macroChartsStatus', 'Could not load the macro series.', loadReports);
        }
    }

    /**
     * Redraws the history with the quarter that just closed. Quiet on purpose: the charts already on the page
     * stay up while it loads, and a failed refresh keeps them rather than replacing them with an error.
     */
    async function refreshReports() {
        refreshTimer = null;
        try {
            const res = await fetch('/api/macro-reports');
            if (!res.ok) throw new Error(`${res.status} ${res.statusText}`);
            const data = await res.json();
            if (isAborted) return;

            rawReports = data;
            hideStatus('macroChartsStatus');
            updateMacroCharts(rawReports);
        } catch (err) {
            if (!isAborted) console.error('Failed to refresh macro series:', err);
        }
    }

    loadReports();

    // The quarter the live clock was in at the last frame; a change (a close, or a reset rewinding the clock)
    // means the history has a row the charts lack.
    let liveQuarter = null;

    // The vitals read the coalesced frame (market-stream.js), never the raw tick.
    marketFrameHandler = (event) => {
        const payload = event.detail;
        if (payload && (payload.macro || payload.economic_cycle)) {
            updateEconomyVitals(payload);
        }

        const simYears = payload?.macro?.total_time;
        if (!Number.isFinite(simYears)) return;
        const quarter = Math.floor(simYears * 4);
        if (liveQuarter !== null && quarter !== liveQuarter && refreshTimer === null) {
            refreshTimer = setTimeout(refreshReports, QUARTER_CLOSE_REFRESH_DELAY_MS);
        }
        liveQuarter = quarter;
    };
    document.addEventListener('market:frame', marketFrameHandler);

    resizeHandler = () => setTimeout(resizeMacroCharts, 50);
    window.addEventListener('resize', resizeHandler);

    setupChartGridFilters({
        gridId: 'macroChartsGrid',
        categoryAttribute: 'macroCategory',
        buttonClass: 'macro-cat-btn',
        searchInputId: 'macroIndicatorSearch',
        resize: resizeMacroCharts
    });
    setupExpandableCards(resizeMacroCharts);

    document.querySelectorAll('.macro-range-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const tf = btn.dataset.macroTimeframe;
            if (tf) setMacroTimeframe(tf);
        });
    });

    document.addEventListener('turbo:before-render', () => {
        isAborted = true;
        cleanupPageResources();
    }, { once: true });
}

function cleanupPageResources() {
    destroyMacroCharts();

    if (refreshTimer !== null) {
        clearTimeout(refreshTimer);
        refreshTimer = null;
    }

    if (marketFrameHandler) {
        document.removeEventListener('market:frame', marketFrameHandler);
        marketFrameHandler = null;
    }
    if (resizeHandler) {
        window.removeEventListener('resize', resizeHandler);
        resizeHandler = null;
    }
}

/* `onPageLoad`, not a bare `turbo:load` listener: on a Turbo navigation this module is
   fetched asynchronously and can evaluate after that page's `turbo:load` has already
   fired. See utils/page-init.js. */
onPageLoad(initEconomyPage);
