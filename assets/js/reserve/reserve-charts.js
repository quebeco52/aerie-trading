import { THEME_COLORS, SERIES, withAlpha } from '../utils/colors.js';
import { destroyChartInstance } from '../utils/chart-config.js';
import { renderWhenVisible, resetLazyCharts } from '../utils/lazy-chart.js';

/** The sleeve colours the page's allocation bar uses (sleeve_swatch in the template), so a sleeve reads the same in every panel. */
const SLEEVE_COLORS = { district: SERIES.blue, equities: SERIES.orange };
/** The flows on the money chart, in the series slots' order. */
const FLOW_COLORS = { draw: SERIES.blue, stabilisation: SERIES.orange, duty: SERIES.aqua };
const REFERENCE_COLOR = THEME_COLORS.textMuted;
const GRID_COLOR = withAlpha(THEME_COLORS.grid, 0.5);

const charts = {};

/** A macro_report column as a percentage, or null where the quarter has no reading. */
function pct(report, column) {
    const raw = report[column];
    if (raw === null || raw === undefined) return null;
    const value = parseFloat(raw);
    return Number.isFinite(value) ? value * 100 : null;
}

function percentTooltip(ctx) {
    return `${ctx.dataset.label}: ${ctx.raw === null || ctx.raw === undefined ? 'N/A' : ctx.raw.toFixed(2) + '%'}`;
}

function indexTooltip(ctx) {
    return `${ctx.dataset.label}: ${ctx.raw === null || ctx.raw === undefined ? 'N/A' : ctx.raw.toFixed(1)}`;
}

function lineDataset(label, data, color, extra = {}) {
    return {
        type: 'line',
        label,
        data,
        borderColor: color,
        backgroundColor: color,
        borderWidth: 2,
        tension: 0.2,
        fill: false,
        spanGaps: false,
        pointRadius: data.length > 50 ? 0 : 2,
        pointHoverRadius: 4,
        ...extra
    };
}

/**
 * A weight against its policy and band as each stood that quarter: the band is two edges filled between, the policy a
 * dashed reference line that steps when a new head sets a new mix. The lower edge is drawn but left out of the legend
 * and the tooltip, which name the band once. A quarter with no recorded policy leaves a gap.
 *
 * @param {Array<{policy: number, band: number}|null>} history the policy and band at each quarter drawn
 */
function bandDatasets(weightLabel, weightData, history, color, fillColor) {
    const edge = (sign) => history.map(h => (h ? (h.policy + sign * h.band) * 100 : null));
    const step = { stepped: 'middle', tension: 0 };
    return [
        lineDataset(weightLabel, weightData, color),
        lineDataset('Policy', history.map(h => (h ? h.policy * 100 : null)), REFERENCE_COLOR, { borderDash: [5, 4], borderWidth: 1.5, pointRadius: 0, ...step }),
        lineDataset('Band', edge(1), fillColor, { borderWidth: 0, pointRadius: 0, pointHoverRadius: 0, fill: '+1', backgroundColor: fillColor, isBandEdge: true, ...step }),
        lineDataset('Band lower', edge(-1), fillColor, { borderWidth: 0, pointRadius: 0, pointHoverRadius: 0, isBandEdge: true, hideFromLegend: true, ...step })
    ];
}

/** The quarter each row closes, as the server dates it, or how many quarters back it sits. */
function quarterLabels(rows) {
    return rows.map((row, i) => row.quarter_label ?? (i === rows.length - 1 ? 'Now' : `-${rows.length - i - 1}Q`));
}

/** Shared chart options; `index` plots a level (a return index) rather than a percentage. */
function chartOptions({ legend = true, index = false } = {}) {
    return {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                display: legend,
                position: 'bottom',
                labels: { boxWidth: 8, usePointStyle: true, filter: (item, data) => !data.datasets[item.datasetIndex].hideFromLegend }
            },
            tooltip: {
                filter: (item) => !item.dataset.isBandEdge,
                callbacks: { label: index ? indexTooltip : percentTooltip }
            }
        },
        scales: {
            y: {
                grid: { color: GRID_COLOR },
                ticks: { callback: (val) => index ? val.toFixed(0) : val.toFixed(1) + '%' }
            },
            x: {
                grid: { color: GRID_COLOR },
                ticks: { maxTicksLimit: 8 }
            }
        }
    };
}

function draw(id, datasets, labels, options) {
    renderWhenVisible(id, () => {
        const canvas = document.getElementById(id);
        if (!canvas) return;
        charts[id] = destroyChartInstance(charts[id]);
        charts[id] = new Chart(canvas.getContext('2d'), { data: { labels, datasets }, options });
    });
}

/**
 * The recorded quarters of the fund's return indices, in time order. A fund that opened before the index existed has
 * quarters with no index; they are left out, so the history starts where the index does.
 *
 * @param {Array<Object>} reports macro_report rows in chronological order
 * @returns {Array<{time: number, index: number, real: number}>}
 */
export function returnHistory(reports) {
    return reports
        .map(r => ({
            time: parseFloat(r.total_time),
            index: parseFloat(r.sovereign_fund_return_index),
            real: parseFloat(r.sovereign_fund_real_return_index)
        }))
        .filter(p => Number.isFinite(p.time) && p.index > 0 && p.real > 0);
}

