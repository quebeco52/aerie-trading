import { readPageData } from '../utils/page-data.js';
import { initPriceChart, updateLivePricePoint, resizePriceChart, destroyPriceChart } from '../stock/price-chart.js';
import { flashTick } from '../utils/tick-flash.js';

let marketUpdateHandler = null;
let beforeRenderHandler = null;
let resizeHandler = null;
let context = {};
let previousCleanPrice = null;

function readContext() {
    const d = readPageData('aerie-data');
    return {
        ticker: d.ticker || null,
        ticksPerYear: d.ticksPerYear || 14400,
        userQuantity: Number(d.userQuantity) || 0,
        faceValue: Number(d.faceValue) || 1000
    };
}

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}

/**
 * Repoints the header and statistics at a fresh quote.
 *
 * The headline figure is the CLEAN price, matching the chart beneath it and the ladder's quote column.
 * The dirty price and the accrued interest are shown separately, because that pair is what an order
 * actually settles at and a trader comparing the ticket estimate against the headline needs to see why
 * the two differ.
 */
function applyQuote(quote) {
    const clean = parseFloat(quote.clean_price);
    const dirty = parseFloat(quote.price);

    if (Number.isFinite(clean)) {
        const el = document.getElementById('big-price');
        if (el) {
            el.textContent = clean.toFixed(2);
            flashTick(el, clean - (previousCleanPrice ?? clean));
        }
        previousCleanPrice = clean;
        updateLivePricePoint(clean);
    }

    if (Number.isFinite(dirty)) {
        setText('stat-dirty', dirty.toFixed(2));

        if (context.userQuantity > 0) {
            setText('user-holding-value', '$' + (dirty * context.userQuantity).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }));
        }
    }

    if (Number.isFinite(parseFloat(quote.accrued_interest))) {
        setText('stat-accrued', parseFloat(quote.accrued_interest).toFixed(2));
    }
    if (Number.isFinite(parseFloat(quote.yield_to_maturity))) {
        setText('stat-ytm', (parseFloat(quote.yield_to_maturity) * 100).toFixed(2) + '%');
    }
    if (Number.isFinite(parseFloat(quote.modified_duration))) {
        setText('stat-duration', parseFloat(quote.modified_duration).toFixed(2));
    }
    if (Number.isFinite(parseFloat(quote.convexity))) {
        setText('stat-convexity', parseFloat(quote.convexity).toFixed(1));
    }

    // Time to maturity is the one statistic that moves on its own, with no trade behind it. Showing it
    // stale would make an issue look frozen right when it matters most: in its final quarter.
    if (Number.isFinite(parseFloat(quote.years_to_maturity))) {
        setText('stat-ttm', parseFloat(quote.years_to_maturity).toFixed(2) + 'y');
    }
}

function onMarketUpdate(event) {
    const quotes = event.detail?.stocks;
    if (!Array.isArray(quotes) || !context.ticker) return;

    // Bonds ride in the same payload array as equities; the ticker merges all three desks into it.
    const quote = quotes.find(q => q.ticker === context.ticker);
    if (quote) applyQuote(quote);
}

function cleanup() {
    if (marketUpdateHandler) {
        document.removeEventListener('market:update', marketUpdateHandler);
        marketUpdateHandler = null;
    }
    if (resizeHandler) {
        window.removeEventListener('resize', resizeHandler);
        resizeHandler = null;
    }
    destroyPriceChart();
    previousCleanPrice = null;
}

function initBondPage() {
    const container = document.getElementById('mainChartContainer');
    if (!container) return;
    if (container.dataset.initialized) return;
    container.dataset.initialized = 'true';

    cleanup();
    context = readContext();
    if (!context.ticker) return;

    initPriceChart(container, context.ticker, context.ticksPerYear);

    marketUpdateHandler = onMarketUpdate;
    document.addEventListener('market:update', marketUpdateHandler);

    resizeHandler = () => resizePriceChart();
    window.addEventListener('resize', resizeHandler);

    if (!beforeRenderHandler) {
        beforeRenderHandler = () => cleanup();
        document.addEventListener('turbo:before-render', beforeRenderHandler);
    }
}

/* turbo:load only, matching the other page modules: it fires on first load and on every Turbo
   navigation, whereas this module's top level runs once and never again on a repeat visit. */
document.addEventListener('turbo:load', initBondPage);
