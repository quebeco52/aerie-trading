import { setupChartDefaults } from '../utils/chart-config.js';
import { showLoading, showError, hideStatus } from '../utils/fetch-status.js';
import { setupChartGridFilters, setupExpandableCards } from '../utils/chart-grid.js';
import { updateMacroIndicators } from '../stock/stats-updater.js';
import { updateMacroCharts, resizeMacroCharts, destroyMacroCharts, setMacroTimeframe } from '../stock/macro-charts.js';

let rawReports = [];
let marketFrameHandler = null;
let resizeHandler = null;

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

    loadReports();

    // The vitals read the coalesced frame (market-stream.js), never the raw tick.
    marketFrameHandler = (event) => {
        const payload = event.detail;
        if (payload && (payload.macro || payload.economic_cycle)) {
            updateMacroIndicators(payload);
        }
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

    if (marketFrameHandler) {
        document.removeEventListener('market:frame', marketFrameHandler);
        marketFrameHandler = null;
    }
    if (resizeHandler) {
        window.removeEventListener('resize', resizeHandler);
        resizeHandler = null;
    }
}

/* Bound to `turbo:load` only: it fires on first load as well as on every Turbo navigation, and on a
   repeat visit this module's top level never runs again. */
document.addEventListener('turbo:load', initEconomyPage);