/**
 * The annualised return on an index from a recorded point to now: (now / then) ^ (1 / years) - 1.
 *
 * With `lookback` it starts from the latest point at least that many years back, so a quarterly record gives a span a
 * little over the lookback, annualised over what it actually is. Without it, from the first point recorded.
 *
 * @param {Array<{time: number, index: number, real: number}>} history
 * @param {{time: number, index: number, real: number}} now
 * @param {'index'|'real'} key
 * @param {number|null} lookback years
 * @returns {{rate: number, years: number}|null} null when the record is shorter than the lookback, or than a year
 */
export function annualisedReturn(history, now, key, lookback = null) {
    let start = null;
    if (lookback === null) {
        start = history[0] ?? null;
    } else {
        for (let i = history.length - 1; i >= 0; i--) {
            if (history[i].time <= now.time - lookback + 1e-9) {
                start = history[i];
                break;
            }
        }
    }
    if (!start || !(start[key] > 0) || !(now[key] > 0)) return null;

    const years = now.time - start.time;
    if (years < Math.max(1, lookback ?? 0) - 1e-9) return null;

    return { rate: Math.pow(now[key] / start[key], 1 / years) - 1, years };
}

/**
 * Draws the fund's quarterly history from /api/macro-reports.
 *
 * Quarters before the fund opened carry a zero size and are left out, so the history starts at inception. The policy
 * weights and bands at each quarter come from the page, keyed by the quarter's total_time, rather than being
 * recomputed here.
 *
 * @param {Array<Object>} reports  macro_report rows in chronological order
 * @param {Object<string, {domesticPolicy: number, domesticBand: number, equityPolicy: number, equityBand: number}>} bands
 * @returns {number} the number of quarters drawn
 */
export function renderReserveCharts(reports, bands) {
    if (typeof Chart === 'undefined') return 0;

    const rows = reports.filter(r => parseFloat(r.sovereign_fund_to_gdp ?? 0) > 0);
    if (rows.length === 0) return 0;

    const labels = quarterLabels(rows);
    const policyAt = rows.map(r => bands?.[r.total_time] ?? null);
    const domesticHistory = policyAt.map(p => (p ? { policy: p.domesticPolicy, band: p.domesticBand } : null));
    const equityHistory = policyAt.map(p => (p ? { policy: p.equityPolicy, band: p.equityBand } : null));

    const size = rows.map(r => pct(r, 'sovereign_fund_to_gdp'));
    const weight = rows.map(r => pct(r, 'sovereign_fund_domestic_weight'));
    const equity = rows.map(r => pct(r, 'sovereign_fund_equity_share'));
    const drawToGdp = rows.map(r => pct(r, 'sovereign_fund_draw_to_gdp'));
    const stabilisation = rows.map(r => pct(r, 'sovereign_fund_stabilisation_to_gdp'));
    // Zero until the fund's first budget year closes: a gap, not a reading.
    const duty = rows.map(r => {
        const v = pct(r, 'sovereign_fund_stamp_duty_to_gdp');
        return v === null || v <= 0 ? null : v;
    });

    draw('reserveSizeChart', [lineDataset('Fund size', size, THEME_COLORS.primary)], labels, chartOptions({ legend: false }));
    draw('reserveWeightChart', bandDatasets('District weight', weight, domesticHistory, SLEEVE_COLORS.district, withAlpha(SLEEVE_COLORS.district, 0.15)), labels, chartOptions());
    draw('reserveEquityChart', bandDatasets('Equity share', equity, equityHistory, SLEEVE_COLORS.equities, withAlpha(SLEEVE_COLORS.equities, 0.15)), labels, chartOptions());
    draw('reserveFlowsChart', [
        lineDataset('Budget draw', drawToGdp, FLOW_COLORS.draw),
        lineDataset('Stabilisation', stabilisation, FLOW_COLORS.stabilisation),
        lineDataset('Stamp duty', duty, FLOW_COLORS.duty)
    ], labels, chartOptions());

    renderReturnChart(returnHistory(reports));

    return rows.length;
}

/**
 * The return index, real and nominal, rebased to 100 at the first quarter shown. The real line is the fund's own
 * colour; the nominal one is a muted reference, since the real return is the one the spending rule is written on.
 */
function renderReturnChart(history) {
    const note = document.getElementById('reserveReturnChartNote');
    if (history.length < 2) {
        note?.classList.remove('hidden');
        return;
    }
    note?.classList.add('hidden');

    const first = history[0];
    const labels = history.map((_, i) => {
        const back = history.length - i - 1;
        return back === 0 ? 'Now' : `-${back}Q`;
    });

    draw('reserveReturnChart', [
        lineDataset('Real', history.map(p => (100 * p.real) / first.real), THEME_COLORS.primary),
        lineDataset('Nominal', history.map(p => (100 * p.index) / first.index), REFERENCE_COLOR, { borderDash: [5, 4], borderWidth: 1.5, pointRadius: 0 })
    ], labels, chartOptions({ index: true }));
}

export function resizeReserveCharts() {
    Object.values(charts).forEach(c => {
        if (c) {
            try { c.resize(); } catch (e) { }
        }
    });
}

export function destroyReserveCharts() {
    resetLazyCharts();
    Object.keys(charts).forEach(id => {
        charts[id] = destroyChartInstance(charts[id]);
    });
}
