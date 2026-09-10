import { readPageData } from '../utils/page-data.js';
import { setupChartDefaults } from '../utils/chart-config.js';
import { THEME_COLORS } from '../utils/colors.js';
import { flashTick } from '../utils/tick-flash.js';

let curveChart = null;
let marketUpdateHandler = null;
let beforeRenderHandler = null;
const previousCleanPrices = {};

/**
 * Draws the zero-coupon curve.
 *
 * Plotted against a LINEAR maturity axis rather than an evenly spaced categorical one. A category axis
 * would put the same distance between the three-month and six-month points as between the twenty and
 * thirty year, which straightens out exactly the front-end bend that distinguishes a normal curve from
 * an inverted one.
 */
function drawCurve(points) {
    const canvas = document.getElementById('curveChart');
    if (!canvas || typeof Chart === 'undefined' || !Array.isArray(points) || points.length === 0) return;

    const data = points.map(p => ({ x: Number(p.tenor), y: Number(p.yield) * 100 }));

    if (curveChart) {
        curveChart.data.datasets[0].data = data;
        curveChart.update('none');
        return;
    }

    curveChart = new Chart(canvas, {
        type: 'line',
        data: {
            datasets: [{
                label: 'Zero yield',
                data,
                borderColor: THEME_COLORS.primary,
                backgroundColor: 'rgba(173, 198, 255, 0.12)',
                borderWidth: 2,
                pointRadius: 3,
                pointBackgroundColor: THEME_COLORS.primary,
                fill: true,
                tension: 0.25
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        title: (items) => `${items[0].parsed.x}-year`,
                        label: (item) => `${item.parsed.y.toFixed(2)}%`
                    }
                }
            },
            scales: {
                x: {
                    type: 'linear',
                    title: { display: true, text: 'Maturity (years)' },
                    ticks: { callback: (value) => `${value}y` }
                },
                y: {
                    title: { display: true, text: 'Yield' },
                    ticks: { callback: (value) => `${Number(value).toFixed(2)}%` }
                }
            }
        }
    });
}

function setCell(id, text, delta) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = text;
    if (typeof delta === 'number') flashTick(el, delta);
}

function onMarketUpdate(event) {
    const quotes = event.detail?.stocks;
    if (!Array.isArray(quotes)) return;

    for (const quote of quotes) {
        if (quote.asset_type !== 'BOND') continue;

        const clean = parseFloat(quote.clean_price);
        if (Number.isFinite(clean)) {
            const previous = previousCleanPrices[quote.ticker];
            setCell(`clean-${quote.ticker}`, clean.toFixed(2), previous === undefined ? undefined : clean - previous);
            previousCleanPrices[quote.ticker] = clean;
        }

        const ytm = parseFloat(quote.yield_to_maturity);
        if (Number.isFinite(ytm)) {
            setCell(`ytm-${quote.ticker}`, (ytm * 100).toFixed(2) + '%');
        }
    }

    // The curve arrives already evaluated in the tick payload. Reimplementing the Svensson evaluation
    // here would make the browser a second authority on the curve, and its copied lambda constants would
    // drift from MacroEngine's the first time those were retuned.
    if (Array.isArray(event.detail?.bond_curve)) {
        drawCurve(event.detail.bond_curve);
    }
}

function cleanup() {
    if (marketUpdateHandler) {
        document.removeEventListener('market:update', marketUpdateHandler);
        marketUpdateHandler = null;
    }
    if (curveChart) {
        try { curveChart.destroy(); } catch (e) {}
        curveChart = null;
    }
}

function initLadder() {
    const canvas = document.getElementById('curveChart');
    if (!canvas) return;
    if (canvas.dataset.initialized) return;
    canvas.dataset.initialized = 'true';

    cleanup();
    setupChartDefaults();

    drawCurve(readPageData('aerie-data').curve);

    marketUpdateHandler = onMarketUpdate;
    document.addEventListener('market:update', marketUpdateHandler);

    if (!beforeRenderHandler) {
        beforeRenderHandler = () => cleanup();
        document.addEventListener('turbo:before-render', beforeRenderHandler);
    }
}

document.addEventListener('turbo:load', initLadder);
